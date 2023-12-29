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

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Hook\RecentChangesPurgeRowsHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\OutputPage;
use ORES\ORESService;
use ORES\Services\ORESServices;
use RecentChange;
use RequestContext;
use Skin;

class Hooks implements
	BeforePageDisplayHook,
	RecentChangesPurgeRowsHook,
	RecentChange_saveHook
{

	/**
	 * Remove cached scores for revisions which were purged from recentchanges
	 *
	 * @param \stdClass[] $rows
	 */
	public function onRecentChangesPurgeRows( $rows ): void {
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
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !Helpers::oresUiEnabled() ) {
			return;
		}

		$oresData = $out->getProperty( 'oresData' );

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

	/**
	 * Returns an array of configuration for ORES API modules
	 *
	 * @return string[]
	 */
	public static function getFrontendConfiguration() {
		return [
			'wikiId' => ORESService::getWikiID(),
			'baseUrl' => ORESService::getFrontendBaseUrl(),
			'apiVersion' => (string)ORESService::API_VERSION,
		];
	}

	/**
	 * @param RecentChange $rc
	 */
	public function onRecentChange_save( $rc ) {
		global $wgOresExcludeBots, $wgOresEnabledNamespaces, $wgOresModels;

		$handler = new RecentChangeSaveHookHandler(
			LoggerFactory::getInstance( 'ORES' ),
			RequestContext::getMain()->getRequest()
		);

		$handler->handle(
			$rc,
			$wgOresModels,
			$wgOresExcludeBots,
			$wgOresEnabledNamespaces
		);
	}

}
