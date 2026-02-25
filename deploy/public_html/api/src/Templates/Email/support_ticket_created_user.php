<?php

declare(strict_types=1);

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

$safeSubject = htmlspecialchars($subjectLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeActor = htmlspecialchars($actorEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = htmlspecialchars($ticketUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$html = <<<HTML
<h2>Support Request Received</h2>
<p>Your ticket <strong>#{$ticketId}</strong> is now in queue.</p>
<p><strong>Subject:</strong> {$safeSubject}<br /><strong>Priority:</strong> {$priority}</p>
HTML;

if ($safeActor !== '') {
    $html .= "<p><strong>Opened by:</strong> {$safeActor}</p>";
}

if ($safeUrl !== '') {
    $html .= "<p><a href=\"{$safeUrl}\">Open ticket in OnLedge</a></p>";
}

$html .= '<p>Our team will reply in-thread as soon as possible.<br />- OnLedge Support</p>';

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];

