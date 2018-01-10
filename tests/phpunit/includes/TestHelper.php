<?php

namespace ORES\Tests;

use ContentHandler;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use Revision;
use Title;
use User;
use WikiPage;

class TestHelper {

	public static function getTablesUsed() {
		$tablesUsed = [
			'recentchanges',
			'page',
			'ores_model',
			'ores_classification',
		];
		return $tablesUsed;
	}

	public static function clearOresTables() {
		wfGetDB( DB_MASTER )->delete( 'recentchanges', '*', __METHOD__ );
		wfGetDB( DB_MASTER )->delete( 'ores_model', '*', __METHOD__ );
		wfGetDB( DB_MASTER )->delete( 'ores_classification', '*', __METHOD__ );
	}

	public static function insertOresData( Revision $revision, $scores ) {
		/** @var ModelLookup $modelLookup */
		$modelLookup = MediaWikiServices::getInstance()->getService( 'ORESModelLookup' );
		// TODO: Use ScoreStorage
		$dbData = [];
		foreach ( $scores as $modelName => $score ) {
			$dbData[] = [
				'oresc_rev' => $revision->getId(),
				'oresc_model' => $modelLookup->getModelId( $modelName ),
				'oresc_class' => 1,
				'oresc_probability' => $score,
				'oresc_is_predicted' => 0
			];
		}
		wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData );
	}

	public static function doPageEdit( User $user, LinkTarget $target, $summary ) {
		static $i = 0;

		$title = Title::newFromLinkTarget( $target );
		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( __CLASS__ . $i++, $title ),
			$summary,
			0,
			false,
			$user
		);
		if ( !$status->isOK() ) {
			throw new RuntimeException( 'Test failed, couldn\'t perform page edit.' );
		}
		return $status;
	}

}
