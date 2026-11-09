<?php

/**
 * Removes only the processed files derived from the jw_watermark test fixtures,
 * so a run starts with a cold cache. Everything else in the installation's
 * processed-file cache is left alone.
 */
$root = rtrim(str_replace('\\', '/', $argv[1]), '/');
$classLoader = require $root . '/vendor/autoload.php';
chdir($root . '/public');

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

$pool = GeneralUtility::makeInstance(ConnectionPool::class);

// sys_file uids of the fixtures
$files = $pool->getConnectionForTable('sys_file')->executeQuery(
    "SELECT uid, identifier FROM sys_file WHERE identifier LIKE '/jw_watermark_test/%'"
)->fetchAllAssociative();

if ($files === []) {
    echo "  no fixture records indexed yet — nothing to reset\n";
    exit(0);
}

$uids = array_column($files, 'uid');
$conn = $pool->getConnectionForTable('sys_file_processedfile');
$rows = $conn->executeQuery(
    'SELECT uid, identifier FROM sys_file_processedfile WHERE original IN (' . implode(',', $uids) . ')'
)->fetchAllAssociative();

$deletedFiles = 0;
foreach ($rows as $r) {
    $p = $root . '/public/fileadmin' . $r['identifier'];
    if ($r['identifier'] !== '' && is_file($p) && unlink($p)) {
        $deletedFiles++;
    }
}
$deletedRows = $rows === [] ? 0 : $conn->executeStatement(
    'DELETE FROM sys_file_processedfile WHERE original IN (' . implode(',', $uids) . ')'
);

printf(
    "  fixtures indexed: %d, processed records removed: %d, files removed: %d\n",
    count($files),
    $deletedRows,
    $deletedFiles
);
