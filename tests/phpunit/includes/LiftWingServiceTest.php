<?php

namespace ORES\Tests;

use Exception;
use MediaWiki\Config\HashConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\WikiMap\WikiMap;
use MockHttpTrait;
use ORES\LiftWingService;
use ORES\PreSaveRevisionData;
use RuntimeException;
use UnexpectedValueException;
use Wikimedia\Stats\StatsFactory;

/**
 * @group ORES
 * @covers \ORES\LiftWingService
 */
class LiftWingServiceTest extends \MediaWikiIntegrationTestCase {
	use MockHttpTrait;
	use TempUserTestTrait;

	private const TEST_URL = 'https://liftwing.wikimedia.org/';

	private const TEST_WIKI_ID = 'testwiki';

	private const TEST_REVISION_ID = 123456;

	private HttpRequestFactory $httpRequestFactory;

	private RevisionLookup $revisionLookup;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'OresLiftWingBaseUrl' => self::TEST_URL,
			'OresWikiId' => self::TEST_WIKI_ID,
			'OresFrontendBaseUrl' => null,
			'OresModelVersions' => [
				'models' => [
					'articletopic' => [ 'version' => '1.3.0' ],
					'revertrisklanguageagnostic' => [ 'version' => '3' ],
				]
			]
		] );

		$this->httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$this->revisionLookup = $this->createMock( RevisionLookup::class );

		$this->setService( 'RevisionLookup', $this->revisionLookup );
	}

	private function getLiftWingService( array $config = [] ): LiftWingService {
		$hostMap = [
			'revertrisklanguageagnostic' => 'revertrisk-language-agnostic.revertrisk.wikimedia.org',
			'revertrisklanguageagnostic-presave' => 'revertrisk-language-agnostic-pre-save.revertrisk.wikimedia.org',
			'revertriskmultilingual' => 'revertrisk-multilingual.revertrisk.wikimedia.org',
			'revertriskmultilingual-presave' => 'revertrisk-multilingual-pre-save.revertrisk.wikimedia.org'
		];

		$config += [
			'OresLiftWingRevertRiskHostHeader' => 'revertrisk-language-agnostic.revertrisk.wikimedia.org',
			'OresLiftWingAddHostHeader' => false,
			'OresLiftWingRevertRiskHosts' => $hostMap,
			'OresLiftWingMultilingualRevertRiskEnabled' => false,
			'ORESDeveloperSetup' => false,
		];

		return new LiftWingService(
			LoggerFactory::getInstance( 'ORES' ),
			$this->httpRequestFactory,
			$this->getServiceContainer()->getUserIdentityUtils(),
			new HashConfig( $config ),
			StatsFactory::newNull()
		);
	}

	public function testServiceUrl() {
		$url = $this->getLiftWingService()->getUrl( 'damaging' );
		$this->assertSame( "https://liftwing.wikimedia.org/v1/models/testwiki-damaging:predict", $url );
	}

	public function testGetWikiID() {
		$this->assertSame( 'testwiki', LiftWingService::getWikiID() );

		$this->overrideConfigValue( 'OresWikiId', 'testwiki2' );
		$this->assertSame( 'testwiki2', LiftWingService::getWikiID() );

		$this->overrideConfigValue( 'OresWikiId', null );
		$this->assertSame( WikiMap::getCurrentWikiId(), LiftWingService::getWikiID() );
	}

	public function testGetBaseUrl() {
		$this->assertSame( 'https://liftwing.wikimedia.org/', LiftWingService::getBaseUrl() );

		$this->overrideConfigValue( 'OresLiftWingBaseUrl', 'https://example.com' );
		$this->assertSame( 'https://example.com', LiftWingService::getBaseUrl() );
	}

	public function testGetFrontendBaseUrl() {
		$this->assertSame( 'https://liftwing.wikimedia.org/', LiftWingService::getFrontendBaseUrl() );

		$this->overrideConfigValue( 'OresFrontendBaseUrl', 'https://example.com' );
		$this->assertSame( 'https://example.com', LiftWingService::getFrontendBaseUrl() );
	}

	/**
	 * @dataProvider provideInvalidInput
	 *
	 * @param int $revId
	 * @param bool $doesRevisionExist
	 * @param int|null $revParentId
	 * @param string $expectedErrorType
	 */
	public function testLanguageAgnosticModelRequestShouldNotMakeCallsForInvalidInput(
		int $revId,
		bool $doesRevisionExist,
		?int $revParentId,
		string $expectedErrorType
	): void {
		$revRecord = null;
		if ( $doesRevisionExist ) {
			$revRecord = $this->createMock( RevisionRecord::class );
			$revRecord->method( 'getParentId' )
				->willReturn( $revParentId );
		}

		$this->revisionLookup->method( 'getRevisionById' )
			->with( $revId )
			->willReturn( $revRecord );

		$this->httpRequestFactory->expects( $this->never() )
			->method( $this->anything() );

		$response = $this->getLiftWingService()->request( [
			'models' => 'revertrisklanguageagnostic',
			'revids' => "$revId"
		] );

		$this->assertArrayHasKey(
			'version',
			$response[self::TEST_WIKI_ID]['models']['revertrisklanguageagnostic']
		);
		$this->assertSame(
			$expectedErrorType,
			$response[self::TEST_WIKI_ID]['scores'][$revId]['revertrisklanguageagnostic']['error']['type']
		);
	}

	public static function provideInvalidInput(): iterable {
		yield 'bad revision ID' => [
			'revId' => 0,
			'doesRevisionExist' => true,
			'revParentId' => 1,
			'expectedErrorType' => 'RevisionNotScorable'
		];

		yield 'missing revision' => [
			'revId' => self::TEST_REVISION_ID,
			'doesRevisionExist' => false,
			'revParentId' => 1,
			'expectedErrorType' => 'RevisionNotFound'
		];

		yield 'first revision of a page' => [
			'revId' => self::TEST_REVISION_ID,
			'doesRevisionExist' => true,
			'revParentId' => 0,
			'expectedErrorType' => 'RevisionNotScorable'
		];

		yield 'revision with unknown parent ID' => [
			'revId' => self::TEST_REVISION_ID,
			'doesRevisionExist' => true,
			'revParentId' => null,
			'expectedErrorType' => 'RevisionNotScorable'
		];
	}

	/**
	 * @dataProvider provideErrorResponses
	 * @param int $statusCode
	 * @param array $serviceResponse
	 * @param string|Exception $expectedErrorTypeOrException
	 * @return void
	 */
	public function testLanguageAgnosticModelRequestShouldHandleErrorResponses(
		int $statusCode,
		array $serviceResponse,
		$expectedErrorTypeOrException
	): void {
		if ( $expectedErrorTypeOrException instanceof Exception ) {
			$this->expectException( get_class( $expectedErrorTypeOrException ) );
		}
		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getParentId' )
			->willReturn( 1 );
		$this->revisionLookup->method( 'getRevisionById' )
			->with( self::TEST_REVISION_ID )
			->willReturn( $revRecord );

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $this->makeFakeHttpRequest(
				json_encode( $serviceResponse ),
				$statusCode,
			) );

		$response = $this->getLiftWingService()->request( [
			'models' => 'revertrisklanguageagnostic',
			'revids' => self::TEST_REVISION_ID,
		] );

		if ( is_string( $expectedErrorTypeOrException ) ) {
			$wikiResponse = $response[self::TEST_WIKI_ID];
			$this->assertArrayHasKey(
				'version',
				$wikiResponse['models']['revertrisklanguageagnostic']
			);
			$this->assertSame(
				$expectedErrorTypeOrException,
				$wikiResponse['scores'][self::TEST_REVISION_ID]['revertrisklanguageagnostic']['error']['type']
			);
		}
	}

	public static function provideErrorResponses(): iterable {
		yield 'error 504' => [
			504,
			[],
			new RuntimeException()
		];

		yield 'error 400' => [
			400,
			[
				'error' => 'The MW API does not have any info related to the rev-id'
			],
			'RevisionNotFound'
		];

		yield 'malformed 200 response' => [
			200,
			[],
			new RuntimeException()
		];
	}

	public function testLanguageAgnosticModelRequestShouldThrowOnMissingHostConfigurationForModel(): void {
		$this->expectException( UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Missing host setup for model name: revertrisklanguageagnostic' );

		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getParentId' )
			->willReturn( 1 );
		$this->revisionLookup->method( 'getRevisionById' )
			->with( self::TEST_REVISION_ID )
			->willReturn( $revRecord );

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $this->makeFakeHttpRequest() );

		$this->getLiftWingService( [
			'OresLiftWingRevertRiskHosts' => [],
			'OresLiftWingAddHostHeader' => true
		] )->request( [
			'models' => 'revertrisklanguageagnostic',
			'revids' => self::TEST_REVISION_ID,
		] );
	}

	/**
	 * @dataProvider provideAddHostHeader
	 */
	public function testLanguageAgnosticModelRequestShouldReturnSuccessfulResponse(
		bool $addHostHeader
	): void {
		$revRecord = $this->createMock( RevisionRecord::class );
		$revRecord->method( 'getParentId' )
			->willReturn( 1 );
		$this->revisionLookup->method( 'getRevisionById' )
			->with( self::TEST_REVISION_ID )
			->willReturn( $revRecord );

		$req = $this->makeFakeHttpRequest(
			json_encode( [
				'wiki_db' => self::TEST_WIKI_ID,
				'model_version' => '1.0',
				'revision_id' => self::TEST_REVISION_ID,
				'output' => [
					'prediction' => true,
					'probabilities' => [
						'true' => 0.7,
						'false' => 0.3,
					],
				],
			] ),
			200,
		);

		$requestHeaders = [];
		$req->method( 'setHeader' )
			->willReturnCallback( static function ( $name, $value ) use ( &$requestHeaders ) {
				$requestHeaders[$name] = $value;
			} );

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $req );

		$response = $this->getLiftWingService( [ 'OresLiftWingAddHostHeader' => $addHostHeader ] )->request( [
			'models' => 'revertrisklanguageagnostic',
			'revids' => self::TEST_REVISION_ID,
		] );

		$expectedRequestHeaders = [ 'Content-Type' => 'application/json' ];
		if ( $addHostHeader ) {
			$expectedRequestHeaders['Host'] = 'revertrisk-language-agnostic.revertrisk.wikimedia.org';
		}

		$this->assertSame(
			[
				self::TEST_WIKI_ID => [
					'models' => [
						'revertrisklanguageagnostic' => [
							'version' => '1.0',
						],
					],
					'scores' => [
						self::TEST_REVISION_ID => [
							'revertrisklanguageagnostic' => [
								'score' => [
									'prediction' => 'true',
									'probability' => [
										'true' => 0.7,
										'false' => 0.3,
									],
								]
							],
						],
					],
				],
			],
			$response
		);
		$this->assertSame( $expectedRequestHeaders, $requestHeaders );
	}

	public static function provideAddHostHeader(): iterable {
		yield 'with added Host header' => [ true ];
		yield 'without added Host header' => [ false ];
	}

	/**
	 * @dataProvider provideSingleLiftWingErrorResponses
	 * @param int $statusCode
	 * @param array $serviceResponse
	 * @param string|Exception $expectedErrorTypeOrException
	 * @return void
	 */
	public function testSingleLiftWingRequestShouldHandleErrorResponses(
		int $statusCode,
		array $serviceResponse,
		$expectedErrorTypeOrException
	) {
		if ( $expectedErrorTypeOrException instanceof Exception ) {
			$this->expectException( get_class( $expectedErrorTypeOrException ) );
		}

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $this->makeFakeHttpRequest(
				json_encode( $serviceResponse ),
				$statusCode,
			) );

		$response = $this->getLiftWingService()->request( [
			'models' => 'articletopic',
			'revids' => self::TEST_REVISION_ID,
		] );

		if ( is_string( $expectedErrorTypeOrException ) ) {
			$this->assertArrayHasKey(
				'version',
				$response[self::TEST_WIKI_ID]['models']['articletopic']
			);
			$this->assertSame(
				$expectedErrorTypeOrException,
				$response[self::TEST_WIKI_ID]['scores'][self::TEST_REVISION_ID]['articletopic']['error']['type']
			);
		}
	}

	public static function provideSingleLiftWingErrorResponses(): iterable {
		yield 'error 504' => [
			504,
			[],
			new RuntimeException()
		];

		yield 'error 400' => [
			400,
			[
				'error' => 'The MW API does not have any info related to the rev-id'
			],
			'RevisionNotFound'
		];

		yield 'malformed 200 response' => [
			200,
			[],
			new RuntimeException()
		];
	}

	public function testSingleLiftWingRequestShouldThrowOnMissingHostConfigurationForModel(): void {
		$this->expectException( UnexpectedValueException::class );
		$this->expectExceptionMessage( 'Missing host setup for model name: test' );

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $this->makeFakeHttpRequest() );

		$this->getLiftWingService( [ 'OresLiftWingAddHostHeader' => true, ] )->request( [
			'models' => 'test',
			'revids' => self::TEST_REVISION_ID,
		] );
	}

	/**
	 * @dataProvider provideAddHostHeader
	 */
	public function testSingleLiftWingRequestShouldReturnSuccessfulResponse(
		bool $addHostHeader
	): void {
		$singleResponse = [
			self::TEST_WIKI_ID => [
				'models' => [
					'articletopic' => [
						'version' => '1.3.0',
					],
				],
				'scores' => [
					self::TEST_REVISION_ID => [
						'articletopic' => [
							'score' => [
								'prediction' => [ 'Test' ],
								'probability' => [
									'Test' => 0.85,
									'Other' => 0.15,
								],
							]
						],
					],
				],
			],
		];

		$req = $this->makeFakeHttpRequest(
			json_encode( $singleResponse ),
			200,
		);

		$requestHeaders = [];
		$req->method( 'setHeader' )
			->willReturnCallback( static function ( $name, $value ) use ( &$requestHeaders ) {
				$requestHeaders[$name] = $value;
			} );

		$this->httpRequestFactory->method( 'create' )
			->willReturn( $req );

		$response = $this->getLiftWingService( [ 'OresLiftWingAddHostHeader' => $addHostHeader ] )->request( [
			'models' => 'articletopic',
			'revids' => self::TEST_REVISION_ID,
		] );

		$expectedRequestHeaders = [ 'Content-Type' => 'application/json' ];
		if ( $addHostHeader ) {
			$wikiID = self::TEST_WIKI_ID;
			$expectedRequestHeaders['Host'] = "{$wikiID}-articletopic.revscoring-articletopic.wikimedia.org";
		}

		$this->assertSame( $singleResponse, $response );
		$this->assertSame( $expectedRequestHeaders, $requestHeaders );
	}

	/**
	 * @dataProvider provideRevertRiskPreSaveErrorResponses
	 */
	public function testRevertRiskPreSaveShouldReturnErrorResponse( ?string $response ): void {
		$data = $this->createMock( PreSaveRevisionData::class );
		$data->method( 'jsonSerialize' )
			->willReturn( [ 'test' => 'payload' ] );

		$this->httpRequestFactory->method( 'create' )
			->with(
				self::TEST_URL . 'v1/models/revertrisk-language-agnostic:predict',
				[
					'method' => 'POST',
					'postData' => json_encode( [ 'revision_data' => [ 'test' => 'payload' ] ] ),
				]
			)
			->willReturn( $this->makeFakeHttpRequest( $response, 400 ) );

		$editor = new UserIdentityValue( 102, 'TestUser' );

		$score = $this->getLiftWingService()->revertRiskPreSave( $editor, $data );

		$this->assertNull( $score );
	}

	public static function provideRevertRiskPreSaveErrorResponses(): iterable {
		yield 'error response without body' => [
			''
		];

		yield 'textual error response' => [
			'some error'
		];

		yield 'JSON error response' => [
			 json_encode( [ 'error' => 'some error' ] )
		];
	}

	/**
	 * @dataProvider provideRevertRiskPreSaveSuccessResponses
	 */
	public function testRevertRiskPreSaveShouldReturnSuccessfulResponse(
		bool $addHostHeader,
		bool $multilingualRevertRiskEnabled,
		UserIdentity $editor,
		string $expectedModelName
	): void {
		$this->enableAutoCreateTempUser( [
			'genPattern' => '~$1',
			'reservedPattern' => '~$1',
		] );

		$data = $this->createMock( PreSaveRevisionData::class );
		$data->method( 'jsonSerialize' )
			->willReturn( [ 'test' => 'payload' ] );

		$response = json_encode( [
			'wiki_db' => self::TEST_WIKI_ID,
			'model_version' => '1.0',
			'revision_id' => -1,
			'output' => [
				'prediction' => true,
				'probabilities' => [
					'true' => 0.7,
					'false' => 0.3,
				],
			],
		] );

		$req = $this->makeFakeHttpRequest( $response, 200 );

		$requestHeaders = [];
		$req->method( 'setHeader' )
			->willReturnCallback( static function ( $name, $value ) use ( &$requestHeaders ) {
				$requestHeaders[$name] = $value;
			} );

		$this->httpRequestFactory->method( 'create' )
			->with(
				self::TEST_URL . "v1/models/$expectedModelName:predict",
				[
					'method' => 'POST',
					'postData' => json_encode( [ 'revision_data' => [ 'test' => 'payload' ] ] ),
				]
			)
			->willReturn( $req );

		$score = $this->getLiftWingService( [
			'OresLiftWingAddHostHeader' => $addHostHeader,
			'OresLiftWingMultilingualRevertRiskEnabled' => $multilingualRevertRiskEnabled,
		] )->revertRiskPreSave( $editor, $data );

		$expectedRequestHeaders = [ 'Content-Type' => 'application/json' ];
		if ( $addHostHeader ) {
			$expectedRequestHeaders['Host'] = "$expectedModelName-pre-save.revertrisk.wikimedia.org";
		}

		$this->assertSame( 0.7, $score );
		$this->assertSame( $expectedRequestHeaders, $requestHeaders );
	}

	public static function provideRevertRiskPreSaveSuccessResponses(): iterable {
		$namedUser = new UserIdentityValue( 102, 'TestUser' );

		yield 'with added Host header for named user, RRML not available' => [
			'addHostHeader' => true,
			'multilingualRevertRiskEnabled' => false,
			'editor' => $namedUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'without added Host header for named user, RRML not available' => [
			'addHostHeader' => false,
			'multilingualRevertRiskEnabled' => false,
			'editor' => $namedUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'with added Host header for named user, RRML available' => [
			'addHostHeader' => true,
			'multilingualRevertRiskEnabled' => true,
			'editor' => $namedUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'without added Host header for named user, RRML available' => [
			'addHostHeader' => false,
			'multilingualRevertRiskEnabled' => true,
			'editor' => $namedUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		$tempUser = new UserIdentityValue( 103, '~2024-8' );

		yield 'with added Host header for temporary account, RRML not available' => [
			'addHostHeader' => true,
			'multilingualRevertRiskEnabled' => false,
			'editor' => $tempUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'without added Host header for temporary account, RRML not available' => [
			'addHostHeader' => false,
			'multilingualRevertRiskEnabled' => false,
			'editor' => $tempUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'with added Host header for temporary account, RRML available' => [
			'addHostHeader' => true,
			'multilingualRevertRiskEnabled' => true,
			'editor' => $tempUser,
			'expectedModelName' => 'revertrisk-multilingual',
		];

		yield 'without added Host header for temporary account, RRML available' => [
			'addHostHeader' => false,
			'multilingualRevertRiskEnabled' => true,
			'editor' => $tempUser,
			'expectedModelName' => 'revertrisk-multilingual',
		];

		$ipUser = new UserIdentityValue( 0, '127.0.0.1' );

		yield 'with added Host header for IP user, RRML not available' => [
			'addHostHeader' => true,
			'multilingualRevertRiskEnabled' => false,
			'editor' => $ipUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'without added Host header for IP user, RRML not available' => [
			'addHostHeader' => false,
			'multilingualRevertRiskEnabled' => false,
			'editor' => $ipUser,
			'expectedModelName' => 'revertrisk-language-agnostic',
		];

		yield 'with added Host header for IP user, RRML available' => [
			'addHostHeader' => true,
			'multilingualRevertRiskEnabled' => true,
			'editor' => $ipUser,
			'expectedModelName' => 'revertrisk-multilingual',
		];

		yield 'without added Host header for IP user, RRML available' => [
			'addHostHeader' => false,
			'multilingualRevertRiskEnabled' => true,
			'editor' => $ipUser,
			'expectedModelName' => 'revertrisk-multilingual',
		];
	}
}
