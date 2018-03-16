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
use ORES\Storage\PopulatedSqlModelLookup;
use ORES\Storage\SqlModelLookup;
use ORES\Storage\SqlScoreLookup;
use ORES\Storage\SqlScoreStorage;

return [
	'ORESModelLookup' => function ( MediaWikiServices $services ) {
		return new PopulatedSqlModelLookup(
			new SqlModelLookup( $services->getDBLoadBalancer() ),
			$services->getService( 'ORESService' ),
			LoggerFactory::getInstance( 'ORES' )
		);
	},

	'ORESThresholdLookup' => function ( MediaWikiServices $services ) {
		return new ThresholdLookup(
			new ThresholdParser( LoggerFactory::getInstance( 'ORES' ) ),
			$services->getService( 'ORESModelLookup' ),
			$services->getService( 'ORESService' ),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'ORES' ),
			$services->getStatsdDataFactory()
		);
	},

	'ORESScoreStorage' => function ( MediaWikiServices $services ) {
		return new SqlScoreStorage(
			$services->getDBLoadBalancer(),
			$services->getService( 'ORESModelLookup' ),
			LoggerFactory::getInstance( 'ORES' )
		);
	},

	'ORESService' => function ( MediaWikiServices $services ) {
		return new ORESService(
			LoggerFactory::getInstance( 'ORES' )
		);
	},

	'OREScoreLookup' => function ( MediaWikiServices $services ) {
		return new SqlScoreLookup(
			$services->getService( 'ORESModelLookup' ),
			$services->getDBLoadBalancer()
		);
	},

];
