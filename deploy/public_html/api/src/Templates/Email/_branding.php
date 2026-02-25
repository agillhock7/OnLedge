<?php

declare(strict_types=1);

if (!function_exists('onledge_email_escape')) {
    function onledge_email_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('onledge_email_layout')) {
    /**
     * @param array<string, string> $options
     */
    function onledge_email_layout(
        string $title,
        string $subtitle,
        string $bodyHtml,
        string $appUrl = '',
        string $ctaLabel = '',
        string $ctaUrl = '',
        array $options = [],
    ): string {
        $safeTitle = onledge_email_escape($title);
        $safeSubtitle = onledge_email_escape($subtitle);
        $safeAppUrl = onledge_email_escape($appUrl);
        $safeCtaLabel = onledge_email_escape($ctaLabel);
        $safeCtaUrl = onledge_email_escape($ctaUrl);
        $previewText = trim((string) ($options['preview_text'] ?? 'OnLedge notification'));
        $safePreview = onledge_email_escape($previewText);
        $footer = trim((string) ($options['footer'] ?? 'OnLedge · Receipt Capture + Search + Export'));
        $safeFooter = onledge_email_escape($footer);

        $cta = '';
        if ($safeCtaLabel !== '' && $safeCtaUrl !== '') {
            $cta = <<<HTML
                <div style="margin-top:20px;">
                  <a href="{$safeCtaUrl}" style="display:inline-block;padding:12px 18px;background:linear-gradient(120deg,#0f2f3a,#1a5a6d);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;letter-spacing:0.01em;">
                    {$safeCtaLabel}
                  </a>
                </div>
            HTML;
        }

        $appLine = $safeAppUrl !== ''
            ? '<p style="margin:16px 0 0;color:#587480;font-size:12px;">' . $safeAppUrl . '</p>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>{$safeTitle}</title>
  </head>
  <body style="margin:0;padding:0;background:#eef4f3;font-family:Manrope,'Segoe UI',Arial,sans-serif;color:#12303a;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$safePreview}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:26px 12px;background:#eef4f3;">
      <tr>
        <td align="center">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #d7e4e2;border-radius:18px;overflow:hidden;">
            <tr>
              <td style="padding:22px 24px;background:linear-gradient(135deg,#0f2d38 0%,#1c5365 64%,#db7f2e 100%);color:#ffffff;">
                <p style="margin:0 0 8px;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;opacity:0.85;">OnLedge Notifications</p>
                <h1 style="margin:0;font-size:24px;line-height:1.2;font-family:Sora,Manrope,'Segoe UI',sans-serif;">{$safeTitle}</h1>
                <p style="margin:10px 0 0;font-size:14px;line-height:1.4;opacity:0.92;">{$safeSubtitle}</p>
              </td>
            </tr>
            <tr>
              <td style="padding:22px 24px;">
                {$bodyHtml}
                {$cta}
                {$appLine}
              </td>
            </tr>
          </table>
          <p style="margin:12px 0 0;color:#617b85;font-size:12px;">{$safeFooter}</p>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;
    }
}

