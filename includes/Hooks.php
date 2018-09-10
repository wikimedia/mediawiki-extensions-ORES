<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace ORES;

use DatabaseUpdater;
use ORES\Hooks\Helpers;
use OutputPage;
use Skin;

class Hooks {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'ores_classification', __DIR__ . '/../sql/ores_classification.sql' );
		$updater->addExtensionTable( 'ores_model', __DIR__ . '/../sql/ores_model.sql' );
		$updater->dropExtensionIndex( 'ores_classification', 'oresc_rev',
			__DIR__ . '/../sql/patch-ores-classification-indexes.sql' );
		$updater->addExtensionIndex( 'ores_classification', 'oresc_rev_model_class',
			__DIR__ . '/../sql/patch-ores-classification-unique-indexes.sql' );
		$updater->addExtensionIndex( 'ores_model', 'oresm_model_status',
			__DIR__ . '/../sql/patch-ores-model-indexes.sql' );
		$updater->addExtensionIndex( 'ores_classification', 'oresc_model_class_prob',
			__DIR__ . '/../sql/patch-ores-classification-model-class-prob-index.sql' );
		$updater->dropExtensionIndex( 'ores_classification', 'oresc_rev_predicted_model',
			__DIR__ . '/../sql/patch-ores-classification-indexes-part-ii.sql' );
	}

	/**
	 * Remove cached scores for revisions which were purged from recentchanges
	 *
	 * @param \stdClass[] $rows
	 */
	public static function onRecentChangesPurgeRows( array $rows ) {
		$revIds = [];
		foreach ( $rows as $row ) {
			$revIds[] = $row->rc_this_oldid;
		}
		ORESServices::getScoreStorage()->purgeRows( $revIds );
	}

	/**
	 * Add CSS styles to output page
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ) {
		if ( !Helpers::oresUiEnabled() ) {
			return;
		}

		$oresData = $out->getProperty( 'oresData' );

		$out->addJsConfigVars(
			[
				'oresWikiId' => ORESService::getWikiID(),
				'oresBaseUrl' => ORESService::getBaseUrl(),
				'oresApiVersion' => ORESService::API_VERSION,
			]
		);

		if ( $oresData !== null ) {
			$out->addJsConfigVars( 'oresData', $oresData );
			$out->addJsConfigVars(
				'oresThresholds',
				[ 'damaging' => Helpers::getDamagingThresholds() ]
			);
			$out->addModuleStyles( 'ext.ores.styles' );
			if ( Helpers::isHighlightEnabled( $out ) ) {
				$out->addModules( 'ext.ores.highlighter' );
			}
		}
	}

}
