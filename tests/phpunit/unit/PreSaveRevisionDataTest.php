<?php
namespace ORES\Tests\Unit;

use CommentStoreComment;
use MediaWiki\Content\Content;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use ORES\PreSaveRevisionData;

/**
 * @covers \ORES\PreSaveRevisionData
 */
class PreSaveRevisionDataTest extends MediaWikiUnitTestCase {
	public function testSerialize(): void {
		$parentRevision = $this->createMock( RevisionRecord::class );
		$parentRevision->method( 'getId' )
			->willReturn( 123 );

		$parentContent = $this->createMock( Content::class );
		$parentContent->method( 'getSize' )
			->willReturn( 19 );
		$parentContent->method( 'serialize' )
			->willReturn( 'test parent content' );

		$parentRevision->method( 'getContent' )
			->with( SlotRecord::MAIN )
			->willReturn( $parentContent );

		$parentRevision->method( 'getTimestamp' )
			->willReturn( '20231001000000' );
		$parentRevision->method( 'getComment' )
			->willReturn( CommentStoreComment::newUnsavedComment( 'test parent summary' ) );

		$data = new PreSaveRevisionData(
			$parentRevision,
			new UserIdentityValue( 102, 'TestUser' ),
			new PageIdentityValue( 456, NS_USER, 'TestPage', PageIdentityValue::LOCAL ),
			'test new content',
			'test new summary',
			'20231002000000',
			'20230815000000',
			'de',
			'User:TestPage'
		);

		$expected = json_encode( [
			'id' => -1,
			'lang' => 'de',
			'bytes' => 16,
			'comment' => 'test new summary',
			'text' => 'test new content',
			'timestamp' => '2023-10-02T00:00:00Z',
			'parent' => [
				'id' => 123,
				'bytes' => 19,
				'comment' => 'test parent summary',
				'text' => 'test parent content',
				'timestamp' => '2023-10-01T00:00:00Z',
				'lang' => 'de',
			],
			'user' => [
				'id' => 102,
			],
			'page' => [
				'id' => 456,
				'title' => 'User:TestPage',
				'first_edit_timestamp' => '2023-08-15T00:00:00Z',
			],
		] );

		$this->assertJsonStringEqualsJsonString( $expected, json_encode( $data ) );
	}
}
