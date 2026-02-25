<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$ticket = is_array($data['ticket'] ?? null) ? $data['ticket'] : [];
$actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];
$ticketId = (int) ($ticket['id'] ?? 0);
$subjectLine = trim((string) ($ticket['subject'] ?? 'Support request'));
$priority = strtoupper((string) ($ticket['priority'] ?? 'normal'));
$reporter = trim((string) ($ticket['reporter_email'] ?? ''));
$actorEmail = trim((string) ($actor['email'] ?? ''));
$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$ticketUrl = $appUrl !== '' ? $appUrl . '/app/settings' : '';

$subject = sprintf('[OnLedge] New Ticket #%d (%s)', $ticketId, $priority);
$text = sprintf(
    "New support ticket #%d\n\nSubject: %s\nPriority: %s\nReporter: %s\nOpened by: %s\n",
    $ticketId,
    $subjectLine,
    $priority,
    $reporter !== '' ? $reporter : 'unknown',
    $actorEmail !== '' ? $actorEmail : 'unknown'
);
if ($ticketUrl !== '') {
    $text .= sprintf("\nOpen queue: %s\n", $ticketUrl);
}
$text .= "\n- OnLedge Support";

$safeSubject = onledge_email_escape($subjectLine);
$safeReporter = onledge_email_escape($reporter !== '' ? $reporter : 'unknown');
$safeActor = onledge_email_escape($actorEmail !== '' ? $actorEmail : 'unknown');
$safePriority = onledge_email_escape($priority);

$bodyHtml = "<p style=\"margin:0 0 10px;font-size:14px;line-height:1.5;\">A new support ticket requires triage.</p>";
$bodyHtml .= "<div style=\"border:1px solid #d6e3e1;border-radius:12px;padding:12px;background:#f8fbfa;\">";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Subject:</strong> {$safeSubject}</p>";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Priority:</strong> {$safePriority}</p>";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Reporter:</strong> {$safeReporter}</p>";
$bodyHtml .= "<p style=\"margin:0;\"><strong>Opened by:</strong> {$safeActor}</p>";
$bodyHtml .= '</div>';

$html = onledge_email_layout(
    'New Support Ticket',
    "Ticket #{$ticketId} has been opened.",
    $bodyHtml,
    $appUrl,
    $ticketUrl !== '' ? 'Open Support Queue' : '',
    $ticketUrl,
    ['preview_text' => "New ticket #{$ticketId} ({$priority})"]
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
