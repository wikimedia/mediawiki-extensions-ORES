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

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ORES\Services\ORESServices;
use ORES\Services\PopulatedSqlModelLookup;
use ORES\Storage\DatabaseQueryBuilder;
use ORES\Storage\ModelLookup;
use ORES\Storage\ScoreStorage;
use ORES\Storage\SqlModelLookup;
use ORES\Storage\SqlScoreLookup;
use ORES\Storage\SqlScoreStorage;
use ORES\Storage\StorageScoreLookup;
use ORES\Storage\ThresholdLookup;
use Psr\Log\LoggerInterface;

return [
	'ORESLogger' => static function ( MediaWikiServices $services ): LoggerInterface {
		return LoggerFactory::getInstance( 'ORES' );
	},

	'ORESModelLookup' => static function ( MediaWikiServices $services ): ModelLookup {
		return new PopulatedSqlModelLookup(
			new SqlModelLookup( $services->getConnectionProvider() ),
			ORESServices::getORESService( $services ),
			ORESServices::getLogger( $services ),
			$services->getMainConfig()->get( 'OresUseLiftwing' )
		);
	},

	'ORESThresholdLookup' => static function ( MediaWikiServices $services ): ThresholdLookup {
		return new ThresholdLookup(
			new ThresholdParser( LoggerFactory::getInstance( 'ORES' ) ),
			ORESServices::getModelLookup( $services ),
			ORESServices::getORESService( $services ),
			$services->getMainWANObjectCache(),
			ORESServices::getLogger( $services ),
			$services->getStatsdDataFactory(),
			$services->getMainConfig()
		);
	},

	'ORESScoreStorage' => static function ( MediaWikiServices $services ): ScoreStorage {
		return new SqlScoreStorage(
			$services->getConnectionProvider(),
			ORESServices::getModelLookup( $services ),
			ORESServices::getLogger( $services )
		);
	},

	'ORESService' => static function ( MediaWikiServices $services ): ORESService {
		if ( $services->getMainConfig()->get( 'OresUseLiftwing' ) ) {
			return new LiftWingService(
				ORESServices::getLogger( $services ),
				$services->getHttpRequestFactory(),
				$services->getMainConfig()
			);
		} else {
			return new ORESService(
				ORESServices::getLogger( $services ),
				$services->getHttpRequestFactory()
			);
		}
	},

	'ORESScoreLookup' => static function ( MediaWikiServices $services ): StorageScoreLookup {
		return new SqlScoreLookup(
			ORESServices::getModelLookup( $services ),
			$services->getConnectionProvider()
		);
	},

	'ORESDatabaseQueryBuilder' => static function ( MediaWikiServices $services ): DatabaseQueryBuilder {
		return new DatabaseQueryBuilder(
			ORESServices::getThresholdLookup( $services ),
			$services->getConnectionProvider()->getReplicaDatabase()
		);
	}

];
