<?php

declare(strict_types=1);

namespace App\Services;

final class TemplatedMailer
{
    private EmailTemplateRenderer $renderer;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
        $this->renderer = new EmailTemplateRenderer();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(string $to, string $template, array $data): bool
    {
        if (!$this->isEnabled() || !function_exists('mail')) {
            return false;
        }

        $safeTo = $this->sanitizeEmail($to);
        if ($safeTo === null) {
            return false;
        }

        $rendered = $this->renderer->render($template, $data);
        $headers = $this->buildHeaders();
        $message = $this->buildMultipartMessage($rendered['text'], $rendered['html'], $headers['boundary']);

        $subject = $rendered['subject'];
        if (function_exists('mb_encode_mimeheader')) {
            $subject = mb_encode_mimeheader($subject, 'UTF-8');
        }

        return mail(
            $safeTo,
            $subject,
            $message,
            implode("\r\n", $headers['lines'])
        );
    }

    private function isEnabled(): bool
    {
        $smtp = $this->config['smtp'] ?? [];
        return is_array($smtp) && (bool) ($smtp['enabled'] ?? false);
    }

    /** @return array{boundary: string, lines: array<int, string>} */
    private function buildHeaders(): array
    {
        $smtp = $this->config['smtp'] ?? [];
        $fromEmail = $this->sanitizeEmail((string) ($smtp['from_email'] ?? ''));
        $fromName = $this->sanitizeHeaderValue((string) ($smtp['from_name'] ?? 'OnLedge Support'));
        $replyTo = $this->sanitizeEmail((string) ($smtp['reply_to'] ?? ''));

        $boundary = 'onledge_' . bin2hex(random_bytes(12));
        $lines = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: OnLedge',
        ];

        if ($fromEmail !== null) {
            $lines[] = 'From: ' . ($fromName !== '' ? sprintf('"%s" <%s>', $fromName, $fromEmail) : $fromEmail);
        }

        if ($replyTo !== null) {
            $lines[] = 'Reply-To: ' . $replyTo;
        }

        return [
            'boundary' => $boundary,
            'lines' => $lines,
        ];
    }

    private function buildMultipartMessage(string $text, string $html, string $boundary): string
    {
        $normalizedText = str_replace(["\r\n", "\r"], "\n", $text);
        $normalizedHtml = str_replace(["\r\n", "\r"], "\n", $html);

        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/plain; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $normalizedText;
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $normalizedHtml;
        $parts[] = '--' . $boundary . '--';
        $parts[] = '';

        return implode("\r\n", $parts);
    }

    private function sanitizeHeaderValue(string $value): string
    {
        $clean = trim(str_replace(["\r", "\n"], '', $value));
        return $clean;
    }

    private function sanitizeEmail(string $value): ?string
    {
        $clean = trim(str_replace(["\r", "\n"], '', $value));
        if ($clean === '' || !filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $clean;
    }
}
