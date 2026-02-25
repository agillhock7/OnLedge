<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$user = is_array($data['user'] ?? null) ? $data['user'] : [];
$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
$summaryPayload = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$summary = is_array($summaryPayload['summary'] ?? null) ? $summaryPayload['summary'] : [];

$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$settingsUrl = $appUrl !== '' ? $appUrl . '/app/settings' : '';
$reportsUrl = $appUrl !== '' ? $appUrl . '/app/reports' : '';
$email = trim((string) ($user['email'] ?? ''));
$generatedAt = trim((string) ($data['generated_at'] ?? ''));

$currency = strtoupper(trim((string) ($summary['currency'] ?? 'USD')));
if ($currency === '') {
    $currency = 'USD';
}
$count = (int) ($summary['receipt_count'] ?? 0);
$spend = (float) ($summary['spend_total'] ?? 0);
$weeklyEnabled = in_array($settings['weekly_report_enabled'] ?? true, [true, 1, '1', 't', 'true'], true);

$subject = 'OnLedge test email delivered';

$text = "This is a test notification from OnLedge.\n\n";
$text .= 'Account: ' . ($email !== '' ? $email : 'unknown') . "\n";
$text .= 'Generated: ' . ($generatedAt !== '' ? $generatedAt : 'now') . "\n";
$text .= 'Weekly reports enabled: ' . ($weeklyEnabled ? 'Yes' : 'No') . "\n";
$text .= "Last 7 days: {$count} receipts, {$currency} " . number_format($spend, 2) . " total\n";
if ($settingsUrl !== '') {
    $text .= "\nSettings: {$settingsUrl}\n";
}
if ($reportsUrl !== '') {
    $text .= "Reports: {$reportsUrl}\n";
}
$text .= "\n- OnLedge";

$safeEmail = onledge_email_escape($email !== '' ? $email : 'unknown');
$safeGeneratedAt = onledge_email_escape($generatedAt !== '' ? $generatedAt : 'now');
$safeWeekly = onledge_email_escape($weeklyEnabled ? 'Enabled' : 'Disabled');
$safeCount = onledge_email_escape((string) $count);
$safeSpend = onledge_email_escape(number_format($spend, 2));
$safeCurrency = onledge_email_escape($currency);

$bodyHtml = '<p style="margin:0 0 12px;font-size:14px;line-height:1.55;">Your email notification channel is working correctly.</p>';
$bodyHtml .= '<div style="border:1px solid #d6e3e1;border-radius:12px;padding:12px;background:#f8fbfa;">';
$bodyHtml .= "<p style=\"margin:0 0 7px;\"><strong>Account:</strong> {$safeEmail}</p>";
$bodyHtml .= "<p style=\"margin:0 0 7px;\"><strong>Generated:</strong> {$safeGeneratedAt}</p>";
$bodyHtml .= "<p style=\"margin:0;\"><strong>Weekly reports:</strong> {$safeWeekly}</p>";
$bodyHtml .= '</div>';
$bodyHtml .= '<p style="margin:14px 0 8px;font-size:13px;font-weight:700;color:#21414d;">Sample weekly snapshot</p>';
$bodyHtml .= '<ul style="margin:0;padding-left:18px;color:#2a4953;line-height:1.5;">';
$bodyHtml .= "<li>{$safeCount} receipts in the last 7 days</li>";
$bodyHtml .= "<li>{$safeCurrency} {$safeSpend} total spend</li>";
$bodyHtml .= '</ul>';

$html = onledge_email_layout(
    'Test Email Successful',
    'OnLedge notifications are configured and can reach your inbox.',
    $bodyHtml,
    $appUrl,
    $settingsUrl !== '' ? 'Open Settings' : '',
    $settingsUrl,
    ['preview_text' => 'OnLedge test email delivered']
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
