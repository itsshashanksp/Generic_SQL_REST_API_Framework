<?php

define('API_REQUEST_STARTED', microtime(true));
define('API_REQUEST_ID', bin2hex(random_bytes(8)));
ob_start();

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';
ExceptionHandler::register('api');
require_once __DIR__ . '/../app/Security/SecurityConfiguration.php';
require_once __DIR__ . '/../app/Http/RequestBodyReader.php';

if (SecurityConfiguration::isProduction()) {
    ini_set('display_errors', '0');
}

$allowed_origins = SecurityConfiguration::allowedOrigins();

if (isset($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins, true)) {
    Response::error('Origin is not allowed.', 403, 'CORS_ORIGIN_DENIED');
}
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    if (SecurityConfiguration::corsCredentialsEnabled()) {
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Expose-Headers: X-CSRF-Token');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: ' . implode(', ', SecurityConfiguration::corsAllowedMethods()));
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key');
header('Content-Type: application/json');
header_remove('X-Powered-By');
header('Cache-Control: no-store');
if (!SecurityConfiguration::isProduction()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

// Handle browser preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405, 'METHOD_NOT_ALLOWED');
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    Response::error('Content-Type must be application/json.', 415, 'UNSUPPORTED_MEDIA_TYPE');
}

require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/FrontendUserAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Middleware/LoggingMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Middleware/ApiRateLimitMiddleware.php';
require_once __DIR__ . '/../app/Middleware/ApplicationRuntimeMiddleware.php';
require_once __DIR__ . '/../core/Validator.php';
require_once __DIR__ . '/../app/Controllers/MetadataController.php';
require_once __DIR__ . '/../app/Controllers/QueryController.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Database/DatabaseDirectory.php';
require_once __DIR__ . '/../app/Authorization/AccessScope/AccessScopeGate.php';
require_once __DIR__ . '/../app/Requests/SetupRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/SetupController.php';
require_once __DIR__ . '/../app/Requests/AuthRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Requests/FrontendUserRequestValidator.php';
require_once __DIR__ . '/../app/Controllers/FrontendUserController.php';

// Read Request Body
$publicRequest = json_decode(RequestBodyReader::read(), true);

// Validate JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    Response::error('Invalid JSON request.', 400, 'INVALID_JSON');
}
if (!is_array($publicRequest)) {
    Response::error(
        'Invalid request.',
        400,
        'INVALID_REQUEST',
        [['path' => '', 'message' => 'Request body must be a JSON object.']]
    );
}
if (array_key_exists('action', $publicRequest) && !is_string($publicRequest['action'])) {
    Response::error('Invalid request.', 400, 'INVALID_REQUEST', [['path' => 'action', 'message' => 'Action must be a string.']]);
}
Response::setRequestContext(['action' => is_string($publicRequest['action'] ?? null) ? $publicRequest['action'] : null]);
(new LoggingMiddleware())->handle($publicRequest);

// First-run setup, backend identities, API keys, and backend roles are
// administered only through the loopback Admin API (admin/api.php).
$setupActions = ['setup.status'];
$authActions = ['auth.csrf', 'auth.login', 'auth.session', 'auth.logout'];
$frontendUserActions = [
    'auth.frontendUsers.list','auth.frontendUsers.create','auth.frontendUsers.update',
    'auth.frontendUsers.enable','auth.frontendUsers.disable','auth.frontendUsers.delete',
    'auth.frontendUsers.changePassword','auth.frontendUsers.assignRole',
];
$publicAuthenticationActions = array_merge($setupActions, $authActions);
unset($_SERVER['GENERIC_AUTH_PROVIDER']);
$requestedAction = (string)($publicRequest['action'] ?? '');
if (str_starts_with($requestedAction, 'admin.')
    || in_array($requestedAction, ['setup.createAdmin', 'auth.roles.list'], true)
    || str_starts_with($requestedAction, 'auth.users.')
    || str_starts_with($requestedAction, 'auth.apiKeys.')) {
    Response::error('Not found.', 404, 'NOT_FOUND');
}
(new ApplicationRuntimeMiddleware('api'))->handle($publicRequest);
$authentication = new AuthenticationMiddleware(true, $publicAuthenticationActions);
$authentication->handle($publicRequest);
(new ApiRateLimitMiddleware())->handle($publicRequest);
(new FrontendUserAuthorizationMiddleware($frontendUserActions))->handle($publicRequest);
(new CsrfProtectionMiddleware())->handle($publicRequest);

