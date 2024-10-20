<?php

namespace ORES\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use ORES\Services\ORESServices;
use Wikimedia\Rdbms\Database;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

/**
 * @ingroup Maintenance
 */
class PurgeScoreCache extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ORES' );
		$this->addDescription( 'Purge out of date (or all) ORES model results' );

		$this->addOption( 'model', 'Model name (optional)', false, true );
		$this->addOption( 'all', 'Flag to indicate that we want to clear all data, ' .
			'even those from the most recent model', false, false );
		$this->addOption( 'old', 'Flag to indicate that we only want to clear old data ' .
			'that is not in recent changes anymore.', false, false );
		$this->setBatchSize( 1000 );
	}

	public function execute() {
		if ( $this->hasOption( 'model' ) ) {
			$models = [ $this->getOption( 'model' ) ];
		} else {
			$models = array_keys( ORESServices::getModelLookup()->getModels() );
		}

		$batchSize = $this->getBatchSize();
		$this->output( "Purging ORES scores:\n" );
		foreach ( $models as $model ) {
			if ( $this->hasOption( 'old' ) ) {
				$deletedRows = $this->purgeOld( $model, $batchSize );
				$description = 'old rows';
			} elseif ( $this->hasOption( 'all' ) ) {
				$deletedRows = $this->purge( $model, true, $batchSize );
				$description = 'scores from all model versions';
			} else {
				$deletedRows = $this->purge( $model, false, $batchSize );
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
		$conditions = [
			'oresm_name' => [ $model, null ],
		];
		$dbr = $this->getReplicaDB();
		if ( !$isEverything ) {
			$conditions[] = $dbr->expr( 'oresm_is_current', '!=', 1 )->or( 'oresm_is_current', '=', null );
		}

		$modelIds = $dbr->newSelectQueryBuilder()
			->select( 'oresm_id' )
			->from( 'ores_model' )
			->where( $conditions )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$modelIds ) {
			return 0;
		}

		return $this->deleteRows( [ 'oresc_model' => $modelIds ], $batchSize );
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
		$dbr = $this->getReplicaDB();
		$modelIds = $dbr->newSelectQueryBuilder()
			->select( 'oresm_id' )
			->from( 'ores_model' )
			->where( [ 'oresm_name' => [ $model, null ] ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$lowestRCRev = $dbr->newSelectQueryBuilder()
			->select( 'rc_this_oldid' )
			->from( 'recentchanges' )
			->caller( __METHOD__ )
			->orderBy( 'rc_id' )
			->fetchField();

		if ( !$lowestRCRev || !$modelIds ) {
			return 0;
		}

		$conditions = [
			$dbr->expr( 'oresc_rev', '<', $lowestRCRev ),
			'oresc_model' => $modelIds
		];
		return $this->deleteRows( $conditions, $batchSize );
	}

	/**
	 * Delete cached scores. Which rows to delete is given by Database::select parameters.
	 * @param array $conditions
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 * @see Database::select
	 */
	private function deleteRows( array $conditions, $batchSize ) {
		$dbr = $this->getReplicaDB();
		$dbw = $this->getPrimaryDB();

		$deletedRows = 0;

		do {
			$ids = $dbr->newSelectQueryBuilder()
				->select( 'oresc_id' )
				->from( 'ores_classification' )
				->where( $conditions )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchFieldValues();
			if ( $ids ) {
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'ores_classification' )
					->where( [ 'oresc_id' => $ids ] )
					->caller( __METHOD__ )
					->execute();
				$deletedRows += $dbw->affectedRows();
				$this->waitForReplication();
			}
		} while ( $ids );

		return $deletedRows;
	}

}

$maintClass = PurgeScoreCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
