<?php

namespace ORES\Specials;

use MediaWiki\Html\TemplateParser;
use MediaWiki\SpecialPage\SpecialPage;
use ORES\Storage\ModelLookup;
use ORES\Storage\ThresholdLookup;

class SpecialORESModels extends SpecialPage {

	/** @var ModelLookup */
	private $modelLookup;

	/** @var ThresholdLookup */
	private $thresholdLookup;

	public function __construct( ModelLookup $modelLookup, ThresholdLookup $thresholdLookup ) {
		parent::__construct( 'ORESModels' );
		$this->modelLookup = $modelLookup;
		$this->thresholdLookup = $thresholdLookup;
	}

	public function execute( $subPage = null ) {
		parent::execute( $subPage );
		$this->addHelpLink( 'ORES' );

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
			];
		}

		$templateParser = new TemplateParser( dirname( __DIR__ ) . '/templates' );
		$this->getOutput()->addHTML( $templateParser->processTemplate(
			'SpecialORESModels',
			[
				'models' => $models,
				'header-filter' => $this->msg( 'ores-specialoresmodels-header-filter' )->text(),
				'header-thresholdrange' => $this->msg( 'ores-specialoresmodels-header-thresholdrange' )->text(),
			 ]
		) );
		$this->getOutput()->addModuleStyles( 'ext.ores.styles' );
	}

	/**
	 * @param string $modelName
	 * @return array|false
	 */
	private function getFilterData( $modelName ) {
		$thresholdConfig = $this->getConfig()->get( 'OresFiltersThresholds' );
		$thresholdData = $this->thresholdLookup->getRawThresholdData( $modelName );
		$thresholds = $this->thresholdLookup->getThresholds( $modelName );
		if ( $thresholds === [] || $thresholdData === false ) {
			return false;
		}
		if ( !in_array( $modelName, [ 'damaging', 'goodfaith', 'revertrisklanguageagnostic' ] ) ) {
			return false;
		}

		$filters = [];
		foreach ( $thresholdConfig[$modelName] as $filterName => $filterMinMax ) {
			if ( $filterMinMax === false ) {
				continue;
			}

			$min = isset( $thresholds[$filterName]['min'] ) ?
				$this->getLanguage()->formatNum( $thresholds[$filterName]['min'] ) :
				'???';
			$max = isset( $thresholds[$filterName]['max'] ) ?
				$this->getLanguage()->formatNum( $thresholds[$filterName]['max'] ) :
				'???';

			$filters[] = [
				'name' => $filterName,
				'label' => $this->getFilterLabel( $modelName, $filterName ),
				'threshold-min' => $min,
				'threshold-max' => $max,
			];
		}
		// Sort the filters we know about in the proper order, put ones we don't know about at the end
		uasort( $filters, static function ( $a, $b ) {
			$knownFilters = [ 'likelygood', 'maybebad', 'likelybad', 'verylikelybad' ];
			$aIndex = array_search( $a['name'], $knownFilters );
			$bIndex = array_search( $b['name'], $knownFilters );
			return ( $aIndex !== false ? $aIndex : INF ) <=> ( $bIndex !== false ? $bIndex : INF );
		} );
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
