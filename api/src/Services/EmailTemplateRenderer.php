<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EmailTemplateRenderer
{
    /**
     * @param array<string, mixed> $data
     * @return array{subject: string, text: string, html: string}
     */
    public function render(string $template, array $data): array
    {
        $path = __DIR__ . '/../Templates/Email/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Email template not found: ' . $template);
        }

        $payload = $data;
        $result = (static function (string $__path, array $__data): mixed {
            $data = $__data;
            return require $__path;
        })($path, $payload);

        if (!is_array($result)) {
            throw new RuntimeException('Email template must return an array: ' . $template);
        }

        $subject = trim((string) ($result['subject'] ?? ''));
        $text = (string) ($result['text'] ?? '');
        $html = (string) ($result['html'] ?? '');

        if ($subject === '') {
            throw new RuntimeException('Email template missing subject: ' . $template);
        }

        if (trim($text) === '' && trim($html) === '') {
            throw new RuntimeException('Email template missing body content: ' . $template);
        }

        if (trim($text) === '' && trim($html) !== '') {
            $text = trim(strip_tags($html));
        }
        if (trim($html) === '' && trim($text) !== '') {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }
}

