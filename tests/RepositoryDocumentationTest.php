<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';

function repositoryDocumentationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$application = require $root . '/config/app.php';
$readme = (string)file_get_contents($root . '/README.md');
$changelog = (string)file_get_contents($root . '/CHANGELOG.md');
$roadmap = (string)file_get_contents($root . '/docs/Roadmap.md');
$aiGuide = (string)file_get_contents($root . '/docs/AI-Development-Guide.md');
$adminExample = json_decode((string)file_get_contents($root . '/config/admin.example.json'), true, 512, JSON_THROW_ON_ERROR);
$databaseStateExample = json_decode((string)file_get_contents($root . '/config/database-state.example.json'), true, 512, JSON_THROW_ON_ERROR);
$applicationRuntimeExample = json_decode((string)file_get_contents($root . '/config/application-runtime-state.example.json'), true, 512, JSON_THROW_ON_ERROR);

// config/app.php is the single version source; the release documents below
// must describe that version.
$version = $application['version'] ?? null;
repositoryDocumentationAssert(
    ($application['app_name'] ?? null) === 'Framework' && $version === '3.0.0',
    'Application identity does not represent the v3.0.0 release.'
);
repositoryDocumentationAssert(
    $adminExample === RuntimeConfiguration::adminDefaults()
        && $databaseStateExample === ['version' => 2, 'databases' => []],
    'Tracked runtime configuration examples do not match bootstrap defaults.'
);
repositoryDocumentationAssert(
    $applicationRuntimeExample === [
        'version' => 1,
        'generation' => 0,
        'services' => [
            'api' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
            'sqlParser' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
        ],
    ],
    'Tracked application runtime example does not match bootstrap defaults.'
);
repositoryDocumentationAssert(
    json_decode((string)file_get_contents($root . '/config/authorization.example.json'), true, 512, JSON_THROW_ON_ERROR)
        === RuntimeConfiguration::authorizationDefaults(),
    'Tracked authorization example does not match bootstrap defaults.'
);
// Releases appear newest first, the current version directly below [Unreleased].
preg_match_all('/^## \[([^\]]+)\](.*)$/m', $changelog, $releases);
repositoryDocumentationAssert(
    $releases[1] === ['Unreleased', $version, '2.1.0', '2.0.0', '1.0.0']
        && $releases[2] === ['', ' - 2026-10-09', '', ' - 2026-10-05', ' - 2026-07-27'],
    'Changelog release status is inconsistent.'
);
repositoryDocumentationAssert(
    str_starts_with($readme, "# Framework\n")
        && str_contains($readme, "| **v{$version}** | **Completed (2026-10-09) — current version** |")
        && str_contains($readme, '| v2.1.0 | Completed |')
        && !preg_match('/v[123]\.[01]\.0[^.\n]*unreleased/i', $readme)
        && preg_match('/Phase [1-4](?:\.[0-9]+)?\s+[—-].*implemented/i', $readme) !== 1,
    'README contains an incorrect release status or phase diary.'
);
// V3 is SQL Server only: no other engine is described as supported or planned.
foreach (['README.md' => $readme, 'docs/Roadmap.md' => $roadmap] as $document => $text) {
    repositoryDocumentationAssert(
        preg_match('/planned providers|(?:MySQL|PostgreSQL|MariaDB)[^.\n]*(?:supported|implemented)/i', $text) !== 1,
        "{$document} claims support for another database engine."
    );
}
repositoryDocumentationAssert(is_file($root . '/docs/Upgrading-to-V3.md'), 'The V2 to V3 upgrade guide is missing.');

