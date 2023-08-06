<?php

namespace ORES\Hooks;

use MediaWiki\Revision\RevisionRecord;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "ORESRecentChangeScoreSavedHook" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface ORESRecentChangeScoreSavedHook {
	/**
	 * @param RevisionRecord $revision
	 * @param array $scores
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onORESRecentChangeScoreSavedHook( RevisionRecord $revision, array $scores );
}
