<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Request;
use App\Helpers\Response;
use PDO;

final class ExportController
{
    public function __construct(private readonly PDO $db, private readonly SessionAuth $auth)
    {
    }

    public function csv(): void
    {
        $userId = $this->auth->requireUserId();
        $from = trim((string) Request::query('from', ''));
        $to = trim((string) Request::query('to', ''));

        $conditions = ['user_id = :user_id'];
        $params = [':user_id' => $userId];

        if ($from !== '') {
            $conditions[] = 'purchased_at >= :from';
            $params[':from'] = $from;
        }

        if ($to !== '') {
            $conditions[] = 'purchased_at <= :to';
            $params[':to'] = $to;
        }

        $sql = 'SELECT id, merchant, total, currency, purchased_at, category, notes, created_at
                FROM receipts
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $csvRows = [];
        foreach ($rows as $row) {
            $csvRows[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['merchant'] ?? ''),
                (string) ($row['total'] ?? ''),
                (string) ($row['currency'] ?? ''),
                (string) ($row['purchased_at'] ?? ''),
                (string) ($row['category'] ?? ''),
                (string) ($row['notes'] ?? ''),
                (string) ($row['created_at'] ?? ''),
            ];
        }

        Response::csv(
            'receipts-export.csv',
            ['id', 'merchant', 'total', 'currency', 'purchased_at', 'category', 'notes', 'created_at'],
            $csvRows
        );
    }
}
