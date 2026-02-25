<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use PDO;

final class RuleController
{
    public function __construct(private readonly PDO $db, private readonly SessionAuth $auth)
    {
    }

    public function index(): void
    {
        $userId = $this->auth->requireUserId();

        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, is_active, priority, conditions, actions, created_at, updated_at
             FROM rules
             WHERE user_id = :user_id
             ORDER BY priority ASC, id ASC'
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        Response::json(['items' => array_map([$this, 'normalizeRule'], $rows)]);
    }

    public function create(): void
    {
        $userId = $this->auth->requireUserId();
        $input = Request::input();

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new HttpException('Rule name is required', 422);
        }

        $priority = (int) ($input['priority'] ?? 100);
        $isActive = (bool) ($input['is_active'] ?? true);
        $conditions = $this->normalizeJsonField($input['conditions'] ?? []);
        $actions = $this->normalizeJsonField($input['actions'] ?? []);

        $stmt = $this->db->prepare(
            'INSERT INTO rules (user_id, name, is_active, priority, conditions, actions)
             VALUES (:user_id, :name, :is_active, :priority, CAST(:conditions AS jsonb), CAST(:actions AS jsonb))
             RETURNING id, user_id, name, is_active, priority, conditions, actions, created_at, updated_at'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':is_active' => $isActive,
            ':priority' => $priority,
            ':conditions' => json_encode($conditions, JSON_UNESCAPED_UNICODE),
            ':actions' => json_encode($actions, JSON_UNESCAPED_UNICODE),
        ]);

        $rule = $stmt->fetch();
        Response::json(['item' => $this->normalizeRule($rule ?: [])], 201);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (int) ($params['id'] ?? 0);
        $input = Request::input();

        $existing = $this->findRule($id, $userId);

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : (string) $existing['name'];
        if ($name === '') {
            throw new HttpException('Rule name is required', 422);
        }

        $priority = array_key_exists('priority', $input) ? (int) $input['priority'] : (int) $existing['priority'];
        $isActive = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : (bool) $existing['is_active'];
        $conditions = array_key_exists('conditions', $input)
            ? $this->normalizeJsonField($input['conditions'])
            : $this->normalizeJsonField($existing['conditions']);
        $actions = array_key_exists('actions', $input)
            ? $this->normalizeJsonField($input['actions'])
            : $this->normalizeJsonField($existing['actions']);

        $stmt = $this->db->prepare(
            'UPDATE rules
             SET name = :name,
                 is_active = :is_active,
                 priority = :priority,
                 conditions = CAST(:conditions AS jsonb),
                 actions = CAST(:actions AS jsonb)
             WHERE id = :id AND user_id = :user_id
             RETURNING id, user_id, name, is_active, priority, conditions, actions, created_at, updated_at'
        );
        $stmt->execute([
            ':name' => $name,
            ':is_active' => $isActive,
            ':priority' => $priority,
            ':conditions' => json_encode($conditions, JSON_UNESCAPED_UNICODE),
            ':actions' => json_encode($actions, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
            ':user_id' => $userId,
        ]);

        $rule = $stmt->fetch();
        Response::json(['item' => $this->normalizeRule($rule ?: [])]);
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        $userId = $this->auth->requireUserId();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->db->prepare('DELETE FROM rules WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $id, ':user_id' => $userId]);

        Response::json(['ok' => true]);
    }

    private function findRule(int $id, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, name, is_active, priority, conditions, actions, created_at, updated_at
             FROM rules
             WHERE id = :id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $rule = $stmt->fetch();

        if (!$rule) {
            throw new HttpException('Rule not found', 404);
        }

        return $this->normalizeRule($rule);
    }

    private function normalizeJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeRule(array $row): array
    {
        $row['id'] = isset($row['id']) ? (int) $row['id'] : null;
        $row['user_id'] = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $row['priority'] = isset($row['priority']) ? (int) $row['priority'] : 100;
        $activeValue = $row['is_active'] ?? true;
        $row['is_active'] = $activeValue === true
            || $activeValue === 1
            || $activeValue === '1'
            || $activeValue === 't'
            || $activeValue === 'true';

        foreach (['conditions', 'actions'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        return $row;
    }
}
