<?php

namespace ORES;

use FormatJson;
use MWHttpRequest;
use RuntimeException;

/**
 * Common methods for accessing an ORES server.
 */
class Api {
	/**
	 * @return string Base URL plus your wiki's `scores` API path.
	 */
	public static function getUrl( $param = array() ) {
		global $wgOresBaseUrl, $wgOresWikiId;

		if ( $wgOresWikiId ) {
			$wikiId = $wgOresWikiId;
		} else {
			$wikiId = wfWikiID();
		}
		$url = "{$wgOresBaseUrl}scores/{$wikiId}/";
		return $url;
	}

	/**
	 * Make an ORES API request and return the decoded result.
	 *
	 * @param array $params optional GET parameters
	 * @return array Decoded response
	 *
	 * @throws RuntimeException
	 */
	public static function request( $params = array() ) {
		$url = Api::getUrl();
		$url = wfAppendQuery( $url, $params );
		$req = MWHttpRequest::factory( $url, null, __METHOD__ );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( "Failed to make ORES request to [{$url}], "
				. $status->getMessage()->text() );
		}
		$json = $req->getContent();
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "Bad response from ORES endpoint [{$url}]: {$json}" );
		}
		return $data;
	}
}
