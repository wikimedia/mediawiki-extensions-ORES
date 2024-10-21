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

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Common methods for accessing a Lift Wing server.
 */
class LiftWingService extends ORESService {

	public const API_VERSION = 1;
	private Config $config;

	public function __construct(
		LoggerInterface $logger, HttpRequestFactory $httpRequestFactory, Config $config
	) {
		parent::__construct( $logger, $httpRequestFactory );
		$this->config = $config;
	}

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
	 * @param array|null $originalRequest
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

		$models = explode( '|', $params['models'] );
		$revids = explode( '|', $params['revids'] );

		$responses = [];

		foreach ( $models as $model ) {
			foreach ( $revids as $revid ) {
				if ( $model == 'revertrisklanguageagnostic' ) {
					// This is a hack to convert the revert-risk response to an ORES response in order to be compatible
					// with the rest of the model responses in order for them to be easily merged together in one
					// response.
					$response = $this->revertRiskLiftWingRequest( $model, $revid );
				} else {
					$response = $this->singleLiftWingRequest( $model, $revid );
				}

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

		$req = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => json_encode( [ 'rev_id' => (int)$revid ] ),
		], __METHOD__ );
		global $wgOresLiftWingAddHostHeader;
		if ( $wgOresLiftWingAddHostHeader ) {
			$req->setHeader( 'Content-Type', 'application/json' );
			$req->setHeader( 'Host', self::createHostHeader( $model ) );
		}
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$message = "Failed to make LiftWing request to [{$url}], " .
				Status::wrap( $status )->getMessage()->inLanguage( 'en' )->text();

			// Server time out, try again once
			if ( $req->getStatus() === 504 ) {
				$req = $this->httpRequestFactory->create( $url, [
					'method' => 'POST',
					'postData' => json_encode( [ 'rev_id' => (int)$revid ] ),
				], __METHOD__ );
				if ( $wgOresLiftWingAddHostHeader ) {
					$req->setHeader( 'Content-Type', 'application/json' );
					$req->setHeader( 'Host', self::createHostHeader( $model ) );
				}

				$status = $req->execute();
				if ( !$status->isOK() ) {
					throw new RuntimeException( $message );
				}
			} elseif ( $req->getStatus() === 400 ) {
				$this->logger->debug( "400 Bad Request: {$message}" );
				$data = FormatJson::decode( $req->getContent(), true );
				if ( strpos( $data["error"], "The MW API does not have any info related to the rev-id" ) === 0 ) {
					return $this->createRevisionNotFoundResponse( $model, $revid );
				} else {
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
			throw new RuntimeException( "Bad response from Lift Wing endpoint [{$url}]: {$json}" );
		}
		return $data;
	}

