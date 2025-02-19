$( () => {
	'use strict';

	const thresholds = mw.config.get( 'oresThresholds' ).damaging,
		// Levels must be descending from the worst to best.
		levels = [ 'verylikelybad', 'likelybad', 'maybebad' ],
		scores = mw.config.get( 'oresData' );

	if ( !document.querySelector( '.mw-changeslist, .mw-contributions-list' ) ) {
		// No changes list on this page
		return;
	}

	$( '[data-mw-revid]' ).each( function () {
		const revid = $( this ).attr( 'data-mw-revid' );
		if ( !scores[ revid ] ) {
			return;
		}
		const score = scores[ revid ].damaging;
		for ( let i = 0; i < levels.length; i++ ) {
			if ( score > thresholds[ levels[ i ] ] ) {
				// The following classes are used here:
				// * mw-changeslist-damaging-maybebad
				// * mw-changeslist-damaging-likelybad
				// * mw-changeslist-damaging-verylikelybad
				$( this ).addClass( 'mw-changeslist-damaging-' + levels[ i ] );
				// $(this) might be collapsed and invisible, in that case highlight
				// the group block as well we rely on the fact that more severe
				// classes have higher-specificity selectors
				// eslint-disable-next-line mediawiki/class-doc
				$( this ).parents( '.mw-enhanced-rc.mw-collapsible' )
					.addClass( 'damaging mw-changeslist-damaging-' + levels[ i ] );
				break;
			}
		}
	} );
} );
