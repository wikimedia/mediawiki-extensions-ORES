( function () {
	/**
	 * @class OresApi
	 */

	/**
	 * @property {Object} defaultOptions Default options for #ajax calls. Can be overridden by passing
	 *     `options` to OresApi constructor.
	 * @property {Object} defaultOptions.parameters Default query parameters for API requests.
	 * @property {Object} defaultOptions.ajax Default options for jQuery#ajax.
	 * @private
	 */
	var defaultOptions = {
			parameters: {
				format: 'json'
			},
			ajax: {
				timeout: 30 * 1000, // 30 seconds
				dataType: 'json',
				type: 'GET'
			}
		},
		OresApi;

	/**
	 * Constructor to create an object to interact with the API of an ORES server.
	 * OresApi objects represent the API of a particular ORES server.
	 *
	 *     var ores = require('ext.ores.api');
	 *     ores.get( {
	 *         revids: [1234, 1235],
	 *         models: [ 'damaging', 'articlequality' ] // same effect as 'damaging|articlequality'
	 *     } ).done( function ( data ) {
	 *         console.log( data );
	 *     } );
	 *
	 * @constructor
	 * @param {Object} config configuration of the ores service.
	 */
	OresApi = function ( config ) {
		var defaults = {};

		defaults.parameters = $.extend( {}, defaultOptions.parameters, defaults.parameters );
		defaults.ajax = $.extend( {}, defaultOptions.ajax, defaults.ajax );
		defaults.ajax.url = config.baseUrl + '/v' + config.apiVersion + '/scores/' + config.wikiId;

		this.defaults = defaults;
		this.requests = [];
	};

	OresApi.prototype = {
		/**
		 * Massage parameters from the nice format we accept into a format suitable for the API.
		 *
		 * @private
		 * @param {Object} parameters (modified in-place)
		 */
		preprocessParameters: function ( parameters ) {
			var key;
			for ( key in parameters ) {
				if ( Array.isArray( parameters[ key ] ) ) {
					parameters[ key ] = parameters[ key ].join( '|' );
				} else if ( parameters[ key ] === false || parameters[ key ] === undefined ) {
					// Boolean values are only false when not given at all
					delete parameters[ key ];
				}
			}
		},

		/**
		 * Perform the API call.
		 *
		 * @param {Object} parameters
		 * @return {jQuery.Promise} Done: API response data and the jqXHR object.
		 *  Fail: Error code
		 */
		get: function ( parameters ) {
			var requestIndex,
				api = this,
				apiDeferred = $.Deferred(),
				xhr,
				ajaxOptions;

			parameters = $.extend( {}, this.defaults.parameters, parameters );
			ajaxOptions = $.extend( {}, this.defaults.ajax );

			this.preprocessParameters( parameters );

			ajaxOptions.data = $.param( parameters );

			xhr = $.ajax( ajaxOptions )
				.done( function ( result, textStatus, jqXHR ) {
					var code;
					if ( result.error ) {
						code = result.error.code === undefined ? 'unknown' : result.error.code;
						apiDeferred.reject( code, result, result, jqXHR );
					} else {
						apiDeferred.resolve( result, jqXHR );
					}
				} );

			requestIndex = this.requests.length;
			this.requests.push( xhr );
			xhr.always( function () {
				api.requests[ requestIndex ] = null;
			} );
			return apiDeferred.promise( { abort: xhr.abort } ).fail( function ( code, details ) {
				if ( !( code === 'http' && details && details.textStatus === 'abort' ) ) {
					mw.log( 'OresApi error: ', code, details );
				}
			} );
		}
	};

	module.exports = new OresApi( {}, mw.config.get( 'wgExtOresApiConfig' ) );

}() );
