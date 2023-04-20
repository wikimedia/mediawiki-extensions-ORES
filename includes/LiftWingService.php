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
use RuntimeException;
use Status;
use WebRequest;

/**
 * Common methods for accessing a Lift Wing server.
 */
class LiftWingService extends ORESService {

	public const API_VERSION = 1;

	/**
	 * @return string Base URL of ORES service
	 */
	public static function getBaseUrl() {
		global $wgOresLiftWingBaseUrl;

		return $wgOresLiftWingBaseUrl;
	}

	/**
	 * @return string Base URL of ORES service being used externally
	 */
	public static function getFrontendBaseUrl() {
		global $wgOresFrontendBaseUrl, $wgOresLiftWingBaseUrl;

		if ( $wgOresFrontendBaseUrl === null ) {
			return $wgOresLiftWingBaseUrl;
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
		return "{$baseUrl}{$prefix}/models/{$wikiId}-{$model}:predict";
	}

	/**
	 * Make an ORES API request and return the decoded result.
	 *
	 * @param array $params
	 * @param WebRequest|string[]|null $originalRequest See MwHttpRequest::setOriginalRequest()
	 *
	 * @return array Decoded response
	 */
	public function request( array $params, $originalRequest = null ) {
		if ( !isset( $params['models'] ) ) {
			throw new RuntimeException( 'Missing required parameter: models' );
		}
		if ( !isset( $params['revids'] ) ) {
			throw new RuntimeException( 'Missing required parameter: revids' );
		}

		$models = (array)$params['models'];
		$revids = (array)$params['revids'];

		$responses = [];

		foreach ( $models as $model ) {
			foreach ( $revids as $revid ) {
				$response = $this->singleLiftWingRequest( $model, $revid );
				$responses[] = $response;
			}
		}
		$wikiId = self::getWikiID();
		return $this->parseLiftWingResults( $wikiId, $responses );
	}

	/**
	 * Make a single call to LW for one revid and one model and return the decoded result.
	 *
	 * @param string $model
	 * @param string $revid
	 *
	 * @return array Decoded response
	 */
	public function singleLiftWingRequest( $model, $revid ) {
		$url = $this->getUrl( $model );
		$this->logger->debug( "Requesting: {$url}" );
		$headers = [ 'Content-Type' => 'application/json', 'Host' => self::createHostHeader( $model ) ];
		$req = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'headers' => $headers,
			'postData' => json_encode( [ 'rev_id' => (int)$revid ] )
			],
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$message = "Failed to make LiftWing request to [{$url}], " .
				Status::wrap( $status )->getMessage()->inLanguage( 'en' )->text();

			// Server time out, try again once
			if ( $req->getStatus() === 504 ) {
				$req = $this->httpRequestFactory->create( $url, [
					'method' => 'POST',
					'headers' => $headers,
					'postData' => json_encode( [ 'rev_id' => $revid ] )
				],
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
	 * @param string $modelname the model name as requested from ORES
	 * @return string The hostname required in the header for the Lift Wing call
	 */
	public static function createHostHeader( string $modelname ) {
		$hostnames = [
			'articlequality' => 'revscoring-articlequality',
			'itemquality' => 'revscoring-articlequality',
			'articletopic' => 'revscoring-articletopic',
			'itemtopic' => 'revscoring-articletopic',
			'draftquality' => 'revscoring-draftquality',
			'drafttopic' => 'revscoring-drafttopic',
			'damaging' => 'revscoring-editquality-damaging',
			'goodfaith' => 'revscoring-editquality-goodfaith',
			'reverted' => 'revscoring-editquality-reverted'
		];
		$wikiID = self::getWikiID();
		return "{$wikiID}-{$modelname}.{$hostnames[$modelname]}.wikimedia.org";
	}

	/**
	 * This function merges the multiple Lift Wing responses into the appropriate format as returned from ORES
	 * @param string $context
	 * @param array $responses
	 * @return array
	 */
	private function parseLiftWingResults( string $context, array $responses ): array {
		$result = [];
		foreach ( $responses as $d ) {
			if ( !$d ) {
				continue;
			}
			foreach ( $d[$context] as $k => $v ) {
				if ( is_array( $v ) && $k === "scores" ) {
					foreach ( $v as $rev_id => $scores ) {
						if ( isset( $result[$context][$k][$rev_id] ) ) {
							$result[$context][$k][$rev_id] = array_merge( $result[$context][$k][$rev_id], $scores );
						} else {
							$result[$context][$k][$rev_id] = $scores;
						}
					}
				} else {
					$result[$context][$k] = array_merge( $result[$context][$k] ?? [], $v );
				}
			}
		}
		return $result;
	}

}
