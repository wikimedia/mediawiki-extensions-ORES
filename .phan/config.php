<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Ignored to allow upgrading Phan, to be fixed later.
$cfg['suppress_issue_types'][] = 'MediaWikiNoIssetIfDefined';

return $cfg;
