( function ( mw, $ ) {
	'use strict';
	if ( !$( '.mw-changeslist, .mw-contributions-list' ).length ) {
		return;
	}
	var thresholds = mw.config.get( 'oresThresholds' ).damaging;
	var names = {};
	names[thresholds.softest] = 'softest';
	names[thresholds.soft] = 'soft';
	names[thresholds.hard] = 'hard';
	$( 'li.damaging' ).each( function () {
		var url = $( this ).children( 'a' ).attr( 'href' );
		if ( !url ) {
			return true;
		}
		var reg = /\bdiff=(\d+)/;
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

