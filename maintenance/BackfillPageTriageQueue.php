<?php

namespace ORES\Maintenance;

use BatchRowIterator;
use ExtensionRegistry;
use Maintenance;
use ORES\ORESServices;
use ORES\ScoreFetcher;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class BackfillPageTriageQueue extends Maintenance {

	const ORES_RECOMMENDED_BATCH_SIZE = 50;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Backfills the missing scores for the articles in the PageTriage queue';
		$this->addOption( 'dry-run', 'Do not fetch scores, only print revisions.' );
		$this->setBatchSize( self::ORES_RECOMMENDED_BATCH_SIZE );
	}

	public function execute() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'PageTriage' ) ) {
			$this->fatalError( 'This wiki does not appear to have PageTriage' );
		}

		$this->backfillScores( 'draftquality' );
		$this->backfillScores( 'articlequality' );

		$this->output( "\nAll done\n" );
	}

	private function backfillScores( $modelName ) {
		$dbr = $this->getDB( DB_REPLICA );
		$modelId = ORESServices::getModelLookup()->getModelId( $modelName );
		$this->output( "\nStarting model $modelName (id: $modelId)\n" );

		$iterator = new BatchRowIterator(
			$dbr,
			[ 'revision', 'page', 'pagetriage_page', 'ores_classification' ],
			'rev_id',
			$this->mBatchSize
		);
		$iterator->setFetchColumns( [ 'rev_id', 'oresc_probability' ] );
		$iterator->addJoinConditions( [
			'page' => [ 'INNER JOIN', 'page_latest = rev_id' ],
			'pagetriage_page' => [ 'INNER JOIN', 'page_id = ptrp_page_id' ],
			'ores_classification' => [ 'LEFT JOIN', [
				'rev_id = oresc_rev',
				'oresc_model' => $modelId,
			] ],
		] );
		$iterator->addConditions( [
			'page_is_redirect' => 0,
			'oresc_probability' => null,
		] );

		foreach ( $iterator as $rows ) {
			$revIds = array_map( function ( $row ) {
				return $row->rev_id;
			}, $rows );

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Revs: " . implode( ', ', $revIds ) . "\n" );
				continue;
			}

			$scores = ScoreFetcher::instance()->getScores(
				$revIds,
				$modelName,
				true
			);

			$errors = 0;
			ORESServices::getScoreStorage()->storeScores(
				$scores,
				function ( $msg, $revision ) use ( &$errors ) {
					$this->output( "WARNING: ScoreFetcher errored for $revision: $msg\n" );
					$errors++;
				}
			);

			$count = count( $revIds );
			$first = reset( $revIds );
			$last = end( $revIds );
			$this->output( "Processed $count revisions with $errors errors. From $first to $last.\n" );
		}

		$this->output( "Finished model $modelName\n" );
	}

}

$maintClass = BackfillPageTriageQueue::class;

require_once RUN_MAINTENANCE_IF_MAIN;
