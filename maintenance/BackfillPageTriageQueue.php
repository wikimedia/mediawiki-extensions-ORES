<?php

namespace ORES\Maintenance;

use BatchRowIterator;
use MediaWiki\Maintenance\Maintenance;
use ORES\ServiceError;
use ORES\Services\ORESServices;
use ORES\Services\ScoreFetcher;
use ORES\Storage\ModelNotFoundError;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class BackfillPageTriageQueue extends Maintenance {

	private const ORES_RECOMMENDED_BATCH_SIZE = 50;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Backfills the missing scores for the articles in the PageTriage queue' );
		$this->addOption( 'dry-run', 'Do not fetch scores, only print revisions.' );
		$this->setBatchSize( self::ORES_RECOMMENDED_BATCH_SIZE );
		$this->requireExtension( 'ORES' );
		$this->requireExtension( 'PageTriage' );
	}

	public function execute() {
		$this->backfillScores( 'draftquality' );
		$this->backfillScores( 'articlequality' );

		$this->output( "\nAll done\n" );
	}

	/**
	 * @param string $modelName
	 */
	private function backfillScores( $modelName ) {
		try {
			$modelId = ORESServices::getModelLookup()->getModelId( $modelName );
		} catch ( ModelNotFoundError ) {
			$this->output( "Skipping missing model \"$modelName\"\n" );
			return;
		}

		$this->output( "\nStarting model $modelName (id: $modelId)\n" );

		$dbr = $this->getDB( DB_REPLICA );
		$iterator = new BatchRowIterator(
			$dbr,
			[ 'revision', 'page', 'pagetriage_page', 'ores_classification' ],
			'rev_id',
			$this->getBatchSize()
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
		$iterator->setCaller( __METHOD__ );

		foreach ( $iterator as $rows ) {
			$revIds = array_map( static function ( $row ) {
				return $row->rev_id;
			}, $rows );

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Revs: " . implode( ', ', $revIds ) . "\n" );
				continue;
			}

			try {
				$scores = $this->retry( static function () use ( $revIds, $modelName ) {
					return ScoreFetcher::instance()->getScores(
						$revIds,
						$modelName,
						true
					);
				}, 5, 3 );
			} catch ( ServiceError $e ) {
				$this->fatalError( "ERROR: ScoreFetcher error when fetching revisions, " .
					"retried 5 times: {$e->getMessage()}\n" );
			}

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

	/**
	 * @param callable $fn
	 * @param int $tries
	 * @param int $wait
	 * @return mixed
	 */
	private function retry( $fn, $tries, $wait ) {
		$tried = 0;
		while ( true ) {
			try {
				return $fn();
			} catch ( ServiceError $ex ) {
				$tried++;
				if ( $tried > $tries ) {
					throw $ex;
				} else {
					$this->error( $ex->getMessage() );
					sleep( $wait );
				}
			}
		}
	}

}

// @codeCoverageIgnoreStart
$maintClass = BackfillPageTriageQueue::class;

require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
