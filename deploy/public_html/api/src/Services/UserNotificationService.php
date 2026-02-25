<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use PDO;

final class UserNotificationService
{
    private TemplatedMailer $mailer;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config = [],
    ) {
        $this->mailer = new TemplatedMailer($config);
    }

    /** @return array<string, mixed> */
    public function getPreferences(int $userId): array
    {
        $this->ensureSettingsRow($userId, false);

        $stmt = $this->db->prepare(
            'SELECT user_id, weekly_report_enabled, weekly_report_last_sent_at, welcome_email_sent_at, created_at, updated_at
             FROM user_notification_settings
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch() ?: [];

        return $this->normalizeSettings($row);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updatePreferences(int $userId, array $input): array
    {
        $current = $this->getPreferences($userId);

        $weeklyEnabled = $current['weekly_report_enabled'] ?? true;
        if (array_key_exists('weekly_report_enabled', $input)) {
            $weeklyEnabled = $this->toBool($input['weekly_report_enabled']);
        }

        $stmt = $this->db->prepare(
            'UPDATE user_notification_settings
             SET weekly_report_enabled = :weekly_report_enabled
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            ':weekly_report_enabled' => $weeklyEnabled ? 'true' : 'false',
            ':user_id' => $userId,
        ]);

        return $this->getPreferences($userId);
    }

    /** @param array<string, mixed> $user */
    public function sendWelcomeEmailIfPending(array $user): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = trim((string) ($user['email'] ?? ''));
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $settings = $this->getPreferences($userId);
        if (trim((string) ($settings['welcome_email_sent_at'] ?? '')) !== '') {
            return false;
        }

        $sent = $this->mailer->send($email, 'welcome_user', [
            'user' => $user,
            'app_url' => $this->appUrl(),
        ]);

        if ($sent) {
            $this->markWelcomeSent($userId);
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $user */
    public function sendWeeklyDigestIfDue(array $user): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = trim((string) ($user['email'] ?? ''));
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $settings = $this->getPreferences($userId);
        if (!$this->toBool($settings['weekly_report_enabled'] ?? true)) {
            return false;
        }

        $lastSent = trim((string) ($settings['weekly_report_last_sent_at'] ?? ''));
        if ($lastSent !== '') {
            try {
                $last = new DateTimeImmutable($lastSent);
                $next = $last->add(new DateInterval('P7D'));
                if ($next > new DateTimeImmutable('now')) {
                    return false;
                }
            } catch (\Throwable) {
                // ignore invalid stored timestamp; continue
            }
        }

        $summary = $this->buildWeeklySummary($userId);
        $sent = $this->mailer->send($email, 'weekly_spending_report', [
            'user' => $user,
            'summary' => $summary,
            'app_url' => $this->appUrl(),
        ]);

        if ($sent) {
            $this->markWeeklyReportSent($userId);
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $user */
    public function sendTestEmail(array $user): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = trim((string) ($user['email'] ?? ''));
        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $settings = $this->getPreferences($userId);
        $summary = $this->buildWeeklySummary($userId);

        return $this->mailer->send($email, 'test_notification', [
            'user' => $user,
            'settings' => $settings,
            'summary' => $summary,
            'app_url' => $this->appUrl(),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /** @return array<string, mixed> */
    private function buildWeeklySummary(int $userId): array
    {
        $end = new DateTimeImmutable('today 23:59:59');
        $start = $end->sub(new DateInterval('P6D'))->setTime(0, 0, 0);
        $fromDate = $start->format('Y-m-d');
        $toDate = $end->format('Y-m-d');

        $baseWhere = 'user_id = :user_id
                      AND COALESCE(purchased_at, created_at::date) >= :from_date
                      AND COALESCE(purchased_at, created_at::date) <= :to_date';
        $params = [
            ':user_id' => $userId,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        $summaryStmt = $this->db->prepare(
            "SELECT COUNT(*)::int AS receipt_count,
                    COALESCE(SUM(total), 0)::numeric(14,2) AS spend_total,
                    COALESCE(AVG(total), 0)::numeric(14,2) AS avg_receipt,
                    COALESCE(MAX(total), 0)::numeric(14,2) AS largest_purchase,
                    COALESCE(NULLIF(upper(trim(currency)), ''), 'USD') AS currency
             FROM receipts
             WHERE {$baseWhere}
             GROUP BY COALESCE(NULLIF(upper(trim(currency)), ''), 'USD')
             ORDER BY COUNT(*) DESC
             LIMIT 1"
        );
        $summaryStmt->execute($params);
        $summary = $summaryStmt->fetch();

        if (!$summary) {
            $summary = [
                'receipt_count' => 0,
                'spend_total' => 0,
                'avg_receipt' => 0,
                'largest_purchase' => 0,
                'currency' => 'USD',
            ];
        }

        $categoryStmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(trim(category), ''), 'Uncategorized') AS label,
                    COUNT(*)::int AS count,
                    COALESCE(SUM(total), 0)::numeric(14,2) AS total
             FROM receipts
             WHERE {$baseWhere}
             GROUP BY 1
             ORDER BY total DESC, count DESC
             LIMIT 5"
        );
        $categoryStmt->execute($params);
        $topCategories = $categoryStmt->fetchAll() ?: [];

        $merchantStmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(trim(merchant), ''), 'Unknown merchant') AS label,
                    COUNT(*)::int AS count,
                    COALESCE(SUM(total), 0)::numeric(14,2) AS total
             FROM receipts
             WHERE {$baseWhere}
             GROUP BY 1
             ORDER BY total DESC, count DESC
             LIMIT 5"
        );
        $merchantStmt->execute($params);
        $topMerchants = $merchantStmt->fetchAll() ?: [];

        $dailyStmt = $this->db->prepare(
            "SELECT COALESCE(purchased_at, created_at::date)::date AS day,
                    COUNT(*)::int AS count,
                    COALESCE(SUM(total), 0)::numeric(14,2) AS total
             FROM receipts
             WHERE {$baseWhere}
             GROUP BY day
             ORDER BY day ASC"
        );
        $dailyStmt->execute($params);
        $dailyRows = $dailyStmt->fetchAll() ?: [];

        $daily = [];
        foreach ($dailyRows as $row) {
            $daily[] = [
                'day' => (string) ($row['day'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        return [
            'window' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'summary' => [
                'receipt_count' => (int) ($summary['receipt_count'] ?? 0),
                'spend_total' => (float) ($summary['spend_total'] ?? 0),
                'avg_receipt' => (float) ($summary['avg_receipt'] ?? 0),
                'largest_purchase' => (float) ($summary['largest_purchase'] ?? 0),
                'currency' => strtoupper((string) ($summary['currency'] ?? 'USD')) ?: 'USD',
            ],
            'top_categories' => array_map(static fn (array $row): array => [
                'label' => (string) ($row['label'] ?? 'Uncategorized'),
                'count' => (int) ($row['count'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ], $topCategories),
            'top_merchants' => array_map(static fn (array $row): array => [
                'label' => (string) ($row['label'] ?? 'Unknown merchant'),
                'count' => (int) ($row['count'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ], $topMerchants),
            'daily' => $daily,
        ];
    }

    private function ensureSettingsRow(int $userId, bool $markWelcomeSent): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_notification_settings (
                user_id, weekly_report_enabled, welcome_email_sent_at
             ) VALUES (
                :user_id, :weekly_report_enabled, :welcome_email_sent_at
             )
             ON CONFLICT (user_id) DO NOTHING'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':weekly_report_enabled' => 'true',
            ':welcome_email_sent_at' => $markWelcomeSent ? (new DateTimeImmutable())->format(DATE_ATOM) : null,
        ]);
    }

    private function markWelcomeSent(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_notification_settings
             SET welcome_email_sent_at = :sent_at
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            ':sent_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ':user_id' => $userId,
        ]);
    }

    private function markWeeklyReportSent(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_notification_settings
             SET weekly_report_last_sent_at = :sent_at
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            ':sent_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ':user_id' => $userId,
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeSettings(array $row): array
    {
        $weekly = $row['weekly_report_enabled'] ?? true;

        return [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'weekly_report_enabled' => in_array($weekly, [true, 1, '1', 't', 'true'], true),
            'weekly_report_last_sent_at' => (string) ($row['weekly_report_last_sent_at'] ?? ''),
            'welcome_email_sent_at' => (string) ($row['welcome_email_sent_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 't', 'yes', 'y', 'on'], true);
        }

        return false;
    }

    private function appUrl(): string
    {
        $app = $this->config['app'] ?? [];
        if (!is_array($app)) {
            return '';
        }

        return rtrim((string) ($app['url'] ?? ''), '/');
    }
}
