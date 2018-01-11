<?php

namespace ORES\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * @ingroup Maintenance
 */
class PurgeScoreCache extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Purge out of date (or all) ORES model results' );

		$this->addOption( 'model', 'Model name (optional)', false, true );
		$this->addOption( 'all', 'Flag to indicate that we want to clear all data, ' .
			'even those from the most recent model', false, false );
		$this->addOption( 'old', 'Flag to indicate that we only want to clear old data ' .
			'that is not in recent changes anymore. Implicitly assumes --all.', false, false );
		$this->setBatchSize( 1000 );
	}

	public function execute() {
		if ( $this->hasOption( 'model' ) ) {
			$models = [ $this->getOption( 'model' ) ];
		} else {
			$models = array_keys(
				MediaWikiServices::getInstance()->getService( 'ORESModelLookup' )->getModels()
			);
		}

		$this->output( "Purging ORES scores:\n" );
		foreach ( $models as $model ) {
			if ( $this->hasOption( 'old' ) ) {
				$deletedRows = $this->purgeOld( $model, $this->mBatchSize );
				$description = 'old rows';
			} elseif ( $this->hasOption( 'all' ) ) {
				$deletedRows = $this->purge( $model, true, $this->mBatchSize );
				$description = 'scores from all model versions';
			} else {
				$deletedRows = $this->purge( $model, false, $this->mBatchSize );
				$description = 'scores from old model versions';
			}
			if ( $deletedRows ) {
				$this->output( "   ...purging $description from '$model' model': deleted $deletedRows rows\n" );
			} else {
				$this->output( "   ...skipping '$model' model, no action needed\n" );
			}
		}
		$this->output( "   done.\n" );
	}

	/**
	 * Delete cached scores
	 *
	 * Normally, we'll only delete scores from out-of-date model versions.
	 *
	 * @param string $model Model name.
	 * @param bool $isEverything When true, delete scores with the up-to-date
	 *   model version as well.  This can be used in cases where the old data is
	 *   bad, but no new model has been released yet.
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 */
	private function purge( $model, $isEverything, $batchSize = 1000 ) {
		$tables = [ 'ores_classification', 'ores_model' ];
		$join_conds = [
			'ores_model' => [ 'LEFT JOIN', 'oresm_id = oresc_model' ],
		];
		$conditions = [
			'oresm_name' => [ $model, null ],
		];
		if ( !$isEverything ) {
			$conditions[] = '(oresm_is_current != 1 OR oresm_is_current IS NULL)';
		}
		return $this->deleteRows( $tables, $conditions, $join_conds, $batchSize );
	}

	/**
	 * Delete old cached scores.
	 * A score is old of the corresponding revision is not in the recentchanges table.
	 * @param string $model Model name.
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 */
	public function purgeOld( $model, $batchSize = 1000 ) {
		$tables = [ 'ores_classification', 'ores_model', 'recentchanges' ];
		$join_conds = [
			'ores_model' => [ 'LEFT JOIN', 'oresm_id = oresc_model' ],
			'recentchanges' => [ 'LEFT JOIN', 'oresc_rev = rc_this_oldid' ],
		];
		$conditions = [
			'oresm_name' => [ $model, null ],
			'rc_this_oldid' => null,
		];
		return $this->deleteRows( $tables, $conditions, $join_conds, $batchSize );
	}

	/**
	 * Delete cached scores. Which rows to delete is given by Database::select parameters.
	 *
	 * @param array $tables
	 * @param array $conditions
	 * @param array $join_conds
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 * @see Database::select
	 */
	private function deleteRows(
		array $tables,
		array $conditions,
		array $join_conds,
		$batchSize
	) {
		$dbr = \wfGetDB( DB_REPLICA );
		$dbw = \wfGetDB( DB_MASTER );

		$deletedRows = 0;

		do {
			$ids = $dbr->selectFieldValues( $tables,
				'oresc_id',
				$conditions,
				__METHOD__,
				[ 'LIMIT' => $batchSize ],
				$join_conds
			);
			if ( $ids ) {
				$dbw->delete( 'ores_classification',
					[ 'oresc_id' => $ids ],
					__METHOD__
				);
				$deletedRows += $dbw->affectedRows();
				MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
			}
		} while ( $ids );

		return $deletedRows;
	}

}

$maintClass = PurgeScoreCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
