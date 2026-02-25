<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class ReportAiReviewer
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * @param array<string, mixed> $overview
     * @param array<int, array<string, mixed>> $receipts
     * @return array<string, mixed>
     */
    public function generate(array $overview, array $receipts): array
    {
        $aiConfig = $this->config['ai'] ?? [];
        $provider = strtolower((string) ($aiConfig['provider'] ?? 'openai'));
        $enabled = (bool) ($aiConfig['enabled'] ?? false);

        if (!$enabled) {
            return [
                'status' => 'skipped',
                'provider' => $provider,
                'model' => '',
                'reason' => 'AI extraction disabled in config.',
            ];
        }

        if ($provider !== 'openai') {
            return [
                'status' => 'skipped',
                'provider' => $provider,
                'model' => '',
                'reason' => 'Unsupported AI provider configured.',
            ];
        }

        $openai = is_array($aiConfig['openai'] ?? null) ? $aiConfig['openai'] : [];
        $apiKey = trim((string) ($openai['api_key'] ?? ''));
        $model = trim((string) ($openai['model'] ?? 'gpt-4o-mini'));
        $baseUrl = rtrim((string) ($openai['base_url'] ?? 'https://api.openai.com/v1'), '/');
        $timeout = max(10, (int) ($openai['timeout_seconds'] ?? 45));
        $maxOutputTokens = max(1200, min(9000, (int) ($openai['report_max_output_tokens'] ?? 3000)));

        if ($apiKey === '') {
            return [
                'status' => 'skipped',
                'provider' => 'openai',
                'model' => $model,
                'reason' => 'OpenAI API key is missing.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'reason' => 'cURL is not available in PHP runtime.',
            ];
        }

        $payload = $this->buildRequestPayload($model, $overview, $receipts, $maxOutputTokens);
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
                'reason' => $error,
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            $message = 'OpenAI request failed.';
            if (is_array($decoded)) {
                $apiMessage = trim((string) ($decoded['error']['message'] ?? ''));
                if ($apiMessage !== '') {
                    $message = $apiMessage;
                }
            }

            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'reason' => $message,
            ];
        }

        $parsed = $this->extractOutputObject($decoded);
        if (!is_array($parsed)) {
            $jsonText = $this->extractOutputText($decoded);
            if ($jsonText !== null) {
                $parsed = $this->decodeJsonText($jsonText);
            }
        }

        if (!is_array($parsed)) {
            return [
                'status' => 'failed',
                'provider' => 'openai',
                'model' => $model,
                'reason' => $this->buildInvalidJsonReason($decoded),
            ];
        }

        $normalized = $this->normalizeReport($parsed);
        $normalized['status'] = 'success';
        $normalized['provider'] = 'openai';
        $normalized['model'] = $model;
        $normalized['generated_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        $normalized['markdown'] = self::toMarkdown($normalized, $overview['window'] ?? []);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $window
     */
    public static function toMarkdown(array $report, array $window): string
    {
        $lines = [];
        $lines[] = '# OnLedge AI Report';
        $lines[] = '';

        $headline = trim((string) ($report['headline'] ?? 'Spending Report'));
        if ($headline !== '') {
            $lines[] = '## ' . $headline;
            $lines[] = '';
        }

        $from = trim((string) ($window['from'] ?? ''));
        $to = trim((string) ($window['to'] ?? ''));
        if ($from !== '' || $to !== '') {
            $lines[] = sprintf('Window: %s to %s', $from !== '' ? $from : 'start', $to !== '' ? $to : 'today');
            $lines[] = '';
        }

        $lines[] = trim((string) ($report['executive_summary'] ?? ''));
        $lines[] = '';

        $trendHighlights = is_array($report['trend_highlights'] ?? null) ? $report['trend_highlights'] : [];
        $lines[] = '### Trend Highlights';
        foreach ($trendHighlights as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $lines[] = '- ' . $text;
            }
        }
        $lines[] = '';

        $anomalies = is_array($report['anomalies'] ?? null) ? $report['anomalies'] : [];
        $lines[] = '### Anomalies';
        foreach ($anomalies as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? '')); 
            $detail = trim((string) ($item['detail'] ?? ''));
            $severity = trim((string) ($item['severity'] ?? ''));
            if ($title !== '' || $detail !== '') {
                $lines[] = sprintf('- %s (%s): %s', $title !== '' ? $title : 'Item', $severity !== '' ? strtoupper($severity) : 'N/A', $detail);
            }
        }
        $lines[] = '';

        $recommendations = is_array($report['recommendations'] ?? null) ? $report['recommendations'] : [];
        $lines[] = '### Recommendations';
        foreach ($recommendations as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? '')); 
            $detail = trim((string) ($item['detail'] ?? ''));
            $impact = trim((string) ($item['impact'] ?? ''));
            if ($title !== '' || $detail !== '') {
                $lines[] = sprintf('- %s (%s): %s', $title !== '' ? $title : 'Recommendation', $impact !== '' ? strtoupper($impact) : 'N/A', $detail);
            }
        }
        $lines[] = '';

        $nextActions = is_array($report['next_actions'] ?? null) ? $report['next_actions'] : [];
        $lines[] = '### Next Actions';
        foreach ($nextActions as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $lines[] = '- ' . $text;
            }
        }
        $lines[] = '';

        return trim(implode("\n", $lines));
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
                return 'OpenAI output was incomplete (' . $incompleteReason . '). Increase report_max_output_tokens.';
            }

            return 'OpenAI output was incomplete. Increase report_max_output_tokens.';
        }

        return 'OpenAI returned invalid JSON.';
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function normalizeReport(array $parsed): array
    {
        return [
            'headline' => $this->normalizeText($parsed['headline'] ?? null, 'Spending Report'),
            'executive_summary' => $this->normalizeText($parsed['executive_summary'] ?? null, 'No executive summary provided.'),
            'trend_highlights' => $this->normalizeStringList($parsed['trend_highlights'] ?? [], 8),
            'anomalies' => $this->normalizeIssueList($parsed['anomalies'] ?? [], 'severity'),
            'recommendations' => $this->normalizeIssueList($parsed['recommendations'] ?? [], 'impact'),
            'budget_signals' => $this->normalizeSignalList($parsed['budget_signals'] ?? []),
            'next_actions' => $this->normalizeStringList($parsed['next_actions'] ?? [], 8),
        ];
    }

    /** @return array<int, string> */
    private function normalizeStringList(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, string>> */
    private function normalizeIssueList(mixed $value, string $levelKey): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $detail = trim((string) ($item['detail'] ?? ''));
            $level = strtolower(trim((string) ($item[$levelKey] ?? 'medium')));
            if (!in_array($level, ['low', 'medium', 'high'], true)) {
                $level = 'medium';
            }

            if ($title === '' && $detail === '') {
                continue;
            }

            $out[] = [
                'title' => $title !== '' ? $title : 'Item',
                'detail' => $detail,
                $levelKey => $level,
            ];

            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, string>> */
    private function normalizeSignalList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $signalValue = trim((string) ($item['value'] ?? ''));
            if ($label === '' || $signalValue === '') {
                continue;
            }

            $out[] = ['label' => $label, 'value' => $signalValue];
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    private function normalizeText(mixed $value, string $fallback): string
    {
        $text = trim((string) $value);
        return $text !== '' ? $text : $fallback;
    }

    /**
     * @param array<string, mixed> $overview
     * @param array<int, array<string, mixed>> $receipts
     * @return array<string, mixed>
     */
    private function buildRequestPayload(string $model, array $overview, array $receipts, int $maxOutputTokens): array
    {
        $sampleReceipts = [];
        foreach (array_slice($receipts, 0, 140) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sampleReceipts[] = [
                'date' => (string) ($row['purchased_at'] ?? ''),
                'merchant' => (string) ($row['merchant'] ?? ''),
                'total' => (float) ($row['total'] ?? 0),
                'category' => (string) ($row['category'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'USD'),
                'tags' => is_array($row['tags'] ?? null) ? array_values($row['tags']) : [],
            ];
        }

        $context = [
            'summary' => $overview['summary'] ?? [],
            'series' => $overview['series'] ?? [],
            'window' => $overview['window'] ?? [],
            'sample_receipts' => $sampleReceipts,
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'headline' => ['type' => 'string'],
                'executive_summary' => ['type' => 'string'],
                'trend_highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
                'anomalies' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                        'required' => ['title', 'detail', 'severity'],
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'impact' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                        'required' => ['title', 'detail', 'impact'],
                    ],
                ],
                'budget_signals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                        ],
                        'required' => ['label', 'value'],
                    ],
                ],
                'next_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'headline',
                'executive_summary',
                'trend_highlights',
                'anomalies',
                'recommendations',
                'budget_signals',
                'next_actions',
            ],
        ];

        $instructions = "You are a financial analyst for expense data. Generate a useful and practical report.\n"
            . "Requirements:\n"
            . "- Base all insights only on the provided JSON dataset.\n"
            . "- Keep language clear and direct for small business users.\n"
            . "- Highlight trends, anomalies, and concrete actions.\n"
            . "- Do not hallucinate missing values.\n"
            . "- Return JSON only matching the schema.\n"
            . "Dataset JSON:\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => 'You produce business-ready spending reports.'],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $instructions],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'onledge_ai_report',
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
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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

    /** @param array<string, mixed> $response */
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

    /** @return array<string, mixed>|null */
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

        $depth = 0;
        $inString = false;
        $escaping = false;
        $length = strlen($text);

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
}
