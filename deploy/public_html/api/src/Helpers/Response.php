<?php

declare(strict_types=1);

namespace App\Helpers;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        self::defaultHeaders();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function csv(string $filename, array $header, array $rows): void
    {
        http_response_code(200);
        self::defaultHeaders();
        header('Content-Type: text/csv; charset=utf-8');
        header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            self::json(['error' => 'Unable to stream CSV'], 500);
            return;
        }

        fputcsv($stream, $header);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        fclose($stream);
    }

    private static function defaultHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }
}
