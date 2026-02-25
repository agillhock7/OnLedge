# OnLedge

Receipt Capture + Search + Export, designed for cPanel shared hosting where deployment is file-copy only.

## Project Goals

- Frontend: Vue 3 + Vite + TypeScript + Pinia + Vue Router + PWA + IndexedDB (`idb`)
- Backend: PHP 8.x REST API + PostgreSQL
- Deployment: build locally, commit deploy artifacts, push to GitHub, let cPanel Git deployment copy files
- Hard rule: no `npm`/Node build steps on cPanel server

## Hosting and Deployment Targets

- Public URL: `https://onledge.gops.app`
- Git remote: `git@github.com:agillhock7/OnLedge.git`
- cPanel Git working tree: `/home/gopsapp1/repositories/onledge`
- Apache document root: `/home/gopsapp1/onledge.gops.app`
- Deployment copy source: `deploy/public_html/*`

## Repository Layout

```txt
/
  .cpanel.yml
  README.md
  .gitignore
  package.json
  /scripts
    prepare-deploy.sh
  /frontend
    (Vue 3 + Vite + TS + Pinia + Router + PWA + idb)
  /api
    /.htaccess
    /.env.example
    /config
      config.example.php
      (config.php is user-created and gitignored)
    /migrations
      001_init.sql
    /public
      index.php
    /src
      Auth/ Controllers/ DB/ Helpers/ Router/
  /deploy
    /public_html
      (frontend build output lands here)
      /api
      /uploads
        .gitkeep
        .htaccess
```

## Local Development

Prereqs:

- Node.js 20+ and npm
- PHP 8.x
- PostgreSQL 14+ (or remote PostgreSQL from cPanel/host)

Install frontend dependencies:

```bash
npm --prefix frontend install
```

Run frontend dev server:

```bash
npm run dev
```

API local serving example (from repo root):

```bash
php -S 127.0.0.1:8080 -t api/public
```

If using local frontend dev against local PHP, set `frontend/.env.local`:

```bash
VITE_API_BASE_URL=http://127.0.0.1:8080
```

## API Configuration

1. Copy template config:

```bash
cp api/config/config.example.php api/config/config.php
```

2. Edit `api/config/config.php` and fill real values:

- `app.env`, `app.url`, `app.api_base_url`
- `database.host`, `port`, `dbname`, `user`, `password`, `sslmode`
- `uploads.dir` (absolute path recommended outside web root)
- `session_cookie.secure`, `httponly`, `samesite`
- `uploads.max_upload_mb`, `uploads.allowed_mime_types`
- optional `smtp.*`

3. Keep secrets out of git:

- `api/config/config.php` is intentionally ignored in `.gitignore`

Reference-only env file:

- `api/.env.example` documents equivalent keys, but runtime uses `config.php`

## Database Migrations (PostgreSQL)

Apply migration SQL:

```bash
psql "host=<HOST> port=<PORT> dbname=<DB> user=<USER> sslmode=<SSLMODE>" -f api/migrations/001_init.sql
```

What migration creates:

- `users`, `password_resets`, `receipts`, `rules`
- `tsvector` search column + GIN index + trigger for full-text search
- `updated_at` triggers

cPanel note:

- If cPanel PostgreSQL is available, use those connection credentials.
- If not, use remote managed PostgreSQL and set values in `config.php`.

## Build Deploy Artifacts Locally

Build command (must run locally, never on server):

```bash
npm run build
```

This does:

1. Builds frontend into `deploy/public_html`
2. Recreates `deploy/public_html/uploads` with hardened `.htaccess`
3. Copies API runtime into `deploy/public_html/api`:
   - `index.php`
   - `src/`
   - `.htaccess`
   - `.env.example`
   - `config/config.example.php`

Excluded from deploy artifact by design:

- `api/config/config.php` (secret)
- `api/migrations/`

## cPanel Deployment (`.cpanel.yml`)

Current root `.cpanel.yml` tasks:

- `export DEPLOYPATH=/home/gopsapp1/onledge.gops.app/`
- `mkdir -p $DEPLOYPATH`
- `mkdir -p $DEPLOYPATH/uploads`
- `mkdir -p $DEPLOYPATH/api`
- `chmod 775 $DEPLOYPATH/uploads`
- `cp -R deploy/public_html/. $DEPLOYPATH`

This copies files only and does not delete existing uploads.

## cPanel Git Version Control Setup

1. In cPanel, create or connect repository to:
   - `git@github.com:agillhock7/OnLedge.git`
2. Confirm working tree is:
   - `/home/gopsapp1/repositories/onledge`
3. Ensure deployment is enabled for the repository and uses root `.cpanel.yml`.
4. Local workflow per release:
   1. `npm run build`
   2. `git add -A`
   3. `git commit -m "build: deploy artifacts"`
   4. `git push origin <branch>`
5. In cPanel, trigger deployment (or auto-deploy on push if configured).

## Post-Deploy Server Steps

After first deploy, on server copy config template and edit secrets:

```bash
cp /home/gopsapp1/onledge.gops.app/api/config/config.example.php \
   /home/gopsapp1/onledge.gops.app/api/config/config.php
```

Then edit `/home/gopsapp1/onledge.gops.app/api/config/config.php`.

Also ensure upload directory exists and is writable by PHP:

- preferred outside web root (example: `/home/gopsapp1/onledge_uploads`)
- if inside web root (`/home/gopsapp1/onledge.gops.app/uploads`), `.htaccess` already disables listing and PHP execution

## Security Notes

- Passwords hashed with `password_hash()`
- Session auth via secure, `HttpOnly` cookies (`SameSite` configurable)
- API endpoints scope data by `user_id`
- `/api/.htaccess` denies direct access to `config` and `migrations`
- `/uploads/.htaccess` prevents directory listing and PHP execution

## API Surface (MVP)

Auth:

- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `POST /api/auth/forgot-password`
- `GET /api/auth/me`

Receipts:

- `GET /api/receipts`
- `POST /api/receipts`
- `GET /api/receipts/{id}`
- `PUT /api/receipts/{id}`
- `DELETE /api/receipts/{id}`
- `POST /api/receipts/{id}/process` (processing stub + explainability)

Rules / Search / Export:

- `GET /api/rules`
- `POST /api/rules`
- `PUT /api/rules/{id}`
- `DELETE /api/rules/{id}`
- `GET /api/search?q=...`
- `GET /api/export/csv?from=YYYY-MM-DD&to=YYYY-MM-DD`

## Compatibility Reference (Documented)

Target environment notes:

- cPanel: `132.0 (build 24)`
- Apache: `2.4.66`
- OS: Linux `x86_64`
- Kernel: `4.18.0-513.18.1.lve.2.el8.x86_64`
- PHP: `8.x` expected
- MySQL exists but this app uses PostgreSQL
