<?php

declare(strict_types=1);

namespace App\DB;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    /** @param array<string, mixed> $dbConfig */
    public static function connection(array $dbConfig): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            (string) ($dbConfig['host'] ?? '127.0.0.1'),
            (int) ($dbConfig['port'] ?? 5432),
            (string) ($dbConfig['dbname'] ?? ''),
            (string) ($dbConfig['sslmode'] ?? 'prefer')
        );

        self::$connection = new PDO(
            $dsn,
            (string) ($dbConfig['user'] ?? ''),
            (string) ($dbConfig['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return self::$connection;
    }
}
