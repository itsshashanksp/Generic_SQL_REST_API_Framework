<?php

require_once __DIR__ . '/AccessScopeAuthorizer.php';

/** The access scope authorizer of the current request, when the action is scoped. */
final class AccessScopeContext
{
    private static ?AccessScopeAuthorizer $authorizer = null;

    public static function set(AccessScopeAuthorizer $authorizer): void { self::$authorizer = $authorizer; }
    public static function current(): ?AccessScopeAuthorizer { return self::$authorizer; }
    public static function clear(): void { self::$authorizer = null; }
}
