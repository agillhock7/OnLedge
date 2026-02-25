<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Services\ReportAiReviewer;
use DateTimeImmutable;
use PDO;

final class ReportsController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly SessionAuth $auth,
        private readonly array $config,
    ) {
    }

    public function overview(): void
    {
        $userId = $this->auth->requireUserId();
        [$from, $to] = $this->resolveWindow(
            trim((string) Request::query('from', '')),
            trim((string) Request::query('to', ''))
        );

        $receipts = $this->loadReceipts($userId, $from, $to);
        $overview = $this->buildOverview($receipts, $from, $to);
        $response = $overview;
        unset($response['sample_receipts']);
        Response::json($response);
    }

    public function aiReview(): void
    {
        $userId = $this->auth->requireUserId();
        $input = Request::input();

        [$from, $to] = $this->resolveWindow(
            trim((string) ($input['from'] ?? '')),
            trim((string) ($input['to'] ?? ''))
        );

        $receipts = $this->loadReceipts($userId, $from, $to);
        $overview = $this->buildOverview($receipts, $from, $to);

        $reviewer = new ReportAiReviewer($this->config);
        $aiReport = $reviewer->generate($overview, $receipts);

        if (($aiReport['status'] ?? 'failed') !== 'success') {
            $aiReport = $this->fallbackAiReport($overview, (string) ($aiReport['reason'] ?? 'AI report unavailable.'), $aiReport);
        }

        $response = $overview;
        unset($response['sample_receipts']);
        $response['ai_report'] = $aiReport;
        Response::json($response);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveWindow(string $from, string $to): array
    {
        $fromDate = $this->normalizeDate($from);
        $toDate = $this->normalizeDate($to);

        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            return [$toDate, $fromDate];
        }

        return [$fromDate, $toDate];
    }

    private function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
        if (!$parsed) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadReceipts(int $userId, ?string $from, ?string $to): array
    {
        $conditions = ['user_id = :user_id'];
        $params = [':user_id' => $userId];

        if ($from !== null) {
            $conditions[] = 'COALESCE(purchased_at, created_at::date) >= :from';
            $params[':from'] = $from;
        }

        if ($to !== null) {
            $conditions[] = 'COALESCE(purchased_at, created_at::date) <= :to';
            $params[':to'] = $to;
        }

        $sql = 'SELECT id, merchant, total, currency, purchased_at, created_at, category, tags, notes
                FROM receipts
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY COALESCE(purchased_at, created_at::date) DESC, created_at DESC
                LIMIT 3000';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll() ?: [];
        $normalized = [];

        foreach ($rows as $row) {
            $effectiveDate = $this->effectiveDate($row);
            $total = $this->toFloatOrNull($row['total'] ?? null);

            $normalized[] = [
                'id' => (string) ($row['id'] ?? ''),
                'merchant' => trim((string) ($row['merchant'] ?? '')),
                'total' => $total,
                'currency' => strtoupper(trim((string) ($row['currency'] ?? 'USD'))) ?: 'USD',
                'purchased_at' => $effectiveDate,
                'category' => trim((string) ($row['category'] ?? '')),
                'tags' => $this->parsePgArray((string) ($row['tags'] ?? '{}')),
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function effectiveDate(array $row): ?string
    {
        $purchased = trim((string) ($row['purchased_at'] ?? ''));
        if ($purchased !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchased) === 1) {
            return $purchased;
        }

        $created = trim((string) ($row['created_at'] ?? ''));
        if ($created === '') {
            return null;
        }

        return substr($created, 0, 10);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildOverview(array $rows, ?string $from, ?string $to): array
    {
        $count = count($rows);
        $sum = 0.0;
        $largest = null;
        $smallest = null;

        /** @var array<string, array{period: string, total: float, count: int}> $monthly */
        $monthly = [];
        /** @var array<string, array{category: string, total: float, count: int}> $categories */
        $categories = [];
        /** @var array<string, array{merchant: string, total: float, count: int}> $merchants */
        $merchants = [];
        /** @var array<string, int> $currencyCounts */
        $currencyCounts = [];
        /** @var array<string, bool> $activeDays */
        $activeDays = [];

        foreach ($rows as $row) {
            $total = $this->toFloatOrNull($row['total'] ?? null);
            if ($total === null) {
                $total = 0.0;
            }

            $sum += $total;

            if ($largest === null || $total > $largest) {
                $largest = $total;
            }
            if ($smallest === null || $total < $smallest) {
                $smallest = $total;
            }

            $currency = strtoupper(trim((string) ($row['currency'] ?? 'USD'))) ?: 'USD';
            $currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + 1;

            $date = trim((string) ($row['purchased_at'] ?? ''));
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $activeDays[$date] = true;

                $period = substr($date, 0, 7);
                if (!isset($monthly[$period])) {
                    $monthly[$period] = ['period' => $period, 'total' => 0.0, 'count' => 0];
                }
                $monthly[$period]['total'] += $total;
                $monthly[$period]['count']++;
            }

            $category = trim((string) ($row['category'] ?? ''));
            $category = $category !== '' ? $category : 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = ['category' => $category, 'total' => 0.0, 'count' => 0];
            }
            $categories[$category]['total'] += $total;
            $categories[$category]['count']++;

            $merchant = trim((string) ($row['merchant'] ?? ''));
            $merchant = $merchant !== '' ? $merchant : 'Unknown merchant';
            if (!isset($merchants[$merchant])) {
                $merchants[$merchant] = ['merchant' => $merchant, 'total' => 0.0, 'count' => 0];
            }
            $merchants[$merchant]['total'] += $total;
            $merchants[$merchant]['count']++;
        }

        uasort($monthly, static fn (array $a, array $b): int => strcmp($a['period'], $b['period']));

        uasort($categories, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        uasort($merchants, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        arsort($currencyCounts);

        $monthlySeries = array_values($monthly);
        if (count($monthlySeries) > 12) {
            $monthlySeries = array_slice($monthlySeries, -12);
        }

        $primaryCurrency = $currencyCounts !== [] ? (string) array_key_first($currencyCounts) : 'USD';

        $topCategories = array_slice(array_values($categories), 0, 8);
        $topMerchants = array_slice(array_values($merchants), 0, 8);

        $categoryTotal = array_reduce($topCategories, static fn (float $carry, array $row): float => $carry + (float) $row['total'], 0.0);
        foreach ($topCategories as &$entry) {
            $entry['total'] = round((float) $entry['total'], 2);
            $entry['share'] = $categoryTotal > 0 ? round(((float) $entry['total'] / $categoryTotal) * 100, 2) : 0.0;
        }
        unset($entry);

        foreach ($topMerchants as &$entry) {
            $entry['total'] = round((float) $entry['total'], 2);
        }
        unset($entry);

        foreach ($monthlySeries as &$entry) {
            $entry['total'] = round((float) $entry['total'], 2);
        }
        unset($entry);

        return [
            'window' => [
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'receipt_count' => $count,
                'spend_total' => round($sum, 2),
                'average_receipt' => $count > 0 ? round($sum / $count, 2) : 0.0,
                'largest_purchase' => round((float) ($largest ?? 0.0), 2),
                'smallest_purchase' => round((float) ($smallest ?? 0.0), 2),
                'active_days' => count($activeDays),
                'currency' => $primaryCurrency,
            ],
            'series' => [
                'monthly' => $monthlySeries,
                'categories' => $topCategories,
                'merchants' => $topMerchants,
            ],
            'sample_receipts' => array_slice($rows, 0, 120),
        ];
    }

    /**
     * @param array<string, mixed> $overview
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function fallbackAiReport(array $overview, string $reason, array $source): array
    {
        $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : [];
        $series = is_array($overview['series'] ?? null) ? $overview['series'] : [];

        $categories = is_array($series['categories'] ?? null) ? $series['categories'] : [];
        $merchants = is_array($series['merchants'] ?? null) ? $series['merchants'] : [];
        $monthly = is_array($series['monthly'] ?? null) ? $series['monthly'] : [];

        $topCategory = is_array($categories[0] ?? null) ? (string) ($categories[0]['category'] ?? 'Uncategorized') : 'Uncategorized';
        $topMerchant = is_array($merchants[0] ?? null) ? (string) ($merchants[0]['merchant'] ?? 'Unknown merchant') : 'Unknown merchant';

        $firstMonth = is_array($monthly[0] ?? null) ? (float) ($monthly[0]['total'] ?? 0.0) : 0.0;
        $lastMonth = is_array($monthly[count($monthly) - 1] ?? null) ? (float) ($monthly[count($monthly) - 1]['total'] ?? 0.0) : 0.0;

        $trendText = 'Stable month-over-month spending.';
        if ($firstMonth > 0 && $lastMonth > $firstMonth * 1.12) {
            $trendText = 'Spending trend is rising in recent months.';
        } elseif ($firstMonth > 0 && $lastMonth < $firstMonth * 0.88) {
            $trendText = 'Spending trend is tapering compared with earlier months.';
        }

        $currency = strtoupper((string) ($summary['currency'] ?? 'USD')) ?: 'USD';
        $total = (float) ($summary['spend_total'] ?? 0.0);
        $avg = (float) ($summary['average_receipt'] ?? 0.0);

        $report = [
            'status' => (string) ($source['status'] ?? 'fallback'),
            'provider' => 'rules_fallback',
            'model' => (string) ($source['model'] ?? ''),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'headline' => 'Spending Health Snapshot',
            'executive_summary' => sprintf(
                'Across %d receipts, total spend is %s %.2f with an average of %s %.2f per receipt. Top category is %s and top merchant is %s.',
                (int) ($summary['receipt_count'] ?? 0),
                $currency,
                $total,
                $currency,
                $avg,
                $topCategory,
                $topMerchant,
            ),
            'trend_highlights' => [
                $trendText,
                sprintf('Top category by spend: %s.', $topCategory),
                sprintf('Most spend concentrated at: %s.', $topMerchant),
            ],
            'anomalies' => [
                [
                    'title' => 'Fallback report in use',
                    'detail' => $reason,
                    'severity' => 'low',
                ],
            ],
            'recommendations' => [
                [
                    'title' => 'Set category budgets',
                    'detail' => 'Define monthly thresholds for your top categories and monitor variance weekly.',
                    'impact' => 'high',
                ],
                [
                    'title' => 'Review top merchants',
                    'detail' => 'Audit recurring purchases from top merchants for consolidation opportunities.',
                    'impact' => 'medium',
                ],
            ],
            'budget_signals' => [
                ['label' => 'Total Spend', 'value' => sprintf('%s %.2f', $currency, $total)],
                ['label' => 'Average Receipt', 'value' => sprintf('%s %.2f', $currency, $avg)],
                ['label' => 'Active Days', 'value' => (string) ((int) ($summary['active_days'] ?? 0))],
            ],
            'next_actions' => [
                'Validate category mapping for highest-spend receipts.',
                'Schedule weekly receipt review for outliers.',
                'Re-run AI review after adding more receipt coverage if needed.',
            ],
        ];

        $report['reason'] = $reason;
        $report['markdown'] = ReportAiReviewer::toMarkdown($report, $overview['window'] ?? []);

        return $report;
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

    private function toFloatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
