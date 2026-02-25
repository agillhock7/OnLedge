<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Authz;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\Schema;
use App\Services\SupportTicketNotifier;
use DateTimeImmutable;
use PDO;
use Throwable;

final class SupportTicketController
{
    /** @var array<int, string> */
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    /** @var array<int, string> */
    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    private SupportTicketNotifier $notifier;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly SessionAuth $auth,
        private readonly array $config = [],
    ) {
        $this->notifier = new SupportTicketNotifier($db, $config);
    }

    public function create(): void
    {
        $this->ensureSupportSchema();
        $actor = $this->currentUser();
        $userId = (int) $actor['id'];

        $input = Request::input();
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? $input['body'] ?? ''));
        $priority = strtolower(trim((string) ($input['priority'] ?? 'normal')));

        if ($subject === '' || strlen($subject) > 180) {
            throw new HttpException('Subject is required and must be 180 characters or less', 422);
        }

        if (strlen($message) < 10) {
            throw new HttpException('Message must be at least 10 characters', 422);
        }

        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new HttpException('Invalid ticket priority', 422);
        }

        $ticketId = 0;
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'INSERT INTO support_tickets (
                    user_id, subject, message, status, priority, last_message_at, last_message_by_user_id
                 ) VALUES (
                    :user_id, :subject, :message, :status, :priority, :last_message_at, :last_message_by_user_id
                 )
                 RETURNING id'
            );
            $now = (new DateTimeImmutable())->format(DATE_ATOM);
            $stmt->execute([
                ':user_id' => $userId,
                ':subject' => $subject,
                ':message' => $message,
                ':status' => 'open',
                ':priority' => $priority,
                ':last_message_at' => $now,
                ':last_message_by_user_id' => $userId,
            ]);
            $created = $stmt->fetch();
            $ticketId = (int) ($created['id'] ?? 0);
            if ($ticketId <= 0) {
                throw new HttpException('Unable to create support ticket', 500);
            }

            $this->insertMessage($ticketId, $userId, $message, false);
            $this->updateConversationMeta($ticketId, $userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $detail = $this->loadTicketDetail($ticketId, $userId, false);
        $this->notifier->notifyTicketCreated($detail['item'], $actor);

        Response::json($detail, 201);
    }

    public function mine(): void
    {
        $this->ensureSupportSchema();
        $actor = $this->currentUser();

        $stmt = $this->db->prepare(
            $this->ticketListSql(false) . '
             WHERE t.user_id = :user_id
             ORDER BY COALESCE(t.last_message_at, t.updated_at, t.created_at) DESC
             LIMIT 300'
        );
        $stmt->execute([':user_id' => (int) $actor['id']]);
        $items = $stmt->fetchAll() ?: [];

        Response::json(['items' => array_map([$this, 'normalizeTicket'], $items)]);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $this->ensureSupportSchema();
        $actor = $this->currentUser();

        $ticketId = (int) ($params['id'] ?? 0);
        if ($ticketId <= 0) {
            throw new HttpException('Invalid ticket id', 422);
        }

        $isAdmin = in_array((string) ($actor['role'] ?? 'user'), ['admin', 'owner'], true);
        $detail = $this->loadTicketDetail($ticketId, (int) $actor['id'], $isAdmin);
        Response::json($detail);
    }

    /** @param array<string, string> $params */
    public function reply(array $params): void
    {
        $this->ensureSupportSchema();
        $actor = $this->currentUser();
        $actorId = (int) $actor['id'];
        $isAdmin = in_array((string) ($actor['role'] ?? 'user'), ['admin', 'owner'], true);

        $ticketId = (int) ($params['id'] ?? 0);
        if ($ticketId <= 0) {
            throw new HttpException('Invalid ticket id', 422);
        }

        $input = Request::input();
        $body = trim((string) ($input['message'] ?? $input['body'] ?? ''));
        if (strlen($body) < 2 || strlen($body) > 6000) {
            throw new HttpException('Message must be between 2 and 6000 characters', 422);
        }

        $isInternal = $isAdmin && $this->toBool($input['is_internal'] ?? false);

        $ticket = $this->loadTicket((int) $ticketId, $actorId, $isAdmin);

        try {
            $this->db->beginTransaction();

            $messageId = $this->insertMessage($ticketId, $actorId, $body, $isInternal);
            $status = strtolower((string) ($ticket['status'] ?? 'open'));
            if ($isAdmin && !$isInternal && in_array($status, ['resolved', 'closed'], true)) {
                $this->touchTicketStatus($ticketId, 'in_progress');
            } elseif (!$isAdmin && in_array($status, ['resolved', 'closed'], true)) {
                $this->touchTicketStatus($ticketId, 'open');
            }

            $this->updateConversationMeta($ticketId, $actorId);
            $this->db->commit();

            $detail = $this->loadTicketDetail($ticketId, $actorId, $isAdmin);
            $message = $this->findMessageById($messageId, $isAdmin);

            if (!$isInternal) {
                $this->notifier->notifyTicketReply($detail['item'], $message, $actor);
            }

            Response::json([
                'item' => $detail['item'],
                'message' => $message,
                'messages' => $detail['messages'],
            ], 201);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function adminIndex(): void
    {
        $this->ensureSupportSchema();
        $actor = $this->currentAdmin();

        $status = strtolower(trim((string) Request::query('status', '')));
        $priority = strtolower(trim((string) Request::query('priority', '')));
        $assignment = strtolower(trim((string) Request::query('assignment', '')));
        $query = trim((string) Request::query('q', ''));
        $where = [];
        $params = [];

        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new HttpException('Invalid status filter', 422);
            }
            $where[] = 't.status = :status';
            $params[':status'] = $status;
        }
        if ($priority !== '') {
            if (!in_array($priority, self::PRIORITIES, true)) {
                throw new HttpException('Invalid priority filter', 422);
            }
            $where[] = 't.priority = :priority';
            $params[':priority'] = $priority;
        }
        if ($assignment !== '') {
            if (!in_array($assignment, ['all', 'mine', 'unassigned'], true)) {
                throw new HttpException('Invalid assignment filter', 422);
            }
            if ($assignment === 'mine') {
                $where[] = 't.assigned_admin_id = :assignment_user_id';
                $params[':assignment_user_id'] = (int) $actor['id'];
            } elseif ($assignment === 'unassigned') {
                $where[] = 't.assigned_admin_id IS NULL';
            }
        }
        if ($query !== '') {
            $where[] = '(t.subject ILIKE :query OR t.message ILIKE :query OR u.email ILIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        $sql = $this->ticketListSql(true);

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY COALESCE(t.last_message_at, t.updated_at, t.created_at) DESC LIMIT 500';

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
        $this->ensureSupportSchema();
        $actor = $this->currentAdmin();
        $ticketId = (int) ($params['id'] ?? 0);
        if ($ticketId <= 0) {
            throw new HttpException('Invalid ticket id', 422);
        }

        $existing = $this->loadTicket($ticketId, (int) $actor['id'], true);
        $input = Request::input();

        $fields = [];
        $payload = [':id' => $ticketId];
        $changedFields = [];

        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string) $input['status']));
            if (!in_array($status, self::STATUSES, true)) {
                throw new HttpException('Invalid status', 422);
            }
            $fields[] = 'status = :status';
            $payload[':status'] = $status;
            $changedFields[] = 'status';
            if ($status === 'resolved' || $status === 'closed') {
                $fields[] = 'closed_at = :closed_at';
                $payload[':closed_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
            } else {
                $fields[] = 'closed_at = NULL';
            }
        }

        if (array_key_exists('priority', $input)) {
            $priority = strtolower(trim((string) $input['priority']));
            if (!in_array($priority, self::PRIORITIES, true)) {
                throw new HttpException('Invalid priority', 422);
            }
            $fields[] = 'priority = :priority';
            $payload[':priority'] = $priority;
            $changedFields[] = 'priority';
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
            $changedFields[] = 'assigned_admin_id';
        }

        if (array_key_exists('admin_note', $input)) {
            $note = trim((string) $input['admin_note']);
            if (strlen($note) > 4000) {
                throw new HttpException('admin_note must be 4000 characters or less', 422);
            }
            $fields[] = 'admin_note = :admin_note';
            $payload[':admin_note'] = $note !== '' ? $note : null;
            $changedFields[] = 'admin_note';
        }

        if ($fields === []) {
            Response::json(['item' => $existing]);
            return;
        }

        if (!array_key_exists(':assigned_admin_id', $payload)) {
            $fields[] = 'assigned_admin_id = COALESCE(assigned_admin_id, :default_assigned_admin_id)';
            $payload[':default_assigned_admin_id'] = (int) $actor['id'];
        }

        $sql = 'UPDATE support_tickets SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($payload);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            throw new HttpException('Support ticket not found', 404);
        }

        $detail = $this->loadTicketDetail($ticketId, (int) $actor['id'], true);
        $this->notifier->notifyTicketUpdated($detail['item'], $actor, array_values(array_unique($changedFields)));

        Response::json(['item' => $detail['item']]);
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $userId = $this->auth->requireUserId();
        return Authz::requireActiveUser($this->db, $userId);
    }

    /** @return array<string, mixed> */
    private function currentAdmin(): array
    {
        $userId = $this->auth->requireUserId();
        return Authz::requireAdmin($this->db, $userId);
    }

    private function ensureSupportSchema(): void
    {
        if (!Schema::hasAdminUserColumns($this->db) || !Schema::hasSupportTickets($this->db)) {
            throw new HttpException('Support schema missing. Run migration 002_admin_support.sql', 503);
        }
        if (!Schema::hasSupportTicketMessages($this->db) || !Schema::hasSupportThreadColumns($this->db)) {
            throw new HttpException('Support threading schema missing. Run migration 006_support_threads.sql', 503);
        }
    }

    /**
     * @return array{item: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    private function loadTicketDetail(int $ticketId, int $viewerId, bool $asAdmin): array
    {
        $ticket = $this->loadTicket($ticketId, $viewerId, $asAdmin);
        $messages = $this->loadMessages($ticketId, $asAdmin);

        return [
            'item' => $ticket,
            'messages' => $messages,
        ];
    }

    /** @return array<string, mixed> */
    private function loadTicket(int $ticketId, int $viewerId, bool $asAdmin): array
    {
        $sql = $this->ticketListSql($asAdmin) . ' WHERE t.id = :ticket_id';
        $params = [':ticket_id' => $ticketId];
        if (!$asAdmin) {
            $sql .= ' AND t.user_id = :viewer_user_id';
            $params[':viewer_user_id'] = $viewerId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            throw new HttpException('Support ticket not found', 404);
        }

        return $this->normalizeTicket($row);
    }

    /** @return array<int, array<string, mixed>> */
    private function loadMessages(int $ticketId, bool $asAdmin): array
    {
        $sql = 'SELECT m.id, m.ticket_id, m.author_user_id, m.body, m.is_internal, m.created_at, m.updated_at,
                       u.email AS author_email, u.role AS author_role
                FROM support_ticket_messages m
                JOIN users u ON u.id = m.author_user_id
                WHERE m.ticket_id = :ticket_id';
        if (!$asAdmin) {
            $sql .= ' AND m.is_internal = FALSE';
        }
        $sql .= ' ORDER BY m.created_at ASC, m.id ASC LIMIT 2000';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':ticket_id' => $ticketId]);

        $rows = $stmt->fetchAll() ?: [];
        return array_map([$this, 'normalizeMessage'], $rows);
    }

    private function insertMessage(int $ticketId, int $authorUserId, string $body, bool $isInternal): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, author_user_id, body, is_internal)
             VALUES (:ticket_id, :author_user_id, :body, :is_internal)
             RETURNING id'
        );
        $stmt->execute([
            ':ticket_id' => $ticketId,
            ':author_user_id' => $authorUserId,
            ':body' => $body,
            ':is_internal' => $isInternal ? 'true' : 'false',
        ]);
        $created = $stmt->fetch();

        return (int) ($created['id'] ?? 0);
    }

    private function updateConversationMeta(int $ticketId, int $authorUserId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE support_tickets
             SET last_message_at = :last_message_at,
                 last_message_by_user_id = :last_message_by_user_id
             WHERE id = :id'
        );
        $stmt->execute([
            ':last_message_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            ':last_message_by_user_id' => $authorUserId,
            ':id' => $ticketId,
        ]);
    }

    private function touchTicketStatus(int $ticketId, string $status): void
    {
        if (in_array($status, ['resolved', 'closed'], true)) {
            $stmt = $this->db->prepare(
                'UPDATE support_tickets
                 SET status = :status,
                     closed_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $status,
                ':id' => $ticketId,
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE support_tickets
             SET status = :status,
                 closed_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':id' => $ticketId,
        ]);
    }

    /** @return array<string, mixed> */
    private function findMessageById(int $messageId, bool $asAdmin): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.ticket_id, m.author_user_id, m.body, m.is_internal, m.created_at, m.updated_at,
                    u.email AS author_email, u.role AS author_role
             FROM support_ticket_messages m
             JOIN users u ON u.id = m.author_user_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $messageId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new HttpException('Support message not found', 404);
        }

        $message = $this->normalizeMessage($row);
        if (!$asAdmin && $message['is_internal']) {
            throw new HttpException('Support message not found', 404);
        }

        return $message;
    }

    private function ticketListSql(bool $asAdmin): string
    {
        $internalClause = $asAdmin ? '' : ' AND sm.is_internal = FALSE';

        return "SELECT t.id, t.user_id, t.subject, t.message, t.status, t.priority, t.assigned_admin_id,
                       t.admin_note, t.created_at, t.updated_at, t.last_message_at, t.last_message_by_user_id,
                       t.closed_at, u.email AS reporter_email, a.email AS assigned_admin_email,
                       lu.email AS last_message_author_email, lu.role AS last_message_author_role,
                       COALESCE((
                         SELECT COUNT(*)::int
                         FROM support_ticket_messages sm
                         WHERE sm.ticket_id = t.id{$internalClause}
                       ), 0) AS message_count,
                       COALESCE((
                         SELECT LEFT(sm.body, 220)
                         FROM support_ticket_messages sm
                         WHERE sm.ticket_id = t.id{$internalClause}
                         ORDER BY sm.created_at DESC, sm.id DESC
                         LIMIT 1
                       ), '') AS last_message_preview
                FROM support_tickets t
                JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.assigned_admin_id
                LEFT JOIN users lu ON lu.id = t.last_message_by_user_id";
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeTicket(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['user_id'] = (int) ($row['user_id'] ?? 0);
        $row['assigned_admin_id'] = isset($row['assigned_admin_id']) ? (int) $row['assigned_admin_id'] : null;
        $row['last_message_by_user_id'] = isset($row['last_message_by_user_id']) ? (int) $row['last_message_by_user_id'] : null;
        $row['message_count'] = isset($row['message_count']) ? (int) $row['message_count'] : 0;
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeMessage(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['ticket_id'] = (int) ($row['ticket_id'] ?? 0);
        $row['author_user_id'] = (int) ($row['author_user_id'] ?? 0);
        $raw = $row['is_internal'] ?? false;
        $row['is_internal'] = in_array($raw, [true, 1, '1', 't', 'true'], true);
        $role = trim((string) ($row['author_role'] ?? 'user'));
        $row['author_role'] = in_array($role, ['user', 'admin', 'owner'], true) ? $role : 'user';

        return $row;
    }
}