	/**
	 * @param string $model
	 * @param string|int $revid
	 * @return array|array[]|mixed
	 */
	public function revertRiskLiftWingRequest( $model, $revid ) {
		$revLookup = MediaWikiServices::getInstance()->getRevisionLookup();
		$revidInt = (int)$revid;
		$revidStr = (string)$revid;
		// The Language-agnostic revert risk model card states that
		// the first or only revision on a page must be excluded.
		if ( $revidInt === 0 ) {
			return $this->createRevisionNotScorableResponse(
				$model,
				$revidStr
			);
		}
		$rev = $revLookup->getRevisionById( $revidInt );
		if ( $rev === null ) {
			return $this->createRevisionNotFoundResponse(
				$model,
				$revidStr
			);
		}
		$parentId = $rev->getParentId();
		if ( $parentId === null || $parentId === 0 ) {
			return $this->createRevisionNotScorableResponse(
				$model,
				$revidStr
			);
		}
		$wikiId = self::getWikiID();
		$language = substr( $wikiId, 0, strpos( $wikiId, "wiki" ) );
		$prefix = 'v' . self::API_VERSION;
		$baseUrl = self::getBaseUrl();
		$url = "{$baseUrl}{$prefix}/models/revertrisk-language-agnostic:predict";

		$this->logger->debug( "Requesting: {$url}" );

		$req = $this->prepareRevertriskRequest( $url, $revid, $language );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$message = "Failed to make LiftWing request to [{$url}], " .
				Status::wrap( $status )->getMessage()->inLanguage( 'en' )->text();

			// Server time out, try again once
			if ( $req->getStatus() === 504 ) {
				$req = $this->prepareRevertriskRequest( $url, $revid, $language );
				$status = $req->execute();
				if ( !$status->isOK() ) {
					throw new RuntimeException( $message );
				}
			} elseif ( $req->getStatus() === 400 ) {
				$this->logger->debug( "400 Bad Request: {$message}" );
				$data = FormatJson::decode( $req->getContent(), true );
				// This is a way to detect whether an error occurred because the revision was not found.
				// Checking for "detail" key in the response is a hack to detect the error message from Lift Wing as
				// there is no universal error handling/messaging at the moment.
				if ( ( $data && isset( $data['error'] ) && strpos( $data["error"],
						"The MW API does not have any info related to the rev-id" ) === 0 ) ||
					array_key_exists( "detail", $data ) ) {
					return $this->createRevisionNotFoundResponse(
						$model,
						$revidStr
					);
				} else {
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
			throw new RuntimeException( "Bad response from Lift Wing endpoint [{$url}]: {$json}" );
		}
		return $this->modifyRevertRiskResponse( $data );
	}

	private function prepareRevertriskRequest( string $url, string $revid, string $language ): \MWHttpRequest {
		$req = $this->httpRequestFactory->create( $url, [
			'method' => 'POST',
			'postData' => json_encode( [ 'rev_id' => (int)$revid, 'lang' => $language ] ),
		], __METHOD__ );
		$req->setHeader( 'Content-Type', 'application/json' );
		global $wgOresLiftWingAddHostHeader;
		if ( $wgOresLiftWingAddHostHeader ) {
			$req->setHeader( 'Host', $this->config->get( 'OresLiftWingRevertRiskHostHeader' ) );
		}
		return $req;
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
			'reverted' => 'revscoring-editquality-reverted',
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

	/**
	 * @param string $model_name
	 * @param string $rev_id
	 * @return array
	 */
	private function createRevisionNotFoundResponse(
		string $model_name,
		string $rev_id
	) {
		global $wgOresModelVersions;
		$error_type = "RevisionNotFound";
		$error_message = "{$error_type}: Could not find revision ({revision}:{$rev_id})";
		return [
			self::getWikiID() => [
				"models" => [
					$model_name => [
						"version" => $wgOresModelVersions['models'][$model_name]['version'],
					],
				],
				"scores" => [
					$rev_id => [
						$model_name => [
							"error" => [
								"message" => $error_message,
								"type" => $error_type,
							],
						],
					],
				],
			],
		];
	}

	/**
	 * @param string $model_name
	 * @param string $rev_id
	 * @return array
	 */
	private function createRevisionNotScorableResponse(
		string $model_name,
		string $rev_id
	) {
		global $wgOresModelVersions;
		$error_type = "RevisionNotScorable";
		$error_message = "{$error_type}: This model may not be used to score ({revision}:{$rev_id}).";
		$error_message .= " See Users and uses section of model card.";
		return [
			self::getWikiID() => [
				"models" => [
					$model_name => [
						"version" => $wgOresModelVersions['models'][$model_name]['version'],
					],
				],
				"scores" => [
					$rev_id => [
						$model_name => [
							"error" => [
								"message" => $error_message,
								"type" => $error_type,
							],
						],
					],
				],
			],
		];
	}

	/**
	 * @param array $response
	 * @return array[]
	 */
	private function modifyRevertRiskResponse( array $response ): array {
		return [
			$response["wiki_db"] => [
				"models" => [
					"revertrisklanguageagnostic" => [
						"version" => $response["model_version"]
					]
				],
				"scores" => [
					(string)$response["revision_id"] => [
						"revertrisklanguageagnostic" => [
							"score" => [
								"prediction" => $response["output"]["prediction"] ? "true" : "false",
								"probability" => [
									"true" => $response["output"]["probabilities"]["true"],
									"false" => $response["output"]["probabilities"]["false"]
								]
							]
						]
					]
				]
			]
		];
	}

}
