#!/usr/bin/env php
<?php

declare(strict_types=1);

function randomString(string $chars, int $len): string
{
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

$email = sprintf('seed-owner-%s@onledge.gops.app', bin2hex(random_bytes(3)));
$password = randomString('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^*', 18);
$hash = password_hash($password, PASSWORD_DEFAULT);

$sql = <<<SQL
INSERT INTO users (email, password_hash, role, is_active, is_seed)
VALUES (
  '{$email}',
  '{$hash}',
  'owner',
  TRUE,
  TRUE
)
ON CONFLICT (email)
DO UPDATE SET
  role = 'owner',
  is_active = TRUE,
  is_seed = TRUE,
  disabled_at = NULL;
SQL;

echo "Seed owner credentials (one-time use):\n";
echo "Email: {$email}\n";
echo "Password: {$password}\n\n";
echo "Run this SQL in pgAdmin:\n{$sql}\n";
