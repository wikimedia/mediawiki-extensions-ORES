<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'../../extensions/AbuseFilter',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/AbuseFilter',
	]
);

// ORES services throw exceptions which callers are expected to catch, so @throws is useful
$cfg['warn_about_undocumented_throw_statements'] = true;
$cfg['warn_about_undocumented_exceptions_thrown_by_invoked_functions'] = true;
$cfg['exception_classes_with_optional_throws_phpdoc'] = [
	'DomainException',
	'InvalidArgumentException',
	'MediaWiki\Api\ApiUsageException',
	'MediaWiki\Config\ConfigException',
	'MediaWiki\Revision\RevisionAccessException',
	'UnexpectedValueException',
];
return $cfg;
