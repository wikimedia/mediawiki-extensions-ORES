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

use User;

class PreferencesHookHandler {

	/**
	 * GetPreferences hook, adding ORES section, letting people choose a threshold
	 * Also let people make hidenondamaging default
	 *
	 * @param User $user
	 * @param array[] &$preferences
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		global $wgOresFiltersThresholds;

		if ( !Helpers::oresUiEnabled() || !Helpers::isModelEnabled( 'damaging' ) ) {
			return;
		}

		$options = [];
		foreach ( Helpers::$damagingPrefMap as $prefName => $level ) {
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

		$preferences['oresDamagingPref'] = [
			'type' => 'select',
			'label-message' => 'ores-pref-damaging',
			'section' => 'watchlist/ores-wl',
			'options' => $options,
			'help-message' => 'ores-help-damaging-pref',
		];

		$preferences['rcOresDamagingPref'] = [
			'type' => 'select',
			'label-message' => 'ores-pref-damaging',
			'section' => 'rc/ores-rc',
			'options' => $options,
			'help-message' => 'ores-help-damaging-pref',
		];

		// highlight damaging edits based on configured sensitivity
		$preferences['oresHighlight'] = [
			'type' => 'toggle',
			'section' => 'watchlist/ores-wl',
			'label-message' => 'ores-pref-highlight',
		];

		// Control whether the "r" appears on RC
		$preferences['ores-damaging-flag-rc'] = [
			'type' => 'toggle',
			'section' => 'rc/ores-rc',
			'label-message' => 'ores-pref-damaging-flag',
		];

		// Make hidenondamaging default
		$preferences['oresWatchlistHideNonDamaging'] = [
			'type' => 'toggle',
			'section' => 'watchlist/ores-wl',
			'label-message' => 'ores-pref-watchlist-hidenondamaging',
		];
		$preferences['oresRCHideNonDamaging'] = [
			'type' => 'toggle',
			'section' => 'rc/ores-rc',
			'label-message' => 'ores-pref-rc-hidenondamaging',
		];
	}

}
