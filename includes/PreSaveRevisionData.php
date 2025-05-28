<?php
namespace ORES;

use JsonSerializable;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserIdentity;

/**
 * Data record holding metadata required by pre-save revertrisk endpoints.
 */
class PreSaveRevisionData implements JsonSerializable {
	private RevisionRecord $parentRevision;
	private UserIdentity $editor;
	private PageIdentity $page;

	private string $newMainSlotContent;
	private string $summary;
	private string $timestamp;
	private string $firstEditTimestamp;
	private string $pageLanguageCode;
	private string $prefixedTitleText;

	public function __construct(
		RevisionRecord $parentRevision,
		UserIdentity $editor,
		PageIdentity $page,
		string $newMainSlotContent,
		string $summary,
		string $timestamp,
		string $firstEditTimestamp,
		string $pageLanguageCode,
		string $prefixedTitleText
	) {
		$this->parentRevision = $parentRevision;
		$this->editor = $editor;
		$this->page = $page;
		$this->newMainSlotContent = $newMainSlotContent;
		$this->summary = $summary;
		$this->timestamp = $timestamp;
		$this->firstEditTimestamp = $firstEditTimestamp;
		$this->pageLanguageCode = $pageLanguageCode;
		$this->prefixedTitleText = $prefixedTitleText;
	}

	public function jsonSerialize(): array {
		$parentMainSlotContent = $this->parentRevision->getContent( SlotRecord::MAIN );

		return [
			'id' => -1,
			'lang' => $this->pageLanguageCode,
			'bytes' => strlen( $this->newMainSlotContent ),
			'comment' => $this->summary,
			'text' => $this->newMainSlotContent,
			'timestamp' => wfTimestamp( TS_ISO_8601, $this->timestamp ),
			'parent' => [
				'id' => $this->parentRevision->getId(),
				'bytes' => $parentMainSlotContent->getSize(),
				'comment' => $this->parentRevision->getComment()->text,
				'text' => $parentMainSlotContent->serialize(),
				'timestamp' => wfTimestamp( TS_ISO_8601, $this->parentRevision->getTimestamp() ),
				'lang' => $this->pageLanguageCode,
			],
			'user' => [
				'id' => $this->editor->getId()
			],
			'page' => [
				'id' => $this->page->getId(),
				'title' => $this->prefixedTitleText,
				'first_edit_timestamp' => wfTimestamp( TS_ISO_8601, $this->firstEditTimestamp ),
			]
		];
	}
}
