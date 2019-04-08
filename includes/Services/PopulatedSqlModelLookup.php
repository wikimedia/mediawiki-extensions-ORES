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

namespace ORES\Services;

use InvalidArgumentException;
use ORES\ORESService;
use ORES\Storage\ModelLookup;
use Psr\Log\LoggerInterface;

class PopulatedSqlModelLookup implements ModelLookup {

	private $modelLookup;

	private $ORESService;

	private $logger;

	public function __construct(
		ModelLookup $modelLookup,
		ORESService $ORESService,
		LoggerInterface $logger
	) {
		$this->modelLookup = $modelLookup;
		$this->ORESService = $ORESService;
		$this->logger = $logger;
	}

	/**
	 * @see ModelLookup::getModels()
	 *
	 * @return array[]
	 */
	public function getModels() {
		$modelData = $this->modelLookup->getModels();
		if ( $modelData === [] ) {
			global $wgOresModels;
			$models = array_keys( array_filter( $wgOresModels ) );
			if ( $models === [] ) {
				return $modelData;
			}

			$this->initializeModels( $models );
			$modelData = $this->modelLookup->getModels();
		}

		return $modelData;
	}

	private function initializeModels( $models ) {
		$wikiId = ORESService::getWikiID();
		$response = $this->ORESService->request( [] );
		if ( !isset( $response[$wikiId] ) || empty( $response[$wikiId]['models'] ) ) {
			$this->logger->error( 'Bad response from ORES when requesting models: '
				. json_encode( $response ) );
			return;
		}

		foreach ( $models as $model ) {
			$this->initializeModel( $model, $response[$wikiId]['models'] );
		}
	}

	private function initializeModel( $model, $modelsData ) {
		if ( !isset( $modelsData[$model] ) || !isset( $modelsData[$model]['version'] ) ) {
			return;
		}

		ScoreFetcher::instance()->updateModelVersion( $model, $modelsData[$model]['version'] );
	}

	/**
	 * @see ModelLookup::getModelId()
	 * @param string $model
	 *
	 * @throws InvalidArgumentException
	 * @return int
	 */
	public function getModelId( $model ) {
		$this->getModels();
		return $this->modelLookup->getModelId( $model );
	}

	/**
	 * @see ModelLookup::getModelVersion()
	 * @param string $model
	 *
	 * @throws InvalidArgumentException
	 * @return string
	 */
	public function getModelVersion( $model ) {
		$this->getModels();
		return $this->modelLookup->getModelVersion( $model );
	}

}
