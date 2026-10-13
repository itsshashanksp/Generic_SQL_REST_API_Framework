<?php

require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Authorization/Principal.php';
require_once __DIR__ . '/../Authorization/FrontendCapabilityPolicy.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/PasswordHasher.php';
require_once __DIR__ . '/../Security/UserProfilePolicy.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../core/Logger.php';

final class UserManagementService
{
    public function __construct(
        private ?AuthRepository $authRepository = null,
        private ?PasswordHasher $passwordHasher = null,
        private ?Logger $logger = null
    ) {
        $this->authRepository ??= new AuthRepository();
        $this->passwordHasher ??= new PasswordHasher();
        $this->logger ??= new Logger();
    }

    public function listUsers(): array
    {
        return $this->sorted(array_map(fn (array $user): array => $this->safeUser($user), $this->loadUsers()));
    }

    /**
     * Frontend user listing. Backend identities cannot be managed through the
     * frontend path, so their profile details are shown only to System
     * Administrators and to the account itself.
     */
    public function listFrontendUsers(?Principal $viewer = null): array
    {
        $users = array_filter(
            $this->loadUsers(),
            fn (array $user): bool => $user['frontendAccess'] === true
                || $user['backendRole'] === null
                || $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
        );
        $fullProfiles = $viewer !== null && $viewer->backendRole === RoleModel::SYSTEM_ADMINISTRATOR;
        return $this->sorted(array_map(function (array $user) use ($viewer, $fullProfiles): array {
            $safe = $this->safeFrontendUser($user);
            if ($safe['backendProtected'] && !$fullProfiles && $viewer?->userId !== $user['id']) {
                $safe['name'] = $safe['mobile'] = $safe['email'] = null;
            }
            return $safe;
        }, $users));
    }

    public function createUser(string $username, string $password, ?string $backendRole, bool $frontendAccess, ?string $frontendRole, bool $enabled = true, ?string $name = null, ?string $mobile = null, ?string $email = null): array
    {
        $this->validateAuthorization($backendRole, $frontendAccess, $frontendRole, $enabled);
        if ($name !== null || $mobile !== null || $email !== null) [$name, $mobile, $email] = $this->validatedProfile($name ?? '', $mobile ?? '', $email, false);
        return $this->create($username, $password, $backendRole, $frontendAccess, $frontendRole, $enabled, false, null, null, $name, $mobile, $email);
    }

    public function createFrontendUser(
        Principal $actor,
        string $name,
        string $username,
        string $mobile,
        ?string $email,
        string $password,
        ?string $role,
        bool $enabled = true
    ): array
    {
        if (!in_array($role, FrontendCapabilityPolicy::assignableRoles(), true)) {
            throw new ApiRequestException('Invalid frontend user request.', 'INVALID_FRONTEND_USER_REQUEST');
        }
        // A frontend "Read Only" account is a frontend reader: frontend access
        // only, governed by the `frontend` access scope. It does not receive
        // the backend read-only role (data.read, metadata.read, sql.execute,
        // routine.execute). Accounts created before this rule keep their role
        // until migrated (see docs/Access-Scopes.md).
        $backendRole = $role === RoleModel::DATA_OPERATOR ? $role : null;
        $frontendRole = $role === RoleModel::APPLICATION_ADMINISTRATOR ? $role : null;
        [$name, $mobile, $email] = $this->validatedProfile($name, $mobile, $email);
        return $this->create($username, $password, $backendRole, true, $frontendRole, $enabled, true, $actor, $role, $name, $mobile, $email);
    }

    public function updateUsername(string $username, string $newUsername): array
    {
        return $this->mutate($username, function (array &$configuration, int $index) use ($newUsername): void {
            $existing = $this->findIndex($configuration['users'], $newUsername);
            if ($existing !== null && $existing !== $index) throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
            if ($configuration['users'][$index]['username'] !== $newUsername) {
                $configuration['users'][$index]['username'] = $newUsername;
                $configuration['users'][$index]['authVersion']++;
            }
        }, 'user.username_changed');
    }

    public function updateUserProfile(string $username, string $name, string $newUsername, string $mobile, ?string $email): array
    {
        [$name, $mobile, $email] = $this->validatedProfile($name, $mobile, $email, false);
        return $this->mutate($username, function (array &$configuration, int $index) use ($name, $newUsername, $mobile, $email): void {
            $existing = $this->findIndex($configuration['users'], $newUsername);
            if ($existing !== null && $existing !== $index) throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
            $user = &$configuration['users'][$index];
            if ($user['username'] !== $newUsername || ($user['name'] ?? null) !== $name || ($user['mobile'] ?? null) !== $mobile || ($user['email'] ?? null) !== $email) {
                $user['name'] = $name; $user['username'] = $newUsername; $user['mobile'] = $mobile; $user['email'] = $email; $user['authVersion']++;
            }
        }, 'user.profile_changed');
    }

