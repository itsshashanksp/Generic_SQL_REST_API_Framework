<?php

declare(strict_types=1);

define('API_REQUEST_STARTED', microtime(true));
define('API_REQUEST_ID', bin2hex(random_bytes(8)));
ob_start();
require_once __DIR__ . '/../core/ExceptionHandler.php';
ExceptionHandler::register('sqlparser');
header_remove('X-Powered-By');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
require_once __DIR__ . '/../app/Middleware/ApplicationRuntimeMiddleware.php';
(new ApplicationRuntimeMiddleware('sqlParser'))->handle([]);

if ($method !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Allow: GET, POST');
    require_once __DIR__ . '/src/SqlParserRequestHandler.php';
    $raw=(string)file_get_contents('php://input',false,null,0,200001);
    [$status,$payload]=(new SqlParserRequestHandler())->handle($method,$raw,(int)($_SERVER['CONTENT_LENGTH']??strlen($raw)));
    http_response_code($status);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (strtolower((string)(getenv('GENERIC_APP_ENV') ?: 'development')) !== 'production') {
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}
// Asset URLs are absolute from the mount path the web server reports in
// SCRIPT_NAME (e.g. /sqlparser/index.php under IIS), so they resolve with or
// without a trailing slash. Only plain path segments are accepted.
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$parserBase = str_ends_with($scriptName, '/index.php') ? substr($scriptName, 0, -strlen('/index.php')) : '';
if (preg_match('#^(?:/(?!\.\.?(?:/|$))[A-Za-z0-9._~-]+)*$#', $parserBase) !== 1) $parserBase = '';
$assetUrl = static fn (string $path): string => htmlspecialchars($parserBase . '/' . $path, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SQL → API JSON Generator</title><link rel="stylesheet" href="<?= $assetUrl('assets/css/app.css') ?>"></head>
<body><main>
<header><p class="eyebrow">Framework · Developer Tool</p><h1>SQL → API JSON Generator</h1><p>Parse SQL into the existing Universal API contract. SQL is analyzed only and never executed.</p></header>
<section class="grid"><article><label for="sql">SQL Input</label><textarea id="sql" spellcheck="false" placeholder="SELECT Item_Code, Item_Desc FROM ItemMasterTable"></textarea><div class="actions"><button id="parse">Parse SQL</button><button id="clear" class="secondary">Clear</button></div></article>
<article><label for="json">Generated API JSON</label><textarea id="json" spellcheck="false" readonly></textarea><div class="actions"><button id="copy">Copy JSON</button><button id="format" class="secondary">Format JSON</button></div></article></section>
<section class="analysis"><h2>Analysis / Result</h2><div id="status" class="status idle">Ready</div><dl id="analysis"></dl><ul id="messages"></ul></section>
</main><script src="<?= $assetUrl('assets/js/app.js') ?>"></script></body></html>
