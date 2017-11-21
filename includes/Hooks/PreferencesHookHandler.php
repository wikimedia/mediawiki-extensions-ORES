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

use ORES\Hooks;
use DerivativeContext;
use DerivativeRequest;
use RequestContext;
use User;

class PreferencesHookHandler {

	/**
	 * GetPreferences hook, adding ORES section, letting people choose a threshold
	 * Also let people make hidenondamaging default
	 *
	 * @param User $user
	 * @param string[] &$preferences
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		global $wgOresFiltersThresholds, $wgOresExtensionStatus, $wgHiddenPrefs;

		if ( !Hooks::oresUiEnabled( $user ) || !Hooks::isModelEnabled( 'damaging' ) ) {
			return;
		}

		$options = [];
		foreach ( Hooks::$damagingPrefMap as $prefName => $level ) {
			// In other places, we look at the keys of getDamagingThresholds() to determine which
			// damaging levels exist, but it can drop levels from its output if the ORES API
			// has issues. We don't want preference definitions to be potentially unstable.
			// So instead, we use $wgOresFiltersThresholds directly so the preference definition
			// only depends on the configuration.
			if (
				isset( $wgOresFiltersThresholds[ 'damaging' ][ $level ] ) &&
				$wgOresFiltersThresholds[ 'damaging' ][ $level ] !== false
			) {
				$text = \wfMessage( 'ores-damaging-' . $level )->text();
				$options[ $text ] = $prefName;
			}
		}
		$oresSection = $wgOresExtensionStatus === 'beta' ? 'rc/ores' : 'watchlist/ores';
		$preferences['oresDamagingPref'] = [
			'type' => 'select',
			'label-message' => 'ores-pref-damaging',
			'section' => $oresSection,
			'options' => $options,
			'help-message' => 'ores-help-damaging-pref',
		];

		if ( $wgOresExtensionStatus !== 'beta' ) {
			// highlight damaging edits based on configured sensitivity
			$preferences['oresHighlight'] = [
				'type' => 'toggle',
				'section' => $oresSection,
				'label-message' => 'ores-pref-highlight',
			];

			// Control whether the "r" appears on RC
			$preferences['ores-damaging-flag-rc'] = [
				'type' => 'toggle',
				'section' => 'rc/advancedrc',
				'label-message' => 'ores-pref-damaging-flag',
			];
		}

		// Make hidenondamaging default
		$preferences['oresWatchlistHideNonDamaging'] = [
			'type' => 'toggle',
			'section' => 'watchlist/ores',
			'label-message' => 'ores-pref-watchlist-hidenondamaging',
		];
		$preferences['oresRCHideNonDamaging'] = [
			'type' => 'toggle',
			'section' => 'rc/advancedrc',
			'label-message' => 'ores-pref-rc-hidenondamaging',
		];

		// Hide RC/wL prefs if enhanced filters are enabled
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $user );
		$context->setRequest( new DerivativeRequest( $context->getRequest(), [] ) );
		$rcFiltersEnabled = Hooks::isRCStructuredUiEnabled( $context );
		// HACK: Note that this only hides the preferences on the preferences page,
		// it does not cause them to behave as if they're set to their default value,
		// because this hook only runs on the preferences page.
		if ( $rcFiltersEnabled ) {
			$wgHiddenPrefs[] = 'ores-damaging-flag-rc';
		}
	}

}
