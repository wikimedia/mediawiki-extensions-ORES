/* eslint-env node */
module.exports = function ( grunt ) {
	var conf = grunt.file.readJSON( 'extension.json' );

	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-stylelint' );

	grunt.initConfig( {
		banana: conf.MessagesDirs,
		stylelint: {
			all: [
				'modules/**/*.css',
				'modules/**/*.less'
			]
		}
	} );

	grunt.registerTask( 'test', [ 'stylelint', 'banana' ] );
};
