const Page = require( 'wdio-mediawiki/Page' );

class RecentChangesPage extends Page {
	get activeFilters() { return $( '.ores-damaging-filter' ); }

	open() {
		super.openTitle( 'Special:RecentChanges' );
	}
}

module.exports = new RecentChangesPage();
