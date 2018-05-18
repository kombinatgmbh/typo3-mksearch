<?php
use TYPO3\CMS\Core\Database\ConnectionPool;

/***************************************************************
*  Copyright notice
*
*  (c) 2014 DMK E-BUSINESS GmbH
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


tx_rnbase::load('tx_mksearch_tests_Util');
tx_rnbase::load('tx_rnbase_util_TYPO3');

/**
 * Base Testcase for DB Tests
 *
 * @package tx_mksearch
 * @subpackage tx_mksearch_tests
 * @author Michael Wagner <michael.wagner@dmk-ebusiness.de>
 * @license http://www.gnu.org/licenses/lgpl.html
 *          GNU Lesser General Public License, version 3 or later
 */
abstract class tx_mksearch_tests_DbTestcase extends Tx_Phpunit_Database_TestCase
{
    protected $workspaceBackup;
    protected $templaVoilaConfigBackup = null;
    protected $db;

    /**
     * @var array
     */
    private $originalDatabaseName;

    /**
     * @var boolean
     */
    protected $unloadTemplavoila = true;

    /**
     * @var array
     */
    protected $addRootLineFieldsBackup = '';

    /**
     * Liste der extensions, welche in die test DB importiert werden müssen.
     *
     * @var array
     */
    protected $importExtensions = array('cms' => 'cms', 'mksearch');

    /**
     * Liste der daten, welche in die test DB importiert werden müssen.
     *
     * @var array
     */
    protected $importDataSets = array();

    /**
     * Constructs a test case with the given name.
     *
     * @param string $name the name of a testcase
     * @param array $data ?
     * @param string $dataName ?
     */
    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        if (tx_rnbase_util_TYPO3::isTYPO62OrHigher()) {
            $this->importExtensions[] = 'core';
            $this->importExtensions[] = 'frontend';
        }

        if (tx_rnbase_util_TYPO3::isTYPO76OrHigher()) {
            unset($this->importExtensions['cms']);
        }

