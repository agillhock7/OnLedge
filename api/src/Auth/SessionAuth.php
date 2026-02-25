<?php

declare(strict_types=1);

namespace App\Auth;

use App\Helpers\HttpException;

final class SessionAuth
{
    public function userId(): ?int
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }

        return (int) $userId;
    }

    public function requireUserId(): int
    {
        $userId = $this->userId();
        if ($userId === null) {
            throw new HttpException('Authentication required', 401);
        }

        return $userId;
    }

    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? true),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
