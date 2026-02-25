<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\RuleEngine;
use App\Helpers\Schema;
use App\Services\ReceiptAiExtractor;
use DateTimeImmutable;
use PDO;

final class ReceiptController
{
    /** @var array<int, string> */
    private const BASE_RECEIPT_COLUMNS = [
        'id',
        'user_id',
        'merchant',
        'total',
        'currency',
        'purchased_at',
        'notes',
        'raw_text',
        'category',
        'tags',
        'file_path',
        'processing_explanation',
        'created_at',
        'updated_at',
    ];

    /** @var array<int, string> */
    private const OPTIONAL_RECEIPT_COLUMNS = [
        'merchant_address',
        'receipt_number',
        'purchased_time',
        'subtotal',
        'tax',
        'tip',
        'line_items',
        'payment_method',
        'payment_last4',
        'ai_confidence',
        'ai_model',
        'processed_at',
    ];

    /** @var array<string, bool> */
    private array $receiptColumnCache = [];

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
            sprintf(
                'SELECT %s
                 FROM receipts
                 WHERE user_id = :user_id
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset',
                $this->receiptColumnsSql(),
            )
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
            sprintf(
                'INSERT INTO receipts (user_id, merchant, total, currency, purchased_at, notes, raw_text, category, tags, file_path, processing_explanation)
                 VALUES (:user_id, :merchant, :total, :currency, :purchased_at, :notes, :raw_text, :category, CAST(:tags AS text[]), :file_path, CAST(:processing_explanation AS jsonb))
                 RETURNING %s',
                $this->receiptColumnsSql(),
            )
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
            sprintf(
                'SELECT %s
                 FROM receipts
                 WHERE id = :id AND user_id = :user_id LIMIT 1',
                $this->receiptColumnsSql(),
            )
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $receipt = $stmt->fetch();

        if (!$receipt) {
            throw new HttpException('Receipt not found', 404);
        }

