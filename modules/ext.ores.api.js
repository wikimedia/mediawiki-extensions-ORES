/**
 * @class ext.ores.api
 * @singleton
 */
( function () {
	'use strict';

	var config = mw.config.get( 'wgExtOresApiConfig' ),
		url = config.baseUrl + '/v' + config.apiVersion + '/scores/' + config.wikiId;

	/**
	 * Transform any parameters we support non-string shortcuts for
	 * to their string equivalant.
	 *
	 * @private
	 * @param {Object} parameters (modified in-place)
	 * @return {Object}
	 */
	function preprocessParameters( parameters ) {
		var key;
		for ( key in parameters ) {
			if ( Array.isArray( parameters[ key ] ) ) {
				parameters[ key ] = parameters[ key ].join( '|' );
			}
		}
		return parameters;
	}

	/**
	 * Make an API request to an ORES server.
	 *
	 *     var ores = require( 'ext.ores.api' );
	 *     ores.get( {
	 *         revids: [ 1234, 1235 ],
	 *         models: [ 'damaging', 'articlequality' ] // same as 'damaging|articlequality'
	 *     } ).then( function ( data ) {
	 *         console.log( data );
	 *     } );
	 *
	 * @param {Object} parameters
	 * @return {jQuery.Promise} Done: API response data, Fail: Error code.
	 */
	function get( parameters ) {
		var req = $.ajax( {
			url: url,
			data: preprocessParameters( parameters ),
			timeout: 30 * 1000, // 30 seconds
			dataType: 'json'
		} );

		req.fail( function ( code, details ) {
			if ( !( code === 'http' && details && details.textStatus === 'abort' ) ) {
				mw.log.warn( 'ORES API error:', code, details );

			}
		} );

		return req;
	}

	module.exports = { get: get };

}() );
