<?php

namespace ORES\Tests;

use ORES\ORESService;
use PHPUnit\Framework\TestCase;

class MockOresServiceBuilder {

	/**
	 * @param TestCase $test
	 * @return ORESService
	 */
	public static function getORESServiceMock( TestCase $test ) {
		$mock = $test->getMockBuilder( ORESService::class )
			->disableOriginalConstructor()
			->getMock();

		$mock->expects( $test->any() )
			->method( 'request' )
			->willReturnCallback( [ self::class, 'mockORESResponse' ] );

		return $mock;
	}

	/**
	 * @param array $params
	 * @param WebRequest|string[]|null $originalRequest
	 * @return array
	 */
	public static function mockORESResponse( array $params, $originalRequest = null ) {
		$models = [];
		foreach ( explode( '|', $params['models'] ) as $model ) {
			$models[$model] = [ 'version' => '0.0.4' ];
		}

		$scores = [];
		foreach ( explode( '|', $params['revids'] ) as $revid ) {
			$scores[(string)$revid] = self::mockRevisionResponse( $revid, array_keys( $models ) );
		}

		return [ ORESService::getWikiID() => [ 'models' => $models, 'scores' => $scores ] ];
	}

	/**
	 * @param int $revid
	 * @param array $models
	 * @return array[]
	 */
	public static function mockRevisionResponse( $revid, $models ) {
		$result = [];
		foreach ( $models as $model ) {
			$result[$model] = [ 'score' => [] ];
			$probability = (float)strrev( substr( $revid, -2 ) ) / 100;
			$result[$model]['score']['probability'] = [
				'true' => $probability,
				'false' => 1 - $probability
			];
			$result[$model]['score']['prediction'] = $probability > 0.5;
		}
		return $result;
	}

}
