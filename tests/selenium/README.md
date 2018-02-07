# Selenium tests

Please see tests/selenium/README.md file in mediawiki/core repository.

## Usage

Set up MediaWiki-Vagrant:

    cd mediawiki/vagrant
    vagrant up
    vagrant roles enable ores
    vagrant provision

Run both mediawiki/core and ORES tests from mediawiki/core folder:

    npm run selenium

To run only ORES tests in one terminal window or tab start Chromedriver:

    chromedriver --url-base=/wd/hub --port=4444

In another terminal tab or window go to mediawiki/core folder:

    ./node_modules/.bin/wdio tests/selenium/wdio.conf.js --spec extensions/ORES/tests/selenium/specs/*.js

Run only one ORES test file from mediawiki/core:

    ./node_modules/.bin/wdio tests/selenium/wdio.conf.js --spec extensions/ORES/tests/selenium/specs/filters.js
