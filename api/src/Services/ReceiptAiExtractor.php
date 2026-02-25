<?php

declare(strict_types=1);

namespace App\Services;

final class ReceiptAiExtractor
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array{
     *   status: string,
     *   provider: string,
     *   model: string,
     *   fields: array<string, mixed>,
     *   reason?: string
     * }
     */
    public function extract(array $receipt): array
    {
        $aiConfig = $this->config['ai'] ?? [];
        $provider = strtolower((string) ($aiConfig['provider'] ?? 'openai'));
        $enabled = (bool) ($aiConfig['enabled'] ?? false);

        if (!$enabled) {
            return [
                'status' => 'skipped',
                'provider' => $provider,
                'model' => '',
                'fields' => [],
                'reason' => 'AI extraction disabled in config.',
            ];
        }

        if ($provider !== 'openai') {
            return [
                'status' => 'skipped',
                'provider' => $provider,
                'model' => '',
                'fields' => [],
                'reason' => 'Unsupported AI provider configured.',
            ];
        }

        $openai = is_array($aiConfig['openai'] ?? null) ? $aiConfig['openai'] : [];
        $apiKey = trim((string) ($openai['api_key'] ?? ''));
        $model = trim((string) ($openai['model'] ?? 'gpt-4o-mini'));
        $baseUrl = rtrim((string) ($openai['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $timeout = max(5, (int) ($openai['timeout_seconds'] ?? 45));
        $maxOutputTokens = max(800, min(8000, (int) ($openai['max_output_tokens'] ?? 2600)));

        if ($apiKey === '') {
            return [
                'status' => 'skipped',
                'provider' => 'openai',
                'model' => $model,
                'fields' => [],
                'reason' => 'OpenAI API key is missing.',
            ];
        }

        $image = $this->loadImageAsDataUrl((string) ($receipt['file_path'] ?? ''));
        if ($image === null) {
            return [
                'status' => 'skipped',
                'provider' => 'openai',
                'model' => $model,
                'fields' => [],
                'reason' => 'Receipt image is missing or unreadable.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'fields' => [],
                'reason' => 'cURL is not available in PHP runtime.',
            ];
        }

        $existingRawText = trim((string) ($receipt['raw_text'] ?? ''));
        $payload = $this->buildRequestPayload($model, $image['data_url'], $existingRawText, $maxOutputTokens);

        [$statusCode, $decoded, $error] = $this->postJson(
            $baseUrl . '/responses',
            $payload,
            [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            $timeout
        );

        if ($error !== null) {
            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'fields' => [],
                'reason' => $error,
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            $message = 'OpenAI request failed.';
            if (is_array($decoded)) {
                $apiMessage = (string) ($decoded['error']['message'] ?? '');
                if ($apiMessage !== '') {
                    $message = $apiMessage;
                }
            }

            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'fields' => [],
                'reason' => $message,
            ];
        }

        $parsed = $this->extractOutputObject($decoded);
        if (!is_array($parsed)) {
            $jsonText = $this->extractOutputText($decoded);
            if ($jsonText === null) {
                return [
                    'status' => 'failed',
                    'provider' => 'openai',
                    'model' => $model,
                    'fields' => [],
                    'reason' => 'OpenAI response did not include structured output.',
                ];
            }

            $parsed = $this->decodeJsonText($jsonText);
        }

        if (!is_array($parsed)) {
            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'fields' => [],
                'reason' => $this->buildInvalidJsonReason($decoded),
            ];
        }

        return [
            'status' => 'success',
            'provider' => 'openai',
            'model' => $model,
            'fields' => $this->normalizeFields($parsed),
        ];
    }

    /**
     * @return array{mime: string, data_url: string}|null
     */
    private function loadImageAsDataUrl(string $filePath): ?array
    {
        $path = trim($filePath);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $path) : null;
        if ($finfo) {
            finfo_close($finfo);
        }

        if (!is_string($mime) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return [
            'mime' => $mime,
            'data_url' => sprintf('data:%s;base64,%s', $mime, base64_encode($raw)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestPayload(
        string $model,
        string $imageDataUrl,
        string $existingRawText,
        int $maxOutputTokens
    ): array
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'merchant_name' => ['type' => ['string', 'null']],
                'merchant_address' => ['type' => ['string', 'null']],
                'receipt_number' => ['type' => ['string', 'null']],
                'purchase_date' => ['type' => ['string', 'null']],
                'purchase_time' => ['type' => ['string', 'null']],
                'currency' => ['type' => ['string', 'null']],
                'subtotal' => ['type' => ['number', 'null']],
                'tax' => ['type' => ['number', 'null']],
                'tip' => ['type' => ['number', 'null']],
                'total' => ['type' => ['number', 'null']],
                'payment_method' => ['type' => ['string', 'null']],
                'payment_last4' => ['type' => ['string', 'null']],
                'category' => ['type' => ['string', 'null']],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'summary_notes' => ['type' => ['string', 'null']],
                'raw_text' => ['type' => ['string', 'null']],
                'confidence' => ['type' => ['number', 'null']],
                'line_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'quantity' => ['type' => ['number', 'null']],
                            'unit_price' => ['type' => ['number', 'null']],
                            'total_price' => ['type' => ['number', 'null']],
                            'category' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['name', 'quantity', 'unit_price', 'total_price', 'category'],
                    ],
                ],
            ],
            'required' => [
                'merchant_name',
                'merchant_address',
                'receipt_number',
                'purchase_date',
                'purchase_time',
                'currency',
                'subtotal',
                'tax',
                'tip',
                'total',
                'payment_method',
                'payment_last4',
                'category',
                'tags',
                'summary_notes',
                'raw_text',
                'confidence',
                'line_items',
            ],
        ];

        $textPrompt = "Extract all receipt fields with best effort.
Rules:
- Return valid JSON only using the provided schema.
- purchase_date must be YYYY-MM-DD when known.
- currency should be ISO code like USD.
- line_items must include each purchased item.
- confidence must be between 0 and 1.
- Keep unknown values as null.
- raw_text should contain OCR-style full receipt text.
";

        if ($existingRawText !== '') {
            $textPrompt .= "\nExisting raw text from user/system:\n" . $existingRawText;
        }

        return [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'You are an expert receipt parser for accounting systems.'],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $textPrompt],
                        ['type' => 'input_image', 'image_url' => $imageDataUrl],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'receipt_extraction',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
            'max_output_tokens' => $maxOutputTokens,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array{0: int, 1: array<string, mixed>|null, 2: string|null}
     */
    private function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            return [0, null, 'Unable to initialize cURL request.'];
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $raw = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            return [0, null, 'OpenAI HTTP request failed: ' . $curlErr];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [$statusCode, null, 'OpenAI response was not valid JSON.'];
        }

        return [$statusCode, $decoded, null];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): ?string
    {
        $outputText = $response['output_text'] ?? null;
        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        $output = $response['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        foreach ($output as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $content = $entry['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }

                $text = $chunk['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function extractOutputObject(array $response): ?array
    {
        $directParsed = $response['output_parsed'] ?? null;
        if (is_array($directParsed)) {
            return $directParsed;
        }

        $output = $response['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        foreach ($output as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $content = $entry['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }

                $parsed = $chunk['parsed'] ?? null;
                if (is_array($parsed)) {
                    return $parsed;
                }

                $json = $chunk['json'] ?? null;
                if (is_array($json)) {
                    return $json;
                }

                if (is_string($json)) {
                    $decoded = $this->decodeJsonText($json);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonText(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            return $parsed;
        }

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches) === 1) {
            $fenced = json_decode((string) ($matches[1] ?? ''), true);
            if (is_array($fenced)) {
                return $fenced;
            }
        }

        $firstObject = $this->extractFirstJsonObject($text);
        if ($firstObject !== null) {
            $recovered = json_decode($firstObject, true);
            if (is_array($recovered)) {
                return $recovered;
            }
        }

        return null;
    }

    private function extractFirstJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $length = strlen($text);
        $depth = 0;
        $inString = false;
        $escaping = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaping) {
                    $escaping = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaping = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function buildInvalidJsonReason(array $response): string
    {
        $status = strtolower(trim((string) ($response['status'] ?? '')));
        if ($status === 'incomplete') {
            $incompleteReason = trim((string) ($response['incomplete_details']['reason'] ?? ''));
            if ($incompleteReason !== '') {
                return 'OpenAI output was incomplete (' . $incompleteReason . '). Increase max_output_tokens.';
            }

            return 'OpenAI output was incomplete. Increase max_output_tokens.';
        }

        return 'OpenAI returned invalid JSON.';
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function normalizeFields(array $parsed): array
    {
        $fields = [];

        $fields['merchant'] = $this->normalizeString($parsed['merchant_name'] ?? null);
        $fields['merchant_address'] = $this->normalizeString($parsed['merchant_address'] ?? null);
        $fields['receipt_number'] = $this->normalizeString($parsed['receipt_number'] ?? null);
        $fields['purchased_at'] = $this->normalizeDate($this->normalizeString($parsed['purchase_date'] ?? null));
        $fields['purchased_time'] = $this->normalizeString($parsed['purchase_time'] ?? null);
        $fields['currency'] = $this->normalizeCurrency($parsed['currency'] ?? null);
        $fields['subtotal'] = $this->normalizeNumber($parsed['subtotal'] ?? null);
        $fields['tax'] = $this->normalizeNumber($parsed['tax'] ?? null);
        $fields['tip'] = $this->normalizeNumber($parsed['tip'] ?? null);
        $fields['total'] = $this->normalizeNumber($parsed['total'] ?? null);
        $fields['payment_method'] = $this->normalizeString($parsed['payment_method'] ?? null);
        $fields['payment_last4'] = $this->normalizeLast4($parsed['payment_last4'] ?? null);
        $fields['category'] = $this->normalizeString($parsed['category'] ?? null);
        $fields['tags'] = $this->normalizeTags($parsed['tags'] ?? []);
        $fields['notes'] = $this->normalizeString($parsed['summary_notes'] ?? null);
        $fields['raw_text'] = $this->normalizeString($parsed['raw_text'] ?? null);
        $fields['ai_confidence'] = $this->normalizeConfidence($parsed['confidence'] ?? null);
        $fields['line_items'] = $this->normalizeLineItems($parsed['line_items'] ?? []);

        return $fields;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $text = $this->normalizeString($value);
        if ($text === null) {
            return null;
        }

        $clean = strtoupper(substr($text, 0, 3));
        return preg_match('/^[A-Z]{3}$/', $clean) === 1 ? $clean : null;
    }

    private function normalizeNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        if (!is_string($value)) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $value);
        if ($clean === null || $clean === '' || !is_numeric($clean)) {
            return null;
        }

        return round((float) $clean, 2);
    }

    private function normalizeLast4(mixed $value): ?string
    {
        $text = $this->normalizeString($value);
        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $text);
        if ($digits === null || $digits === '') {
            return null;
        }

        return substr($digits, -4);
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $num = (float) $value;
        } elseif (is_string($value) && is_numeric($value)) {
            $num = (float) $value;
        } else {
            return null;
        }

        if ($num < 0) {
            $num = 0;
        } elseif ($num > 1) {
            $num = 1;
        }

        return round($num, 4);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $text = trim((string) $item);
            if ($text !== '') {
                $tags[] = $text;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLineItems(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->normalizeString($item['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'quantity' => $this->normalizeNumber($item['quantity'] ?? null),
                'unit_price' => $this->normalizeNumber($item['unit_price'] ?? null),
                'total_price' => $this->normalizeNumber($item['total_price'] ?? null),
                'category' => $this->normalizeString($item['category'] ?? null),
            ];
        }

        return $items;
    }
}
