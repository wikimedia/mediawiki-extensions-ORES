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

	/** @return LoggerInterface */
	public static function getLogger() {
		return MediaWikiServices::getInstance()->getService( 'ORESLogger' );
	}

	/** @return ModelLookup */
	public static function getModelLookup() {
		return MediaWikiServices::getInstance()->getService( 'ORESModelLookup' );
	}

	/** @return ThresholdLookup */
	public static function getThresholdLookup() {
		return MediaWikiServices::getInstance()->getService( 'ORESThresholdLookup' );
	}

	/** @return ScoreStorage */
	public static function getScoreStorage() {
		return MediaWikiServices::getInstance()->getService( 'ORESScoreStorage' );
	}

	/** @return ORESService */
	public static function getORESService() {
		return MediaWikiServices::getInstance()->getService( 'ORESService' );
	}

	/** @return StorageScoreLookup */
	public static function getScoreLookup() {
		return MediaWikiServices::getInstance()->getService( 'ORESScoreLookup' );
	}

	/** @return DatabaseQueryBuilder */
	public static function getDatabaseQueryBuilder() {
		return MediaWikiServices::getInstance()->getService( 'ORESDatabaseQueryBuilder' );
	}

}
