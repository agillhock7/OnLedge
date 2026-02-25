<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\RuleEngine;
use PDO;

final class ReceiptController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly SessionAuth $auth,
        private readonly array $config,
    ) {
    }

    public function index(): void
    {
        $userId = $this->auth->requireUserId();
        $limit = min((int) Request::query('limit', 50), 200);
        $offset = max((int) Request::query('offset', 0), 0);

        $stmt = $this->db->prepare(
            'SELECT id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at
             FROM receipts
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        Response::json(['items' => array_map([$this, 'normalizeReceipt'], $rows)]);
    }

    public function create(): void
    {
        $userId = $this->auth->requireUserId();
        $input = Request::input();

        $merchant = trim((string) ($input['merchant'] ?? '')) ?: null;
        $total = ($input['total'] ?? null);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD')));
        $purchasedAt = trim((string) ($input['purchased_at'] ?? '')) ?: null;
        $notes = trim((string) ($input['notes'] ?? '')) ?: null;
        $rawText = trim((string) ($input['raw_text'] ?? '')) ?: null;
        $category = trim((string) ($input['category'] ?? '')) ?: null;
        $tags = $this->normalizeTags($input['tags'] ?? []);

        $filePath = $this->handleUpload();

        $stmt = $this->db->prepare(
            'INSERT INTO receipts (user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation)
             VALUES (:user_id, :merchant, :total, :currency, :purchased_at, :notes, :raw_text, :category, CAST(:tags AS text[]), :file_path, CAST(:processing_explanation AS jsonb))
             RETURNING id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':merchant' => $merchant,
            ':total' => $total !== null && $total !== '' ? (float) $total : null,
            ':currency' => $currency,
            ':purchased_at' => $purchasedAt,
            ':notes' => $notes,
            ':raw_text' => $rawText,
            ':category' => $category,
            ':tags' => $this->toPgArray($tags),
            ':file_path' => $filePath,
            ':processing_explanation' => json_encode([], JSON_UNESCAPED_UNICODE),
        ]);

        $receipt = $stmt->fetch();
        Response::json(['item' => $this->normalizeReceipt($receipt ?: [])], 201);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (string) ($params['id'] ?? '');

        $stmt = $this->db->prepare(
            'SELECT id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at
             FROM receipts
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $receipt = $stmt->fetch();

        if (!$receipt) {
            throw new HttpException('Receipt not found', 404);
        }

        Response::json(['item' => $this->normalizeReceipt($receipt)]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (string) ($params['id'] ?? '');
        $input = Request::input();

        $existing = $this->findReceipt($id, $userId);

        $merchant = array_key_exists('merchant', $input) ? trim((string) $input['merchant']) : ($existing['merchant'] ?? null);
        $total = array_key_exists('total', $input) ? $input['total'] : ($existing['total'] ?? null);
        $currency = array_key_exists('currency', $input)
            ? strtoupper(trim((string) $input['currency']))
            : (string) ($existing['currency'] ?? 'USD');
        $purchasedAt = array_key_exists('purchased_at', $input)
            ? trim((string) $input['purchased_at'])
            : ($existing['purchased_at'] ?? null);
        $notes = array_key_exists('notes', $input) ? trim((string) $input['notes']) : ($existing['notes'] ?? null);
        $rawText = array_key_exists('raw_text', $input) ? trim((string) $input['raw_text']) : ($existing['raw_text'] ?? null);
        $category = array_key_exists('category', $input) ? trim((string) $input['category']) : ($existing['category'] ?? null);
        $tags = array_key_exists('tags', $input)
            ? $this->normalizeTags($input['tags'])
            : $this->normalizeTags($existing['tags'] ?? []);

        $stmt = $this->db->prepare(
            'UPDATE receipts
             SET merchant = :merchant,
                 total = :total,
                 currency = :currency,
                 purchased_at = :purchased_at,
                 notes = :notes,
                 raw_text = :raw_text,
                 category = :category,
                 tags = CAST(:tags AS text[])
             WHERE id = :id AND user_id = :user_id
             RETURNING id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at'
        );

        $stmt->execute([
            ':merchant' => $merchant !== '' ? $merchant : null,
            ':total' => $total !== null && $total !== '' ? (float) $total : null,
            ':currency' => $currency ?: 'USD',
            ':purchased_at' => $purchasedAt !== '' ? $purchasedAt : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':raw_text' => $rawText !== '' ? $rawText : null,
            ':category' => $category !== '' ? $category : null,
            ':tags' => $this->toPgArray($tags),
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        $receipt = $stmt->fetch();
        Response::json(['item' => $this->normalizeReceipt($receipt ?: [])]);
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (string) ($params['id'] ?? '');

        $stmt = $this->db->prepare('DELETE FROM receipts WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $id, ':user_id' => $userId]);

        Response::json(['ok' => true]);
    }

    /** @param array<string, string> $params */
    public function process(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (string) ($params['id'] ?? '');

        $receipt = $this->findReceipt($id, $userId);
        $rules = $this->findRules($userId);

        $result = RuleEngine::apply($receipt, $rules);
        $updated = $result['updated'];
        $explanation = $result['explanation'];

        $stmt = $this->db->prepare(
            'UPDATE receipts
             SET category = :category,
                 notes = :notes,
                 tags = CAST(:tags AS text[]),
                 processing_explanation = CAST(:processing_explanation AS jsonb)
             WHERE id = :id AND user_id = :user_id
             RETURNING id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at'
        );
        $stmt->execute([
            ':category' => $updated['category'] ?? null,
            ':notes' => $updated['notes'] ?? null,
            ':tags' => $this->toPgArray($this->normalizeTags($updated['tags'] ?? [])),
            ':processing_explanation' => json_encode($explanation, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        $updatedReceipt = $stmt->fetch();
        Response::json([
            'item' => $this->normalizeReceipt($updatedReceipt ?: []),
            'explanation' => $explanation,
        ]);
    }

    private function findReceipt(string $id, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation, created_at, updated_at
             FROM receipts
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $receipt = $stmt->fetch();

        if (!$receipt) {
            throw new HttpException('Receipt not found', 404);
        }

        return $this->normalizeReceipt($receipt);
    }

    /** @return array<int, array<string, mixed>> */
    private function findRules(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, conditions, actions
             FROM rules
             WHERE user_id = :user_id AND is_active = TRUE
             ORDER BY priority ASC, id ASC'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll() ?: [];
    }

    private function handleUpload(): ?string
    {
        $file = Request::file('receipt');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new HttpException('Upload failed', 422);
        }

        $maxBytes = ((int) ($this->config['uploads']['max_upload_mb'] ?? 10)) * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new HttpException('Uploaded file exceeds size limit', 422);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpPath) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowed = $this->config['uploads']['allowed_mime_types'] ?? [];
        if (!is_array($allowed)) {
            $allowed = [];
        }

        if ($mime === false || $mime === null || !in_array($mime, $allowed, true)) {
            throw new HttpException('File type is not allowed', 422);
        }

        $uploadDir = (string) ($this->config['uploads']['dir'] ?? '');
        if ($uploadDir === '') {
            throw new HttpException('Upload directory is not configured', 500);
        }

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new HttpException('Unable to create upload directory', 500);
        }

        $ext = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION);
        $safeExt = $ext !== '' ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '';
        $filename = sprintf('%s%s', bin2hex(random_bytes(16)), $safeExt);
        $target = rtrim($uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $target)) {
            throw new HttpException('Unable to move uploaded file', 500);
        }

        return $target;
    }

    /** @param mixed $tags @return array<int, string> */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (!is_array($tags)) {
            return [];
        }

        $result = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }

            $clean = trim((string) $tag);
            if ($clean !== '') {
                $result[] = $clean;
            }
        }

        return array_values(array_unique($result));
    }

    /** @param array<int, string> $values */
    private function toPgArray(array $values): string
    {
        if ($values === []) {
            return '{}';
        }

        $escaped = array_map(static function (string $value): string {
            $escapedValue = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            return '"' . $escapedValue . '"';
        }, $values);

        return '{' . implode(',', $escaped) . '}';
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeReceipt(array $row): array
    {
        $row['user_id'] = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $row['total'] = isset($row['total']) ? (float) $row['total'] : null;
        $row['tags'] = $this->parsePgArray((string) ($row['tags'] ?? '{}'));

        if (isset($row['processing_explanation']) && is_string($row['processing_explanation'])) {
            $decoded = json_decode($row['processing_explanation'], true);
            $row['processing_explanation'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
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
