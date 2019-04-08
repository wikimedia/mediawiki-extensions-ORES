<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace ORES\Hooks\Api;

use ApiResult;
use ApiQuery;
use ApiQueryBase;
use ORES\Hooks\Helpers;
use ORES\Services\ORESServices;

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
			'damagingthresholds' => Helpers::getDamagingThresholds(),
			'namespaces' => $wgOresEnabledNamespaces
				? array_keys( array_filter( $wgOresEnabledNamespaces ) )
				: \MWNamespace::getValidNamespaces(),
		];
		ApiResult::setArrayType( $data['models'], 'assoc' );
		ApiResult::setIndexedTagName( $data['namespaces'], 'ns' );

		$models = ORESServices::getModelLookup()->getModels();

		foreach ( $models as $modelName => $modelData ) {
			$data['models'][$modelName] = [
				'version' => $modelData['version'],
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
