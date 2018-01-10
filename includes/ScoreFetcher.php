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

namespace ORES;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;

class ScoreFetcher implements ScoreLookup {

	/**
	 * @see ScoreLookup::getScores()
	 *
	 * @param int|array $revisions Single or multiple revisions
	 * @param string|array|null $models Single or multiple model names. If
	 * left empty, all configured models are queried.
	 * @param bool $precache either the request is made for precaching or not

	 * @param null $originalRequest
	 * @return array Results in the form returned by ORES API
	 */
	public function getScores(
		$revisions,
		$models = null,
		$precache = false,
		$originalRequest = null
	) {
		if ( !$models ) {
			global $wgOresModels;
			$models = array_keys( array_filter( $wgOresModels ) );
		}

		$params = [
			'models' => implode( '|', (array)$models ),
			'revids' => implode( '|', (array)$revisions ),
		];
		if ( $precache === true ) {
			$params['precache'] = true;
		}

		$oresService = MediaWikiServices::getInstance()->getService( 'ORESService' );
		$wireData = $oresService->request( $params, $originalRequest );

		$wikiId = ORESService::getWikiID();
		if ( array_key_exists( 'models', $wireData[$wikiId] ) ) {
			$this->checkAndUpdateModels( $wireData[$wikiId]['models'] );
		}

		return $wireData[$wikiId]['scores'];
	}

	/**
	 * @param array $modelData Model information returned by the API
	 */
	private function checkAndUpdateModels( array $modelData ) {
		foreach ( $modelData as $model => $modelOutputs ) {
			$responseVersion = $this->checkModelVersion( $model, $modelOutputs );
			if ( $responseVersion !== null ) {
				$this->updateModelVersion( $model, $responseVersion );
			}
		}
	}

	/**
	 * @param string $model API response
	 * @param array $modelOutputs
	 * @return null|string return null if the versions match, otherwise return
	 * the new model version to update to
	 */
	public function checkModelVersion( $model, $modelOutputs ) {
		if ( !array_key_exists( 'version', $modelOutputs ) ) {
			return null;
		}

		$modelLookup = MediaWikiServices::getInstance()->getService( 'ORESModelLookup' );
		try {
			$storageVersion = $modelLookup->getModelVersion( $model );
		} catch ( InvalidArgumentException $exception ) {
			$storageVersion = null;
		}

		$responseVersion = $modelOutputs['version'];

		if ( $storageVersion === $responseVersion ) {
			return null;
		}

		return $responseVersion;
	}

	/**
	 * @param string $model
	 * @param string $responseVersion
	 */
	public function updateModelVersion( $model, $responseVersion ) {
		// TODO: Move to ModelStorage service
		$dbw = \wfGetDB( DB_MASTER );
		$dbw->update(
			'ores_model',
			[
				'oresm_is_current' => 0,
			],
			[
				'oresm_name' => $model,
				'oresm_version != ' . $dbw->addQuotes( $responseVersion ),
			],
			__METHOD__
		);

		$dbw->upsert(
			'ores_model',
			[
				'oresm_name' => $model,
				'oresm_version' => $responseVersion,
				'oresm_is_current' => 1,
			],
			[ 'oresm_name', 'oresm_version' ],
			[
				'oresm_name' => $model,
				'oresm_version' => $responseVersion,
				'oresm_is_current' => 1,
			],
			__METHOD__
		);
	}

	public static function instance() {
		return new self();
	}

}
