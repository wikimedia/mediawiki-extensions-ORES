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
use Html;
use ORES\Hooks;
use RequestContext;
use SpecialContributions;
use Xml;

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
		if ( !Hooks::oresUiEnabled( $pager->getUser() ) ) {
			return;
		}

		if ( Hooks::isModelEnabled( 'damaging' ) ) {
			$request = $pager->getContext()->getRequest();

			Hooks::joinWithOresTables(
				'damaging',
				'rev_id',
				$query['tables'],
				$query['fields'],
				$query['join_conds']
			);

			Hooks::hideNonDamagingFilter(
				$query['fields'],
				$query['conds'],
				$request->getVal( 'hidenondamaging' ),
				$pager->getUser()
			);
		}
	}

	public static function onSpecialContributionsFormatRowFlags(
		RequestContext $context,
		$row,
		array &$flags
	) {
		if ( !Hooks::oresUiEnabled( $context->getUser() ) ) {
			return;
		}

		// Doesn't have ores score, skipping.
		if ( !isset( $row->ores_damaging_score ) ) {
			return;
		}

		Hooks::addRowData( $context, $row->rev_id, (float)$row->ores_damaging_score, 'damaging' );

		if (
			Hooks::isDamagingFlagEnabled( $context ) &&
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
		if ( !Hooks::oresUiEnabled( $pager->getUser() ) ) {
			return;
		}

		// Doesn't have ores score or threshold is not set properly, skipping.
		if ( !isset( $row->ores_damaging_score ) || !isset( $row->ores_damaging_threshold ) ) {
			return;
		}

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
			if ( Hooks::isHighlightEnabled( $pager ) ) {
				$classes[] = 'ores-highlight';
			}
			if ( Hooks::isDamagingFlagEnabled( $pager ) ) {
				$classes[] = 'damaging';
			}
		}
	}

	/**
	 * Hook into Special:Contributions filters
	 *
	 * @param SpecialContributions $page
	 * @param string[] &$filters HTML
	 */
	public static function onSpecialContributionsGetFormFilters(
		SpecialContributions $page,
		array &$filters
	) {
		if ( !Hooks::oresUiEnabled( $page->getUser() ) || !Hooks::isModelEnabled( 'damaging' ) ) {
			return;
		}

		$filters[] = Html::rawElement(
			'span',
			[ 'class' => 'mw-input-with-label' ],
			Xml::checkLabel(
				$page->msg( 'ores-hide-nondamaging-filter' )->text(),
				'hidenondamaging',
				'ores-hide-nondamaging',
				$page->getRequest()->getVal( 'hidenondamaging' ),
				[ 'class' => 'mw-input' ]
			)
		);
	}

}