        // templavoila und realurl brauchen wir da es im BE sonst Warnungen hagelt
        // und man die Testergebnisse nicht sieht
        if (tx_rnbase_util_Extensions::isLoaded('realurl')) {
            $this->importExtensions[] = 'realurl';
        }
        if (tx_rnbase_util_Extensions::isLoaded('templavoila')) {
            $this->importExtensions[] = 'templavoila';
        }
        // fügt felder bei datenbank abfragen hinzu in $TYPO3_CONF_VARS['FE']['pageOverlayFields']
        // und $TYPO3_CONF_VARS['FE']['addRootLineFields']
        if (tx_rnbase_util_Extensions::isLoaded('tq_seo')) {
            $this->importExtensions[] = 'tq_seo';
        }
    }

    /**
     * setUp() = init DB etc.
     */
    protected function setUp()
    {
        tx_mksearch_tests_Util::emptyAddRootlineFields();

        // set up the TCA
        tx_mksearch_tests_Util::tcaSetUp($this->importExtensions);

        // set up hooks
        tx_mksearch_tests_Util::hooksSetUp();

        // wir deaktivieren den relation manager
        tx_mksearch_tests_Util::disableRelationManager();

        // set up the workspace
        $this->workspaceBackup = $GLOBALS['BE_USER']->workspace;
        $GLOBALS['BE_USER']->setWorkspace(0);

        // WORKAROUND: phpunit seems to backup static attributes (in phpunit.xml)
        // from version 3.6.10 not before. I'm not completely
        // sure about that but from version 3.6.10 clearPageInstance is no
        // more neccessary to have the complete test suite succeed.
        // But this version is buggy. (http://forge.typo3.org/issues/36232)
        // as soon as this bug is fixed, we can use the new phpunit version
        // and dont need this anymore
        tx_mksearch_service_indexer_core_Config::clearPageInstance();

        // set up database
        $GLOBALS['TYPO3_DB']->debugOutput = true;
        try {
            $this->createDatabase();
        } catch (RuntimeException $e) {
            $this->markTestSkipped(
                'This test is skipped because the test database is not available.'
            );
        }
        // assuming that test-database can be created otherwise PHPUnit will skip the test
        $this->db = $this->useTestDatabase();

        if (tx_rnbase_util_TYPO3::isTYPO87OrHigher()) {
            $this->setUpTestDatabaseForConnectionPool();
        }

        $this->importStdDB();
        $this->importExtensions($this->importExtensions);

        foreach ($this->importDataSets as $importDataSet) {
            $this->importDataSet($importDataSet);
        }

        // das devlog stört nur bei der Testausführung im BE und ist da auch
        // vollkommen unnötig
        tx_mksearch_tests_Util::disableDevlog();

        // set up tv
        if (tx_rnbase_util_Extensions::isLoaded('templavoila') && $this->unloadTemplavoila) {
            $this->templaVoilaConfigBackup = $GLOBALS['TYPO3_LOADED_EXT']['templavoila'];
            $GLOBALS['TYPO3_LOADED_EXT']['templavoila'] = null;

            tx_mksearch_tests_Util::unloadExtensionForTypo362OrHigher('templavoila');
        }

        $this->purgeRootlineCaches();
    }

    /**
     * tearDown() = destroy DB etc.
     */
    protected function tearDown()
    {
        // tear down TCA
        tx_mksearch_tests_Util::tcaTearDown();

        // tear down hooks
        tx_mksearch_tests_Util::hooksTearDown();

        // wir aktivieren den relation manager wieder
        tx_mksearch_tests_Util::restoreRelationManager();

        $this->tearDownDatabase();

        // tear down Workspace
        $GLOBALS['BE_USER']->setWorkspace($this->workspaceBackup);

        // tear down tv
        if ($this->templaVoilaConfigBackup !== null) {
            $GLOBALS['TYPO3_LOADED_EXT']['templavoila'] = $this->templaVoilaConfigBackup;
            $this->templaVoilaConfigBackup = null;

            if (tx_rnbase_util_TYPO3::isTYPO62OrHigher()) {
                $extensionManagementUtility = new TYPO3\CMS\Core\Utility\ExtensionManagementUtility();
                $extensionManagementUtility->loadExtension('templavoila');
            }
        }

        tx_mksearch_tests_Util::resetAddRootlineFields();

        $this->purgeRootlineCaches();
    }

    /**
     * @return void
     */
    protected function purgeRootlineCaches()
    {
        if (tx_rnbase_util_TYPO3::isTYPO62OrHigher()) {
            \TYPO3\CMS\Core\Utility\RootlineUtility::purgeCaches();
        }
    }

    /**
     * We need to set the new database for the connection pool connections aswell
     * because it is used for example in the rootline utility
     * @todo we should not only support the default connection
     * @return void
     */
    protected function setUpTestDatabaseForConnectionPool()
    {
        // truncate connections so they can be reinitialized with $this->testDatabase
        // when needed
        $connections = new ReflectionProperty(ConnectionPool::class, 'connections');
        $connections->setAccessible(true);
        $connections->setValue(null, array());

        $this->originalDatabaseName = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = $this->testDatabase;
    }

    /**
     * @return void
     */
    protected function tearDownDatabase()
    {
        if (tx_rnbase_util_TYPO3::isTYPO87OrHigher() && $this->originalDatabaseName) {
            // $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] is used
            // inside phpunit to get the original database so we need to reset that
            // before anything is done
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname'] = $this->originalDatabaseName;
        }
        $this->cleanDatabase();
        $this->dropDatabase();

        // we need to reset the database for the connection pool connections aswell
        if (tx_rnbase_util_TYPO3::isTYPO87OrHigher()) {
            // truncate connections so they can be reinitialized with the real configuration
            $connections = new ReflectionProperty(ConnectionPool::class, 'connections');
            $connections->setAccessible(true);
            $connections->setValue(null, array());
        }

        $this->switchToTypo3Database();
    }
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/tests/class.tx_mksearch_tests_DbTestcase.php']) {
    include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/mksearch/tests/class.tx_mksearch_tests_DbTestcase.php']);
}