// The roadmap separates completed, upcoming, and deferred work and records the
// real release state of each milestone.
foreach (['## Completed', '## Upcoming', '## Deferred'] as $section) {
    repositoryDocumentationAssert(
        preg_match('/^' . preg_quote($section, '/') . '$/m', $roadmap) === 1,
        "Roadmap is missing section: {$section}."
    );
}
foreach ([
    '| v1.0.0 | Core Generic SQL REST API Framework | Completed (2026-07-27) |',
    '| v2.0.0 | Platform expansion and security | Completed (2026-10-05) |',
    '| v2.1.0 | Security verification, operational hardening, and generic authorization | Completed |',
    "| v{$version} | Multi-database support for SQL Server | Completed (2026-10-09) |",
    '| v3.1 | Developer experience and API integration | Upcoming |',
] as $milestone) {
    repositoryDocumentationAssert(str_contains($roadmap, $milestone), "Roadmap does not record milestone: {$milestone}");
}
// v2.1.0 was followed directly by v3.0.0; there is no separate v2.2
// milestone or 2.2.0 development version.
foreach (['README.md' => $readme, 'CHANGELOG.md' => $changelog, 'docs/Roadmap.md' => $roadmap] as $document => $text) {
    repositoryDocumentationAssert(preg_match('/\b2\.2\.0\b|\bv2\.2\b/i', $text) !== 1, "{$document} describes a v2.2 milestone.");
}
repositoryDocumentationAssert(
    preg_match('/\bPhase [0-9]/', $roadmap) !== 1 && is_file($root . '/docs/Windows-IIS-Deployment.md'),
    'Roadmap contains a phase diary or the Windows deployment guide is missing.'
);

// Security documentation: one current model, one verification record with the
// complete findings register, and the penetration-test handoff.
foreach (['Security-Model.md', 'Security-Verification.md', 'Penetration-Test-Preparation.md', 'Windows-PHP-Runtime.sha256'] as $securityDocument) {
    repositoryDocumentationAssert(is_file($root . '/docs/security/' . $securityDocument), "Security document is missing: {$securityDocument}.");
}
$verification = (string)file_get_contents($root . '/docs/security/Security-Verification.md');
foreach (['Current status', 'Verification history', 'Findings register', 'Runtime dependencies', 'Deferred security work'] as $section) {
    repositoryDocumentationAssert(
        preg_match('/^## ' . preg_quote($section, '/') . '$/m', $verification) === 1,
        "Security verification is missing section: {$section}."
    );
}
foreach (['ST' => [1, 7, '%03d'], 'SSA' => [1, 20, '%02d'], 'AAPI' => [1, 9, '%02d'], 'DAST' => [1, 5, '%02d'], 'SAOH' => [1, 8, '%02d']] as $prefix => [$first, $last, $format]) {
    for ($number = $first; $number <= $last; $number++) {
        $finding = $prefix . '-' . sprintf($format, $number);
        repositoryDocumentationAssert(str_contains($verification, "| {$finding} |"), "Findings register is missing {$finding}.");
    }
}
repositoryDocumentationAssert(
    preg_match('/^\| ST-003 \|[^\n]*\*\*Open/m', $verification) === 1
        && preg_match('/^\| ST-004 \|[^\n]*\*\*Open/m', $verification) === 1,
    'Findings register does not record ST-003 and ST-004 as open.'
);
$verificationText = (string)preg_replace('/\s+/', ' ', $verification);
repositoryDocumentationAssert(
    str_contains($verificationText, 'no Composer, npm, vendored')
        && str_contains($verificationText, 'not a vulnerability scan')
        && str_contains($verificationText, 'external penetration test has not been performed')
        && !is_file($root . '/composer.json')
        && !is_file($root . '/package.json'),
    'Security verification dependency scope, limitations, or dependency inventory is inconsistent.'
);

foreach (['Filesystem health', 'Session health'] as $removedCheck) {
    repositoryDocumentationAssert(
        !str_contains($roadmap, $removedCheck),
        "Roadmap still lists the removed System Health check: {$removedCheck}."
    );
}

// Relative Markdown links and their heading anchors must resolve.
$markdownSlug = static fn (string $heading): string => str_replace(' ', '-',
    (string)preg_replace('/[^\p{L}\p{N} _-]/u', '', strtolower(trim($heading))));
