<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$ticket = is_array($data['ticket'] ?? null) ? $data['ticket'] : [];
$actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];
$changedFields = is_array($data['changed_fields'] ?? null) ? $data['changed_fields'] : [];
$ticketId = (int) ($ticket['id'] ?? 0);
$subjectLine = trim((string) ($ticket['subject'] ?? 'Support request'));
$status = (string) ($ticket['status'] ?? 'open');
$priority = (string) ($ticket['priority'] ?? 'normal');
$assignee = trim((string) ($ticket['assigned_admin_email'] ?? ''));
$actorEmail = trim((string) ($actor['email'] ?? 'Support team'));
$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$ticketUrl = $appUrl !== '' ? $appUrl . '/app/settings' : '';

$changes = $changedFields === [] ? 'status' : implode(', ', $changedFields);

$subject = sprintf('Ticket #%d Updated', $ticketId);
$text = sprintf(
    "Your support ticket #%d was updated.\n\nSubject: %s\nUpdated by: %s\nChanged: %s\nStatus: %s\nPriority: %s\n",
    $ticketId,
    $subjectLine,
    $actorEmail !== '' ? $actorEmail : 'Support team',
    $changes,
    $status,
    $priority
);
if ($assignee !== '') {
    $text .= sprintf("Assigned to: %s\n", $assignee);
}
if ($ticketUrl !== '') {
    $text .= sprintf("\nView ticket: %s\n", $ticketUrl);
}
$text .= "\n- OnLedge Support";

$safeSubject = onledge_email_escape($subjectLine);
$safeActor = onledge_email_escape($actorEmail !== '' ? $actorEmail : 'Support team');
$safeChanges = onledge_email_escape($changes);
$safeStatus = onledge_email_escape($status);
$safePriority = onledge_email_escape($priority);
$safeAssignee = onledge_email_escape($assignee);

$bodyHtml = "<p style=\"margin:0 0 10px;font-size:14px;line-height:1.5;\">Your ticket details were updated by support.</p>";
$bodyHtml .= "<div style=\"border:1px solid #d6e3e1;border-radius:12px;padding:12px;background:#f8fbfa;\">";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Subject:</strong> {$safeSubject}</p>";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Updated by:</strong> {$safeActor}</p>";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Changed:</strong> {$safeChanges}</p>";
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Status:</strong> {$safeStatus}</p>";
$bodyHtml .= "<p style=\"margin:0;\"><strong>Priority:</strong> {$safePriority}</p>";
if ($safeAssignee !== '') {
    $bodyHtml .= "<p style=\"margin:8px 0 0;\"><strong>Assigned to:</strong> {$safeAssignee}</p>";
}
$bodyHtml .= '</div>';

$html = onledge_email_layout(
    'Support Ticket Updated',
    "Ticket #{$ticketId} has updated details.",
    $bodyHtml,
    $appUrl,
    $ticketUrl !== '' ? 'View Ticket' : '',
    $ticketUrl,
    ['preview_text' => "Ticket #{$ticketId} updated"]
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
