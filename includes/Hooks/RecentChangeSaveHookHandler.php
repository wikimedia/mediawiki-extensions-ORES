<?php
/**
 * Copyright (C) 2016 Brad Jorsch <bjorsch@wikimedia.org>
 *
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

namespace ORES\Hooks;

use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use ORES\FetchScoreJob;
use Psr\Log\LoggerInterface;
use RecentChange;
use RequestContext;
use WebRequest;

class RecentChangeSaveHookHandler {

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var WebRequest
	 */
	private $request;

	/**
	 * @param LoggerInterface $logger
	 * @param WebRequest $request
	 */
	public function __construct( LoggerInterface $logger, WebRequest $request ) {
		$this->logger = $logger;
		$this->request = $request;
	}

	/**
	 * Ask the ORES server for scores on this recent change
	 *
	 * @param RecentChange $rc
	 */
	public static function onRecentChange_save( RecentChange $rc ) {
		global $wgOresExcludeBots, $wgOresEnabledNamespaces, $wgOresModels, $wgOresDraftQualityNS;

		$self = new self(
			LoggerFactory::getInstance( 'ORES' ),
			RequestContext::getMain()->getRequest()
		);

		$self->handle(
			$rc,
			$wgOresModels,
			$wgOresExcludeBots,
			$wgOresEnabledNamespaces,
			$wgOresDraftQualityNS
		);
	}

	/**
	 * @param RecentChange $rc
	 * @param array $modelsConfig
	 * @param bool $excludeBots
	 * @param array $enabledNamespaces
	 * @param array $draftQualityNS
	 */
	public function handle(
		RecentChange $rc,
		array $modelsConfig,
		$excludeBots,
		array $enabledNamespaces,
		array $draftQualityNS
	) {
		if ( $rc->getAttribute( 'rc_bot' ) && $excludeBots ) {
			return;
		}

		// Check if we actually want score for this namespace
		$ns = $rc->getAttribute( 'rc_namespace' );
		if ( $enabledNamespaces &&
			!( isset( $enabledNamespaces[$ns] ) && $enabledNamespaces[$ns] )
		) {
			return;
		}

		$models = [];
		foreach ( $modelsConfig as $model => $modelConfig ) {
			// b/c
			// TODO: Remove it
			if ( !is_array( $modelConfig ) ) {
				$this->handleOld( $rc, $modelsConfig, $draftQualityNS );
				return;
			}

			$add = $this->checkModel( $rc, $modelConfig );
			if ( $add === true ) {
				$models[] = $model;
			}
		}

		$this->triggerJob( $rc, $models );
	}

	private function checkModel( RecentChange $rc, $config ) {
		if ( $config['enabled'] !== true ) {
			return false;
		}

		if ( isset( $config['types'] ) ) {
			$acceptedTypes = $config['types'];
		} else {
			$acceptedTypes = [ RC_EDIT, RC_NEW ];
		}
		if ( !in_array( $rc->getAttribute( 'rc_type' ), $acceptedTypes ) ) {
			return false;
		}

		$ns = $rc->getAttribute( 'rc_namespace' );
		if ( isset( $config['namespaces'] ) && !array_key_exists( $ns, $config['namespaces'] ) ) {
			return false;
		}

		if ( isset( $config['excludeBots'] ) && $config['excludeBots'] !== false &&
			$rc->getAttribute( 'rc_bot' )
		) {
			return false;
		}

		return true;
	}

	private function handleOld( RecentChange $rc, array $modelsConfig, array $draftQualityNS ) {
		$ns = $rc->getAttribute( 'rc_namespace' );
		$models = array_keys( array_filter( $modelsConfig ) );
		$rc_type = $rc->getAttribute( 'rc_type' );
		if ( $rc_type === RC_EDIT || $rc_type === RC_NEW ) {
			// Do not store draftquality data when it's not a new page in article or draft ns
			if ( $rc_type !== RC_NEW ||
				!( isset( $draftQualityNS[$ns] ) && $draftQualityNS[$ns] )
			) {
				$models = array_diff( $models, [ 'draftquality' ] );
			}

			$this->triggerJob( $rc, $models );
		}
	}

	private function triggerJob( RecentChange $rc, array $models ) {
		if ( $models === [] ) {
			return;
		}

		$revid = $rc->getAttribute( 'rc_this_oldid' );
		$this->logger->debug( 'Processing edit {revid}', [
			'revid' => $revid,
		] );
		$job = new FetchScoreJob( $rc->getTitle(), [
			'revid' => $revid,
			'originalRequest' => [
				'ip' => $this->request->getIP(),
				'userAgent' => $this->request->getHeader( 'User-Agent' ),
			],
			'models' => $models,
			'precache' => true,
		] );
		JobQueueGroup::singleton()->push( $job );
		$this->logger->debug( 'Job pushed for {revid}', [
			'revid' => $revid,
		] );
	}

}