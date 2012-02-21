<?php
/**
 *
 *  @package tx_mksearch
 *  @subpackage tx_mksearch_mod1
 *
 *  Copyright notice
 *
 *  (c) 2012 das MedienKombinat GmbH <kontakt@das-medienkombinat.de>
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
 */
require_once(t3lib_extMgm::extPath('rn_base', 'class.tx_rnbase.php'));
tx_rnbase::load('tx_rnbase_util_Templates');

/**
 *
 * @package tx_mksearch
 * @subpackage tx_mksearch_mod1
 * @author Michael Wagner <michael.wagner@das-medienkombinat.de>
 */
class tx_mksearch_mod1_util_Misc {

	/**
	 *
	 * @TODO: prüfen, ob wir uns auf der richtigen seite befinden.
	 *
	 * @param tx_rnbase_mod_IModule $mod
	 * @return mixed null or string
	 */
	public static function checkPid(tx_rnbase_mod_IModule $mod) {
		if ($mod->getPid()){
			return null;
		}
		$pages = self::getStorageFolders();
		foreach($pages as &$page) {
			$pid = intval($page);
			$pageinfo = t3lib_BEfunc::readPageAccess($pid, $mod->perms_clause);
			$page  = '<a href="index.php?id=' . $pid . '">';
			$page .= t3lib_iconWorks::getSpriteIconForRecord('pages', t3lib_BEfunc::getRecord('pages', $pid));
			$page .= ' '.$pageinfo['title'];
			$page .= ' '.htmlspecialchars($pageinfo['_thePath']);
			$page .= '</a>';
		}
		$out  = '<div class="tables graybox">';
		$out .= '	<h2 class="bgColor2 t3-row-header">###LABEL_NO_PAGE_SELECTED###</h2>';
		if (!empty($pages)) {
			$out .= '	<ul><li>'.implode('</li><li>', $pages).'</li></ul>';
		}
		$out .= '</div>';
		return $out;
	}
	/**
	 * Liefert Page Ids zu seiten mit mksearch inhalten.
	 * @return array
	 */
	private static function getStorageFolders() {
		$pages = array_merge(
			// wir holen alle seiten auf denen indexer liegen
			tx_rnbase_util_DB::doSelect('pid as pageid', 'tx_mksearch_indices', array('enablefieldsoff' => 1)),
			// wir holen alle seiten die mksearch beinhalten
			tx_rnbase_util_DB::doSelect('uid as pageid', 'pages', array('enablefieldsoff' => 1, 'where' => 'module=\'mksearch\''))
		);
		if (empty($pages)) {
			return array();
		}
		// wir mergen die seiten zusammen
		$pages = call_user_func_array('array_merge_recursive', array_values($pages));
		if (empty($pages['pageid'])) {
			return array();
		}
		return array_keys(array_flip($pages['pageid']));
	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/mod1/util/class.tx_mksearch_mod1_util_Misc.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/mksearch/mod1/util/class.tx_mksearch_mod1_util_Misc.php']);
}