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

namespace ORES\Hooks;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$sqlPath = __DIR__ . '/../../sql/' . $type . '/';
		$updater->addExtensionTable( 'ores_classification', $sqlPath . 'tables-generated.sql' );

		if ( $type === 'mysql' ) {
			// 1.31
			$updater->addExtensionIndex( 'ores_classification', 'oresc_model_class_prob',
				$sqlPath . 'patch-ores-classification-model-class-prob-index.sql' );
			$updater->dropExtensionIndex( 'ores_classification', 'oresc_rev_predicted_model',
				$sqlPath . 'patch-ores-classification-indexes-part-ii.sql' );
		}
	}

}
