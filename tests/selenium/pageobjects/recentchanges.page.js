const Page = require( 'wdio-mediawiki/Page' );

class RecentChangesPage extends Page {
	get activeFilters() { return browser.element( '.ores-damaging-filter' ); }

	open() {
		super.openTitle( 'Special:RecentChanges' );
	}
}

module.exports = new RecentChangesPage();