        Response::json(['item' => $this->normalizeReceipt($receipt)]);
    }

    /** @param array<string, string> $params */
    public function image(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (string) ($params['id'] ?? '');

        $stmt = $this->db->prepare(
            'SELECT file_path
             FROM receipts
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException('Receipt not found', 404);
        }

        $filePath = trim((string) ($row['file_path'] ?? ''));
        if ($filePath === '') {
            throw new HttpException('Receipt image not found', 404);
        }

        $resolvedPath = realpath($filePath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new HttpException('Receipt image not found', 404);
        }

        $uploadDir = trim((string) ($this->config['uploads']['dir'] ?? ''));
        if ($uploadDir !== '') {
            $resolvedUploadDir = realpath($uploadDir);
            if ($resolvedUploadDir !== false) {
                $prefix = rtrim($resolvedUploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if ($resolvedPath !== $resolvedUploadDir && !str_starts_with($resolvedPath, $prefix)) {
                    throw new HttpException('Receipt image path is not allowed', 403);
                }
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $resolvedPath) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        $contentType = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
        $fileSize = filesize($resolvedPath);
        $filename = basename($resolvedPath);

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        if ($fileSize !== false) {
            header('Content-Length: ' . (string) $fileSize);
        }

        $stream = fopen($resolvedPath, 'rb');
        if ($stream === false) {
            throw new HttpException('Unable to read receipt image', 500);
        }

        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
        }

        fclose($stream);
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

        $setClauses = [
            'merchant = :merchant',
            'total = :total',
            'currency = :currency',
            'purchased_at = :purchased_at',
            'notes = :notes',
            'raw_text = :raw_text',
            'category = :category',
            'tags = CAST(:tags AS text[])',
        ];

        $params = [
            ':merchant' => $merchant !== '' ? $merchant : null,
            ':total' => $this->numericOrNull($total),
            ':currency' => $currency ?: 'USD',
            ':purchased_at' => $purchasedAt !== '' ? $purchasedAt : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':raw_text' => $rawText !== '' ? $rawText : null,
            ':category' => $category !== '' ? $category : null,
            ':tags' => $this->toPgArray($tags),
            ':id' => $id,
            ':user_id' => $userId,
        ];

        if ($this->hasReceiptColumn('merchant_address')) {
            $setClauses[] = 'merchant_address = :merchant_address';
            $merchantAddress = array_key_exists('merchant_address', $input)
                ? trim((string) $input['merchant_address'])
                : trim((string) ($existing['merchant_address'] ?? ''));
            $params[':merchant_address'] = $merchantAddress !== '' ? $merchantAddress : null;
        }

        if ($this->hasReceiptColumn('receipt_number')) {
            $setClauses[] = 'receipt_number = :receipt_number';
            $receiptNumber = array_key_exists('receipt_number', $input)
                ? trim((string) $input['receipt_number'])
                : trim((string) ($existing['receipt_number'] ?? ''));
            $params[':receipt_number'] = $receiptNumber !== '' ? $receiptNumber : null;
        }

        if ($this->hasReceiptColumn('purchased_time')) {
            $setClauses[] = 'purchased_time = :purchased_time';
            $purchasedTime = array_key_exists('purchased_time', $input)
                ? trim((string) $input['purchased_time'])
                : trim((string) ($existing['purchased_time'] ?? ''));
            $params[':purchased_time'] = $purchasedTime !== '' ? $purchasedTime : null;
        }

        if ($this->hasReceiptColumn('subtotal')) {
            $setClauses[] = 'subtotal = :subtotal';
            $subtotal = array_key_exists('subtotal', $input) ? $input['subtotal'] : ($existing['subtotal'] ?? null);
            $params[':subtotal'] = $this->numericOrNull($subtotal);
        }

        if ($this->hasReceiptColumn('tax')) {
            $setClauses[] = 'tax = :tax';
            $tax = array_key_exists('tax', $input) ? $input['tax'] : ($existing['tax'] ?? null);
            $params[':tax'] = $this->numericOrNull($tax);
        }

        if ($this->hasReceiptColumn('tip')) {
            $setClauses[] = 'tip = :tip';
            $tip = array_key_exists('tip', $input) ? $input['tip'] : ($existing['tip'] ?? null);
            $params[':tip'] = $this->numericOrNull($tip);
        }

        if ($this->hasReceiptColumn('payment_method')) {
            $setClauses[] = 'payment_method = :payment_method';
            $paymentMethod = array_key_exists('payment_method', $input)
                ? trim((string) $input['payment_method'])
                : trim((string) ($existing['payment_method'] ?? ''));
            $params[':payment_method'] = $paymentMethod !== '' ? $paymentMethod : null;
        }

        if ($this->hasReceiptColumn('payment_last4')) {
            $setClauses[] = 'payment_last4 = :payment_last4';
            $paymentLast4 = array_key_exists('payment_last4', $input)
                ? trim((string) $input['payment_last4'])
                : trim((string) ($existing['payment_last4'] ?? ''));
            $digits = preg_replace('/\D+/', '', $paymentLast4);
            $params[':payment_last4'] = is_string($digits) && $digits !== '' ? substr($digits, -4) : null;
        }

        if ($this->hasReceiptColumn('line_items')) {
            $setClauses[] = 'line_items = CAST(:line_items AS jsonb)';
            $lineItems = array_key_exists('line_items', $input)
                ? $this->normalizeLineItems($input['line_items'])
                : $this->normalizeLineItems($existing['line_items'] ?? []);
            $params[':line_items'] = json_encode($lineItems, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->db->prepare(
            sprintf(
                'UPDATE receipts
                 SET %s
                 WHERE id = :id AND user_id = :user_id
                 RETURNING %s',
                implode(",\n                     ", $setClauses),
                $this->receiptColumnsSql(),
            )
        );
        $stmt->execute($params);

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

        $extractor = new ReceiptAiExtractor($this->config);
        $aiResult = $extractor->extract($receipt);
        $receiptAfterAi = $this->mergeAiFields($receipt, $aiResult['fields'] ?? []);

        $rules = $this->findRules($userId);
        $ruleResult = RuleEngine::apply($receiptAfterAi, $rules);

        $updatedForRules = $ruleResult['updated'] ?? [];
        $receiptAfterAi['category'] = $updatedForRules['category'] ?? ($receiptAfterAi['category'] ?? null);
        $receiptAfterAi['notes'] = $updatedForRules['notes'] ?? ($receiptAfterAi['notes'] ?? null);
        $receiptAfterAi['tags'] = $this->normalizeTags($updatedForRules['tags'] ?? ($receiptAfterAi['tags'] ?? []));

        $explanation = [
            [
                'stage' => 'ai_extraction',
                'status' => (string) ($aiResult['status'] ?? 'unknown'),
                'provider' => (string) ($aiResult['provider'] ?? 'unknown'),
                'model' => (string) ($aiResult['model'] ?? ''),
                'reason' => (string) ($aiResult['reason'] ?? ''),
                'fields_extracted' => $this->nonEmptyFieldKeys(is_array($aiResult['fields'] ?? null) ? $aiResult['fields'] : []),
            ],
        ];

        foreach (($ruleResult['explanation'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $explanation[] = [
                'stage' => 'rule_engine',
                ...$entry,
            ];
        }

        $updatedReceipt = $this->persistProcessedReceipt($id, $userId, $receiptAfterAi, $explanation, $aiResult);
        Response::json([
            'item' => $this->normalizeReceipt($updatedReceipt ?: []),
            'explanation' => $explanation,
        ]);
    }

    private function findReceipt(string $id, int $userId): array
    {
        $stmt = $this->db->prepare(
            sprintf(
                'SELECT %s
                 FROM receipts
                 WHERE id = :id AND user_id = :user_id LIMIT 1',
                $this->receiptColumnsSql(),
            )
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

    /**
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function mergeAiFields(array $receipt, array $fields): array
    {
        $merged = $receipt;

        foreach (['merchant', 'merchant_address', 'receipt_number', 'purchased_at', 'purchased_time', 'currency', 'payment_method', 'payment_last4', 'category', 'notes', 'raw_text'] as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            if ($value !== null) {
                $merged[$key] = $value;
            }
        }

        foreach (['total', 'subtotal', 'tax', 'tip', 'ai_confidence'] as $numericField) {
            if (!array_key_exists($numericField, $fields)) {
                continue;
            }

            $numericValue = $this->numericOrNull($fields[$numericField]);
            if ($numericValue !== null) {
                $merged[$numericField] = $numericValue;
            }
        }

        if (array_key_exists('tags', $fields) && is_array($fields['tags'])) {
            $tags = $this->normalizeTags($fields['tags']);
            if ($tags !== []) {
                $merged['tags'] = $tags;
            }
        }

        if (array_key_exists('line_items', $fields) && is_array($fields['line_items'])) {
            $items = $this->normalizeLineItems($fields['line_items']);
            if ($items !== [] || !isset($merged['line_items']) || !is_array($merged['line_items']) || $merged['line_items'] === []) {
                $merged['line_items'] = $items;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<int, array<string, mixed>> $explanation
     * @param array<string, mixed> $aiResult
     * @return array<string, mixed>|false
     */
    private function persistProcessedReceipt(string $id, int $userId, array $receipt, array $explanation, array $aiResult): array|false
    {
        $setClauses = [
            'merchant = :merchant',
            'total = :total',
            'currency = :currency',
            'purchased_at = :purchased_at',
            'notes = :notes',
            'raw_text = :raw_text',
            'category = :category',
            'tags = CAST(:tags AS text[])',
            'processing_explanation = CAST(:processing_explanation AS jsonb)',
        ];

        $params = [
            ':merchant' => $receipt['merchant'] ?? null,
            ':total' => $this->numericOrNull($receipt['total'] ?? null),
            ':currency' => strtoupper(trim((string) ($receipt['currency'] ?? 'USD'))) ?: 'USD',
            ':purchased_at' => $receipt['purchased_at'] ?? null,
            ':notes' => $receipt['notes'] ?? null,
            ':raw_text' => $receipt['raw_text'] ?? null,
            ':category' => $receipt['category'] ?? null,
            ':tags' => $this->toPgArray($this->normalizeTags($receipt['tags'] ?? [])),
            ':processing_explanation' => json_encode($explanation, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
            ':user_id' => $userId,
        ];

        if ($this->hasReceiptColumn('merchant_address')) {
            $setClauses[] = 'merchant_address = :merchant_address';
            $params[':merchant_address'] = $receipt['merchant_address'] ?? null;
        }

        if ($this->hasReceiptColumn('receipt_number')) {
            $setClauses[] = 'receipt_number = :receipt_number';
            $params[':receipt_number'] = $receipt['receipt_number'] ?? null;
        }

        if ($this->hasReceiptColumn('purchased_time')) {
            $setClauses[] = 'purchased_time = :purchased_time';
            $params[':purchased_time'] = $receipt['purchased_time'] ?? null;
        }

        if ($this->hasReceiptColumn('subtotal')) {
            $setClauses[] = 'subtotal = :subtotal';
            $params[':subtotal'] = $this->numericOrNull($receipt['subtotal'] ?? null);
        }

        if ($this->hasReceiptColumn('tax')) {
            $setClauses[] = 'tax = :tax';
            $params[':tax'] = $this->numericOrNull($receipt['tax'] ?? null);
        }

        if ($this->hasReceiptColumn('tip')) {
            $setClauses[] = 'tip = :tip';
            $params[':tip'] = $this->numericOrNull($receipt['tip'] ?? null);
        }

        if ($this->hasReceiptColumn('line_items')) {
            $setClauses[] = 'line_items = CAST(:line_items AS jsonb)';
            $params[':line_items'] = json_encode($this->normalizeLineItems($receipt['line_items'] ?? []), JSON_UNESCAPED_UNICODE);
        }

        if ($this->hasReceiptColumn('payment_method')) {
            $setClauses[] = 'payment_method = :payment_method';
            $params[':payment_method'] = $receipt['payment_method'] ?? null;
        }

        if ($this->hasReceiptColumn('payment_last4')) {
            $setClauses[] = 'payment_last4 = :payment_last4';
            $params[':payment_last4'] = $receipt['payment_last4'] ?? null;
        }

        if ($this->hasReceiptColumn('ai_confidence')) {
            $setClauses[] = 'ai_confidence = :ai_confidence';
            $params[':ai_confidence'] = $this->numericOrNull($receipt['ai_confidence'] ?? null);
        }

        if ($this->hasReceiptColumn('ai_model')) {
            $setClauses[] = 'ai_model = :ai_model';
            $aiModel = trim((string) ($aiResult['model'] ?? ''));
            $params[':ai_model'] = $aiModel !== '' ? $aiModel : ($receipt['ai_model'] ?? null);
        }

        if ($this->hasReceiptColumn('processed_at')) {
            $setClauses[] = 'processed_at = :processed_at';
            $params[':processed_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        }

        $stmt = $this->db->prepare(
            sprintf(
                'UPDATE receipts
                 SET %s
                 WHERE id = :id AND user_id = :user_id
                 RETURNING %s',
                implode(",\n                     ", $setClauses),
                $this->receiptColumnsSql(),
            )
        );

        $stmt->execute($params);

        return $stmt->fetch();
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
        $row['total'] = $this->numericOrNull($row['total'] ?? null);
        $row['subtotal'] = $this->numericOrNull($row['subtotal'] ?? null);
        $row['tax'] = $this->numericOrNull($row['tax'] ?? null);
        $row['tip'] = $this->numericOrNull($row['tip'] ?? null);
        $row['ai_confidence'] = $this->numericOrNull($row['ai_confidence'] ?? null);
        $row['tags'] = $this->parsePgArray((string) ($row['tags'] ?? '{}'));

        if (isset($row['processing_explanation']) && is_string($row['processing_explanation'])) {
            $decoded = json_decode($row['processing_explanation'], true);
            $row['processing_explanation'] = is_array($decoded) ? $decoded : [];
        }

        if (isset($row['line_items']) && is_string($row['line_items'])) {
            $decoded = json_decode($row['line_items'], true);
            $row['line_items'] = is_array($decoded) ? $this->normalizeLineItems($decoded) : [];
        } elseif (isset($row['line_items']) && is_array($row['line_items'])) {
            $row['line_items'] = $this->normalizeLineItems($row['line_items']);
        } else {
            $row['line_items'] = [];
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

    /** @return array<int, array<string, mixed>> */
    private function normalizeLineItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'quantity' => $this->numericOrNull($item['quantity'] ?? null),
                'unit_price' => $this->numericOrNull($item['unit_price'] ?? null),
                'total_price' => $this->numericOrNull($item['total_price'] ?? null),
                'category' => trim((string) ($item['category'] ?? '')) ?: null,
            ];
        }

        return $normalized;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, string>
     */
    private function nonEmptyFieldKeys(array $fields): array
    {
        $keys = [];
        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            if (is_array($value) && $value === []) {
                continue;
            }

            $keys[] = (string) $key;
        }

        return $keys;
    }

    private function receiptColumnsSql(): string
    {
        $columns = self::BASE_RECEIPT_COLUMNS;
        foreach (self::OPTIONAL_RECEIPT_COLUMNS as $column) {
            if ($this->hasReceiptColumn($column)) {
                $columns[] = $column;
            }
        }

        return implode(', ', $columns);
    }

    private function hasReceiptColumn(string $column): bool
    {
        if (array_key_exists($column, $this->receiptColumnCache)) {
            return $this->receiptColumnCache[$column];
        }

        $exists = Schema::hasColumn($this->db, 'receipts', $column);
        $this->receiptColumnCache[$column] = $exists;

        return $exists;
    }
}
