<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class SupportTicketNotifier
{
    private TemplatedMailer $mailer;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config = [],
    ) {
        $this->mailer = new TemplatedMailer($config);
    }

    /** @param array<string, mixed> $ticket @param array<string, mixed> $creator */
    public function notifyTicketCreated(array $ticket, array $creator): void
    {
        $reporterEmail = $this->resolveReporterEmail($ticket);
        $assigneeEmail = $this->resolveAssignedAdminEmail($ticket);

        $payload = [
            'ticket' => $ticket,
            'actor' => $creator,
            'app_url' => $this->appUrl(),
        ];

        try {
            if ($reporterEmail !== null) {
                $this->mailer->send($reporterEmail, 'support_ticket_created_user', $payload);
            }

            $adminRecipients = $this->activeAdminEmails((int) ($creator['id'] ?? 0));
            foreach ($adminRecipients as $recipient) {
                $this->mailer->send($recipient, 'support_ticket_created_admin', $payload);
            }

            if ($assigneeEmail !== null && !in_array($assigneeEmail, $adminRecipients, true)) {
                $this->mailer->send($assigneeEmail, 'support_ticket_created_admin', $payload);
            }
        } catch (Throwable $exception) {
            error_log('[OnLedge][SupportMailer] notifyTicketCreated failed: ' . $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $ticket @param array<string, mixed> $message @param array<string, mixed> $actor */
    public function notifyTicketReply(array $ticket, array $message, array $actor): void
    {
        $actorRole = strtolower(trim((string) ($actor['role'] ?? 'user')));
        $payload = [
            'ticket' => $ticket,
            'message' => $message,
            'actor' => $actor,
            'app_url' => $this->appUrl(),
        ];

        try {
            if (in_array($actorRole, ['admin', 'owner'], true)) {
                $reporterEmail = $this->resolveReporterEmail($ticket);
                if ($reporterEmail !== null) {
                    $this->mailer->send($reporterEmail, 'support_ticket_reply_user', $payload);
                }
                return;
            }

            $adminRecipients = $this->activeAdminEmails((int) ($actor['id'] ?? 0));
            foreach ($adminRecipients as $recipient) {
                $this->mailer->send($recipient, 'support_ticket_reply_admin', $payload);
            }
        } catch (Throwable $exception) {
            error_log('[OnLedge][SupportMailer] notifyTicketReply failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $actor
     * @param array<int, string> $changedFields
     */
    public function notifyTicketUpdated(array $ticket, array $actor, array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        $payload = [
            'ticket' => $ticket,
            'actor' => $actor,
            'changed_fields' => $changedFields,
            'app_url' => $this->appUrl(),
        ];

        try {
            $reporterEmail = $this->resolveReporterEmail($ticket);
            if ($reporterEmail !== null) {
                $this->mailer->send($reporterEmail, 'support_ticket_updated_user', $payload);
            }
        } catch (Throwable $exception) {
            error_log('[OnLedge][SupportMailer] notifyTicketUpdated failed: ' . $exception->getMessage());
        }
    }

    /** @return array<int, string> */
    private function activeAdminEmails(int $excludeUserId = 0): array
    {
        $params = [];
        $sql = "SELECT email
                FROM users
                WHERE is_active = TRUE
                  AND role IN ('admin', 'owner')";
        if ($excludeUserId > 0) {
            $sql .= ' AND id <> :exclude_user_id';
            $params[':exclude_user_id'] = $excludeUserId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $emails = [];
        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /** @param array<string, mixed> $ticket */
    private function resolveReporterEmail(array $ticket): ?string
    {
        $email = trim((string) ($ticket['reporter_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $userId = (int) ($ticket['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        $resolved = trim((string) ($row['email'] ?? ''));
        if ($resolved === '' || !filter_var($resolved, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $resolved;
    }

    /** @param array<string, mixed> $ticket */
    private function resolveAssignedAdminEmail(array $ticket): ?string
    {
        $email = trim((string) ($ticket['assigned_admin_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
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

