#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\DB\Database;
use App\Helpers\Schema;
use App\Services\UserNotificationService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "[OnLedge] This script must run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$bootstrapPath = $root . '/api/src/bootstrap.php';
if (!is_file($bootstrapPath)) {
    fwrite(STDERR, "[OnLedge] Bootstrap not found: {$bootstrapPath}\n");
    exit(1);
}

require_once $bootstrapPath;

$lockPath = '/tmp/onledge-weekly-report-cron.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "[OnLedge] Unable to create lock file: {$lockPath}\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[OnLedge] Weekly report cron already running, skipping.\n");
    fclose($lockHandle);
    exit(0);
}

$start = microtime(true);

try {
    $config = onledge_load_config();
    $db = Database::connection($config['database'] ?? []);

    if (!Schema::hasUserNotificationSettings($db)) {
        fwrite(STDERR, "[OnLedge] Notification schema missing. Run migration 007_user_notifications.sql\n");
        exitCodeAndUnlock($lockHandle, 1);
    }

    $service = new UserNotificationService($db, is_array($config) ? $config : []);
    $stmt = $db->query(
        "SELECT id, email, role, is_active, is_seed, created_at, updated_at, disabled_at
         FROM users
         WHERE is_active = TRUE
         ORDER BY id ASC"
    );

    $users = $stmt->fetchAll() ?: [];
    $processed = 0;
    $sent = 0;
    $errors = 0;

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }

        $processed++;
        try {
            if ($service->sendWeeklyDigestIfDue($user)) {
                $sent++;
            }
        } catch (Throwable $exception) {
            $errors++;
            $userId = (int) ($user['id'] ?? 0);
            error_log(sprintf('[OnLedge][WeeklyCron] user_id=%d failed: %s', $userId, $exception->getMessage()));
        }
    }

    $duration = round(microtime(true) - $start, 2);
    fwrite(
        STDOUT,
        sprintf(
            "[OnLedge] Weekly report cron complete. users=%d sent=%d errors=%d duration=%ss\n",
            $processed,
            $sent,
            $errors,
            $duration
        )
    );
    exitCodeAndUnlock($lockHandle, 0);
} catch (Throwable $exception) {
    error_log('[OnLedge][WeeklyCron] fatal: ' . $exception->getMessage());
    fwrite(STDERR, '[OnLedge] Weekly report cron failed: ' . $exception->getMessage() . "\n");
    exitCodeAndUnlock($lockHandle, 1);
}

/**
 * @param resource $lockHandle
 */
function exitCodeAndUnlock($lockHandle, int $code): void
{
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit($code);
}

