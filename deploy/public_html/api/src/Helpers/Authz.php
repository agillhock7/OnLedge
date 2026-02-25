<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

final class Authz
{
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
        $stmt = $db->prepare(
            'SELECT id, email, role, is_active, is_seed, created_at, updated_at, disabled_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new HttpException('User not found', 404);
        }

        return self::normalizeUser($user);
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