if (in_array($publicRequest['action'] ?? null, $setupActions, true)) {
    $request = (new SetupRequestValidator())->validate($publicRequest);
    Response::setRequestContext(['action' => $request['action']]);
    $controller = new SetupController();
    $controller->status($request);
}

if (in_array($publicRequest['action'] ?? null, $authActions, true)) {
    $request = (new AuthRequestValidator())->validate($publicRequest);
    Response::setRequestContext(['action' => $request['action']]);
    $controller = new AuthController();
    if ($request['action'] === 'auth.csrf') {
        $controller->csrf($request);
    }
    if ($request['action'] === 'auth.login') {
        $controller->login($request);
    }
    if ($request['action'] === 'auth.session') {
        $controller->session($request);
    }
    $controller->logout($request);
}

if (in_array($publicRequest['action'] ?? null,$frontendUserActions,true)){$request=(new FrontendUserRequestValidator())->validate($publicRequest);Response::setRequestContext(['action'=>$request['action']]);(new FrontendUserController())->dispatch($request);}

(new AuthorizationMiddleware())->handle($publicRequest);
// Access scopes, phase 0: before the availability gate can answer for a
// database outside the caller's scope.
$accessScope = AccessScopeGate::beforeAvailability($publicRequest);
(new DatabaseAvailabilityMiddleware())->handle($publicRequest);

$phaseStarted = microtime(true);
$validator = new QueryRequestValidator();
$validator->validate($publicRequest);
(new Logger())->timing('validation', (microtime(true) - $phaseStarted) * 1000, [
    'action' => $publicRequest['action'] ?? null,
]);

$phaseStarted = microtime(true);
$normalizer = new QueryRequestNormalizer();
$request = $normalizer->normalize($publicRequest);
Response::setRequestContext($request);
(new Logger())->timing('normalization', (microtime(true) - $phaseStarted) * 1000, [
    'action' => $publicRequest['action'] ?? null,
]);

// Access scopes, phase 1 (see AccessScopeGate): before planning.
AccessScopeGate::beforePlanning($publicRequest, authorizer: $accessScope);

// Database planning: references → registry contexts → access policy → plan.
// The database listing reads the registry only: no plan, no connection.
$phaseStarted = microtime(true);
if (!DatabaseDirectory::isRegistryOnly($publicRequest['action'] ?? null)) {
    DatabaseQueryPlanContext::set((new DatabaseQueryPlanner())->plan($publicRequest, PrincipalContext::current()));
}
(new Logger())->timing('database_planning', (microtime(true) - $phaseStarted) * 1000, [
    'action' => $publicRequest['action'] ?? null,
]);

// Access scopes, phase 2: after planning, before any query runs.
AccessScopeGate::afterPlanning($publicRequest, DatabaseQueryPlanContext::current());

// Validate Controller
Validator::required($request, [
    'controller',
    'action'
]);

// Controller Name
$controller = ucfirst($request['controller']) . "Controller";

// Action Name
$action = $request['action'];

// Controller File
$controllerFile = __DIR__ . "/../app/Controllers/" . $controller . ".php";

// Check Controller Exists
if (!file_exists($controllerFile)) {
    Response::error("Controller Not Found", 404);
}

// Load Controller
require_once $controllerFile;

// Create Controller Object
$instance = new $controller();

// Check Action Exists
if (!method_exists($instance, $action)) {
    Response::error("Action Not Found", 404);
}

// Execute Action
$instance->$action($request);
