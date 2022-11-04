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

use Exception;
use IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use ORES\Services\ORESServices;
use SpecialRecentChanges;
use SpecialWatchlist;
use Title;

class Helpers {

	/**
	 * @var string[] The oresDamagingPref preference uses these names for historical reasons
	 */
	public static $damagingPrefMap = [
		'hard' => 'maybebad',
		'soft' => 'likelybad',
		'softest' => 'verylikelybad',
	];

	public static function hideNonDamagingFilter(
		array &$fields, array &$conds, $hidenondamaging, UserIdentity $user, Title $title = null
	) {
		$dbr = \wfGetDB( DB_REPLICA );
		// Add user-based threshold
		$threshold = self::getThreshold( 'damaging', $user, $title );
		if ( $threshold === null ) {
			return;
		}
		// FIXME: This is not a "filter" but an undocumented side effect of this function.
		$fields['ores_damaging_threshold'] = $threshold;

		if ( $hidenondamaging ) {
			// Filter out non-damaging edits.
			$conds[] = 'ores_damaging_cls.oresc_probability > ' . $dbr->addQuotes( $threshold );
		}
	}

	public static function joinWithOresTables(
		$type, $revIdField, array &$tables, array &$fields, array &$join_conds
	) {
		if ( !ctype_lower( $type ) ) {
			throw new Exception( "Invalid value for parameter 'type': '$type'. " .
				'Restricted to one lower case word to prevent accidental injection.' );
		}

		$modelId = ORESServices::getModelLookup()->getModelId( $type );
		$tables["ores_{$type}_cls"] = 'ores_classification';

		$fields["ores_{$type}_score"] = "ores_{$type}_cls.oresc_probability";

		$join_conds["ores_{$type}_cls"] = [
			'LEFT JOIN',
			[
				"ores_{$type}_cls.oresc_model" => $modelId,
				"ores_{$type}_cls.oresc_rev=$revIdField",
				"ores_{$type}_cls.oresc_class" => 1
			]
		];
	}

	/**
	 * @param IContextSource $context
	 * @param int $revisionId
	 * @param float $score
	 * @param string $model
	 */
	public static function addRowData( IContextSource $context, $revisionId, $score, $model ) {
		$out = $context->getOutput();
		$data = $out->getProperty( 'oresData' );
		if ( !isset( $data[$revisionId] ) ) {
			$data[$revisionId] = [];
		}
		$data[$revisionId][$model] = $score;
		$out->setProperty( 'oresData', $data );
	}

	/**
	 * Check whether a given model is enabled in the config
	 * @param string $model
	 * @return bool
	 */
	public static function isModelEnabled( $model ) {
		global $wgOresModels;

		return isset( $wgOresModels[$model]['enabled'] ) && $wgOresModels[$model]['enabled'];
	}

	/**
	 * @param IContextSource $context
	 * @return bool Whether the damaging flag ("r") should be shown
	 */
	public static function isDamagingFlagEnabled( IContextSource $context ) {
		$user = $context->getUser();

		if ( !self::oresUiEnabled() ) {
			return false;
		}

		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		if ( self::isRCPage( $context->getTitle() ) ) {
			return !self::isRCStructuredUiEnabled( $context ) &&
				$userOptionsLookup->getBoolOption( $user, 'ores-damaging-flag-rc' );
		}

		if ( self::isWLPage( $context->getTitle() ) ) {
			return !self::isWLStructuredUiEnabled( $context ) &&
				$userOptionsLookup->getBoolOption( $user, 'oresHighlight' );
		}

		return $userOptionsLookup->getBoolOption( $user, 'oresHighlight' );
	}

	/**
	 * @param IContextSource $context
	 * @return bool Whether highlights should be shown
	 */
	public static function isHighlightEnabled( IContextSource $context ) {
		// Was previously controlled by different preferences than the "r", but they're currently
		// the same.
		return self::isDamagingFlagEnabled( $context );
	}

	/**
	 * @param Title $title
	 * @return bool Whether $title is a RecentChanges page
	 */
	private static function isRCPage( Title $title ) {
		return $title->isSpecial( 'Recentchanges' ) ||
			$title->isSpecial( 'Recentchangeslinked' );
	}

	/**
	 * Check whether ores is enabled
	 *
	 * @return bool
	 */
	public static function oresUiEnabled() {
		global $wgOresUiEnabled;

		return (bool)$wgOresUiEnabled;
	}

	/**
	 * Internal helper to get threshold
	 * @param string $type
	 * @param UserIdentity $user
	 * @param Title|null $title
	 * @return float|null Threshold, or null if not set
	 * @throws Exception When $type is not recognized
	 */
	public static function getThreshold( $type, UserIdentity $user, Title $title = null ) {
		if ( $type === 'damaging' ) {
			$pref = self::getDamagingLevelPreference( $user, $title );
			$thresholds = self::getDamagingThresholds();
			if ( isset( $thresholds[$pref] ) ) {
				return $thresholds[$pref];
			}

			return null;
		}
		throw new Exception( "Unknown ORES test: '$type'" );
	}

	/**
	 * Internal helper to get damaging level preference
	 * with backward compatibility for old level names
	 * @param UserIdentity $user
	 * @param Title|null $title
	 * @return string 'maybebad', 'likelybad', or 'verylikelybad'
	 */
	public static function getDamagingLevelPreference( UserIdentity $user, Title $title = null ) {
		$option = !$title || self::isWLPage( $title ) ? 'oresDamagingPref' : 'rcOresDamagingPref';
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$pref = $userOptionsLookup->getOption( $user, $option );
		if ( isset( self::$damagingPrefMap[$pref] ) ) {
			$pref = self::$damagingPrefMap[$pref];
		}

		return $pref;
	}

	/**
	 * @param Title $title
	 * @return bool Whether $title is the Watchlist page
	 */
	private static function isWLPage( Title $title ) {
		return $title->isSpecial( 'Watchlist' );
	}

	public static function getDamagingThresholds() {
		$thresholds = [];
		foreach ( ORESServices::getThresholdLookup()->getThresholds( 'damaging' ) as $name => $bounds ) {
			$thresholds[$name] = $bounds['min'];
		}
		unset( $thresholds['likelygood'] );

		return $thresholds;
	}

	public static function isRCStructuredUiEnabled( IContextSource $context ) {
		/** @var SpecialRecentChanges $page */
		$page = MediaWikiServices::getInstance()->getSpecialPageFactory()
			->getPage( 'Recentchanges' );
		'@phan-var SpecialRecentChanges $page';
		$page->setContext( $context );

		return $page->isStructuredFilterUiEnabled();
	}

	public static function isWLStructuredUiEnabled( IContextSource $context ) {
		/** @var SpecialWatchlist $page */
		$page = MediaWikiServices::getInstance()->getSpecialPageFactory()
			->getPage( 'Watchlist' );
		'@phan-var SpecialWatchlist $page';
		$page->setContext( $context );

		return $page->isStructuredFilterUiEnabled();
	}

}
