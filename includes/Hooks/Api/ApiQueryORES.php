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

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\WikiMap\WikiMap;
use ORES\Hooks\Helpers;
use ORES\Services\ORESServices;

/**
 * A query action to return meta information about ORES models and
 * configuration on the wiki.
 *
 * @ingroup API
 */
class ApiQueryORES extends ApiQueryBase {
	private NamespaceInfo $namespaceInfo;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		NamespaceInfo $namespaceInfo
	) {
		parent::__construct( $query, $moduleName, 'ores' );
		$this->namespaceInfo = $namespaceInfo;
	}

	public function execute() {
		$enabledNamespaces = $this->getConfig()->get( 'OresEnabledNamespaces' );

		$result = $this->getResult();
		$data = [
			'baseurl' => $this->getConfig()->get( 'OresBaseUrl' ),
			'wikiid' => $this->getConfig()->get( 'OresWikiId' ) ?: WikiMap::getCurrentWikiId(),
			'models' => [],
			'excludebots' => (bool)$this->getConfig()->get( 'OresExcludeBots' ),
			'damagingthresholds' => Helpers::getDamagingThresholds(),
			'namespaces' => $enabledNamespaces
				? array_keys( array_filter( $enabledNamespaces ) )
				: $this->namespaceInfo->getValidNamespaces(),
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

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		return 'public';
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&meta=ores'
				=> 'apihelp-query+ores-example-simple',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ORES';
	}

}
