<?php

namespace ORES;

use FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MWHttpRequest;
use RequestContext;
use RuntimeException;
use WebRequest;

/**
 * Common methods for accessing an ORES server.
 */
class Api {
	/** @var WebRequest|string[]|null */
	private $originalRequest;

	const API_VERSION = 3;

	/**
	 * @return Api
	 */
	public static function newFromContext() {
		$self = new self();
		if ( empty( $GLOBALS['wgCommandLineMode'] ) ) {
			$self->setOriginalRequest( RequestContext::getMain()->getRequest() );
		}
		return $self;
	}

	/**
	 * @param WebRequest|string[] $originalRequest See MwHttpRequest::setOriginalRequest()
	 */
	public function setOriginalRequest( $originalRequest ) {
		$this->originalRequest = $originalRequest;
	}

	/**
	 * @return string Wiki ID used by ORES.
	 */
	public function getWikiID() {
		global $wgOresWikiId;
		if ( $wgOresWikiId ) {
			$wikiId = $wgOresWikiId;
		} else {
			$wikiId = wfWikiID();
		}
		return $wikiId;
	}

	/**
	 * @return string Base URL plus your wiki's `scores` API path.
	 */
	public function getUrl() {
		global $wgOresBaseUrl;

		$wikiId = $this->getWikiID();
		$prefix = 'v' . self::API_VERSION;
		$url = "{$wgOresBaseUrl}{$prefix}/scores/{$wikiId}/";
		return $url;
	}

	/**
	 * Make an ORES API request and return the decoded result.
	 *
	 * @param array $params optional GET parameters
	 * @return array Decoded response
	 *
	 */
	public function request( $params = [] ) {
		$logger = LoggerFactory::getInstance( 'ORES' );

		$url = $this->getUrl();
		$params['format'] = 'json';
		$url = wfAppendQuery( $url, $params );
		$logger->debug( "Requesting: {$url}" );
		$req = MWHttpRequest::factory( $url, $this->getMWHttpRequestOptions(), __METHOD__ );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( "Failed to make ORES request to [{$url}], "
				. $status->getMessage()->text() );
		}
		$json = $req->getContent();
		$logger->debug( "Raw response: {$json}" );
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "Bad response from ORES endpoint [{$url}]: {$json}" );
		}
		return $data;
	}

	protected function getMWHttpRequestOptions() {
		return $this->originalRequest ? [ 'originalRequest' => $this->originalRequest ] : [];
	}

}
