<?php

/**
 * php-cs-fixer configuration for jw_watermark.
 *
 * Based on the extension template shipped with typo3/coding-standards, which
 * applies @PER-CS3x0 (the successor to PSR-12) plus the TYPO3 rule set.
 *
 * Run it with:
 *   vendor/bin/php-cs-fixer fix --dry-run --diff   # show what would change
 *   vendor/bin/php-cs-fixer fix                    # apply
 *
 * No header_comment rule is configured on purpose: typo3/coding-standards
 * defaults to a GPL header, while this extension is MIT licensed.
 */

$config = \TYPO3\CodingStandards\CsFixerConfig::create();

$config->getFinder()
    ->in(__DIR__)
    ->exclude(['vendor', 'build', 'public'])
;

return $config;
