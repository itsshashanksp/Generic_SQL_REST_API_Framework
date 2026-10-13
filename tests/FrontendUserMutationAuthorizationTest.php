<?php

require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Requests/FrontendUserRequestValidator.php';

function frontendMutationAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function frontendMutationDenied(callable $operation, string $message, string $code = 'AUTHORIZATION_DENIED'): void
{
    try { $operation(); }
    catch (ApiRequestException $exception) {
        frontendMutationAssert($exception->getErrorCode() === $code, $message . ' Wrong error code.');
        frontendMutationAssert($exception->getStatusCode() === 403, $message . ' Wrong HTTP status.');
        return;
    }
    throw new RuntimeException($message);
}
function frontendMutationPrincipal(AuthRepository $repository, string $username): Principal
{
    $user = $repository->findUser($username);
    if ($user === null) throw new RuntimeException("Missing test user {$username}.");
    return new Principal($user['id'], $user['username'], 'session', $user['backendRole'], $user['frontendAccess'], $user['frontendRole'], $user['enabled']);
}
function frontendMutationRemove(string $path): void
{
    if (!is_dir($path)) return;
    foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $item) @unlink($item);
    @rmdir($path);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-frontend-user-policy-' . bin2hex(random_bytes(8));
$authPath = $directory . DIRECTORY_SEPARATOR . 'auth.json';
$logPath = $directory . DIRECTORY_SEPARATOR . 'logs';

