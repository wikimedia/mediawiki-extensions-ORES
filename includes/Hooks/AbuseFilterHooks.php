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
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
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

	public function __construct(
		private RevisionLookup $revisionLookup,
		private Config $config,
		private UserIdentityLookup $userIdentityLookup,
		private UserNameUtils $userNameUtils,
		private VariablesManager $variablesManager,
		// TODO: When ORESService is removed, type $liftWingService as LiftWingService
		private ORESService|LiftWingService $liftWingService
	) {
	}

	/**
	 * Register the 'revertrisk_level' variable for use in AbuseFilter.
	 *
	 * @inheritDoc
	 */
	public function onAbuseFilter_generateTitleVars(
		VariableHolder $vars, Title $title, string $prefix, ?RecentChange $rc
	) {
		$vars->setLazyLoadVar( 'revertrisk_level', 'revertrisk-level', [
			'title' => $title,
			'rc' => $rc,
		] );
	}

	/**
	 * Handle computing the 'revertrisk_level' variable in AbuseFilter.
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
		if ( $method !== 'revertrisk-level' ) {
			// Different variable, nothing to do here.
			return true;
		}

		// In case we've switched off the integration, but there are still filters using the
		// revertrisk_score variable.
		if ( !$this->config->get( 'ORESRevertRiskAbuseFilterIntegrationEnabled' ) ) {
			return false;
		}

		$oresFilterThresholds = $this->config->get( 'OresFiltersThresholds' );
		$minThreshold = $oresFilterThresholds['revertrisklanguageagnostic']['revertrisk']['min'] ?? null;
		if ( !$minThreshold ) {
			// An RRLA threshold must have been set for this wiki for us to be able
			// to map the score into a level ("unknown" or "high").
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

		$userName = $this->variablesManager->getVar( $vars, 'user_name' )->toString();

		// If the performer is an IP user, it may not have an actor table record and looking it up would
		// always yield an unregistered UserIdentity anyways, so avoid going through UserIdentityLookup.
		// This can occur during pre-save edit stash requests from logged-out edits, for example (T402298).
		if ( $this->userNameUtils->isIP( $userName ) ) {
			$editor = new UserIdentityValue( 0, $userName );
		} else {
			$editor = $this->userIdentityLookup->getUserIdentityByName( $userName );
		}

		Assert::postcondition(
			$editor instanceof UserIdentity,
			"user_name variable is set to \"$userName\" but does not resolve to a UserIdentity"
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
		$score = $this->liftWingService->revertRiskPreSave( $editor, $data );

		$result = $score > $minThreshold ? 'high' : 'unknown';

		return false;
	}

	/**
	 * Add the 'revertrisk_level' variable to the list of known variables in AbuseFilter
	 *
	 * @param array &$builderValues
	 * @return true
	 */
	public function onAbuseFilter_builder( array &$builderValues ) {
		if ( $this->config->get( 'ORESRevertRiskAbuseFilterIntegrationEnabled' ) ) {
			$builderValues['vars']['revertrisk_level'] = 'revertrisk-level';
		}
		return true;
	}
}
