<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Authz;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\Schema;
use PDO;
use PDOException;

final class AdminController
{
    /** @var array<string, bool> */
    private array $userColumnCache = [];

    public function __construct(private readonly PDO $db, private readonly SessionAuth $auth)
    {
    }

    public function users(): void
    {
        $this->ensureAdminSchema();
        $this->currentAdmin();

        try {
            $stmt = $this->db->query(
                sprintf(
                    'SELECT %s
                     FROM users
                     ORDER BY created_at DESC
                     LIMIT 500',
                    $this->userSelectColumnsSql(),
                )
            );
        } catch (PDOException $exception) {
            $this->throwMappedPdo($exception, 'loading admin users');
        }

        $items = $stmt->fetchAll() ?: [];
        $items = array_map(static fn (array $row): array => Authz::normalizeUser($row), $items);

        Response::json(['items' => $items]);
    }

    public function createUser(): void
    {
        $this->ensureAdminSchema();
        $actor = $this->currentAdmin();
        $input = Request::input();

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $role = strtolower(trim((string) ($input['role'] ?? 'user')));
        $isSeed = isset($input['is_seed']) ? (bool) $input['is_seed'] : false;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Valid email is required', 422);
        }
        if (strlen($password) < 10) {
            throw new HttpException('Password must be at least 10 characters', 422);
        }
        if (!in_array($role, ['user', 'admin', 'owner'], true)) {
            throw new HttpException('Invalid role', 422);
        }
        if ($role === 'owner' && $actor['role'] !== 'owner') {
            throw new HttpException('Only owner can create owner accounts', 403);
        }
        if ($isSeed && $actor['role'] !== 'owner') {
            throw new HttpException('Only owner can flag seed users', 403);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $this->db->prepare(
                sprintf(
                    'INSERT INTO users (email, password_hash, role, is_seed)
                     VALUES (:email, :password_hash, :role, :is_seed)
                     RETURNING %s',
                    $this->userSelectColumnsSql(),
                )
            );
            $stmt->execute([
                ':email' => $email,
                ':password_hash' => $hash,
                ':role' => $role,
                ':is_seed' => $isSeed ? 'true' : 'false',
            ]);
            $user = $stmt->fetch();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23505') {
                throw new HttpException('Email is already registered', 409);
            }
            $this->throwMappedPdo($exception, 'creating admin user');
        }

        Response::json(['item' => Authz::normalizeUser($user ?: [])], 201);
    }

    /** @param array<string, string> $params */
    public function updateUser(array $params): void
    {
        $this->ensureAdminSchema();
        $actor = $this->currentAdmin();
        $targetId = (int) ($params['id'] ?? 0);
        if ($targetId <= 0) {
            throw new HttpException('Invalid user id', 422);
        }

        $target = Authz::findUser($this->db, $targetId);
        $input = Request::input();

        $fields = [];
        $payload = [':id' => $targetId];

        if (array_key_exists('role', $input)) {
            $role = strtolower(trim((string) $input['role']));
            if (!in_array($role, ['user', 'admin', 'owner'], true)) {
                throw new HttpException('Invalid role', 422);
            }

            if ($role === 'owner' && $actor['role'] !== 'owner') {
                throw new HttpException('Only owner can assign owner role', 403);
            }
            if ($target['role'] === 'owner' && $actor['role'] !== 'owner') {
                throw new HttpException('Only owner can update owner accounts', 403);
            }

            $fields[] = 'role = :role';
            $payload[':role'] = $role;
        }

        if (array_key_exists('is_seed', $input)) {
            if ($actor['role'] !== 'owner') {
                throw new HttpException('Only owner can modify seed flags', 403);
            }

            $fields[] = 'is_seed = :is_seed';
            $payload[':is_seed'] = ((bool) $input['is_seed']) ? 'true' : 'false';
        }

        if (array_key_exists('is_active', $input)) {
            $isActive = (bool) $input['is_active'];

            if ($targetId === (int) $actor['id'] && !$isActive) {
                throw new HttpException('You cannot deactivate your own account', 422);
            }
            if ($target['role'] === 'owner' && $actor['role'] !== 'owner') {
                throw new HttpException('Only owner can deactivate owner accounts', 403);
            }

            $fields[] = 'is_active = :is_active';
            $payload[':is_active'] = $isActive ? 'true' : 'false';

            if ($this->hasUserColumn('disabled_at')) {
                $fields[] = 'disabled_at = :disabled_at';
                $payload[':disabled_at'] = $isActive ? null : (new \DateTimeImmutable())->format(DATE_ATOM);
            }
        }

        if ($fields === []) {
            Response::json(['item' => $target]);
            return;
        }

        $sql = sprintf(
            'UPDATE users SET %s WHERE id = :id RETURNING %s',
            implode(', ', $fields),
            $this->userSelectColumnsSql(),
        );

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($payload);
            $updated = $stmt->fetch();
        } catch (PDOException $exception) {
            $this->throwMappedPdo($exception, 'updating admin user');
        }

        Response::json(['item' => Authz::normalizeUser($updated ?: [])]);
    }

    /** @return array<string, mixed> */
    private function currentAdmin(): array
    {
        $userId = $this->auth->requireUserId();
        return Authz::requireAdmin($this->db, $userId);
    }

    private function ensureAdminSchema(): void
    {
        if (!Schema::hasAdminUserColumns($this->db)) {
            throw new HttpException('Admin schema missing. Run migration 002_admin_support.sql', 503);
        }
    }

    private function hasUserColumn(string $column): bool
    {
        if (array_key_exists($column, $this->userColumnCache)) {
            return $this->userColumnCache[$column];
        }

        $exists = Schema::hasColumn($this->db, 'users', $column);
        $this->userColumnCache[$column] = $exists;

        return $exists;
    }

    private function userSelectColumnsSql(): string
    {
        $columns = ['id', 'email', 'role', 'is_active', 'is_seed', 'created_at'];
        foreach (['disabled_at', 'updated_at'] as $optionalColumn) {
            if ($this->hasUserColumn($optionalColumn)) {
                $columns[] = $optionalColumn;
            }
        }

        return implode(', ', $columns);
    }

    private function throwMappedPdo(PDOException $exception, string $context): never
    {
        $sqlState = (string) $exception->getCode();
        $rawMessage = strtolower($exception->getMessage());

        if ($sqlState === '23505') {
            throw new HttpException('Email is already registered', 409);
        }
        if ($sqlState === '23514') {
            throw new HttpException('Database constraint violation while managing users. Verify allowed role values and constraints.', 422);
        }
        if ($sqlState === '22P02') {
            throw new HttpException('Invalid input format for database field while managing users. Check boolean/status values.', 422);
        }
        if ($sqlState === '42501' || str_contains($rawMessage, 'permission denied')) {
            throw new HttpException('Database permission denied while managing users. Grant table/sequence/function privileges to the app user.', 500);
        }
        if (in_array($sqlState, ['42P01', '42703'], true)) {
            throw new HttpException('Admin schema is outdated. Run migration 002_admin_support.sql and ensure users columns exist.', 500);
        }
        if ($sqlState === '42883') {
            throw new HttpException('Required database function is missing. Recreate set_updated_at() and related triggers.', 500);
        }

        throw new HttpException(
            sprintf('Database failure while %s (SQLSTATE %s).', $context, $sqlState !== '' ? $sqlState : 'unknown'),
            500
        );
    }
}
