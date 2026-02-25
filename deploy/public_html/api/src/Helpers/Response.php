<?php

declare(strict_types=1);

namespace App\Helpers;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function csv(string $filename, array $header, array $rows): void
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));

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
}
