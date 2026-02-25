<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$ticket = is_array($data['ticket'] ?? null) ? $data['ticket'] : [];
$message = is_array($data['message'] ?? null) ? $data['message'] : [];
$actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];
$ticketId = (int) ($ticket['id'] ?? 0);
$subjectLine = trim((string) ($ticket['subject'] ?? 'Support request'));
$actorEmail = trim((string) ($actor['email'] ?? 'user'));
$body = trim((string) ($message['body'] ?? ''));
$reporter = trim((string) ($ticket['reporter_email'] ?? 'unknown'));
$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$ticketUrl = $appUrl !== '' ? $appUrl . '/app/settings' : '';

$subject = sprintf('[OnLedge] Reply on Ticket #%d', $ticketId);
$text = sprintf(
    "A customer replied on ticket #%d.\n\nSubject: %s\nReporter: %s\nFrom: %s\n\n%s\n",
    $ticketId,
    $subjectLine,
    $reporter,
    $actorEmail !== '' ? $actorEmail : 'user',
    $body !== '' ? $body : '(no message content)'
);
if ($ticketUrl !== '') {
    $text .= sprintf("\nOpen queue: %s\n", $ticketUrl);
}
$text .= "\n- OnLedge Support";

$safeSubject = onledge_email_escape($subjectLine);
$safeReporter = onledge_email_escape($reporter);
$safeActor = onledge_email_escape($actorEmail !== '' ? $actorEmail : 'user');
$safeBody = nl2br(onledge_email_escape($body !== '' ? $body : '(no message content)'));

$bodyHtml = "<p style=\"margin:0 0 10px;font-size:14px;line-height:1.5;\">A customer posted a new reply.</p>";
$bodyHtml .= "<p style=\"margin:0 0 6px;\"><strong>Subject:</strong> {$safeSubject}</p>";
$bodyHtml .= "<p style=\"margin:0 0 6px;\"><strong>Reporter:</strong> {$safeReporter}</p>";
$bodyHtml .= "<p style=\"margin:0 0 10px;\"><strong>From:</strong> {$safeActor}</p>";
$bodyHtml .= "<blockquote style=\"margin:0;padding:12px;border-left:4px solid #8dbac2;background:#f2f9fa;border-radius:8px;\">{$safeBody}</blockquote>";

$html = onledge_email_layout(
    'Customer Reply Received',
    "Ticket #{$ticketId} needs attention.",
    $bodyHtml,
    $appUrl,
    $ticketUrl !== '' ? 'Open Support Queue' : '',
    $ticketUrl,
    ['preview_text' => "Customer replied on ticket #{$ticketId}"]
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
