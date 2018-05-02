var assert = require( 'assert' ),
	RecentChangesPage = require( '../pageobjects/recentchanges.page' );

describe( 'ORES', function () {

	it( 'filters are present', function () {
		RecentChangesPage.open();

		assert( RecentChangesPage.activeFilters.isExisting() );

	} );

} );
