<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Authz;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\Schema;
use App\Services\UserNotificationService;
use App\Helpers\HttpException;
use PDO;

final class NotificationController
{
    private UserNotificationService $service;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly SessionAuth $auth,
        private readonly array $config = [],
    ) {
        $this->service = new UserNotificationService($db, $config);
    }

    public function preferences(): void
    {
        $this->ensureSchema();
        $user = $this->currentUser();
        $settings = $this->service->getPreferences((int) $user['id']);
        Response::json(['item' => $settings]);
    }

    public function updatePreferences(): void
    {
        $this->ensureSchema();
        $user = $this->currentUser();
        $input = Request::input();

        if (!array_key_exists('weekly_report_enabled', $input)) {
            throw new HttpException('No notification preference fields provided', 422);
        }

        $settings = $this->service->updatePreferences((int) $user['id'], $input);
        Response::json(['item' => $settings]);
    }

    public function sendTestEmail(): void
    {
        $this->ensureSchema();
        $user = $this->currentUser();

        $sent = $this->service->sendTestEmail($user);
        if (!$sent) {
            throw new HttpException('Unable to send test email. Confirm SMTP/sendmail settings are valid.', 503);
        }

        Response::json([
            'ok' => true,
            'message' => 'Test email sent successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $userId = $this->auth->requireUserId();
        return Authz::requireActiveUser($this->db, $userId);
    }

    private function ensureSchema(): void
    {
        if (!Schema::hasUserNotificationSettings($this->db)) {
            throw new HttpException('Notification schema missing. Run migration 007_user_notifications.sql', 503);
        }
    }
}