$markdownAnchors = static function (string $path) use ($markdownSlug): array {
    preg_match_all('/^#{1,6}\s+(.+?)\s*#*$/m', (string)file_get_contents($path), $headings);
    return array_map($markdownSlug, $headings[1]);
};
foreach (array_merge([$root . '/README.md', $root . '/CHANGELOG.md', $root . '/CONTRIBUTING.md'], glob($root . '/docs/*.md') ?: [], glob($root . '/docs/security/*.md') ?: []) as $document) {
    preg_match_all('/\]\(([^)\s]*)\)/', (string)file_get_contents($document), $links);
    foreach ($links[1] as $link) {
        if ($link === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $link) === 1) continue;
        [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $targetPath = $target === '' ? $document : dirname($document) . '/' . rawurldecode($target);
        repositoryDocumentationAssert(file_exists($targetPath), basename($document) . " links to a missing file: {$link}");
        if ($anchor !== null && $anchor !== '' && str_ends_with($targetPath, '.md')) {
            repositoryDocumentationAssert(
                in_array($anchor, $markdownAnchors($targetPath), true),
                basename($document) . " links to a missing heading: {$link}"
            );
        }
    }
}
foreach (['request flow', 'X-API-Key', 'queries/system/', 'php -n tests/run.php'] as $guideMarker) {
    repositoryDocumentationAssert(
        str_contains(strtolower($aiGuide), strtolower($guideMarker)),
        "AI development guide is missing {$guideMarker}."
    );
}

foreach (['QueryCapabilityTest.php', 'CrudOperationsTest.php'] as $test) {
    repositoryDocumentationAssert(is_file($root . '/tests/' . $test), "Renamed test is missing: {$test}");
}
foreach ([
    'config/database.php',
    'app/Queries/MetadataQueries.php',
    'app/Controllers/DatabaseController.php',
    'app/Services/DatabaseService.php',
    'app/Repositories/DatabaseRepository.php',
    'queries/system/Databases.sql',
    'docs/SQL-Parser-Backend-Capability-Audit.md',
    'index.php',
    'docs/CHANGELOG.md',
    'docs/CONTRIBUTING.md',
    'docs/Introduction.md',
    'docs/Hosting.md',
    'docs/Production-Validation.md',
    'docs/Security-Testing.md',
    'docs/Set-Operations.md',
    'docs/Admin-Console-and-Configuration.md',
    'docs/Admin-Runtime-and-Features.md',
    'docs/API-Keys.md',
    'docs/Authentication-and-User-Management.md',
    'docs/Authorization-and-Roles.md',
    'docs/Audit-and-Security-Logging.md',
    'docs/Operational-Logging.md',
    'docs/Validation-and-Errors.md',
    'docs/Production-Error-Handling.md',
    'docs/CRUD.md',
    'docs/Write-Resource-Configuration.md',
    'docs/SQL-Resource-Configuration.md',
    'docs/SQL-Resource-Files.md',
    'docs/security/Authorization-API-Security-Inventory.md',
    'docs/security/DAST-Report.md',
    'docs/security/Static-Security-Analysis-Inventory.md',
    'docs/security/Security-Architecture-and-Operational-Hardening.md',
    'docs/security/Dependency-Security-Review.md',
    // v2.1.0 removed the application-specific registries.
    'config/query-sources.php',
    'config/write-resources.php',
    'config/routine-resources.php',
    'app/Resources/QuerySourcePolicy.php',
    'app/Resources/QuerySourceRegistry.php',
    'app/Resources/WriteResourceRegistry.php',
    'app/Resources/RoutineResourceRegistry.php',
    'tests/support/PermissiveQuerySourcePolicy.php',
] as $obsolete) {
    repositoryDocumentationAssert(!file_exists($root . '/' . $obsolete), "Obsolete file remains: {$obsolete}");
}
foreach (['Tables.sql', 'Columns.sql', 'Views.sql', 'Procedures.sql', 'Schema.sql'] as $metadataQuery) {
    repositoryDocumentationAssert(
        is_file($root . '/queries/system/' . $metadataQuery),
        "Runtime metadata query was removed: {$metadataQuery}"
    );
}

echo "Repository documentation and cleanup tests passed.\n";
