'use strict';
const Page = require( '../../../../../tests/selenium/pageobjects/page' );

class RecentChangesPage extends Page {

	get activeFilters() { return browser.element( '.ores-damaging-filter' ); }

	open() {
		super.open( 'Special:RecentChanges' );
	}
}
module.exports = new RecentChangesPage();
