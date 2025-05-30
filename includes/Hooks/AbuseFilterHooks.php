<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace ORES\Hooks;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterBuilderHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterComputeVariableHook;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterGenerateTitleVarsHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use ORES\LiftWingService;
use ORES\ORESService;
use ORES\PreSaveRevisionData;
use RecentChange;
use Wikimedia\Assert\Assert;

/**
 * Hooks related to Extension:AbuseFilter
 */
class AbuseFilterHooks implements
	AbuseFilterGenerateTitleVarsHook,
	AbuseFilterComputeVariableHook,
	AbuseFilterBuilderHook
{

	// TODO: When ORESService is removed, type $liftWingService as LiftWingService
	/** @var ORESService|LiftWingService */
	private $liftWingService;
	private RevisionLookup $revisionLookup;
	private Config $config;
	private VariablesManager $variablesManager;
	private UserIdentityLookup $userIdentityLookup;

	/**
	 * @param RevisionLookup $revisionLookup
	 * @param Config $config
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param VariablesManager $variablesManager
	 * @param ORESService|LiftWingService $liftWingService
	 */
	public function __construct(
		RevisionLookup $revisionLookup,
		Config $config,
		UserIdentityLookup $userIdentityLookup,
		VariablesManager $variablesManager,
		$liftWingService
	) {
		$this->revisionLookup = $revisionLookup;
		$this->config = $config;
		$this->liftWingService = $liftWingService;
		$this->variablesManager = $variablesManager;
		$this->userIdentityLookup = $userIdentityLookup;
	}

	/**
	 * Register the 'revertrisk_score' variable for use in AbuseFilter.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilter_generateTitleVars(
		VariableHolder $vars, Title $title, string $prefix, ?RecentChange $rc
	) {
		$vars->setLazyLoadVar( 'revertrisk_score', 'revertrisk-score', [
			'title' => $title,
			'rc' => $rc,
		] );
	}

	/**
	 * Handle computing the 'revertrisk-score' variable in AbuseFilter.
	 *
	 * @param string $method The name of the lazy computation method being invoked.
	 * @param VariableHolder $vars
	 * @param array $parameters The parameters passed to the lazy computation method.
	 * @param string|null &$result The result of the computation, if applicable.
	 * @return bool `true` if other methods should be invoked for the variable being computed,
	 * `false` if this method has fully handled the variable and no further processing is needed.
	 */
	public function onAbuseFilter_computeVariable(
		string $method, VariableHolder $vars, array $parameters, ?string &$result
	): bool {
		if ( $method !== 'revertrisk-score' ) {
			// Different variable, nothing to do here.
			return true;
		}

		// In case we've switched off the integration, but there are still filters using the
		// revertrisk_score variable.
		if ( !$this->config->get( 'ORESRevertRiskAbuseFilterIntegrationEnabled' ) ) {
			return false;
		}

		if ( !( $this->liftWingService instanceof LiftWingService ) ) {
			return false;
		}

		// Only edits can be evaluated for revert risk.
		$action = $vars->getComputedVariable( 'action' )->toString();
		if ( $action !== 'edit' ) {
			return false;
		}

		/** @var RecentChange|null $rc */
		$rc = $parameters['rc'];
		/** @var Title $title */
		$title = $parameters['title'];

		// Determine the parent revision ID from the passed RecentChange object
		// if we're inspecting a historical edit.
		if ( $rc instanceof RecentChange ) {
			$parentRevId = $rc->getAttribute( 'rc_last_oldid' );
			$parentRevision = $parentRevId ? $this->revisionLookup->getRevisionById( $parentRevId ) : false;
		} else {
			$parentRevision = $this->revisionLookup->getKnownCurrentRevision(
				$title,
				$title->getLatestRevID()
			);
		}

		// No parent revision implies this is a page creation, which we can't evaluate for revert risk.
		if ( !$parentRevision ) {
			return false;
		}

		$firstRevision = $this->revisionLookup->getFirstRevision( $title );

		$editor = $this->userIdentityLookup->getUserIdentityByName(
			$this->variablesManager->getVar( $vars, 'user_name' )->toString()
		);

		Assert::postcondition(
			$editor instanceof UserIdentity,
			'user_name variable must resolve to a UserIdentity'
		);

		$data = new PreSaveRevisionData(
			$parentRevision,
			$editor,
			$title,
			$this->variablesManager->getVar( $vars, 'new_wikitext' )->toString(),
			$this->variablesManager->getVar( $vars, 'summary' )->toString(),
			$this->variablesManager->getVar( $vars, 'timestamp' )->toNative(),
			$firstRevision->getTimestamp(),
			$title->getPageLanguage()->getCode(),
			$title->getPrefixedText()
		);
		$result = $this->liftWingService->revertRiskPreSave( $editor, $data );

		return false;
	}

	/**
	 * Add the 'revertrisk_score' variable to the list of known variables in AbuseFilter
	 *
	 * @param array &$builderValues
	 * @return true
	 */
	public function onAbuseFilter_builder( array &$builderValues ) {
		if ( $this->config->get( 'ORESRevertRiskAbuseFilterIntegrationEnabled' ) ) {
			$builderValues['vars']['revertrisk_score'] = 'revertrisk-score';
		}
		return true;
	}
}
