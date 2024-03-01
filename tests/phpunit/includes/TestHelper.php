<?php

namespace ORES\Tests;

use ContentHandler;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use ORES\Services\ORESServices;
use RuntimeException;

class TestHelper {

	public const DAMAGING_OLD = 1;
	public const REVERTED = 2;
	public const DAMAGING = 3;

	public static function insertModelData() {
		$db = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$dump = [
			[
				'oresm_id' => self::DAMAGING,
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.2',
				'oresm_is_current' => true
			],
			[
				'oresm_id' => self::REVERTED,
				'oresm_name' => 'reverted',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => true
			],
			[
				'oresm_id' => self::DAMAGING_OLD,
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => false
			],
		];

		foreach ( $dump as $row ) {
			$db->newInsertQueryBuilder()
				->insertInto( 'ores_model' )
				->row( $row )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * @param RevisionRecord|int $revision
	 * @param float[] $scores
	 */
	public static function insertOresData( $revision, array $scores ) {
		if ( is_numeric( $revision ) ) {
			$revisionId = $revision;
		} else {
			$revisionId = $revision->getId();
		}
		// TODO: Use ScoreStorage
		$dbData = [];
		foreach ( $scores as $modelName => $score ) {
			// Dirty trick that lets tests insert data for old models by
			// specifying its ID.
			if ( is_numeric( $modelName ) ) {
				$modelId = $modelName;
			} else {
				$modelId = ORESServices::getModelLookup()->getModelId( $modelName );
			}

			$dbData[] = [
				'oresc_rev' => $revisionId,
				'oresc_model' => $modelId,
				'oresc_class' => 1,
				'oresc_probability' => $score,
				'oresc_is_predicted' => 0
			];
		}
		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ores_classification' )
			->rows( $dbData )
			->caller( __METHOD__ )
			->execute();
	}

	public static function doPageEdit( User $user, LinkTarget $target, $summary ) {
		static $i = 0;

		$title = Title::newFromLinkTarget( $target );
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$status = $page->doUserEditContent(
			ContentHandler::makeContent( __CLASS__ . $i++, $title ),
			$user,
			$summary
		);
		if ( !$status->isOK() ) {
			throw new RuntimeException( 'Test failed, couldn\'t perform page edit.' );
		}
		return $status;
	}

}
