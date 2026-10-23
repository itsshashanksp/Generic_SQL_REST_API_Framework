<?php

require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Resources/SqlResourceStatement.php';
require_once __DIR__ . '/../app/Resources/SqlResourceDiscovery.php';

function exampleLibraryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$examples = $root . '/examples';
$validator = new QueryRequestValidator();
$jsonFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($examples, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'json') $jsonFiles[] = $file->getPathname();
}
sort($jsonFiles, SORT_STRING);
exampleLibraryAssert($jsonFiles !== [], 'Backend API example library is empty.');
foreach ($jsonFiles as $path) {
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    exampleLibraryAssert(is_array($value) && !array_is_list($value), "Example is not a request object: {$path}.");
    $validator->validate($value);
}

$sqlRoot = $examples . '/sql-resources';
$sqlFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sqlRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'sql') {
        $path = $file->getPathname();
        SqlResourceStatement::analyze((string)file_get_contents($path));
        $sqlFiles[] = $path;
    }
}
exampleLibraryAssert(count($sqlFiles) >= 5, 'The generic SQL Resource catalog is incomplete.');

// The examples tree is outside the configured discovery root and therefore
// cannot become executable merely by being checked out.
$liveIds = (new SqlResourceDiscovery())->ids();
foreach ($sqlFiles as $path) {
    $relative = substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $sqlRoot)) + 1, -4);
    exampleLibraryAssert(!in_array('examples/' . $relative, $liveIds, true), 'An example SQL file entered live resource discovery.');
}
foreach (['Columns.sql', 'Procedures.sql', 'Schema.sql', 'Tables.sql', 'Views.sql'] as $internal) {
    exampleLibraryAssert(is_file($root . '/queries/system/' . $internal), "Internal SQL resource {$internal} is missing.");
}

echo "Backend example library tests passed.\n";
