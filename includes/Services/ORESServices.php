<?php

namespace ORES\Services;

use MediaWiki\MediaWikiServices;
use ORES\ORESService;
use ORES\Storage\DatabaseQueryBuilder;
use ORES\Storage\ModelLookup;
use ORES\Storage\ScoreStorage;
use ORES\Storage\StorageScoreLookup;
use ORES\Storage\ThresholdLookup;
use Psr\Log\LoggerInterface;

/**
 * @license GPL-2.0-or-later
 * @author Amir Sarabadani
 */
class ORESServices {

	/**
	 * @param MediaWikiServices|null $services
	 * @return LoggerInterface
	 */
	public static function getLogger( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESLogger' );
	}

	/**
	 * @param MediaWikiServices|null $services
	 * @return ModelLookup
	 */
	public static function getModelLookup( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESModelLookup' );
	}

	/**
	 * @param MediaWikiServices|null $services
	 * @return ThresholdLookup
	 */
	public static function getThresholdLookup( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESThresholdLookup' );
	}

	/**
	 * @param MediaWikiServices|null $services
	 * @return ScoreStorage
	 */
	public static function getScoreStorage( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESScoreStorage' );
	}

	/**
	 * @param MediaWikiServices|null $services
	 * @return ORESService
	 */
	public static function getORESService( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESService' );
	}

	/**
	 * @param MediaWikiServices|null $services
	 * @return StorageScoreLookup
	 */
	public static function getScoreLookup( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESScoreLookup' );
	}

	/**
	 * @param MediaWikiServices|null $services
	 * @return DatabaseQueryBuilder
	 */
	public static function getDatabaseQueryBuilder( MediaWikiServices $services = null ) {
		return ( $services ?? MediaWikiServices::getInstance() )->getService( 'ORESDatabaseQueryBuilder' );
	}

}
