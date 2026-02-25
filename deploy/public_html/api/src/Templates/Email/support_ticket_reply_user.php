<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$ticket = is_array($data['ticket'] ?? null) ? $data['ticket'] : [];
$message = is_array($data['message'] ?? null) ? $data['message'] : [];
$actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];
$ticketId = (int) ($ticket['id'] ?? 0);
$subjectLine = trim((string) ($ticket['subject'] ?? 'Support request'));
$actorEmail = trim((string) ($actor['email'] ?? 'OnLedge Support'));
$body = trim((string) ($message['body'] ?? ''));
$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$ticketUrl = $appUrl !== '' ? $appUrl . '/app/settings' : '';

$subject = sprintf('Update on Ticket #%d', $ticketId);
$text = sprintf(
    "Your support ticket #%d has a new reply.\n\nSubject: %s\nFrom: %s\n\n%s\n",
    $ticketId,
    $subjectLine,
    $actorEmail !== '' ? $actorEmail : 'Support team',
    $body !== '' ? $body : '(no message content)'
);
if ($ticketUrl !== '') {
    $text .= sprintf("\nReply in OnLedge: %s\n", $ticketUrl);
}
$text .= "\n- OnLedge Support";

$safeSubject = onledge_email_escape($subjectLine);
$safeActor = onledge_email_escape($actorEmail !== '' ? $actorEmail : 'Support team');
$safeBody = nl2br(onledge_email_escape($body !== '' ? $body : '(no message content)'));

$bodyHtml = "<p style=\"margin:0 0 10px;font-size:14px;line-height:1.5;\">A support team member replied to your ticket.</p>";
$bodyHtml .= "<p style=\"margin:0 0 6px;\"><strong>Subject:</strong> {$safeSubject}</p>";
$bodyHtml .= "<p style=\"margin:0 0 10px;\"><strong>From:</strong> {$safeActor}</p>";
$bodyHtml .= "<blockquote style=\"margin:0;padding:12px;border-left:4px solid #8dbac2;background:#f2f9fa;border-radius:8px;\">{$safeBody}</blockquote>";

$html = onledge_email_layout(
    'Support Reply Received',
    "Ticket #{$ticketId} has a new message.",
    $bodyHtml,
    $appUrl,
    $ticketUrl !== '' ? 'Reply In OnLedge' : '',
    $ticketUrl,
    ['preview_text' => "New reply on ticket #{$ticketId}"]
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
