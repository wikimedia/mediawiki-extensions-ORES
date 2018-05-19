<?php

namespace ORES;

use TemplateParser;
use ORES\Storage\ModelLookup;

class SpecialORESModels extends \SpecialPage {

	public function __construct( ModelLookup $modelLookup, ThresholdLookup $thresholdLookup ) {
		parent::__construct( 'ORESModels' );
		$this->modelLookup = $modelLookup;
		$this->thresholdLookup = $thresholdLookup;
	}

	public static function newFromGlobalState() {
		return new SpecialORESModels(
			ORESServices::getModelLookup(),
			ORESServices::getThresholdLookup()
		);
	}

	public function execute( $subPage = null ) {
		parent::execute( $subPage );

		// TemplateParser does not currently support accessing variables from the parent scope,
		// so these have to be passed to each model separately (see T203209)
		$headerMessages = [
			'header-filter' => $this->msg( 'ores-specialoresmodels-header-filter' )->text(),
			'header-precision' => $this->msg( 'ores-specialoresmodels-header-precision' )->text(),
			'header-recall' => $this->msg( 'ores-specialoresmodels-header-recall' )->text(),
			'header-thresholdrange' => $this->msg( 'ores-specialoresmodels-header-thresholdrange' )->text(),
		];
		$models = [];
		foreach ( $this->modelLookup->getModels() as $modelName => $modelData ) {
			$filters = $this->getFilterData( $modelName );
			if ( $filters === false ) {
				continue;
			}

			$models[] = [
				'model' => $modelName,
				'title' => $this->msg( "ores-rcfilters-$modelName-title" )->text(),
				'filters' => $filters
			] + $headerMessages;
		}

		$templateParser = new TemplateParser( __DIR__ . '/templates' );
		$this->getOutput()->addHTML( $templateParser->processTemplate(
			'SpecialORESModels',
			[ 'models' => $models ]
		) );
		$this->getOutput()->addModuleStyles( 'ext.ores.specialoresmodels.styles' );
	}

	private function getFilterData( $modelName ) {
		$thresholdConfig = $this->getConfig()->get( 'OresFiltersThresholds' );
		$thresholdData = $this->thresholdLookup->getRawThresholdData( $modelName );
		$thresholds = $this->thresholdLookup->getThresholds( $modelName );
		if ( $thresholds === [] ) {
			return false;
		}
		if ( !isset( $thresholdData['true'] ) || !isset( $thresholdData['false'] ) ) {
			// wp10 has threshold settings that aren't true/false-based; skip it
			return false;
		}

		$filters = [];
		foreach ( $thresholdConfig[$modelName] as $filterName => $filterMinMax ) {
			if ( $filterMinMax === false ) {
				continue;
			}

			$relevantStat = is_string( $filterMinMax['min'] ) ? $filterMinMax['min'] : $filterMinMax['max'];
			$outcome = is_string( $filterMinMax['min'] ) ? 'true' : 'false';
			$statInfo = $thresholdData[$outcome][$relevantStat];
			$filters[] = [
				'name' => $filterName,
				'label' => $this->getFilterLabel( $modelName, $filterName ),
				'precision' => $this->msg( 'percent' )->numParams( $statInfo['precision'] * 100 ),
				'recall' => $this->msg( 'percent' )->numParams( $statInfo['recall'] * 100 ),
				'threshold-min' => $this->getLanguage()->formatNum( $thresholds[$filterName]['min'] ),
				'threshold-max' => $this->getLanguage()->formatNum( $thresholds[$filterName]['max'] )
			];
		}
		return $filters;
	}

	/**
	 * Get the UI label for a filter as it appears in RecentChanges.
	 * @param string $modelName 'damaging' or 'goodfaith'
	 * @param string $filterName 'likelygood', 'maybebad', 'likelybad' or 'verylikelybad'
	 * @return string Filter label (plain text)
	 */
	private function getFilterLabel( $modelName, $filterName ) {
		// TODO factor this out in ChangesListHooksHandler
		$special = [
			'goodfaith' => [
				'likelygood' => 'ores-rcfilters-goodfaith-good-label',
				'likelybad' => 'ores-rcfilters-goodfaith-bad-label',
			]
		];
		$msgKey = $special[$modelName][$filterName] ?? "ores-rcfilters-$modelName-$filterName-label";
		return $this->msg( $msgKey )->text();
	}

}
