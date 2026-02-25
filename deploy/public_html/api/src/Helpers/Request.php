<?php

declare(strict_types=1);

namespace App\Helpers;

final class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException('Invalid JSON payload', 422);
        }

        return $decoded;
    }

    public static function input(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            return self::json();
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
            parse_str(file_get_contents('php://input') ?: '', $parsed);
            return is_array($parsed) ? $parsed : [];
        }

        return [];
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function file(string $key): ?array
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return null;
        }

        return $_FILES[$key];
    }
}
