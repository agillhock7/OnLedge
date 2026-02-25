<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Request;
use App\Helpers\Response;
use PDO;

final class SearchController
{
    public function __construct(private readonly PDO $db, private readonly SessionAuth $auth)
    {
    }

    public function query(): void
    {
        $userId = $this->auth->requireUserId();
        $q = trim((string) Request::query('q', ''));

        if ($q === '') {
            Response::json(['items' => []]);
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at,
                    ts_rank_cd(search_vector, plainto_tsquery('simple', :query_rank)) AS rank
             FROM receipts
             WHERE user_id = :user_id
               AND search_vector @@ plainto_tsquery('simple', :query_match)
             ORDER BY rank DESC, created_at DESC
             LIMIT 50"
        );
        $stmt->execute([
            ':query_rank' => $q,
            ':query_match' => $q,
            ':user_id' => $userId,
        ]);

        $items = $stmt->fetchAll() ?: [];

        foreach ($items as &$item) {
            $item['user_id'] = (int) ($item['user_id'] ?? 0);
            $item['total'] = isset($item['total']) ? (float) $item['total'] : null;
            $item['rank'] = isset($item['rank']) ? (float) $item['rank'] : 0.0;
            $item['tags'] = $this->parsePgArray((string) ($item['tags'] ?? '{}'));
            if (isset($item['processing_explanation']) && is_string($item['processing_explanation'])) {
                $decoded = json_decode($item['processing_explanation'], true);
                $item['processing_explanation'] = is_array($decoded) ? $decoded : [];
            }
        }

        Response::json(['items' => $items]);
    }

    /** @return array<int, string> */
    private function parsePgArray(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '{}' || $trimmed === '') {
            return [];
        }

        $inner = trim($trimmed, '{}');
        if ($inner === '') {
            return [];
        }

        $parts = str_getcsv($inner);
        $parts = array_map(static fn (string $item): string => trim($item, '"'), $parts);
        return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
    }
}
