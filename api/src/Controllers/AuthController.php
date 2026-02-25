<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\SessionAuth;
use App\Helpers\Authz;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Response;
use App\Helpers\Schema;
use PDO;
use PDOException;

final class AuthController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly SessionAuth $auth,
        private readonly array $config = [],
    ) {
    }

    public function register(): void
    {
        $input = Request::input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Valid email is required', 422);
        }
        if (strlen($password) < 8) {
            throw new HttpException('Password must be at least 8 characters', 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (email, password_hash)
                 VALUES (:email, :password_hash)
                 RETURNING id'
            );
            $stmt->execute([
                ':email' => $email,
                ':password_hash' => $hash,
            ]);
            $created = $stmt->fetch();
            $userId = (int) ($created['id'] ?? 0);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23505') {
                throw new HttpException('Email is already registered', 409);
            }
            throw $exception;
        }

        if ($userId <= 0) {
            throw new HttpException('Unable to create user account', 500);
        }

        $user = $this->findUserById($userId);

        $this->auth->login($userId);

        Response::json([
            'user' => $this->normalizeAuthUser($user),
        ], 201);
    }

    public function login(): void
    {
        $input = Request::input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        $user = $this->findUserByEmail($email);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new HttpException('Invalid email or password', 401);
        }

        $normalized = $this->normalizeAuthUser($user);
        if (!$normalized['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        $this->auth->login((int) $normalized['id']);

        Response::json([
            'user' => $normalized,
        ]);
    }

    public function logout(): void
    {
        $this->auth->logout();
        Response::json(['ok' => true]);
    }

    public function forgotPassword(): void
    {
        $input = Request::input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['ok' => true, 'message' => 'If the account exists, a reset flow can be initiated.']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id
             FROM users
             WHERE lower(trim(email)) = :email
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(DATE_ATOM);

            $insert = $this->db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)');
            $insert->execute([
                ':user_id' => (int) $user['id'],
                ':token_hash' => $tokenHash,
                ':expires_at' => $expiresAt,
            ]);

            // SMTP integration can be added later; token is intentionally not returned.
        }

        Response::json(['ok' => true, 'message' => 'If the account exists, a reset flow can be initiated.']);
    }

    public function me(): void
    {
        $userId = $this->auth->requireUserId();
        $user = $this->findUserById($userId);

        if (!$user) {
            throw new HttpException('User not found', 404);
        }

        $normalized = $this->normalizeAuthUser($user);
        if (!$normalized['is_active']) {
            $this->auth->logout();
            throw new HttpException('Account is disabled', 403);
        }

        Response::json([
            'user' => $normalized,
        ]);
    }

    public function oauthProviders(): void
    {
        $providers = ['github', 'discord'];
        $items = [];

        foreach ($providers as $provider) {
            $cfg = $this->oauthConfig($provider);
            $items[] = [
                'provider' => $provider,
                'enabled' => $this->oauthEnabled($cfg),
            ];
        }

        Response::json(['items' => $items]);
    }

    /** @param array<string, string> $params */
    public function oauthStart(array $params): void
    {
        $provider = strtolower((string) ($params['provider'] ?? ''));
        $cfg = $this->oauthConfig($provider);

        if (!$this->oauthEnabled($cfg)) {
            throw new HttpException('OAuth provider is not configured', 503);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = [
            'provider' => $provider,
            'value' => $state,
            'expires_at' => time() + 600,
        ];

        $authUrl = $this->buildOauthAuthorizeUrl($provider, $cfg, $state);
        header('Location: ' . $authUrl, true, 302);
        exit;
    }

    /** @param array<string, string> $params */
    public function oauthCallback(array $params): void
    {
        $provider = strtolower((string) ($params['provider'] ?? ''));
        $cfg = $this->oauthConfig($provider);

        if (!$this->oauthEnabled($cfg)) {
            throw new HttpException('OAuth provider is not configured', 503);
        }

        $code = trim((string) Request::query('code', ''));
        $state = trim((string) Request::query('state', ''));

        if ($code === '' || $state === '') {
            throw new HttpException('Invalid OAuth callback', 422);
        }

        $stored = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        if (!is_array($stored)
            || ($stored['provider'] ?? '') !== $provider
            || ($stored['value'] ?? '') !== $state
            || (int) ($stored['expires_at'] ?? 0) < time()) {
            throw new HttpException('OAuth state verification failed', 422);
        }

        if (!Schema::hasOauthTables($this->db)) {
            throw new HttpException('OAuth schema missing. Run migration 004_oauth_identities.sql', 503);
        }

        $token = $this->exchangeOauthToken($provider, $cfg, $code);
        $profile = $this->fetchOauthProfile($provider, $token);
        $user = $this->findOrCreateOauthUser($provider, $profile);

        $normalized = $this->normalizeAuthUser($user);
        if (!$normalized['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        $this->auth->login((int) $normalized['id']);

        $appUrl = rtrim((string) (($this->config['app']['url'] ?? '')), '/');
        $redirect = $appUrl !== '' ? ($appUrl . '/app/dashboard') : '/app/dashboard';
        header('Location: ' . $redirect, true, 302);
        exit;
    }

    /** @return array<string, mixed>|null */
    private function findUserByEmail(string $email): ?array
    {
        $columns = ['id', 'email', 'password_hash', 'created_at'];
        if ($this->hasAdminColumns()) {
            $columns = [...$columns, 'role', 'is_active', 'is_seed', 'updated_at', 'disabled_at'];
        }

        $stmt = $this->db->prepare(
            sprintf(
                'SELECT %s
                 FROM users
                 WHERE lower(trim(email)) = :email
                 LIMIT 1',
                implode(', ', $columns)
            )
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    private function findUserById(int $id): ?array
    {
        $columns = ['id', 'email', 'password_hash', 'created_at'];
        if ($this->hasAdminColumns()) {
            $columns = [...$columns, 'role', 'is_active', 'is_seed', 'updated_at', 'disabled_at'];
        }

        $stmt = $this->db->prepare(sprintf('SELECT %s FROM users WHERE id = :id LIMIT 1', implode(', ', $columns)));
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function normalizeAuthUser(array $user): array
    {
        if (!$this->hasAdminColumns()) {
            $user['role'] = 'user';
            $user['is_active'] = true;
            $user['is_seed'] = false;
            $user['updated_at'] = $user['created_at'] ?? null;
            $user['disabled_at'] = null;
        }

        return Authz::normalizeUser($user);
    }

    private function hasAdminColumns(): bool
    {
        return Schema::hasAdminUserColumns($this->db);
    }

    /** @return array<string, mixed> */
    private function oauthConfig(string $provider): array
    {
        $oauth = $this->config['oauth'] ?? [];
        if (!is_array($oauth)) {
            return [];
        }

        $providerCfg = $oauth[$provider] ?? [];
        return is_array($providerCfg) ? $providerCfg : [];
    }

    /** @param array<string, mixed> $cfg */
    private function oauthEnabled(array $cfg): bool
    {
        return (bool) ($cfg['enabled'] ?? false)
            && trim((string) ($cfg['client_id'] ?? '')) !== ''
            && trim((string) ($cfg['client_secret'] ?? '')) !== ''
            && trim((string) ($cfg['redirect_uri'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $cfg */
    private function buildOauthAuthorizeUrl(string $provider, array $cfg, string $state): string
    {
        $clientId = (string) ($cfg['client_id'] ?? '');
        $redirectUri = (string) ($cfg['redirect_uri'] ?? '');

        if ($provider === 'github') {
            return 'https://github.com/login/oauth/authorize?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => 'read:user user:email',
                'state' => $state,
            ]);
        }

        if ($provider === 'discord') {
            return 'https://discord.com/api/oauth2/authorize?' . http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'scope' => 'identify email',
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'prompt' => 'consent',
            ]);
        }

        throw new HttpException('Unsupported OAuth provider', 422);
    }

    /** @param array<string, mixed> $cfg */
    private function exchangeOauthToken(string $provider, array $cfg, string $code): string
    {
        $clientId = (string) ($cfg['client_id'] ?? '');
        $clientSecret = (string) ($cfg['client_secret'] ?? '');
        $redirectUri = (string) ($cfg['redirect_uri'] ?? '');

        if ($provider === 'github') {
            $response = $this->httpJson(
                'POST',
                'https://github.com/login/oauth/access_token',
                [
                    'Accept: application/json',
                ],
                http_build_query([
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ])
            );

            $token = (string) ($response['access_token'] ?? '');
            if ($token === '') {
                throw new HttpException('OAuth token exchange failed', 502);
            }

            return $token;
        }

        if ($provider === 'discord') {
            $response = $this->httpJson(
                'POST',
                'https://discord.com/api/oauth2/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ])
            );

            $token = (string) ($response['access_token'] ?? '');
            if ($token === '') {
                throw new HttpException('OAuth token exchange failed', 502);
            }

            return $token;
        }

        throw new HttpException('Unsupported OAuth provider', 422);
    }

    /** @return array{provider_user_id: string, email: string} */
    private function fetchOauthProfile(string $provider, string $token): array
    {
        if ($provider === 'github') {
            $user = $this->httpJson('GET', 'https://api.github.com/user', [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'User-Agent: OnLedgeOAuth',
            ]);

            $emails = $this->httpJson('GET', 'https://api.github.com/user/emails', [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'User-Agent: OnLedgeOAuth',
            ]);

            $email = '';
            if (is_array($emails)) {
                foreach ($emails as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    if (($entry['verified'] ?? false) && ($entry['primary'] ?? false)) {
                        $email = strtolower(trim((string) ($entry['email'] ?? '')));
                        break;
                    }
                }

                if ($email === '') {
                    foreach ($emails as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }

                        if (($entry['verified'] ?? false)) {
                            $email = strtolower(trim((string) ($entry['email'] ?? '')));
                            break;
                        }
                    }
                }
            }

            if ($email === '') {
                $email = strtolower(trim((string) ($user['email'] ?? '')));
            }

            $providerUserId = (string) ($user['id'] ?? '');
            if ($providerUserId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException('OAuth profile is missing a verified email', 422);
            }

            return [
                'provider_user_id' => $providerUserId,
                'email' => $email,
            ];
        }

        if ($provider === 'discord') {
            $user = $this->httpJson('GET', 'https://discord.com/api/users/@me', [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ]);

            $providerUserId = (string) ($user['id'] ?? '');
            $email = strtolower(trim((string) ($user['email'] ?? '')));

            if ($providerUserId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException('Discord account must provide a valid email', 422);
            }

            return [
                'provider_user_id' => $providerUserId,
                'email' => $email,
            ];
        }

        throw new HttpException('Unsupported OAuth provider', 422);
    }

    /** @param array{provider_user_id: string, email: string} $profile
     *  @return array<string, mixed>
     */
    private function findOrCreateOauthUser(string $provider, array $profile): array
    {
        $identityStmt = $this->db->prepare(
            'SELECT user_id
             FROM oauth_identities
             WHERE provider = :provider AND provider_user_id = :provider_user_id
             LIMIT 1'
        );
        $identityStmt->execute([
            ':provider' => $provider,
            ':provider_user_id' => $profile['provider_user_id'],
        ]);

        $identity = $identityStmt->fetch();
        if ($identity && isset($identity['user_id'])) {
            $user = $this->findUserById((int) $identity['user_id']);
            if ($user) {
                return $user;
            }
        }

        $user = $this->findUserByEmail($profile['email']);
        if (!$user) {
            $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $stmt = $this->db->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :password_hash) RETURNING id');
            $stmt->execute([
                ':email' => $profile['email'],
                ':password_hash' => $passwordHash,
            ]);
            $created = $stmt->fetch();
            $userId = (int) ($created['id'] ?? 0);
            if ($userId <= 0) {
                throw new HttpException('Unable to create OAuth user', 500);
            }

            $user = $this->findUserById($userId);
        }

        if (!$user) {
            throw new HttpException('Unable to load OAuth user', 500);
        }

        $link = $this->db->prepare(
            'INSERT INTO oauth_identities (provider, provider_user_id, user_id)
             VALUES (:provider, :provider_user_id, :user_id)
             ON CONFLICT (provider, provider_user_id)
             DO UPDATE SET user_id = EXCLUDED.user_id'
        );
        $link->execute([
            ':provider' => $provider,
            ':provider_user_id' => $profile['provider_user_id'],
            ':user_id' => (int) $user['id'],
        ]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpJson(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('OAuth requires cURL on server', 500);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new HttpException('Unable to initialize HTTP request', 500);
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        if ($headers !== []) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new HttpException('OAuth HTTP request failed: ' . $curlError, 502);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException('OAuth provider returned an invalid response', 502);
        }

        if ($status >= 400) {
            throw new HttpException('OAuth provider request failed', 502);
        }

        return $decoded;
    }
}
