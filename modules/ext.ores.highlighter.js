( function ( mw, $ ) {
	'use strict';
	$( function () {
		var $changesList = $( '.mw-changeslist, .mw-contributions-list' ),
			thresholds = mw.config.get( 'oresThresholds' ).damaging,
			levels = [ 'verylikelybad', 'likelybad', 'maybebad' ],
			scores = mw.config.get( 'oresData' );
		if ( !$changesList.length ) {
			return;
		}

		$( 'li.damaging' ).each( function () {
			var i, revid, score,
				$link = $( this ).find( 'a.mw-changeslist-diff' ),
				href = $link.prop( 'href' ),
				uri = new mw.Uri( href );
			if ( !href ) {
				return;
			}
			// URI looks like either diff=prev&oldid=R, or diff=R&oldid=P, and we want R
			revid = uri.query.diff === 'prev' ? uri.query.oldid : uri.query.diff;
			if ( !scores[ revid ] ) {
				return;
			}
			score = scores[ revid ].damaging;
			for ( i = 0; i < levels.length; i++ ) {
				if ( score > thresholds[ levels[ i ] ] ) {
					$( this ).attr( 'data-ores-damaging', levels[ i ] );
					break;
				}
			}
		} );
	} );

}( mediaWiki, jQuery ) );
