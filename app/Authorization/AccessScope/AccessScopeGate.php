<?php

require_once __DIR__ . '/AccessScopeContext.php';
require_once __DIR__ . '/AccessScopeRequestDatabases.php';
require_once __DIR__ . '/../PrincipalContext.php';
require_once __DIR__ . '/../../Database/DatabaseQueryPlan.php';
require_once __DIR__ . '/../../Database/DatabaseDirectory.php';
require_once __DIR__ . '/../../Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../../Requests/QueryRequestValidator.php';

/**
 * The API's access-scope checkpoints, shared by api/index.php and tests.
 *
 * beforeAvailability: action and the database the availability gate would
 * examine — before that gate, so its not-found, disabled, and unavailable
 * answers are never given for a database outside the caller's scope.
 * beforePlanning: action, named databases, and SQL Resource — before planning
 * reads resource files or the registry, so out-of-scope requests learn
 * nothing about which databases or resources exist.
 * afterPlanning: every planned database and, on the primary one, the SQL
 * Resource filters or metadata operation — before any query runs.
 * Structured-query tables and columns are checked while the builders resolve
 * them (AuthorizingMetadataRepository).
 */
final class AccessScopeGate
{
    /**
     * Runs on the unvalidated request, exactly where DatabaseAvailabilityMiddleware
     * reads it. Returns the authorizer to continue with in beforePlanning, so a
     * log-only request is still recorded once.
     */
    public static function beforeAvailability(
        array $request,
        ?Principal $principal = null,
        ?AccessScopeConfiguration $configuration = null,
        ?DatabaseContextResolver $resolver = null
    ): ?AccessScopeAuthorizer {
        AccessScopeContext::clear();
        $action = $request['action'] ?? null;
        $principal ??= PrincipalContext::current();
        if ($principal === null || !QueryRequestValidator::isAction($action) || !AccessScopeAuthorizer::appliesTo($action)
            || DatabaseDirectory::isRegistryOnly($action)) return null;
        $authorizer = new AccessScopeAuthorizer($configuration ?? AccessScopeConfiguration::load(), $principal);
        $databaseId = DatabaseReferenceCollector::declaredPrimaryId($request);
        if ($databaseId === null) {
            try {
                $databaseId = ($resolver ?? new DatabaseContextResolver())->defaultDatabaseId();
            } catch (Throwable $exception) {
                // The availability gate reports an unreadable registry to every caller alike.
                $databaseId = null;
            }
        }
        $authorizer->authorizeAvailabilityTarget($action, $databaseId);
        return $authorizer;
    }

    public static function beforePlanning(
        array $request,
        ?Principal $principal = null,
        ?AccessScopeConfiguration $configuration = null,
        ?DatabaseContextResolver $resolver = null,
        ?AccessScopeAuthorizer $authorizer = null
    ): void {
        AccessScopeContext::clear();
        $principal ??= PrincipalContext::current();
        if ($principal === null || !AccessScopeAuthorizer::appliesTo((string)($request['action'] ?? ''))) return;
        $authorizer ??= new AccessScopeAuthorizer($configuration ?? AccessScopeConfiguration::load(), $principal);
        $authorizer->authorizeRequest($request, AccessScopeRequestDatabases::named($request, $resolver));
        AccessScopeContext::set($authorizer);
    }

    public static function afterPlanning(array $request, ?DatabaseQueryPlan $plan): void
    {
        AccessScopeContext::current()?->authorizePlan($request, $plan?->databaseIds() ?? []);
    }
}
