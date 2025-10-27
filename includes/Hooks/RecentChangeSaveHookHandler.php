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

use MediaWiki\MediaWikiServices;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Request\WebRequest;
use ORES\Services\FetchScoreJob;
use Psr\Log\LoggerInterface;

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
	 * @param RecentChange $rc
	 * @param array[] $modelsConfig
	 * @param bool $excludeBots
	 * @param bool[] $enabledNamespaces
	 */
	public function handle(
		RecentChange $rc,
		array $modelsConfig,
		$excludeBots,
		array $enabledNamespaces
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
			$add = $this->checkModel( $rc, $modelConfig );
			if ( $add ) {
				$models[] = $model;
			}
		}

		( new HookRunner( MediaWikiServices::getInstance()->getHookContainer() ) )->onORESCheckModels( $rc, $models );

		$this->triggerJob( $rc, $models );
	}

	private function checkModel( RecentChange $rc, array $config ): bool {
		if ( $config['enabled'] !== true ) {
			return false;
		}

		if ( isset( $config['types'] ) ) {
			wfDeprecatedMsg(
				'Configuring ORES models with "types" is deprecated, use "sources" instead',
				'1.45',
				'ORES'
			);
			if ( !in_array( $rc->getAttribute( 'rc_type' ), $config['types'] ) ) {
				return false;
			}
		} else {
			$acceptedSources = $config['sources'] ?? [ RecentChange::SRC_EDIT, RecentChange::SRC_NEW ];
			if ( !in_array( $rc->getAttribute( 'rc_source' ), $acceptedSources ) ) {
				return false;
			}
		}

		$ns = $rc->getAttribute( 'rc_namespace' );
		if ( isset( $config['namespaces'] ) && !in_array( $ns, $config['namespaces'] ) ) {
			return false;
		}

		if ( isset( $config['excludeBots'] ) && $config['excludeBots'] !== false &&
			$rc->getAttribute( 'rc_bot' )
		) {
			return false;
		}

		return true;
	}

	private function triggerJob( RecentChange $rc, array $models ) {
		if ( $models === [] ) {
			return;
		}

		$revid = $rc->getAttribute( 'rc_this_oldid' );
		$this->logger->debug( 'Processing edit {revid}', [
			'revid' => $revid,
		] );
		$ua = $this->request->getHeader( 'User-Agent' );
		$ua = $ua !== false ? $this->request->normalizeUnicode( $ua ) : false;
		$job = new FetchScoreJob( $rc->getTitle(), [
			'revid' => $revid,
			'originalRequest' => [
				'ip' => $this->request->getIP(),
				'userAgent' => $ua,
			],
			'models' => $models,
			'precache' => true,
		] );
		MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush( $job );
		$this->logger->debug( 'Job pushed for {revid}', [
			'revid' => $revid,
		] );
	}

}
