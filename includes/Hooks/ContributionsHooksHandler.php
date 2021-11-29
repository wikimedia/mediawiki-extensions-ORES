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

use ChangesList;
use ContribsPager;
use IContextSource;
use MediaWiki\MediaWikiServices;
use RequestContext;
use SpecialContributions;

class ContributionsHooksHandler {

	/**
	 * Filter out non-damaging changes from Special:Contributions
	 *
	 * @param ContribsPager $pager
	 * @param array &$query
	 */
	public static function onContribsGetQueryInfo(
		ContribsPager $pager,
		&$query
	) {
		if ( !Helpers::oresUiEnabled() ) {
			return;
		}

		if ( Helpers::isModelEnabled( 'damaging' ) ) {
			Helpers::joinWithOresTables( 'damaging', 'rev_id', $query['tables'], $query['fields'],
				$query['join_conds'] );

			Helpers::hideNonDamagingFilter( $query['fields'], $query['conds'],
				self::hideNonDamagingPreference( $pager->getContext() ), $pager->getUser(),
				$pager->getTitle() );
		}
	}

	public static function onSpecialContributionsFormatRowFlags(
		RequestContext $context,
		$row,
		array &$flags
	) {
		if ( !Helpers::oresUiEnabled() ) {
			return;
		}

		// Doesn't have ores score, skipping.
		if (
			!isset( $row->ores_damaging_score ) ||
			!isset( $row->ores_damaging_threshold )
		) {
			return;
		}

		Helpers::addRowData( $context, $row->rev_id, (float)$row->ores_damaging_score, 'damaging' );

		if (
			Helpers::isDamagingFlagEnabled( $context ) &&
			$row->ores_damaging_score > $row->ores_damaging_threshold
		) {
			// Prepend the "r" flag
			array_unshift( $flags, ChangesList::flag( 'damaging' ) );
		}
	}

	public static function onContributionsLineEnding(
		ContribsPager $pager,
		&$ret,
		$row,
		array &$classes
	) {
		if ( !Helpers::oresUiEnabled() ) {
			return;
		}

		// Doesn't have ores score or threshold is not set properly, skipping.
		if ( !isset( $row->ores_damaging_score ) || !isset( $row->ores_damaging_threshold ) ) {
			return;
		}

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
			if ( Helpers::isHighlightEnabled( $pager ) ) {
				$classes[] = 'ores-highlight';
			}
			if ( Helpers::isDamagingFlagEnabled( $pager ) ) {
				$classes[] = 'damaging';
			}
		}
	}

	/**
	 * Hook into Special:Contributions filters
	 *
	 * @param SpecialContributions $page
	 * @param array[] &$filters HTML
	 */
	public static function onSpecialContributionsGetFormFilters(
		SpecialContributions $page,
		array &$filters
	) {
		if ( !Helpers::oresUiEnabled() || !Helpers::isModelEnabled( 'damaging' ) ) {
			return;
		}

		$filters[] = [
			'type' => 'check',
			'id' => 'ores-hide-nondamaging',
			'label' => $page->msg( 'ores-hide-nondamaging-filter' )->text(),
			'name' => 'hidenondamaging',
			'default' => self::hideNonDamagingPreference( $page->getContext() ),
		];
	}

	/**
	 * Get user preference for hiding non-damaging edits.
	 * - If form is submitted: filter is enabled if the hidenondamaging is set, disabled otherwise.
	 * - If Contributions page is opened regularly: filter is enabled if the parameter is set or
	 * the preference is enabled, disabled otherwise.
	 *
	 * @param IContextSource $context
	 * @return bool True if non damaging preference should be enabled
	 */
	private static function hideNonDamagingPreference( IContextSource $context ) {
		$checkbox = $context->getRequest()->getBool( 'hidenondamaging' );
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$preference = $userOptionsLookup->getOption( $context->getUser(), 'oresRCHideNonDamaging' );

		// Unchecked options aren't submitted with HTML form, so we have hidenondamaging=1 or null.
		// To distinguish when form on Special:Contributions is submitted, we check for
		// hidden parameter on the Special:Contributions form, with name 'limit'.
		// Watchlist special page defines similar hidden input field, called 'action'
		// which is used in the same fashion as we are using 'limit' here.
		if ( $context->getRequest()->getBool( 'limit' ) ) {
			return $checkbox;
		}

		return $preference || $checkbox;
	}

}
