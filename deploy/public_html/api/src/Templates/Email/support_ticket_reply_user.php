<?php

declare(strict_types=1);

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

$safeSubject = htmlspecialchars($subjectLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeActor = htmlspecialchars($actorEmail !== '' ? $actorEmail : 'Support team', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeBody = nl2br(htmlspecialchars($body !== '' ? $body : '(no message content)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
$safeUrl = htmlspecialchars($ticketUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$html = <<<HTML
<h2>Support Reply Received</h2>
<p>Ticket <strong>#{$ticketId}</strong> has a new update.</p>
<p><strong>Subject:</strong> {$safeSubject}<br /><strong>From:</strong> {$safeActor}</p>
<blockquote style="padding:10px;border-left:4px solid #d4e1df;background:#f8fbf9;">{$safeBody}</blockquote>
HTML;

if ($safeUrl !== '') {
    $html .= "<p><a href=\"{$safeUrl}\">Reply in OnLedge</a></p>";
}

$html .= '<p>- OnLedge Support</p>';

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];

