<?php

namespace ORES;

use Maintenance;
use MediaWiki\MediaWikiServices;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * @ingroup Maintenance
 */
class PopulateDatabase extends Maintenance {

	/**
	 * @var int|null
	 */
	private $batchSize;

	/**
	 * @var int|null
	 */
	private $apiBatchSize;

	/**
	 * @var int|null
	 */
	private $revisionLimit;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populate ores_classification table by scoring ' .
			'the latest edits in recentchanges table that are not scored' );
		$this->addOption( 'number', 'Number of revisions to be scored', false, true, 'n' );
		$this->addOption( 'batch', 'Batch size for the SELECT SQL query', false, true, 'b' );
		$this->addOption( 'apibatch', 'Batch size for the API request', false, true );
	}

	public function execute() {
		global $wgOresExcludeBots, $wgOresRevisionsPerBatch;

		$scoring = Scoring::instance();
		$cache = Cache::instance();
		$cache->setErrorCallback( function ( $mssg, $revision ) {
			$this->output( "Scoring errored for $revision: $mssg\n" );
		} );
		$this->batchSize = $this->getOption( 'batch', 5000 );
		$this->revisionLimit = $this->getOption( 'number', 1000 );
		$this->apiBatchSize = $this->getOption( 'apibatch', $wgOresRevisionsPerBatch ?: 30 );

		$latestRcId = 0;
		$dbr = wfGetDB( DB_REPLICA );
		$join_conds = [ 'ores_classification' =>
			[ 'LEFT JOIN', [ 'oresc_rev = rc_this_oldid' ] ]
		];

		$count = 0;
		while ( $count < $this->revisionLimit ) {
			$conditions = [ 'oresc_id IS NULL', 'rc_type' => [ RC_EDIT, RC_NEW ] ];

			if ( $wgOresExcludeBots === true ) {
				$conditions['rc_bot'] = 0;
			}
			if ( $latestRcId ) {
				$conditions[] = 'rc_id < ' . $dbr->addQuotes( $latestRcId );
			}

			$res = $dbr->select( [ 'recentchanges', 'ores_classification' ],
				[ 'rc_id', 'rc_this_oldid' ],
				$conditions,
				__METHOD__,
				[ 'ORDER BY' => 'rc_id DESC',
					'LIMIT' => $this->batchSize ],
				$join_conds
			);

			$pack = [];
			foreach ( $res as $row ) {
				$pack[] = $row->rc_this_oldid;
				if ( count( $pack ) % $this->apiBatchSize === 0 ) {
					$this->processScores( $pack, $scoring, $cache );
					$pack = [];
				}
				$latestRcId = $row->rc_id;
			}
			if ( $pack !== [] ) {
				$this->processScores( $pack, $scoring, $cache );
			}

			$count += $this->batchSize;
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();

			if ( $res->numRows() < $this->batchSize ) {
				break;
			}
		}

		$this->output( "Finished processing the revisions\n" );
	}

	/**
	 * Process several edits and store the scores in the database
	 *
	 * @param array $revs array of revision ids
	 * @param Scoring $scoring
	 * @param Cache $cache
	 */
	private function processScores( array $revs, Scoring $scoring, Cache $cache ) {
		$size = count( $revs );
		$this->output( "Processing $size revisions\n" );

		$scores = $scoring->getScores( $revs );
		$cache->storeScores( $scores );
	}

}

$maintClass = PopulateDatabase::class;
require_once RUN_MAINTENANCE_IF_MAIN;
