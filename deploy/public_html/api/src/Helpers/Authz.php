<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

final class Authz
{
    /** @var array<string, bool> */
    private static array $userColumnCache = [];

    /** @return array<string, mixed> */
    public static function requireActiveUser(PDO $db, int $userId): array
    {
        $user = self::findUser($db, $userId);

        if (!$user['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public static function requireAdmin(PDO $db, int $userId): array
    {
        $user = self::requireActiveUser($db, $userId);
        if (!in_array($user['role'], ['admin', 'owner'], true)) {
            throw new HttpException('Admin access required', 403);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public static function findUser(PDO $db, int $userId): array
    {
        $columns = ['id', 'email', 'created_at'];
        if (Schema::hasAdminUserColumns($db)) {
            $columns = [...$columns, 'role', 'is_active', 'is_seed'];
            foreach (['updated_at', 'disabled_at'] as $optionalColumn) {
                if (self::hasUserColumn($db, $optionalColumn)) {
                    $columns[] = $optionalColumn;
                }
            }
        }

        $stmt = $db->prepare(
            sprintf(
                'SELECT %s FROM users WHERE id = :id LIMIT 1',
                implode(', ', $columns)
            )
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new HttpException('User not found', 404);
        }

        return self::normalizeUser($user);
    }

    private static function hasUserColumn(PDO $db, string $column): bool
    {
        $cacheKey = 'users:' . $column;
        if (array_key_exists($cacheKey, self::$userColumnCache)) {
            return self::$userColumnCache[$cacheKey];
        }

        $exists = Schema::hasColumn($db, 'users', $column);
        self::$userColumnCache[$cacheKey] = $exists;

        return $exists;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function normalizeUser(array $row): array
    {
        unset($row['password_hash']);

        $row['id'] = (int) ($row['id'] ?? 0);
        $active = $row['is_active'] ?? false;
        $seed = $row['is_seed'] ?? false;

        $row['is_active'] = in_array($active, [true, 1, '1', 't', 'true'], true);
        $row['is_seed'] = in_array($seed, [true, 1, '1', 't', 'true'], true);

        $role = (string) ($row['role'] ?? 'user');
        $row['role'] = in_array($role, ['user', 'admin', 'owner'], true) ? $role : 'user';

        return $row;
    }
}
