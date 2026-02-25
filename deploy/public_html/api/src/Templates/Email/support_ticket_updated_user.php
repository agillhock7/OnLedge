<?php

declare(strict_types=1);

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

$safeSubject = htmlspecialchars($subjectLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeActor = htmlspecialchars($actorEmail !== '' ? $actorEmail : 'Support team', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeChanges = htmlspecialchars($changes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeStatus = htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safePriority = htmlspecialchars($priority, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeAssignee = htmlspecialchars($assignee, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = htmlspecialchars($ticketUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$html = <<<HTML
<h2>Support Ticket #{$ticketId} Updated</h2>
<p><strong>Subject:</strong> {$safeSubject}<br /><strong>Updated by:</strong> {$safeActor}<br /><strong>Changed:</strong> {$safeChanges}</p>
<p><strong>Status:</strong> {$safeStatus}<br /><strong>Priority:</strong> {$safePriority}</p>
HTML;

if ($safeAssignee !== '') {
    $html .= "<p><strong>Assigned to:</strong> {$safeAssignee}</p>";
}
if ($safeUrl !== '') {
    $html .= "<p><a href=\"{$safeUrl}\">Open ticket in OnLedge</a></p>";
}

$html .= '<p>- OnLedge Support</p>';

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];

