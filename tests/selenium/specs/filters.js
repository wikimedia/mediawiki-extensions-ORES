var assert = require( 'assert' ),
	RecentChangesPage = require( '../pageobjects/recentchanges.page' );

describe( 'ORES', function () {
	// Broken: T198201
	it.skip( 'filters are present', function () {
		RecentChangesPage.open();

		assert( RecentChangesPage.activeFilters.isExisting() );

	} );

} );
