<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

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
class ORESService {
	/** @var WebRequest|string[]|null */
	private $originalRequest;

	const API_VERSION = 3;

	/**
	 * @return ORESService
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
	public static function getWikiID() {
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

		$wikiId = self::getWikiID();
		$prefix = 'v' . self::API_VERSION;
		$url = "{$wgOresBaseUrl}{$prefix}/scores/{$wikiId}/";
		return $url;
	}

	/**
	 * Make an ORES API request and return the decoded result.
	 *
	 * @param array $params
	 * @return array Decoded response
	 *
	 */
	public function request( array $params ) {
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
