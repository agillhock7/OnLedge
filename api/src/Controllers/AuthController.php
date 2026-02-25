<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Authz;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use PDO;
use PDOException;

final class AuthController
{
    public function __construct(private readonly PDO $db, private readonly SessionAuth $auth)
    {
    }

    public function register(): void
    {
        $input = Request::input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Valid email is required', 422);
        }
        if (strlen($password) < 8) {
            throw new HttpException('Password must be at least 8 characters', 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (email, password_hash)
                 VALUES (:email, :password_hash)
                 RETURNING id, email, role, is_active, is_seed, created_at, updated_at, disabled_at'
            );
            $stmt->execute([
                ':email' => $email,
                ':password_hash' => $hash,
            ]);
            $user = $stmt->fetch();
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23505') {
                throw new HttpException('Email is already registered', 409);
            }
            throw $exception;
        }

        $this->auth->login((int) $user['id']);

        Response::json([
            'user' => Authz::normalizeUser($user ?: []),
        ], 201);
    }

    public function login(): void
    {
        $input = Request::input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        $stmt = $this->db->prepare(
            'SELECT id, email, password_hash, role, is_active, is_seed, created_at, updated_at, disabled_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new HttpException('Invalid email or password', 401);
        }
        if (!in_array(($user['is_active'] ?? false), [true, 1, '1', 't', 'true'], true)) {
            throw new HttpException('Account is disabled', 403);
        }

        $this->auth->login((int) $user['id']);

        Response::json([
            'user' => Authz::normalizeUser($user),
        ]);
    }

    public function logout(): void
    {
        $this->auth->logout();
        Response::json(['ok' => true]);
    }

    public function forgotPassword(): void
    {
        $input = Request::input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['ok' => true, 'message' => 'If the account exists, a reset flow can be initiated.']);
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(DATE_ATOM);

            $insert = $this->db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
            $insert->execute([
                ':user_id' => (int) $user['id'],
                ':token_hash' => $tokenHash,
                ':expires_at' => $expiresAt,
            ]);

            // SMTP integration can be added later; token is intentionally not returned.
        }

        Response::json(['ok' => true, 'message' => 'If the account exists, a reset flow can be initiated.']);
    }

    public function me(): void
    {
        $userId = $this->auth->requireUserId();

        $stmt = $this->db->prepare(
            'SELECT id, email, role, is_active, is_seed, created_at, updated_at, disabled_at
             FROM users
             WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new HttpException('User not found', 404);
        }
        $normalized = Authz::normalizeUser($user);
        if (!$normalized['is_active']) {
            $this->auth->logout();
            throw new HttpException('Account is disabled', 403);
        }

        Response::json([
            'user' => $normalized,
        ]);
    }
}
