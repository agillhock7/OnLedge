<?php

declare(strict_types=1);

require_once __DIR__ . '/_branding.php';

$user = is_array($data['user'] ?? null) ? $data['user'] : [];
$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$window = is_array($summary['window'] ?? null) ? $summary['window'] : [];
$metrics = is_array($summary['summary'] ?? null) ? $summary['summary'] : [];
$topCategories = is_array($summary['top_categories'] ?? null) ? $summary['top_categories'] : [];
$topMerchants = is_array($summary['top_merchants'] ?? null) ? $summary['top_merchants'] : [];
$daily = is_array($summary['daily'] ?? null) ? $summary['daily'] : [];

$appUrl = rtrim((string) ($data['app_url'] ?? ''), '/');
$reportsUrl = $appUrl !== '' ? $appUrl . '/app/reports' : '';

$from = trim((string) ($window['from'] ?? ''));
$to = trim((string) ($window['to'] ?? ''));
$receiptCount = (int) ($metrics['receipt_count'] ?? 0);
$currency = strtoupper(trim((string) ($metrics['currency'] ?? 'USD')));
$total = (float) ($metrics['spend_total'] ?? 0);
$avg = (float) ($metrics['avg_receipt'] ?? 0);
$largest = (float) ($metrics['largest_purchase'] ?? 0);
$email = trim((string) ($user['email'] ?? ''));

$windowLabel = ($from !== '' && $to !== '') ? "{$from} to {$to}" : 'last 7 days';
$subject = sprintf('OnLedge Weekly Spending Report (%s)', $windowLabel);

$fmt = static function (float $amount, string $curr): string {
    return sprintf('%s %.2f', $curr !== '' ? $curr : 'USD', $amount);
};

$text = "Weekly spending report ({$windowLabel})\n\n";
$text .= "Account: " . ($email !== '' ? $email : 'unknown') . "\n";
$text .= "Receipts: {$receiptCount}\n";
$text .= "Total spend: " . $fmt($total, $currency) . "\n";
$text .= "Average receipt: " . $fmt($avg, $currency) . "\n";
$text .= "Largest purchase: " . $fmt($largest, $currency) . "\n";

if ($topCategories !== []) {
    $text .= "\nTop categories:\n";
    foreach ($topCategories as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = (string) ($row['label'] ?? 'Uncategorized');
        $rowTotal = (float) ($row['total'] ?? 0);
        $rowCount = (int) ($row['count'] ?? 0);
        $text .= sprintf("- %s: %s (%d receipts)\n", $label, $fmt($rowTotal, $currency), $rowCount);
    }
}

if ($reportsUrl !== '') {
    $text .= "\nOpen reports: {$reportsUrl}\n";
}

$text .= "\n- OnLedge";

$safeWindowLabel = onledge_email_escape($windowLabel);
$safeCurrency = onledge_email_escape($currency);

$bodyHtml = '<p style="margin:0 0 10px;font-size:14px;line-height:1.5;">Here is your weekly spending snapshot.</p>';
$bodyHtml .= '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">';
$bodyHtml .= '<div style="border:1px solid #d6e3e1;border-radius:10px;padding:10px;background:#f8fbfa;"><strong>Receipts</strong><br />' . $receiptCount . '</div>';
$bodyHtml .= '<div style="border:1px solid #d6e3e1;border-radius:10px;padding:10px;background:#f8fbfa;"><strong>Total Spend</strong><br />' . onledge_email_escape($fmt($total, $currency)) . '</div>';
$bodyHtml .= '<div style="border:1px solid #d6e3e1;border-radius:10px;padding:10px;background:#f8fbfa;"><strong>Average</strong><br />' . onledge_email_escape($fmt($avg, $currency)) . '</div>';
$bodyHtml .= '<div style="border:1px solid #d6e3e1;border-radius:10px;padding:10px;background:#f8fbfa;"><strong>Largest</strong><br />' . onledge_email_escape($fmt($largest, $currency)) . '</div>';
$bodyHtml .= '</div>';

if ($topCategories !== []) {
    $bodyHtml .= '<h3 style="margin:16px 0 8px;font-size:16px;">Top Categories</h3><ul style="margin:0;padding-left:18px;color:#2a4953;line-height:1.5;">';
    foreach ($topCategories as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = onledge_email_escape((string) ($row['label'] ?? 'Uncategorized'));
        $rowTotal = (float) ($row['total'] ?? 0);
        $rowCount = (int) ($row['count'] ?? 0);
        $bodyHtml .= '<li><strong>' . $label . '</strong> · ' . onledge_email_escape($fmt($rowTotal, $currency)) . ' (' . $rowCount . ' receipts)</li>';
    }
    $bodyHtml .= '</ul>';
}

if ($topMerchants !== []) {
    $bodyHtml .= '<h3 style="margin:16px 0 8px;font-size:16px;">Top Merchants</h3><ul style="margin:0;padding-left:18px;color:#2a4953;line-height:1.5;">';
    foreach ($topMerchants as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = onledge_email_escape((string) ($row['label'] ?? 'Unknown merchant'));
        $rowTotal = (float) ($row['total'] ?? 0);
        $rowCount = (int) ($row['count'] ?? 0);
        $bodyHtml .= '<li><strong>' . $label . '</strong> · ' . onledge_email_escape($fmt($rowTotal, $currency)) . ' (' . $rowCount . ' receipts)</li>';
    }
    $bodyHtml .= '</ul>';
}

if ($daily !== []) {
    $bodyHtml .= '<h3 style="margin:16px 0 8px;font-size:16px;">Daily Activity</h3><p style="margin:0;font-size:13px;color:#48626c;">';
    $parts = [];
    foreach ($daily as $row) {
        if (!is_array($row)) {
            continue;
        }
        $day = onledge_email_escape((string) ($row['day'] ?? ''));
        $rowTotal = (float) ($row['total'] ?? 0);
        $parts[] = $day . ': ' . onledge_email_escape($fmt($rowTotal, $currency));
    }
    $bodyHtml .= implode(' · ', $parts);
    $bodyHtml .= '</p>';
}

$html = onledge_email_layout(
    'Weekly Spending Report',
    "{$safeWindowLabel} · {$safeCurrency}",
    $bodyHtml,
    $appUrl,
    $reportsUrl !== '' ? 'Open Reports Dashboard' : '',
    $reportsUrl,
    ['preview_text' => "Weekly spend: {$fmt($total, $currency)}"]
);

return [
    'subject' => $subject,
    'text' => $text,
    'html' => $html,
];
