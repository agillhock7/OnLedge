<?php

declare(strict_types=1);

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

$safeSubject = htmlspecialchars($subjectLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeReporter = htmlspecialchars($reporter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeActor = htmlspecialchars($actorEmail !== '' ? $actorEmail : 'user', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeBody = nl2br(htmlspecialchars($body !== '' ? $body : '(no message content)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
$safeUrl = htmlspecialchars($ticketUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$html = <<<HTML
<h2>Customer Reply on Ticket #{$ticketId}</h2>
<p><strong>Subject:</strong> {$safeSubject}<br /><strong>Reporter:</strong> {$safeReporter}<br /><strong>From:</strong> {$safeActor}</p>
<blockquote style="padding:10px;border-left:4px solid #d4e1df;background:#f8fbf9;">{$safeBody}</blockquote>
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

