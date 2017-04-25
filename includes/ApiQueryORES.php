<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace ORES;

use ApiResult;
use ApiQuery;
use ApiQueryBase;

/**
 * A query action to return meta information about ORES models and
 * configuration on the wiki.
 *
 * @ingroup API
 */
class ApiQueryORES extends ApiQueryBase {

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'ores' );
	}

	public function execute() {
		global $wgOresBaseUrl, $wgOresExcludeBots,
			$wgOresEnabledNamespaces, $wgOresWikiId;

		$result = $this->getResult();
		$data = [
			'baseurl' => $wgOresBaseUrl,
			'wikiid' => $wgOresWikiId ?: wfWikiID(),
			'models' => [],
			'excludebots' => (bool)$wgOresExcludeBots,
			'damagingthresholds' => Hooks::getDamagingThresholds(),
			'namespaces' => $wgOresEnabledNamespaces
				? array_keys( array_filter( $wgOresEnabledNamespaces ) )
				: \MWNamespace::getValidNamespaces(),
		];
		ApiResult::setArrayType( $data['models'], 'assoc' );
		ApiResult::setIndexedTagName( $data['namespaces'], 'ns' );

		$this->addTables( 'ores_model' );
		$this->addFields( [ 'oresm_name', 'oresm_version', 'oresm_is_current' ] );
		$this->addWhere( [ 'oresm_is_current' => 1 ] );
		$res = $this->select( __METHOD__ );

		foreach ( $res as $row ) {
			$data['models'][$row->oresm_name] = [
				'version' => $row->oresm_version,
			];
		}

		$result->addValue( [ 'query' ], 'ores', $data );
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return [];
	}

	protected function getExamplesMessages() {
		return [
			'action=query&meta=ores'
				=> 'apihelp-query+ores-example-simple',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:ORES';
	}

}
