<?php

namespace ORES\Tests\Unit;

use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use ORES\Hooks\HookRunner;

/**
 * @covers \ORES\Hooks\HookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	public static function provideHookRunners() {
		yield HookRunner::class => [ HookRunner::class ];
	}
}
