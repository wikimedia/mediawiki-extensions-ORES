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
use ORES\Storage\SqlModelLookup;
use ORES\Storage\SqlScoreLookup;
use ORES\Storage\SqlScoreStorage;
use ORES\Storage\ThresholdLookup;

return [
	'ORESLogger' => static function ( MediaWikiServices $services ) {
		return LoggerFactory::getInstance( 'ORES' );
	},

	'ORESModelLookup' => static function ( MediaWikiServices $services ) {
		return new PopulatedSqlModelLookup(
			new SqlModelLookup( $services->getDBLoadBalancer() ),
			ORESServices::getORESService(),
			ORESServices::getLogger()
		);
	},

	'ORESThresholdLookup' => static function ( MediaWikiServices $services ) {
		return new ThresholdLookup(
			new ThresholdParser( LoggerFactory::getInstance( 'ORES' ) ),
			ORESServices::getModelLookup(),
			ORESServices::getORESService(),
			$services->getMainWANObjectCache(),
			ORESServices::getLogger(),
			$services->getStatsdDataFactory()
		);
	},

	'ORESScoreStorage' => static function ( MediaWikiServices $services ) {
		return new SqlScoreStorage(
			$services->getDBLoadBalancer(),
			ORESServices::getModelLookup(),
			ORESServices::getLogger()
		);
	},

	'ORESService' => static function ( MediaWikiServices $services ) {
		return new ORESService(
			ORESServices::getLogger(),
			$services->getHttpRequestFactory()
		);
	},

	'ORESScoreLookup' => static function ( MediaWikiServices $services ) {
		return new SqlScoreLookup(
			ORESServices::getModelLookup(),
			$services->getDBLoadBalancer()
		);
	},

	'ORESDatabaseQueryBuilder' => static function ( MediaWikiServices $services ) {
		return new DatabaseQueryBuilder(
			ORESServices::getThresholdLookup(),
			$services->getDBLoadBalancer()->getConnection( DB_REPLICA )
		);
	}

];
