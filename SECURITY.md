# Security Policy

## Scope

This repository is public. Never commit secrets, credentials, or server-local configuration files.

Sensitive files that must remain untracked:

- `api/config/config.php`
- any `.env` file containing real credentials

## Production Baseline

Use these minimum settings in production:

- `app.env = 'production'`
- `app.debug_errors = false`
- `session_cookie.secure = true`
- `session_cookie.httponly = true`
- `session_cookie.samesite = 'Lax'` (or stricter if your flow allows)

## Data and Access Controls

- All receipt/rule queries are user-scoped by `user_id`
- Passwords are stored with `password_hash()`
- API mutating requests require `X-OnLedge-Client: web`
- `/api/.htaccess` blocks direct access to `config`, `migrations`, and `src`
- Uploads should be outside web root when possible

## Dependency Hygiene

Before release:

```bash
npm --prefix frontend audit
npm --prefix frontend outdated
```

Review and patch high/critical issues before deployment.

## Reporting

If you discover a security issue, do not post exploit details in a public issue first.

Open a private report with:

- reproduction steps
- impact scope
- suggested mitigation (if available)
