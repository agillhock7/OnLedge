<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$user = is_array($data['user'] ?? null) ? $data['user'] : [];
$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$dashboardUrl = $appUrl !== '' ? $appUrl . '/app/dashboard' : '';
$reportsUrl = $appUrl !== '' ? $appUrl . '/app/reports' : '';
$email = trim((string) ($user['email'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? 'user')));

$subject = 'Welcome to OnLedge';
$text = "Welcome to OnLedge.\n\n";
$text .= "You can now capture receipts, search records, and generate exports/reports from one place.\n";
$text .= "Your account: " . ($email !== '' ? $email : 'unknown') . "\n";
$text .= "Role: " . ($role !== '' ? $role : 'user') . "\n";
if ($dashboardUrl !== '') {
    $text .= "\nOpen dashboard: {$dashboardUrl}\n";
}
if ($reportsUrl !== '') {
    $text .= "Reports: {$reportsUrl}\n";
}
$text .= "\nWeekly spending reports are enabled by default. You can manage this in Settings.\n\n- OnLedge";

$safeEmail = onledge_email_escape($email !== '' ? $email : 'unknown');
$safeRole = onledge_email_escape($role !== '' ? $role : 'user');
$safeReportsUrl = onledge_email_escape($reportsUrl);

$bodyHtml = '<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">Your workspace is ready. Capture receipts and keep your spending organized.</p>';
$bodyHtml .= '<div style="border:1px solid #d6e3e1;border-radius:12px;padding:12px;background:#f8fbfa;">';
$bodyHtml .= "<p style=\"margin:0 0 8px;\"><strong>Account:</strong> {$safeEmail}</p>";
$bodyHtml .= "<p style=\"margin:0;\"><strong>Role:</strong> {$safeRole}</p>";
$bodyHtml .= '</div>';
$bodyHtml .= '<ul style="margin:14px 0 0;padding-left:18px;color:#2a4953;line-height:1.5;">';
$bodyHtml .= '<li>Capture receipts in seconds</li>';
$bodyHtml .= '<li>Search and inspect every receipt record</li>';
$bodyHtml .= '<li>Review trends in reports and export to CSV</li>';
$bodyHtml .= '</ul>';
if ($safeReportsUrl !== '') {
    $bodyHtml .= "<p style=\"margin:12px 0 0;font-size:13px;color:#48626c;\">Tip: Visit Reports weekly to spot spend trends faster.</p>";
}

$html = onledge_email_layout(
    'Welcome To OnLedge',
    'Receipt capture, search, and reporting in one focused workspace.',
    $bodyHtml,
    $appUrl,
    $dashboardUrl !== '' ? 'Open Dashboard' : '',
    $dashboardUrl,
    ['preview_text' => 'Your OnLedge account is ready']
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
