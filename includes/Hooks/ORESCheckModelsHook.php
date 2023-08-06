<?php

namespace ORES\Hooks;

use RecentChange;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "ORESCheckModels" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface ORESCheckModelsHook {
	/**
	 * Allows modifying which models should a revision be scored with
	 *
	 * @param RecentChange $rc RecentChange object
	 * @param array &$models List of model names to score
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onORESCheckModels( RecentChange $rc, array &$models );
}
