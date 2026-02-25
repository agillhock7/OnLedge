<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Authz;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use PDO;

final class SupportTicketController
{
    public function __construct(private readonly PDO $db, private readonly SessionAuth $auth)
    {
    }

    public function create(): void
    {
        $userId = $this->auth->requireUserId();
        Authz::requireActiveUser($this->db, $userId);

        $input = Request::input();
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $priority = strtolower(trim((string) ($input['priority'] ?? 'normal')));

        if ($subject === '' || strlen($subject) > 180) {
            throw new HttpException('Subject is required and must be 180 characters or less', 422);
        }

        if (strlen($message) < 10) {
            throw new HttpException('Message must be at least 10 characters', 422);
        }

        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new HttpException('Invalid ticket priority', 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO support_tickets (user_id, subject, message, priority)
             VALUES (:user_id, :subject, :message, :priority)
             RETURNING id, user_id, subject, message, status, priority, assigned_admin_id, admin_note, created_at, updated_at'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':subject' => $subject,
            ':message' => $message,
            ':priority' => $priority,
        ]);

        $ticket = $stmt->fetch();
        Response::json(['item' => $this->normalizeTicket($ticket ?: [])], 201);
    }

    public function mine(): void
    {
        $userId = $this->auth->requireUserId();
        Authz::requireActiveUser($this->db, $userId);

        $stmt = $this->db->prepare(
            'SELECT t.id, t.user_id, t.subject, t.message, t.status, t.priority, t.assigned_admin_id,
                    t.admin_note, t.created_at, t.updated_at, u.email AS reporter_email,
                    a.email AS assigned_admin_email
             FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN users a ON a.id = t.assigned_admin_id
             WHERE t.user_id = :user_id
             ORDER BY t.created_at DESC
             LIMIT 200'
        );
        $stmt->execute([':user_id' => $userId]);
        $items = $stmt->fetchAll() ?: [];

        Response::json(['items' => array_map([$this, 'normalizeTicket'], $items)]);
    }

    public function adminIndex(): void
    {
        $actor = $this->currentAdmin();

        $status = strtolower(trim((string) Request::query('status', '')));
        $where = [];
        $params = [];

        if ($status !== '') {
            if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                throw new HttpException('Invalid status filter', 422);
            }
            $where[] = 't.status = :status';
            $params[':status'] = $status;
        }

        $sql = 'SELECT t.id, t.user_id, t.subject, t.message, t.status, t.priority, t.assigned_admin_id,
                       t.admin_note, t.created_at, t.updated_at, u.email AS reporter_email,
                       a.email AS assigned_admin_email
                FROM support_tickets t
                JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.assigned_admin_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY t.created_at DESC LIMIT 500';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll() ?: [];

        Response::json([
            'actor' => [
                'id' => (int) $actor['id'],
                'email' => (string) $actor['email'],
                'role' => (string) $actor['role'],
            ],
            'items' => array_map([$this, 'normalizeTicket'], $items),
        ]);
    }

    /** @param array<string, string> $params */
    public function adminUpdate(array $params): void
    {
        $actor = $this->currentAdmin();
        $ticketId = (int) ($params['id'] ?? 0);
        if ($ticketId <= 0) {
            throw new HttpException('Invalid ticket id', 422);
        }

        $input = Request::input();

        $fields = [];
        $payload = [':id' => $ticketId];

        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string) $input['status']));
            if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                throw new HttpException('Invalid status', 422);
            }
            $fields[] = 'status = :status';
            $payload[':status'] = $status;
        }

        if (array_key_exists('priority', $input)) {
            $priority = strtolower(trim((string) $input['priority']));
            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                throw new HttpException('Invalid priority', 422);
            }
            $fields[] = 'priority = :priority';
            $payload[':priority'] = $priority;
        }

        if (array_key_exists('assigned_admin_id', $input)) {
            $assignedAdminId = $input['assigned_admin_id'] === null ? null : (int) $input['assigned_admin_id'];
            if ($assignedAdminId !== null) {
                $assignee = Authz::findUser($this->db, $assignedAdminId);
                if (!$assignee['is_active'] || !in_array($assignee['role'], ['admin', 'owner'], true)) {
                    throw new HttpException('assigned_admin_id must reference an active admin/owner', 422);
                }
            }

            $fields[] = 'assigned_admin_id = :assigned_admin_id';
            $payload[':assigned_admin_id'] = $assignedAdminId;
        }

        if (array_key_exists('admin_note', $input)) {
            $note = trim((string) $input['admin_note']);
            if (strlen($note) > 4000) {
                throw new HttpException('admin_note must be 4000 characters or less', 422);
            }
            $fields[] = 'admin_note = :admin_note';
            $payload[':admin_note'] = $note !== '' ? $note : null;
        }

        if ($fields === []) {
            throw new HttpException('No update fields provided', 422);
        }

        if (!array_key_exists(':assigned_admin_id', $payload) && $actor['role'] !== 'user') {
            $fields[] = 'assigned_admin_id = COALESCE(assigned_admin_id, :default_assigned_admin_id)';
            $payload[':default_assigned_admin_id'] = (int) $actor['id'];
        }

        $sql = 'UPDATE support_tickets SET ' . implode(', ', $fields) . ' WHERE id = :id
                RETURNING id, user_id, subject, message, status, priority, assigned_admin_id, admin_note, created_at, updated_at';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($payload);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            throw new HttpException('Support ticket not found', 404);
        }

        Response::json(['item' => $this->normalizeTicket($ticket)]);
    }

    /** @return array<string, mixed> */
    private function currentAdmin(): array
    {
        $userId = $this->auth->requireUserId();
        return Authz::requireAdmin($this->db, $userId);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeTicket(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['user_id'] = (int) ($row['user_id'] ?? 0);
        $row['assigned_admin_id'] = isset($row['assigned_admin_id']) ? (int) $row['assigned_admin_id'] : null;
        return $row;
    }
}
