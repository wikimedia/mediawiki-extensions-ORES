<?php

namespace ORES\Hooks;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Revision\RevisionRecord;

/**
 * This is a hook runner class, see docs/Hooks.md in core.
 * @internal
 */
class HookRunner implements
	ORESCheckModelsHook,
	ORESRecentChangeScoreSavedHook
{
	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * @inheritDoc
	 */
	public function onORESCheckModels( RecentChange $rc, array &$models ) {
		return $this->hookContainer->run(
			'ORESCheckModels',
			[ $rc, &$models ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onORESRecentChangeScoreSavedHook( RevisionRecord $revision, array $scores ) {
		return $this->hookContainer->run(
			'ORESRecentChangeScoreSavedHook',
			[ $revision, $scores ]
		);
	}
}
