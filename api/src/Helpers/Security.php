<?php

declare(strict_types=1);

namespace App\Helpers;

final class Security
{
    public static function applyApiHeaders(): void
    {
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    }

    public static function enforceMutatingRequestGuard(string $method): void
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $clientHeader = trim((string) ($_SERVER['HTTP_X_ONLEDGE_CLIENT'] ?? ''));
        if ($clientHeader !== 'web') {
            throw new HttpException('Forbidden request', 403);
        }
    }
}
