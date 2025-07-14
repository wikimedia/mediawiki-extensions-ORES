<?php

namespace ORES\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use ORES\Services\ORESServices;
use ORES\Services\ScoreFetcher;
use ORES\Storage\ScoreStorage;

// @codeCoverageIgnoreStart
require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * @ingroup Maintenance
 */
class PopulateDatabase extends Maintenance {

	/**
	 * @var int|null
	 */
	private $apiBatchSize;

	/**
	 * @var int|null
	 */
	private $revisionLimit;

	/**
	 * @var int|0
	 */
	private $successCount = 0;

	/**
	 * @var int|0
	 */
	private $totalCount = 0;

	/**
	 * @var int|0
	 */
	private $runtimeExceptionErrors = 0;

	/**
	 * @var int|0
	 */
	private $revisionNotFoundErrors = 0;

	/**
	 * @var int|0
	 */
	private $revisionNotScorableErrors = 0;

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ORES' );
		$this->addDescription( 'Populate ores_classification table by scoring ' .
			'the latest edits in recentchanges table that are not scored' );
		$this->addOption( 'number', 'Number of revisions to be scored', false, true, 'n' );
		$this->addOption( 'apibatch', 'Batch size for the API request', false, true );
		$this->setBatchSize( 5000 );
	}

	public function execute() {
		global $wgOresExcludeBots, $wgOresRevisionsPerBatch;

		$scoreFetcher = ScoreFetcher::instance();
		/** @var ScoreStorage $scoreStorage */
		$scoreStorage = ORESServices::getScoreStorage();
		$batchSize = $this->getBatchSize();
		$this->revisionLimit = $this->getOption( 'number', 1000 );
		$this->apiBatchSize = $this->getOption( 'apibatch', $wgOresRevisionsPerBatch ?: 30 );

		$latestRcId = 0;
		$dbr = $this->getReplicaDB();

		$count = 0;
		while ( $count < $this->revisionLimit ) {
			$conditions = [ 'oresc_id' => null, 'rc_type' => [ RC_EDIT, RC_NEW ] ];

			if ( $wgOresExcludeBots === true ) {
				$conditions['rc_bot'] = 0;
			}
			if ( $latestRcId ) {
				$conditions[] = $dbr->expr( 'rc_id', '<', $latestRcId );
			}

			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'rc_id', 'rc_this_oldid' ] )
				->from( 'recentchanges' )
				->leftJoin( 'ores_classification', null, 'oresc_rev = rc_this_oldid' )
				->where( $conditions )
				->orderBy( 'rc_id DESC' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();

			$pack = [];
			foreach ( $res as $row ) {
				$pack[] = $row->rc_this_oldid;
				if ( count( $pack ) % $this->apiBatchSize === 0 ) {
					$this->processScores( $pack, $scoreFetcher, $scoreStorage );
					$pack = [];
				}
				$latestRcId = $row->rc_id;
			}
			if ( $pack !== [] ) {
				$this->processScores( $pack, $scoreFetcher, $scoreStorage );
			}

			$count += $batchSize;
			$this->waitForReplication();

			if ( $res->numRows() < $batchSize ) {
				break;
			}
		}

		// Calculate the successCount
		$this->successCount = $this->totalCount - array_sum( [
			$this->runtimeExceptionErrors,
			$this->revisionNotFoundErrors,
			$this->revisionNotScorableErrors
		] );
		$this->output( "Finished processing {$this->totalCount} revisions\n" );
		$this->output( "Revisions successfully scored: {$this->successCount}\n" );
		$this->output( "RevisionNotFound errors: {$this->revisionNotFoundErrors}\n" );
		$this->output( "RevisionNotScorable errors: {$this->revisionNotScorableErrors}\n" );
		$this->output( "RuntimeException errors: {$this->runtimeExceptionErrors}\n" );
	}

	/**
	 * Process several edits and store the scores in the database
	 *
	 * @param int[] $revs Array of revision IDs
	 * @param ScoreFetcher $scoreFetcher
	 * @param ScoreStorage $scoreStorage service to store scores in persistence layer
	 */
	private function processScores(
		array $revs,
		ScoreFetcher $scoreFetcher,
		ScoreStorage $scoreStorage
	) {
		$size = count( $revs );
		$this->output( "Processing $size revisions\n" );
		$scores = [];
		foreach ( $revs as $revId ) {
			$this->totalCount++;
			try {
				$scores[ $revId ] = $scoreFetcher->getScores( $revId )[ $revId ];
			} catch ( \RuntimeException $e ) {
				$message = $e->getMessage();
				$this->output( "ScoreFetcher errored for $revId: $message\n" );
				$this->runtimeExceptionErrors++;
				continue;
			}
		}
		$scoreStorage->storeScores(
			$scores,
			function ( $mssg, $revision ) {
				$this->output( "ScoreFetcher errored for $revision: $mssg\n" );
				if ( strpos( $mssg, 'RevisionNotFound' ) !== false ) {
					$this->revisionNotFoundErrors++;
				} elseif ( strpos( $mssg, 'RevisionNotScorable' ) !== false ) {
					$this->revisionNotScorableErrors++;
				}
			}
		);
	}

}

// @codeCoverageIgnoreStart
$maintClass = PopulateDatabase::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
