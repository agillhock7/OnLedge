<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

final class Schema
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function hasTable(PDO $db, string $table): bool
    {
        $key = sprintf('table:%s', $table);
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $stmt = $db->prepare(
            "SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = :table
            )"
        );
        $stmt->execute([':table' => $table]);
        $exists = (bool) $stmt->fetchColumn();
        self::$cache[$key] = $exists;

        return $exists;
    }

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $key = sprintf('column:%s:%s', $table, $column);
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $stmt = $db->prepare(
            "SELECT EXISTS (
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = :table
                  AND column_name = :column
            )"
        );
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        $exists = (bool) $stmt->fetchColumn();
        self::$cache[$key] = $exists;

        return $exists;
    }

    public static function hasAdminUserColumns(PDO $db): bool
    {
        return self::hasColumn($db, 'users', 'role')
            && self::hasColumn($db, 'users', 'is_active')
            && self::hasColumn($db, 'users', 'is_seed');
    }

    public static function hasSupportTickets(PDO $db): bool
    {
        return self::hasTable($db, 'support_tickets');
    }

    public static function hasSupportTicketMessages(PDO $db): bool
    {
        return self::hasTable($db, 'support_ticket_messages');
    }

    public static function hasSupportThreadColumns(PDO $db): bool
    {
        return self::hasColumn($db, 'support_tickets', 'last_message_at')
            && self::hasColumn($db, 'support_tickets', 'last_message_by_user_id')
            && self::hasColumn($db, 'support_tickets', 'closed_at');
    }

    public static function hasOauthTables(PDO $db): bool
    {
        return self::hasTable($db, 'oauth_identities');
    }
}