    public function setEnabled(string $username, bool $enabled): array
    {
        return $this->mutate($username, function (array &$configuration, int $index) use ($enabled): void {
            $user = $configuration['users'][$index];
            if ($enabled && $user['backendRole'] === null && $user['frontendAccess'] !== true) throw new ApiRequestException('User has no assigned access.', 'USER_ACCESS_REQUIRED', [], 409);
            if (!$enabled && $user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
            if ($user['enabled'] !== $enabled) {
                $configuration['users'][$index]['enabled'] = $enabled;
                $configuration['users'][$index]['authVersion']++;
            }
        }, $enabled ? 'user.enabled' : 'user.disabled');
    }

    public function deleteUser(string $username, string $currentUsername): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $currentUsername): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $user = $configuration['users'][$index];
                if (strcasecmp($user['username'], $currentUsername) === 0
                    && $user['backendRole'] !== RoleModel::SYSTEM_ADMINISTRATOR) {
                    throw new ApiRequestException('The current user cannot be deleted.', 'CANNOT_DELETE_CURRENT_USER', [], 409);
                }
                if ($user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                    && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
                array_splice($configuration['users'], $index, 1);
                return $this->safeUser($user);
            });
            $this->audit('user.deleted', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    public function changePassword(string $username, string $newPassword): array
    {
        $hash = $this->passwordHasher->hash($newPassword);
        return $this->mutate($username, function (array &$configuration, int $index) use ($hash): void {
            $configuration['users'][$index]['passwordHash'] = $hash;
            $configuration['users'][$index]['authVersion']++;
        }, 'user.password_changed');
    }

    public function assignAuthorization(
        string $username,
        ?string $backendRole,
        bool $frontendAccess,
        ?string $frontendRole,
        string $actorUserId
    ): array
    {
        $this->validateAuthorization($backendRole, $frontendAccess, $frontendRole, false);
        return $this->mutate($username, function (array &$configuration, int $index) use ($backendRole, $frontendAccess, $frontendRole, $actorUserId): void {
            $user = $configuration['users'][$index];
            if (hash_equals($user['id'], $actorUserId)) {
                throw new ApiRequestException(
                    'Users cannot change their own authorization.',
                    'AUTHORIZATION_DENIED',
                    [],
                    403
                );
            }
            if ($user['enabled'] && $backendRole === null && !$frontendAccess) {
                throw new ApiRequestException('An enabled user requires backend or frontend access.', 'USER_ACCESS_REQUIRED', [], 409);
            }
            if ($user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                && $backendRole !== RoleModel::SYSTEM_ADMINISTRATOR
                && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
            $configuration['users'][$index]['backendRole'] = $backendRole;
            $configuration['users'][$index]['frontendAccess'] = $frontendAccess;
            $configuration['users'][$index]['frontendRole'] = $frontendAccess ? $frontendRole : null;
            $configuration['users'][$index]['authVersion']++;
        }, 'user.authorization_changed');
    }

    public function updateFrontendProfile(
        Principal $actor,
        string $username,
        string $name,
        string $newUsername,
        string $mobile,
        ?string $email
    ): array
    {
        [$name, $mobile, $email] = $this->validatedProfile($name, $mobile, $email);
        return $this->frontendMutate($actor, $username, function (array &$configuration, int $index) use ($name, $newUsername, $mobile, $email): void {
            $existing = $this->findIndex($configuration['users'], $newUsername);
            if ($existing !== null && $existing !== $index) throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
            $user = &$configuration['users'][$index];
            if ($user['username'] !== $newUsername || ($user['name'] ?? null) !== $name
                || ($user['mobile'] ?? null) !== $mobile || ($user['email'] ?? null) !== $email) {
                $user['name'] = $name;
                $user['username'] = $newUsername;
                $user['mobile'] = $mobile;
                $user['email'] = $email;
                $configuration['users'][$index]['authVersion']++;
            }
        }, 'user.profile_changed', 'profile');
    }

    public function setFrontendUserEnabled(Principal $actor, string $username, bool $enabled): array
    {
        return $this->frontendMutate($actor, $username, function (array &$configuration, int $index) use ($enabled): void {
            $user = $configuration['users'][$index];
            if ($enabled && $user['backendRole'] === null && $user['frontendAccess'] !== true) {
                throw new ApiRequestException('User has no assigned access.', 'USER_ACCESS_REQUIRED', [], 409);
            }
            if (!$enabled && $user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
            if ($user['enabled'] !== $enabled) {
                $configuration['users'][$index]['enabled'] = $enabled;
                $configuration['users'][$index]['authVersion']++;
            }
        }, $enabled ? 'user.enabled' : 'user.disabled', 'lifecycle');
    }

    public function deleteFrontendUser(Principal $actor, string $username): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($actor, $username): array {
                $index = $this->requireFrontendTargetIndex($configuration['users'], $actor, $username);
                $user = $configuration['users'][$index];
                $this->assertFrontendMutationAllowed($configuration['users'], $actor, $user, 'lifecycle');
                if ($user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
                    && $this->enabledSystemAdministratorCount($configuration['users']) <= 1) $this->lastEnabledAdministrator();
                array_splice($configuration['users'], $index, 1);
                return $this->safeFrontendUser($user);
            });
            $this->audit('user.deleted', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    public function changeFrontendUserPassword(Principal $actor, string $username, string $newPassword): array
    {
        $hash = $this->passwordHasher->hash($newPassword);
        return $this->frontendMutate($actor, $username, function (array &$configuration, int $index) use ($hash): void {
            $configuration['users'][$index]['passwordHash'] = $hash;
            $configuration['users'][$index]['authVersion']++;
        }, 'user.password_changed', 'password');
    }

    public function assignFrontendAccess(Principal $actor, string $username, bool $frontendAccess, ?string $frontendRole): array
    {
        if (!in_array($frontendRole, [null, RoleModel::APPLICATION_ADMINISTRATOR], true)
            || (!$frontendAccess && $frontendRole !== null)) {
            throw new ApiRequestException('Invalid frontend user request.', 'INVALID_FRONTEND_USER_REQUEST');
        }
        return $this->frontendMutate(
            $actor,
            $username,
            function (array &$configuration, int $index) use ($frontendAccess, $frontendRole): void {
                $user = $configuration['users'][$index];
                $configuration['users'][$index]['frontendAccess'] = $frontendAccess;
                $configuration['users'][$index]['frontendRole'] = $frontendAccess ? $frontendRole : null;
                if (!$frontendAccess && $user['backendRole'] === null) {
                    $configuration['users'][$index]['enabled'] = false;
                }
                $configuration['users'][$index]['authVersion']++;
            },
            'user.frontend_authorization_changed',
            'authorization',
            $frontendAccess,
            $frontendRole
        );
    }

    private function create(
        string $username,
        string $password,
        ?string $backendRole,
        bool $frontendAccess,
        ?string $frontendRole,
        bool $enabled,
        bool $frontendOnly,
        ?Principal $frontendActor = null,
        ?string $requestedFrontendRole = null,
        ?string $name = null,
        ?string $mobile = null,
        ?string $email = null
    ): array
    {
        try {
            $hash = $this->passwordHasher->hash($password);
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $hash, $backendRole, $frontendAccess, $frontendRole, $enabled, $frontendOnly, $frontendActor, $requestedFrontendRole, $name, $mobile, $email): array {
                if ($frontendActor !== null) {
                    $this->assertFrontendActor($configuration['users'], $frontendActor);
                    if ($requestedFrontendRole === RoleModel::SYSTEM_ADMINISTRATOR) {
                        $this->denyFrontendMutation($frontendActor, null, 'system_administrator_assignment_denied');
                    }
                }
                if ($this->findIndex($configuration['users'], $username) !== null) throw new ApiRequestException('User already exists.', 'USER_ALREADY_EXISTS', [], 409);
                $user = [
                    'id' => bin2hex(random_bytes(16)), 'username' => $username, 'passwordHash' => $hash,
                    'enabled' => $enabled, 'backendRole' => $backendRole, 'frontendAccess' => $frontendAccess,
                    'frontendRole' => $frontendRole, 'createdAt' => gmdate(DATE_ATOM), 'authVersion' => 1,
                ];
                if ($frontendOnly || $name !== null || $mobile !== null || $email !== null) $user += ['name' => $name, 'mobile' => $mobile, 'email' => $email];
                $configuration['users'][] = $user;
                return $frontendOnly ? $this->safeFrontendUser($user) : $this->safeUser($user);
            });
            $this->audit('user.created', $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function mutate(string $username, callable $operation, string $event = 'user.updated'): array
    {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($username, $operation): array {
                $index = $this->requireUserIndex($configuration['users'], $username);
                $operation($configuration, $index);
                return $this->safeUser($configuration['users'][$index]);
            });
            $this->audit($event, $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function frontendMutate(
        Principal $actor,
        string $username,
        callable $operation,
        string $event,
        string $mutation = 'general',
        ?bool $requestedAccess = null,
        ?string $requestedRole = null
    ): array {
        try {
            $result = $this->authRepository->update(function (array &$configuration) use ($actor, $username, $operation, $mutation, $requestedAccess, $requestedRole): array {
                $index = $this->requireFrontendTargetIndex($configuration['users'], $actor, $username);
                $this->assertFrontendMutationAllowed(
                    $configuration['users'],
                    $actor,
                    $configuration['users'][$index],
                    $mutation,
                    $requestedAccess,
                    $requestedRole
                );
                $operation($configuration, $index);
                return $this->safeFrontendUser($configuration['users'][$index]);
            });
            $this->audit($event, $username);
            return $result;
        } catch (ApiRequestException $exception) { throw $exception; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function assertFrontendMutationAllowed(
        array $users,
        Principal $actor,
        array $target,
        string $mutation = 'general',
        ?bool $requestedAccess = null,
        ?string $requestedRole = null
    ): void
    {
        $isSystemAdministrator = $this->assertFrontendActor($users, $actor);
        // Backend identities are managed only through the loopback Admin API,
        // so the public frontend path refuses them for every actor, including
        // System Administrators.
        if (!$this->isFrontendManagedIdentity($target)) {
            $this->denyFrontendMutation($actor, $target, 'backend_identity_protected');
        }
        if ($isSystemAdministrator) return;
        $self = $actor->userId === $target['id'];
        if ($self && in_array($mutation, ['profile', 'password'], true)) return;
        if ($self) $this->denyFrontendMutation($actor, $target, 'self_mutation');
        if ($target['frontendRole'] === RoleModel::APPLICATION_ADMINISTRATOR) {
            if (in_array($mutation, ['profile', 'password', 'lifecycle'], true)) return;
            if ($mutation === 'authorization' && $requestedAccess === false) return;
            $this->denyFrontendMutation($actor, $target, 'administrator_role_change_denied');
        }
        if ($mutation === 'authorization' && $requestedRole === RoleModel::APPLICATION_ADMINISTRATOR) {
            $this->denyFrontendMutation($actor, $target, 'administrator_assignment_denied');
        }
    }

    /**
     * A missing account is refused exactly like a protected one, so the public
     * frontend path does not reveal which usernames exist.
     */
    private function requireFrontendTargetIndex(array $users, Principal $actor, string $username): int
    {
        $index = $this->findIndex($users, $username);
        if ($index !== null) return $index;
        $this->assertFrontendActor($users, $actor);
        $this->denyFrontendMutation($actor, ['username' => $username], 'target_unavailable');
    }

    /**
     * A frontend-managed identity holds no backend role, or holds a backend
     * role the frontend itself assigns (FrontendCapabilityPolicy) together with
     * frontend access. System Administrators, roles the frontend cannot assign
     * (such as Data Operator), and backend roles without frontend access are
     * backend identities.
     */
    private function isFrontendManagedIdentity(array $target): bool
    {
        if ($target['backendRole'] === null) return true;
        return $target['backendRole'] !== RoleModel::SYSTEM_ADMINISTRATOR
            && $target['frontendAccess'] === true
            && in_array($target['backendRole'], FrontendCapabilityPolicy::assignableRoles(), true);
    }

    private function assertFrontendActor(array $users, Principal $actor): bool
    {
        $persisted = null;
        foreach ($users as $candidate) {
            if ($actor->userId !== null && hash_equals($candidate['id'], $actor->userId)) {
                $persisted = $candidate;
                break;
            }
        }
        if ($actor->authenticationType !== 'session'
            || $persisted === null || $persisted['enabled'] !== true
            || !hash_equals($persisted['username'], $actor->username)) {
            $this->denyFrontendMutation($actor, null, 'actor_unavailable');
        }
        if ($persisted['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR) return true;
        if ($persisted['frontendAccess'] !== true
            || $persisted['frontendRole'] !== RoleModel::APPLICATION_ADMINISTRATOR) {
            $this->denyFrontendMutation($actor, null, 'actor_not_administrator');
        }
        return false;
    }

    private function denyFrontendMutation(Principal $actor, ?array $target, string $reason): never
    {
        $this->logger->audit('authorization.denied', 'denied', 'NOTICE', [
            'actorType' => 'user',
            'actorId' => $actor->userId,
            'actorUsername' => $actor->username,
            'role' => $actor->backendRole ?? $actor->frontendRole,
            'authenticationMethod' => $actor->authenticationType,
            'action' => 'frontend.users.manage',
            'targetType' => 'user',
            'targetUsername' => $target['username'] ?? null,
            'reason' => $reason,
            'component' => 'user_management',
        ]);
        throw new ApiRequestException('Authorization denied.', 'AUTHORIZATION_DENIED', [], 403);
    }

    private function loadUsers(): array
    {
        try { return $this->authRepository->load()['users']; }
        catch (Throwable $exception) { $this->fail($exception); }
    }

    private function validateAuthorization(?string $backendRole, bool $frontendAccess, ?string $frontendRole, bool $enabled): void
    {
        if (!in_array($backendRole, [null, ...RoleModel::backendRoles()], true)
            || !in_array($frontendRole, [null, ...RoleModel::frontendRoles()], true)
            || (!$frontendAccess && $frontendRole !== null)
            || ($enabled && $backendRole === null && !$frontendAccess)) {
            throw new ApiRequestException('Invalid user authorization.', 'INVALID_USER_REQUEST');
        }
    }

    private function validatedProfile(string $name, string $mobile, ?string $email, bool $frontend = true): array
    {
        try {
            return [UserProfilePolicy::name($name), UserProfilePolicy::mobile($mobile), UserProfilePolicy::email($email)];
        } catch (InvalidArgumentException $exception) {
            throw new ApiRequestException($frontend ? 'Invalid frontend user request.' : 'Invalid user request.', $frontend ? 'INVALID_FRONTEND_USER_REQUEST' : 'INVALID_USER_REQUEST', [
                ['path' => 'profile', 'message' => $exception->getMessage()],
            ]);
        }
    }

    private function requireUserIndex(array $users, string $username): int
    {
        $index = $this->findIndex($users, $username);
        if ($index === null) throw new ApiRequestException('User was not found.', 'USER_NOT_FOUND', [], 404);
        return $index;
    }

    private function findIndex(array $users, string $username): ?int
    {
        foreach ($users as $index => $user) if (strcasecmp($user['username'], $username) === 0) return $index;
        return null;
    }

    private function enabledSystemAdministratorCount(array $users): int
    {
        return count(array_filter($users, fn (array $user): bool => $user['enabled'] && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR));
    }

    private function safeUser(array $user): array
    {
        return [
            'name' => $user['name'] ?? null,
            'username' => $user['username'],
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
            'enabled' => $user['enabled'],
            'backendRole' => $user['backendRole'],
            'frontendAccess' => $user['frontendAccess'],
            'frontendRole' => $user['frontendRole'],
            'createdAt' => $user['createdAt'],
        ];
    }

    private function safeFrontendUser(array $user): array
    {
        return [
            'name' => $user['name'] ?? null,
            'username' => $user['username'],
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
            'enabled' => $user['enabled'],
            'backendRole' => $user['backendRole'],
            'frontendAccess' => $user['frontendAccess'],
            'frontendRole' => $user['frontendRole'],
            'backendProtected' => !$this->isFrontendManagedIdentity($user),
            'createdAt' => $user['createdAt'],
        ];
    }

    private function sorted(array $users): array
    {
        usort($users, fn (array $left, array $right): int => strcasecmp($left['username'], $right['username']));
        return $users;
    }

    private function lastEnabledAdministrator(): never
    {
        throw new ApiRequestException('At least one enabled System Administrator is required.', 'LAST_ENABLED_ADMIN', [], 403);
    }

    private function audit(string $event, string $targetUsername): void
    {
        $this->logger->audit($event, 'success', 'INFO', [
            'targetType' => 'user',
            'targetUsername' => $targetUsername,
            'component' => 'user_management',
        ]);
    }

    private function fail(Throwable $exception): never
    {
        $this->logger->audit('user.operation', 'failure', 'ERROR', [
            'reason' => get_class($exception),
            'component' => 'user_management',
        ]);
        throw new ApiRequestException('Unable to complete user operation.', 'USER_OPERATION_FAILED', [], 500);
    }
}
