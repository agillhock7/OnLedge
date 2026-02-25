<?php

declare(strict_types=1);

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

$safeSubject = htmlspecialchars($subjectLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeReporter = htmlspecialchars($reporter !== '' ? $reporter : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeActor = htmlspecialchars($actorEmail !== '' ? $actorEmail : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeUrl = htmlspecialchars($ticketUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$html = <<<HTML
<h2>New Support Ticket #{$ticketId}</h2>
<p><strong>Subject:</strong> {$safeSubject}<br /><strong>Priority:</strong> {$priority}</p>
<p><strong>Reporter:</strong> {$safeReporter}<br /><strong>Opened by:</strong> {$safeActor}</p>
HTML;

if ($safeUrl !== '') {
    $html .= "<p><a href=\"{$safeUrl}\">Open support queue</a></p>";
}

$html .= '<p>- OnLedge Support</p>';

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];

