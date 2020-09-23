<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010 René Nitzche <dev@dmk-ebusiness.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Elastica\Client;
use Elastica\Document;
use Elastica\Exception\ClientException;
use Elastica\Index;
use Elastica\Query;
use Elastica\QueryBuilder;
use Elastica\Result;
use Elastica\ResultSet;
use Elastica\Search;

/**
 * Service "ElasticSearch search engine" for the "mksearch" extension.
 */
class tx_mksearch_service_engine_ElasticSearch extends Tx_Rnbase_Service_Base implements tx_mksearch_interface_SearchEngine
{
    /**
     * Index used for searching and indexing.
     *
     * @var Index
     */
    private $index = null;

    /**
     * @var tx_mksearch_model_internal_Index
     */
    private $mksearchIndexModel = null;

    /**
     * @var string
     */
    private $credentialsString = '';

    /**
     * Name of the currently open index.
     *
     * @var string
     */
    private $indexName;

    /**
     * @var array
     */
    private $config = [];

    /**
     * @var QueryBuilder
     */
    private $qb = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->config = $this->getConfiguration();
        $useInternalElasticaLib = Tx_Rnbase_Configuration_Processor::getExtensionCfgValue(
            'mksearch',
            'useInternalElasticaLib'
        );
        // if no config is set, enable the internal lib by default!
        $useInternalElasticaLib = false === $useInternalElasticaLib ? true : (int) $useInternalElasticaLib > 0;
        if ($useInternalElasticaLib > 0) {
            \DMK\Mksearch\Utility\ComposerUtility::autoloadElastica();
        }
        $this->qb = new QueryBuilder();
    }

    /**
     * @param array $credentials
     *
     * @throws Exception
     */
    protected function initElasticSearchConnection(array $credentials)
    {
        $this->index = $this->getElasticaIndex($credentials);
        if (!$this->index->exists()) {
            $this->index->create();
        }
        $this->index->open();

        if (!$this->isServerAvailable()) {
            // wir rufen die Methode mit call_user_func_array auf, da sie
            // statisch ist, womit wir diese nicht mocken könnten
            call_user_func_array(
                [$this->getLogger(), 'fatal'],
                [
                    'ElasticSearch service not responding.',
                    'mksearch',
                    [$credentials],
                ]
            );
            throw new ClientException('ElasticSearch service not responding.');
        }
    }

    /**
     * @param array $credentials
     *
     * @return Index
     */
    protected function getElasticaIndex($credentials)
    {
        $elasticaClient = new Client($credentials);

        return $elasticaClient->getIndex($this->getOpenIndexName());
    }

    /**
     * @return bool
     */
    protected function isServerAvailable()
    {
        $response = $this->getIndex()->getClient()->getStatus()->getResponse();

        return 200 == $response->getStatus();
    }

    /**
     * @return string
     */
    protected function getLogger()
    {
        return tx_rnbase_util_Logger;
    }

    /**
     * Search indexed data.
     *
     * @param array $fields
     * @param array $options
     *
     * @return array[tx_mksearch_model_SearchResult] search results
     *
     * @todo support für alle optionen von elasticsearch
     *
     * @see  http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html
     */
    public function search(array $fields = [], array $options = [])
    {
        $startTime = microtime(true);
        $result = [];

        try {
            /* @var $searchResult ResultSet */
            $searchResult = $this->getIndex()->search(
                $this->getElasticaQuery($fields, $options),
                $this->getOptionsForElastica($options)
            );

            $this->checkResponseOfSearchResult($searchResult);
            $items = $this->getItemsFromSearchResult($searchResult);

            $lastRequest = $this->getIndex()->getClient()->getLastRequest();
            $result['searchUrl'] = $lastRequest->getPath();
            $result['searchFields'] = $fields;
            $result['searchQuery'] = $lastRequest->getQuery();
            $result['searchData'] = $lastRequest->getData();
            $result['searchTime'] = (microtime(true) - $startTime).' ms';
            $result['queryTime'] = $searchResult->getTotalTime().' ms';
            $result['numFound'] = $searchResult->getTotalHits();
            $result['aggregations'] = $searchResult->getAggregations();
            $result['error'] = $searchResult->getResponse()->getError();
            $result['items'] = $items;

            if ($options['debug']) {
                tx_rnbase_util_Debug::debug(
                    ['options' => $options, 'result' => $result],
                    __METHOD__.' Line: '.__LINE__
                );
            }
        } catch (Exception $e) {
            $message = 'Exception caught from ElasticSearch: '.$e->getMessage();
            throw new RuntimeException($message);
        }

        return $result;
    }

    /**
     * @param array $fields
     * @param array $options
     *
     * @return Query
     */
    protected function getElasticaQuery(array $fields, array $options)
    {
        $query = $this->qb->query()->bool()
            ->addMust(
                $this->qb->query()->multi_match()
                    ->setQuery($this->getQueryTerm($fields))
                    ->setFields($this->setFields($fields))
                    ->setOperator('and')
            );

        $filterQueryBool = $this->qb->query()->bool();
        $filterQueryBool = $this->filterFeGroups($filterQueryBool);
        $filterQueryBool = $this->filterFacets($filterQueryBool, $fields);
        $query->addFilter($filterQueryBool);

        $elasticaQuery = new Query($query);
        $elasticaQuery = $this->handleSorting($elasticaQuery, $options);
        $elasticaQuery = $this->handleFacets($elasticaQuery);

        return $elasticaQuery;
    }

    /**
     * Get the query term. If there is a ":" in the term string you will just get the part after it.
     *
     * @param array $fields
     *
     * @return string
     */
    private function getQueryTerm(array $fields)
    {
        if (false !== strpos($fields['term'], ':')) {
            $term = Tx_Rnbase_Utility_Strings::trimExplode(':', $fields['term']);

            return array_pop($term);
        }

        return $fields['term'];
    }

    /**
     * Set field to given value if its name is send via form.
     *
     * @param array $fields
     *
     * @return string[]
     */
    private function setFields(array $fields)
    {
        if (!isset($this->config['allowedSearchFields']) || empty($this->config['allowedSearchFields'])) {
            return $this->getBoostOrDefaultFields();
        }
        $allowedFields = Tx_Rnbase_Utility_Strings::trimExplode(',', $this->config['allowedSearchFields']);

        if (false !== strpos($fields['term'], ':')) {
            $term = Tx_Rnbase_Utility_Strings::trimExplode(':', $fields['term']);
            $setFields = trim(array_shift($term));

            if (in_array($setFields, $allowedFields)) {
                return [$setFields];
            }
        }

        return $this->getBoostOrDefaultFields();
    }

    /**
     * @return array
     */
    private function getBoostOrDefaultFields()
    {
        if (!isset($this->config['filter']['boost']) || empty($this->config['filter']['boost'])) {
            return ['_all'];
        }
        $fields = [];

        foreach ($this->config['filter']['boost'] as $fieldName => $boost) {
            $fields[] = "$fieldName^$boost";
        }

        return $fields;
    }

    /**
     * @param Query $elasticaQuery
     *
     * @return Query
     */
    private function handleFacets(Query $elasticaQuery)
    {
        if (isset($this->config['filter']['facets']['fields']) &&
            !empty($this->config['filter']['facets']['fields']) &&
            is_array($this->config['filter']['facets']['fields'])
        ) {
            foreach ($this->config['filter']['facets']['fields'] as $facet) {
                $agg = new \Elastica\Aggregation\Terms($facet['name']);
                $agg->setField("{$facet['field']}.keyword");
                $elasticaQuery->addAggregation($agg);
            }
        }

        return $elasticaQuery;
    }

    /**
     * @param Query\BoolQuery $query
     *
     * @return Query\BoolQuery
     */
    private function filterFeGroups(Query\BoolQuery $query)
    {
        $userGroups = $GLOBALS['TSFE']->fe_user->groupData['uid'];

        if (empty($userGroups)) {
            $groups = '0 -2'; // "-2" is "show at any login"
        } else {
            $groups = implode(' ', $userGroups);
            $groups .= ' 0 -2';  // separate with whitespace if you have more search values, e.g.: '0 35'
        }

        return $query->addMust(
            $this->qb->query()->match('fe_group_mi', $groups)
        );
    }

    /**
     * @param Query\BoolQuery $query
     * @param array $fields
     *
     * @return Query
     */
    private function filterFacets(Query\BoolQuery $query, array $fields)
    {
        if (isset($fields['facet']) &&
            is_array($fields['facet']) &&
            !empty($fields['facet'])
        ) {
            $mapping = $this->config['filter']['facets']['hit']['mapping']['field'];

            foreach ($fields['facet'] as $name => $value) {
                if (isset($mapping[$name])) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    foreach ($value as $item) {
                        $query->addMust(
                            $this->qb->query()->match($mapping[$name], $item)
                        );
                    }
                }
            }
        }

        return $query;
    }
    /**
     * @param Query $elasticaQuery
     * @param array $options
     *
     * @return Query
     */
    private function handleSorting(Query $elasticaQuery, array $options)
    {
        if ($options['sort']) {
            list($field, $order) = Tx_Rnbase_Utility_Strings::trimExplode(' ', $options['sort'],
                true);
            $elasticaQuery->addSort(
                [
                    $field => [
                        'order' => $order,
                    ],
                ]
            );
        }

        return $elasticaQuery;
    }

    /**
     * @param ResultSet $searchResult
     *
     * @throws RuntimeException
     */
    protected function checkResponseOfSearchResult(ResultSet $searchResult)
    {
        $httpStatus = $searchResult->getResponse()->getStatus();
        if (200 != $httpStatus) {
            $lastRequest = $this->getIndex()->getClient()->getLastRequest();
            $message = 'Error requesting ElasticSearch. HTTP status: '.$httpStatus.
                '; Path: '.$lastRequest->getPath().
                '; Query: '.$lastRequest->getQuery().
                '; Data: '.$lastRequest->getData();
            throw new RuntimeException($message);
        }
    }

    /**
     * @param ResultSet $searchResult
     *
     * @return \tx_mksearch_model_SearchHit[]
     */
    protected function getItemsFromSearchResult(ResultSet $searchResult)
    {
        $items = [];
        if ($elasticSearchResult = $searchResult->getResults()) {
            /* @var $item Result */
            foreach ($elasticSearchResult as $item) {
                $hit = tx_rnbase::makeInstance(
                    'tx_mksearch_model_SearchHit',
                    $item->getData()
                    );
                $item->getIndex() && $hit->setIndex($item->getIndex());
                $item->getType() && $hit->setType($item->getType());
                $item->getId() && $hit->setId($item->getId());
                $item->getScore() && $hit->setScore($item->getScore());
                $items[] = $hit;
            }
        }

        return $items;
    }

    /**
     * @param array $options
     *
     * @return array
     */
    protected function getOptionsForElastica(array $options)
    {
        $elasticaOptions = [];

        foreach ($options as $key => $value) {
            $key = $this->remapElasticaOptionKey($key);
            switch ($key) {
                case Search::OPTION_SEARCH_TYPE:
                case Search::OPTION_ROUTING:
                case Search::OPTION_PREFERENCE:
                case Search::OPTION_VERSION:
                case Search::OPTION_TIMEOUT:
                case Search::OPTION_FROM:
                case Search::OPTION_SIZE:
                case Search::OPTION_SCROLL:
                case Search::OPTION_SCROLL_ID:
                case Search::OPTION_SEARCH_TYPE_SUGGEST:
                    // explain und limit wird von Elastica selbst remapped
                case 'explain':
                case 'limit':
                    $elasticaOptions[$key] = $value;
            }
        }

        return $elasticaOptions;
    }

    /**
     * @param string $optionKey
     *
     * @return string
     */
    private function remapElasticaOptionKey($optionKey)
    {
        switch ($optionKey) {
            case 'debug':
                $optionKey = 'explain';
                break;
            case 'offset':
                $optionKey = Search::OPTION_FROM;
                break;
        }

        return $optionKey;
    }

    /**
     * Get a document from index.
     *
     * @param string $uid
     * @param string $extKey
     * @param string $contentType
     *
     * @return tx_mksearch_model_SearchHit
     */
    public function getByContentUid($uid, $extKey, $contentType)
    {
    }

    /**
     * Return name of the index currently opened.
     *
     * @return string
     */
    public function getOpenIndexName()
    {
        return $this->indexName;
    }

    /**
     * Open an index.
     *
     * @param tx_mksearch_model_internal_Index $index         Instance of the index to open
     * @param bool                             $forceCreation Force creation of index if it doesn't
     *                                                        exist
     */
    public function openIndex(
        tx_mksearch_model_internal_Index $index,
        $forceCreation = false
    ) {
        $credentialsForElastica = $this->getElasticaCredentialsFromCredentialsString(
            $index->getCredentialString()
        );
        $this->initElasticSearchConnection($credentialsForElastica);
    }

    /**
     * Der String ist semikolon separiert. der erste Teil ist der Index,
     * alle weiteren sind die Server. Die Credentials für die Server
     * werden kommasepariert erwartet wobei erst host, dann port dann url pfad.
     *
     * @param string $credentialString
     *
     * @return array
     */
    protected function getElasticaCredentialsFromCredentialsString($credentialString)
    {
        $this->credentialsString = $credentialString;
        $serverCredentials = tx_rnbase_util_Strings::trimExplode(';', $credentialString, true);

        $this->indexName = $serverCredentials[0];
        unset($serverCredentials[0]);

        $credentialsForElastica = [];
        foreach ($serverCredentials as $serverCredential) {
            $credentialsForElastica['servers'][] =
                $this->getElasticaCredentialArrayFromIndexCredentialStringForOneServer(
                    $serverCredential
                );
        }

        return $credentialsForElastica;
    }

    /**
     * @param string $credentialString
     *
     * @return array
     */
    private function getElasticaCredentialArrayFromIndexCredentialStringForOneServer(
        $credentialString
    ) {
        $serverCredential = tx_rnbase_util_Strings::trimExplode(',', $credentialString);

        return [
            'host' => $serverCredential[0],
            'port' => $serverCredential[1],
            'path' => $serverCredential[2],
        ];
    }

    /**
     * Liefert den Index.
     *
     * @return Index
     */
    public function getIndex()
    {
        if (!is_object($this->index)) {
            $this->openIndex($this->mksearchIndexModel);
        }

        return $this->index;
    }

    /**
     * Check if the specified index exists.
     *
     * @param string $name Name of index
     *
     * @return bool
     */
    public function indexExists($name)
    {
        return $this->getIndex()->getClient()->getStatus()->indexExists($name);
    }

    /**
     * Commit index.
     *
     * @return bool success
     */
    public function commitIndex()
    {
        // wird direkt beim Hinzufügen oder Löschen ausgeführt
    }

    /**
     * Close index.
     */
    public function closeIndex()
    {
        $this->getIndex()->close();
        unset($this->index);
    }

    /**
     * Delete an entire index.
     *
     * @param optional              string $name Name of index to delete, if not the open index is
     *                                           meant to be deleted
     */
    public function deleteIndex($name = null)
    {
        if ($name) {
            $this->getIndex()->getClient()->getIndex($name)->delete();
        } else {
            $this->getIndex()->delete();
        }
    }

    /**
     * Optimize index.
     */
    public function optimizeIndex()
    {
        $this->getIndex()->optimize();
    }

    /**
     * Replace an index with another.
     *
     * The index to be replaced will be deleted.
     * This actually means that the old's index's directory will be deleted recursively!
     *
     * @param string $which Name of index to be replaced i. e. deleted
     * @param string $by    Name of index which replaces the index named $which
     */
    public function replaceIndex($which, $by)
    {
        //vorerst nichts zu tun
    }

    /**
     * Put a new record into index.
     *
     * @param tx_mksearch_interface_IndexerDocument $doc Document to index
     *
     * @return bool $success
     */
    public function indexNew(tx_mksearch_interface_IndexerDocument $doc)
    {
        $data = [];

        // Primary key data (fields are all scalar)
        $primaryKeyData = $doc->getPrimaryKey();
        foreach ($primaryKeyData as $key => $field) {
            if (!empty($field)) {
                $data[$key] = tx_mksearch_util_Misc::utf8Encode($field->getValue());
            }
        }
        foreach ($doc->getData() as $key => $field) {
            if ($field) {
                $data[$key] = tx_mksearch_util_Misc::utf8Encode($field->getValue());
            }
        }

        $primaryKey = $doc->getPrimaryKey();
        $elasticaDocument = new Document($primaryKey['uid']->getValue(), $data);
        $elasticaDocument->setType(
            $primaryKey['extKey']->getValue().':'.
            $primaryKey['contentType']->getValue()
        );

        return $this->getIndex()->addDocuments([$elasticaDocument])->isOk();
    }

    /**
     * Update or create an index record.
     *
     * @param tx_mksearch_interface_IndexerDocument $doc Document to index
     *
     * @return bool $success
     */
    public function indexUpdate(tx_mksearch_interface_IndexerDocument $doc)
    {
        // ElasticSearch erkennt selbst ob ein Update nötig ist
        return $this->indexNew($doc);
    }

    /**
     * Delete index document specified by content uid.
     *
     * @param int    $uid         Unique identifier of data record - unique within the
     *                            scope of $extKey and $content_type
     * @param string $extKey      Key of extension the data record belongs to
     * @param string $contentType Name of semantic content type
     *
     * @return bool success
     */
    public function indexDeleteByContentUid($uid, $extKey, $contentType)
    {
        $type = $extKey.':'.$contentType;
        $elasticaDocument = new Document($uid);
        $elasticaDocument->setType($type);

        return $this->getIndex()->deleteDocuments([$elasticaDocument])->isOk();
    }

    /**
     * Delete index document specified by index id.
     *
     * @param string $id
     */
    public function indexDeleteByIndexId($id)
    {
    }

    /**
     * Delete documents specified by raw query.
     *
     * @param string $query
     */
    public function indexDeleteByQuery($query, $options = [])
    {
    }

    /**
     * Return an indexer document instance for the given content type.
     *
     * @param string $extKey      Extension key of records to be indexed
     * @param string $contentType Content type of records to be indexed
     *
     * @return tx_mksearch_interface_IndexerDocument
     */
    public function makeIndexDocInstance($extKey, $contentType)
    {
        return tx_rnbase::makeInstance(
            'tx_mksearch_model_IndexerDocumentBase',
            $extKey,
            $contentType,
            'tx_mksearch_model_IndexerFieldBase'
        );
    }

    /**
     * Returns a index status object.
     *
     * @return tx_mksearch_util_Status
     */
    public function getStatus()
    {
        /* @var $status tx_mksearch_util_Status */
        $status = tx_rnbase::makeInstance('tx_mksearch_util_Status');

        $id = -1;
        $msg = 'Down. Maybe not started?';
        try {
            if ($this->isServerAvailable()) {
                $id = 1;
                $msg = 'Up and running (Ping time: '.
                    $this->getIndex()->getClient()->getStatus()->getResponse()->getQueryTime().
                    ' ms)';
            }
        } catch (Exception $e) {
            $msg = 'Error connecting ElasticSearch: '.$e->getMessage().'.';
            $msg .= ' Credentials: '.$this->credentialsString;
        }

        $status->setStatus($id, $msg);

        return $status;
    }

    /**
     * Set index model with credential data.
     *
     * @param tx_mksearch_model_internal_Index $index Instance of the index to open
     */
    public function setIndexModel(tx_mksearch_model_internal_Index $index)
    {
        $this->mksearchIndexModel = $index;
    }

    /**
     * This function is called for each index after the indexing
     * is done.
     *
     * @param tx_mksearch_model_internal_Index $index
     */
    public function postProcessIndexing(tx_mksearch_model_internal_Index $index)
    {
    }

    /**
     * Get the configuration array for the elasticsearch typoscript setup.
     *
     * @return array
     */
    private function getConfiguration()
    {
        $objectManager = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\Extbase\\Object\\ObjectManager');
        $configurationManager = $objectManager->get('TYPO3\\CMS\\Extbase\\Configuration\\ConfigurationManager');
        $extbaseFrameworkConfiguration = $configurationManager->getConfiguration(\TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
        $typoScriptService = new \TYPO3\CMS\Core\TypoScript\TypoScriptService();

        return $typoScriptService->convertTypoScriptArrayToPlainArray($extbaseFrameworkConfiguration['plugin.']['tx_mksearch.']['elasticsearch.']);
    }
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/service/engine/class.tx_mksearch_service_engine_ElasticSearch.php']) {
    include_once $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/service/engine/class.tx_mksearch_service_engine_ElasticSearch.php'];
}
