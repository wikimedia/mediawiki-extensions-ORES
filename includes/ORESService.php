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

use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Request\WebRequest;
use MediaWiki\Status\Status;
use MediaWiki\WikiMap\WikiMap;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Common methods for accessing an ORES server.
 */
class ORESService {

	public const API_VERSION = 3;

	/**
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * @var HttpRequestFactory
	 */
	protected $httpRequestFactory;

	/**
	 * @param LoggerInterface $logger
	 * @param HttpRequestFactory $httpRequestFactory
	 */
	public function __construct(
		LoggerInterface $logger,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->logger = $logger;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @return string Wiki ID used by ORES.
	 */
	public static function getWikiID() {
		global $wgOresWikiId;
		if ( $wgOresWikiId ) {
			$wikiId = $wgOresWikiId;
		} else {
			$wikiId = WikiMap::getCurrentWikiId();
		}

		return $wikiId;
	}

	/**
	 * @return string Base URL of ORES service
	 */
	public static function getBaseUrl() {
		global $wgOresBaseUrl;

		return $wgOresBaseUrl;
	}

	/**
	 * @return string Base URL of ORES service being used externally
	 */
	public static function getFrontendBaseUrl() {
		global $wgOresFrontendBaseUrl, $wgOresBaseUrl;

		if ( $wgOresFrontendBaseUrl === null ) {
			return $wgOresBaseUrl;
		}

		return $wgOresFrontendBaseUrl;
	}

	/**
	 * @param string|null $model
	 * @return string Base URL plus your wiki's `scores` API path.
	 */
	public function getUrl( $model = null ) {
		$wikiId = self::getWikiID();
		$prefix = 'v' . self::API_VERSION;
		$baseUrl = self::getBaseUrl();
		$url = "{$baseUrl}{$prefix}/scores/{$wikiId}/";
		return $url;
	}

	/**
	 * Make an ORES API request and return the decoded result.
	 *
	 * @param array $params
	 * @param WebRequest|string[]|null $originalRequest See MwHttpRequest::setOriginalRequest()
	 *
	 * @return array Decoded response
	 */
	public function request(
		array $params,
		$originalRequest = null
	) {
		$url = $this->getUrl();
		$params['format'] = 'json';
		$url = wfAppendQuery( $url, $params );
		$this->logger->debug( "Requesting: {$url}" );
		$req = $this->httpRequestFactory->create(
			$url,
			$this->getMWHttpRequestOptions( $originalRequest ),
			__METHOD__
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$message = "Failed to make ORES request to [{$url}], " .
				Status::wrap( $status )->getMessage()->inLanguage( 'en' )->text();

			// Server time out, try again once
			if ( $req->getStatus() === 504 ) {
				$req = $this->httpRequestFactory->create(
					$url,
					$this->getMWHttpRequestOptions( $originalRequest ),
					__METHOD__
				);
				$status = $req->execute();
				if ( !$status->isOK() ) {
					throw new RuntimeException( $message );
				}
			} else {
				throw new RuntimeException( $message );
			}
		}
		$json = $req->getContent();
		$this->logger->debug( "Raw response: {$json}" );
		$data = FormatJson::decode( $json, true );
		if ( !$data || !empty( $data['error'] ) ) {
			throw new RuntimeException( "Bad response from ORES endpoint [{$url}]: {$json}" );
		}
		return $data;
	}

	/**
	 * @param WebRequest|string[]|null $originalRequest {@see MWHttpRequest::setOriginalRequest}
	 *
	 * @return array
	 */
	protected function getMWHttpRequestOptions( $originalRequest ) {
		if ( $originalRequest === null && MW_ENTRY_POINT !== 'cli' ) {
			$originalRequest = RequestContext::getMain()->getRequest();
		}

		return $originalRequest ? [ 'originalRequest' => $originalRequest ] : [];
	}

}