try {
    mkdir($directory, 0700, true); mkdir($logPath, 0700, true);
    $repository = new AuthRepository($authPath);
    $repository->save(['version' => 4, 'users' => []]);
    $service = new UserManagementService($repository, new PasswordHasher(), new Logger($logPath));
    $service->createUser('Super.Admin', 'super-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Application.Admin', 'application-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Peer.Admin', 'peer-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Delete.Admin', 'delete-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Normal.User', 'normal-user-password', null, true, null);
    $service->createUser('Normal.Actor', 'normal-actor-password', null, true, null);
    $admin = frontendMutationPrincipal($repository, 'Application.Admin');
    $superAdmin = frontendMutationPrincipal($repository, 'Super.Admin');

    $normalActor = $repository->findUser('Normal.Actor');
    $forgedAdmin = new Principal($normalActor['id'], $normalActor['username'], 'session', null, true, RoleModel::APPLICATION_ADMINISTRATOR, true);
    frontendMutationDenied(fn () => $service->updateFrontendProfile($forgedAdmin, 'Normal.User', 'Forged User', 'Forged.User', '+15551230000', null), 'Client-supplied actor roles overrode persisted authorization.');

    foreach ([['Created.Admin', RoleModel::APPLICATION_ADMINISTRATOR], ['Created.Reader', RoleModel::READ_ONLY]] as [$username, $role]) {
        $created = $service->createFrontendUser($admin, str_replace('.', ' ', $username), $username, '+15551234567', strtolower($username) . '@example.test', 'created-user-password', $role);
        frontendMutationAssert($created['name'] !== null && $created['mobile'] === '+15551234567', "Profile was not stored for {$role}.");
    }
    foreach ([RoleModel::SYSTEM_ADMINISTRATOR, RoleModel::DATA_OPERATOR] as $unsupportedRole) {
        try {
            $service->createFrontendUser($admin, 'Unsupported User', 'Unsupported.User', '+15551234567', null, 'unsupported-password', $unsupportedRole);
            throw new RuntimeException("Frontend created unsupported role {$unsupportedRole}.");
        } catch (ApiRequestException $exception) {
            frontendMutationAssert($exception->getErrorCode() === 'INVALID_FRONTEND_USER_REQUEST', 'Unsupported frontend role returned the wrong error.');
            frontendMutationAssert($exception->getStatusCode() === 400, 'Unsupported frontend role returned the wrong status.');
        }
    }

    // Admin self: profile/password allowed; lifecycle/access/demotion denied.
    $service->updateFrontendProfile($admin, 'Application.Admin', 'Application Owner', 'Application.Owner', '+15557654321', 'owner@example.test');
    $admin = frontendMutationPrincipal($repository, 'Application.Owner');
    $service->changeFrontendUserPassword($admin, 'Application.Owner', 'replacement-self-password');
    foreach ([
        fn () => $service->setFrontendUserEnabled($admin, 'Application.Owner', false),
        fn () => $service->deleteFrontendUser($admin, 'Application.Owner'),
        fn () => $service->assignFrontendAccess($admin, 'Application.Owner', false, null),
        fn () => $service->assignFrontendAccess($admin, 'Application.Owner', true, null),
    ] as $operation) frontendMutationDenied($operation, 'Admin modified its own protected authorization.');

    // Admin peer: user management allowed; Admin-role change denied.
    $service->updateFrontendProfile($admin, 'Peer.Admin', 'Peer Owner', 'Peer.Owner', '+15559876543', 'peer@example.test');
    $service->changeFrontendUserPassword($admin, 'Peer.Owner', 'replacement-peer-password');
    $service->setFrontendUserEnabled($admin, 'Peer.Owner', false);
    $service->setFrontendUserEnabled($admin, 'Peer.Owner', true);
    $service->assignFrontendAccess($admin, 'Peer.Owner', false, null);
    frontendMutationAssert($repository->findUser('Peer.Owner')['frontendAccess'] === false, 'Admin could not remove another Admin frontend access.');
    $service->assignFrontendAccess($superAdmin, 'Peer.Owner', true, RoleModel::APPLICATION_ADMINISTRATOR);
    frontendMutationDenied(fn () => $service->assignFrontendAccess($admin, 'Peer.Owner', true, null), 'Admin demoted another Admin.');
    $service->deleteFrontendUser($admin, 'Delete.Admin');
    frontendMutationAssert($repository->findUser('Delete.Admin') === null, 'Admin could not delete another Admin.');

    // Normal-user management remains available; promotion to Admin remains restricted.
    $service->updateFrontendProfile($admin, 'Normal.User', 'Normal Renamed', 'Normal.Renamed', '+15550001111', null);
    $service->changeFrontendUserPassword($admin, 'Normal.Renamed', 'replacement-normal-password');
    $service->setFrontendUserEnabled($admin, 'Normal.Renamed', false);
    $service->setFrontendUserEnabled($admin, 'Normal.Renamed', true);
    frontendMutationDenied(fn () => $service->assignFrontendAccess($admin, 'Normal.Renamed', true, RoleModel::APPLICATION_ADMINISTRATOR), 'Admin promoted a normal user to Admin.');

    // Super Admin targets remain protected from Admin actors.
    foreach ([
        fn () => $service->updateFrontendProfile($admin, 'Super.Admin', 'Changed Super', 'Changed.Super', '+15551234567', null),
        fn () => $service->changeFrontendUserPassword($admin, 'Super.Admin', 'changed-super-password'),
        fn () => $service->setFrontendUserEnabled($admin, 'Super.Admin', false),
        fn () => $service->deleteFrontendUser($admin, 'Super.Admin'),
        fn () => $service->assignFrontendAccess($admin, 'Super.Admin', false, null),
        fn () => $service->assignFrontendAccess($admin, 'Super.Admin', true, null),
    ] as $operation) frontendMutationDenied($operation, 'Admin modified a Super Admin.');

    // AAPI-01: backend identities are refused by the public frontend path for
    // every actor. A backend identity holds a role the frontend cannot assign
    // (Data Operator) or a backend role without frontend access.
    $backendIdentities = [
        'Backend.Operator' => ['backend-operator-password', RoleModel::DATA_OPERATOR, false],
        'Backend.Reader' => ['backend-reader-password', RoleModel::READ_ONLY, false],
        'Frontend.Operator' => ['frontend-operator-password', RoleModel::DATA_OPERATOR, true],
    ];
    foreach ($backendIdentities as $username => [$password, $role, $access]) $service->createUser($username, $password, $role, $access, null);
    foreach ([...array_keys($backendIdentities), 'backend.operator'] as $target) {
        foreach (['Application Admin' => $admin, 'Super Admin' => $superAdmin] as $actorName => $actor) {
            foreach ([
                'password reset' => fn () => $service->changeFrontendUserPassword($actor, $target, 'taken-over-password'),
                'rename' => fn () => $service->updateFrontendProfile($actor, $target, 'Taken Over', 'Taken.Over', '+15551234567', null),
                'disable' => fn () => $service->setFrontendUserEnabled($actor, $target, false),
                'enable' => fn () => $service->setFrontendUserEnabled($actor, $target, true),
                'delete' => fn () => $service->deleteFrontendUser($actor, $target),
                'grant access' => fn () => $service->assignFrontendAccess($actor, $target, true, null),
                'grant Admin' => fn () => $service->assignFrontendAccess($actor, $target, true, RoleModel::APPLICATION_ADMINISTRATOR),
                'revoke access' => fn () => $service->assignFrontendAccess($actor, $target, false, null),
            ] as $operationName => $operation) frontendMutationDenied($operation, "{$actorName} {$operationName} reached backend identity {$target}.");
        }
    }
    foreach ($backendIdentities as $username => [$password, $role, $access]) {
        $stored = $repository->findUser($username);
        frontendMutationAssert($stored !== null && $stored['username'] === $username && $stored['enabled'] === true
            && $stored['backendRole'] === $role && $stored['frontendAccess'] === $access && $stored['frontendRole'] === null
            && $stored['authVersion'] === 1 && password_verify($password, $stored['passwordHash']),
            "Rejected public mutation changed backend identity {$username}.");
    }
    // Request fields cannot convert or replace a backend identity.
    foreach ([
        fn () => $service->createFrontendUser($admin, 'Backend Operator', 'Backend.Operator', '+15551234567', null, 'replacement-password', RoleModel::READ_ONLY),
        fn () => $service->createFrontendUser($admin, 'Backend Operator', 'BACKEND.OPERATOR', '+15551234567', null, 'replacement-password', RoleModel::APPLICATION_ADMINISTRATOR),
        fn () => $service->updateFrontendProfile($admin, 'Normal.Renamed', 'Normal Renamed', 'Backend.Operator', '+15550001111', null),
    ] as $operation) {
        try { $operation(); throw new RuntimeException('A frontend request replaced a backend identity.'); }
        catch (ApiRequestException $exception) { frontendMutationAssert($exception->getErrorCode() === 'USER_ALREADY_EXISTS', 'Backend identity collision returned the wrong error.'); }
    }
    foreach ([
        ['action' => 'auth.frontendUsers.changePassword', 'username' => 'Backend.Operator', 'newPassword' => 'validated-user-password', 'passwordConfirmation' => 'validated-user-password', 'backendRole' => null],
        ['action' => 'auth.frontendUsers.update', 'username' => 'Backend.Operator', 'name' => 'Operator', 'newUsername' => 'Backend.Operator', 'mobile' => '+15551234567', 'email' => null, 'frontendAccess' => true],
        ['action' => 'auth.frontendUsers.assignRole', 'username' => 'Backend.Operator', 'frontendAccess' => true, 'frontendRole' => null, 'backendRole' => RoleModel::READ_ONLY],
    ] as $request) {
        try { (new FrontendUserRequestValidator())->validate($request); throw new RuntimeException('Frontend validator accepted a backend identity field.'); }
        catch (ApiRequestException $exception) { frontendMutationAssert($exception->getErrorCode() === 'INVALID_FRONTEND_USER_REQUEST', 'Backend identity field returned the wrong error.'); }
    }

    // Legitimate frontend users (including frontend-assigned Read Only users)
    // remain manageable by an Application Administrator.
    $service->changeFrontendUserPassword($admin, 'Created.Reader', 'replacement-reader-password');
    $service->updateFrontendProfile($admin, 'Created.Reader', 'Created Reader', 'Created.Reader', '+15551112222', null);
    $service->setFrontendUserEnabled($admin, 'Created.Reader', false);
    $service->setFrontendUserEnabled($admin, 'Created.Reader', true);
    $reader = $repository->findUser('Created.Reader');
    // Frontend-created Read Only users hold frontend access only (Phase 4 / F-01).
    frontendMutationAssert($reader['backendRole'] === null && $reader['frontendAccess'] === true
        && password_verify('replacement-reader-password', $reader['passwordHash']), 'Frontend Read Only user is no longer manageable.');
    // A reader created before Phase 4 keeps the backend read-only role. Revoking
    // its frontend access leaves a backend-only account that only the Admin API
    // manages afterwards.
    $service->createUser('Legacy.Reader', 'legacy-reader-password', RoleModel::READ_ONLY, true, null);
    $service->assignFrontendAccess($admin, 'Legacy.Reader', false, null);
    frontendMutationDenied(fn () => $service->assignFrontendAccess($admin, 'Legacy.Reader', true, null), 'Backend-only Read Only account regained access through the frontend path.');
    frontendMutationDenied(fn () => $service->changeFrontendUserPassword($admin, 'Legacy.Reader', 'taken-over-password'), 'Backend-only Read Only account was taken over through the frontend path.');
    // A Phase 4 reader has no backend role: revoking frontend access disables it
    // and it stays a role-less frontend account (existing rule for accounts
    // without a backend role), holding no permission until access is restored.
    $service->assignFrontendAccess($admin, 'Created.Reader', false, null);
    $revoked = $repository->findUser('Created.Reader');
    frontendMutationAssert($revoked['frontendAccess'] === false && $revoked['enabled'] === false && $revoked['backendRole'] === null,
        'Revoking a role-less reader did not disable it.');

    // Super Admin retains full frontend management over Admin accounts.
    $service->updateFrontendProfile($superAdmin, 'Peer.Owner', 'Managed Admin', 'Managed.Admin', '+15552345678', null);
    $service->changeFrontendUserPassword($superAdmin, 'Managed.Admin', 'managed-admin-password');
    $service->setFrontendUserEnabled($superAdmin, 'Managed.Admin', false);
    $service->setFrontendUserEnabled($superAdmin, 'Managed.Admin', true);
    $service->assignFrontendAccess($superAdmin, 'Managed.Admin', false, null);
    $service->assignFrontendAccess($superAdmin, 'Managed.Admin', true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->assignFrontendAccess($superAdmin, 'Managed.Admin', true, null);
    frontendMutationAssert($repository->findUser('Managed.Admin')['frontendRole'] === null, 'Super Admin could not demote an Admin.');

    // Persisted enabled Super Admin count is authoritative (Admin API path).
    frontendMutationDenied(fn () => $service->setEnabled('Super.Admin', false), 'Sole Super Admin disabled itself.', 'LAST_ENABLED_ADMIN');
    frontendMutationDenied(fn () => $service->deleteUser('Super.Admin', 'Super.Admin'), 'Sole Super Admin deleted itself.', 'LAST_ENABLED_ADMIN');
    frontendMutationDenied(fn () => $service->assignAuthorization(
        'Super.Admin', null, true, RoleModel::APPLICATION_ADMINISTRATOR, str_repeat('f', 32)
    ), 'Sole Super Admin demoted itself.', 'LAST_ENABLED_ADMIN');
    $service->createUser('Second.Super', 'second-super-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);

    // AAPI-02: Super Admin identities, including the actor's own, are refused
    // by the public frontend path even for a Super Admin actor.
    foreach ([
        fn () => $service->updateFrontendProfile($superAdmin, 'Second.Super', 'Changed Super', 'Changed.Super', '+15551234567', null),
        fn () => $service->changeFrontendUserPassword($superAdmin, 'Second.Super', 'changed-super-password'),
        fn () => $service->setFrontendUserEnabled($superAdmin, 'Second.Super', false),
        fn () => $service->deleteFrontendUser($superAdmin, 'Second.Super'),
        fn () => $service->assignFrontendAccess($superAdmin, 'Second.Super', false, null),
        fn () => $service->changeFrontendUserPassword($superAdmin, 'Super.Admin', 'changed-own-password'),
        fn () => $service->setFrontendUserEnabled($superAdmin, 'Super.Admin', false),
        fn () => $service->deleteFrontendUser($superAdmin, 'Super.Admin'),
    ] as $operation) frontendMutationDenied($operation, 'Public frontend path modified a Super Admin.');
    frontendMutationAssert($repository->findUser('Second.Super')['enabled'] === true
        && password_verify('second-super-password', $repository->findUser('Second.Super')['passwordHash']),
        'Rejected public Super Admin mutation changed the account.');

    $service->setEnabled('Second.Super', false);
    frontendMutationDenied(fn () => $service->setEnabled('Super.Admin', false), 'Disabled Super Admin counted as enabled.', 'LAST_ENABLED_ADMIN');
    $service->setEnabled('Second.Super', true);
    $service->assignAuthorization(
        'Second.Super', null, true, RoleModel::APPLICATION_ADMINISTRATOR, $superAdmin->userId
    );
    frontendMutationAssert($repository->findUser('Second.Super')['backendRole'] === null, 'Super Admin demotion failed with another enabled Super Admin.');
    $service->createUser('Third.Super', 'third-super-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->setEnabled('Third.Super', false);
    $service->deleteUser('Third.Super', 'Super.Admin');
    $service->createUser('Final.Super', 'final-super-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->setEnabled('Super.Admin', false);
    $service->setEnabled('Super.Admin', true);
    $service->deleteUser('Super.Admin', 'Super.Admin');
    frontendMutationAssert($repository->findUser('Super.Admin') === null, 'Super Admin self-delete failed while another enabled Super Admin remained.');

    $listed = $service->listFrontendUsers();
    frontendMutationAssert(!str_contains(json_encode($listed, JSON_THROW_ON_ERROR), 'password'), 'Frontend response exposed credentials.');
    frontendMutationAssert(array_key_exists('name', $listed[0]) && array_key_exists('createdAt', $listed[0]), 'Frontend response omitted profile metadata.');

    $validator = new FrontendUserRequestValidator();
    $valid = $validator->validate(['action' => 'auth.frontendUsers.create', 'name' => 'Valid User', 'username' => 'Valid.User', 'mobile' => '+15551234567', 'email' => '', 'password' => 'validated-user-password', 'passwordConfirmation' => 'validated-user-password', 'role' => RoleModel::READ_ONLY]);
    frontendMutationAssert($valid['email'] === null && $valid['role'] === RoleModel::READ_ONLY, 'Valid profile was not normalized.');
    foreach ([
        ['name' => '', 'mobile' => '+15551234567', 'email' => null],
        ['name' => 'Valid User', 'mobile' => null, 'email' => null],
        ['name' => 'Valid User', 'mobile' => '123', 'email' => null],
        ['name' => 'Valid User', 'mobile' => '+15551234567', 'email' => 'invalid-email'],
    ] as $invalidProfile) {
        try {
            $validator->validate(['action' => 'auth.frontendUsers.create', 'username' => 'Invalid.User', 'password' => 'validated-user-password', 'passwordConfirmation' => 'validated-user-password', 'role' => RoleModel::READ_ONLY, ...$invalidProfile]);
            throw new RuntimeException('Invalid profile was accepted.');
        } catch (ApiRequestException $exception) { frontendMutationAssert($exception->getErrorCode() === 'INVALID_FRONTEND_USER_REQUEST', 'Wrong profile validation error.'); }
    }
    try {
        $validator->validate(['action' => 'auth.frontendUsers.create', 'name' => 'Invalid Role', 'username' => 'Invalid.Role', 'mobile' => '+15551234567', 'email' => 'valid@example.test', 'password' => 'validated-user-password', 'passwordConfirmation' => 'validated-user-password', 'role' => 'root']);
        throw new RuntimeException('Invalid role was accepted.');
    } catch (ApiRequestException $exception) { frontendMutationAssert($exception->getErrorCode() === 'INVALID_FRONTEND_USER_REQUEST', 'Wrong role validation error.'); }
    try {
        $validator->validate(['action' => 'auth.frontendUsers.create', 'name' => 'Unsupported Operator', 'username' => 'Unsupported.Operator', 'mobile' => '+15551234567', 'email' => null, 'password' => 'validated-user-password', 'passwordConfirmation' => 'validated-user-password', 'role' => RoleModel::DATA_OPERATOR]);
        throw new RuntimeException('Validator accepted a role requiring unsupported frontend write capability.');
    } catch (ApiRequestException $exception) { frontendMutationAssert($exception->getErrorCode() === 'INVALID_FRONTEND_USER_REQUEST', 'Unsupported role validation returned the wrong error.'); }
    echo "Frontend user mutation authorization tests passed.\n";
} finally {
    frontendMutationRemove($logPath); @unlink($authPath); @unlink($authPath . '.lock'); @rmdir($directory);
}
