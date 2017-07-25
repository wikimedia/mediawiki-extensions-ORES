<?php

namespace ORES\Hooks;

use ORES\Hooks;
use User;

class PreferencesHookHandler {

	/**
	 * GetPreferences hook, adding ORES section, letting people choose a threshold
	 * Also let people make hidenondamaging default
	 *
	 * @param User $user
	 * @param string[] $preferences
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
		// Hide RC prefs if enhanced filters are enabled
		if ( $user->getBoolOption( 'rcenhancedfilters' ) ) {
			// HACK: Note that this only hides the preferences on the preferences page,
			// it does not cause them to behave as if they're set to their default value,
			// because this hook only runs on the preferences page.
			$wgHiddenPrefs[] = 'oresRCHideNonDamaging';
			$wgHiddenPrefs[] = 'ores-damaging-flag-rc';
		}
	}

}
