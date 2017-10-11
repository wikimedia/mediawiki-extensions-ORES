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
	 * @param string|null $model Name of the model to query
	 * @return string Base URL plus your wiki's `scores` API path.
	 */
	public function getUrl( $model = null ) {
		global $wgOresBaseUrl, $wgOresWikiId;

		if ( $wgOresWikiId ) {
			$wikiId = $wgOresWikiId;
		} else {
			$wikiId = wfWikiID();
		}
		$url = "{$wgOresBaseUrl}scores/{$wikiId}/";
		if ( $model ) {
			$url .= "{$model}/";
		}
		return $url;
	}

	/**
	 * Make an ORES API request and return the decoded result.
	 *
	 * @param array $params optional GET parameters
	 * @param string|null $model Name of the model to query
	 * @return array Decoded response
	 *
	 */
	public function request( $params = [], $model = null ) {
		$logger = LoggerFactory::getInstance( 'ORES' );

		$url = $this->getUrl( $model );
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
