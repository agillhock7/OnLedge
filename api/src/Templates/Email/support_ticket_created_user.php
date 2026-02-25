<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$ticket = is_array($data['ticket'] ?? null) ? $data['ticket'] : [];
$actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];
$ticketId = (int) ($ticket['id'] ?? 0);
$subjectLine = trim((string) ($ticket['subject'] ?? 'Support request'));
$priority = strtoupper((string) ($ticket['priority'] ?? 'normal'));
$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$ticketUrl = $appUrl !== '' ? $appUrl . '/app/settings' : '';
$actorEmail = trim((string) ($actor['email'] ?? ''));

$subject = sprintf('OnLedge Support Ticket #%d Received', $ticketId);
$text = "Hi,\n\n";
$text .= sprintf("We received your support request (#%d).\n", $ticketId);
$text .= sprintf("Subject: %s\nPriority: %s\n", $subjectLine, $priority);
if ($actorEmail !== '') {
    $text .= sprintf("Opened by: %s\n", $actorEmail);
}
if ($ticketUrl !== '') {
    $text .= sprintf("\nView ticket: %s\n", $ticketUrl);
}
$text .= "\nOur team will reply in-thread as soon as possible.\n\n- OnLedge Support";

$safeSubject = onledge_email_escape($subjectLine);
$safeActor = onledge_email_escape($actorEmail);
$safePriority = onledge_email_escape($priority);

$bodyHtml = "<p style=\"margin:0 0 10px;font-size:14px;line-height:1.5;\">Your ticket <strong>#{$ticketId}</strong> is now in queue.</p>";
$bodyHtml .= "<div style=\"border:1px solid #d6e3e1;border-radius:12px;padding:12px;background:#f8fbfa;\">";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Subject:</strong> {$safeSubject}</p>";
$bodyHtml .= "<p style=\"margin:0;\"><strong>Priority:</strong> {$safePriority}</p>";
if ($safeActor !== '') {
    $bodyHtml .= "<p style=\"margin:8px 0 0;\"><strong>Opened by:</strong> {$safeActor}</p>";
}
$bodyHtml .= '</div>';
$bodyHtml .= '<p style="margin:12px 0 0;font-size:13px;color:#48626c;">Our team will reply in-thread as soon as possible.</p>';

$html = onledge_email_layout(
    'Support Request Received',
    'We have your ticket and the team is on it.',
    $bodyHtml,
    $appUrl,
    $ticketUrl !== '' ? 'Open Ticket' : '',
    $ticketUrl,
    ['preview_text' => "Ticket #{$ticketId} received"]
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
