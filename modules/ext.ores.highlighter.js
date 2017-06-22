( function ( mw, $ ) {
	'use strict';

	$( function () {
		var $changesList = $( '.mw-changeslist, .mw-contributions-list' ),
			thresholds = mw.config.get( 'oresThresholds' ).damaging,
			// Levels must be descending from the worst to best.
			levels = [ 'verylikelybad', 'likelybad', 'maybebad' ],
			scores = mw.config.get( 'oresData' );
		if ( !$changesList.length ) {
			return;
		}

		$( '[data-mw-revid]' ).each( function () {
			var i, revid, score;
			revid = $( this ).attr( 'data-mw-revid' );
			if ( !scores[ revid ] ) {
				return;
			}
			score = scores[ revid ].damaging;
			for ( i = 0; i < levels.length; i++ ) {
				if ( score > thresholds[ levels[ i ] ] ) {
					$( this ).addClass( 'mw-changeslist-damaging-' + levels[ i ] );
					// $(this) might be collapsed and invisible, in that case highlight the group block as well
					// we rely on the fact that more severe classes have higher-specificity selectors
					$( this ).parents( '.mw-enhanced-rc.mw-collapsible' )
						.addClass( 'damaging mw-changeslist-damaging-' + levels[ i ] );
					break;
				}
			}
		} );
	} );

}( mediaWiki, jQuery ) );
