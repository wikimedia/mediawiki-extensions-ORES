( function ( mw, $ ) {
	'use strict';
	if ( !$( '.mw-changeslist, .mw-contributions-list' ).length ) {
		return;
	}
	var thresholds = mw.config.get( 'oresThresholds' ).damaging;
	var names = {};
	names[thresholds.verylikelybad] = 'verylikelybad';
	names[thresholds.likelybad] = 'likelybad';
	names[thresholds.maybebad] = 'maybebad';
	$( 'li.damaging' ).each( function () {
		var url = $( this ).children( 'a.mw-changeslist-diff' ).attr( 'href' );
		if ( !url ) {
			return true;
		}
		var reg = /\b(?:diff=|diff=prev&oldid=)(\d+)/;
		var res = reg.exec( url );
		if ( !res || !( res[1] in mw.config.get( 'oresData' ) ) ) {
			return true;
		}
		var score = mw.config.get( 'oresData' )[res[1]]['damaging'];
		var threshold = 0;
		for ( threshold in names ) {
			if ( score > threshold ) {
				$( this ).attr( 'data-ores-damaging', names[threshold] );
				break;
			}
		}
	} )
}( mediaWiki, jQuery ) );
