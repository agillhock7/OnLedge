# OnLedge

![OnLedge logo](frontend/public/icon.svg)

Camera-first receipt capture with searchable records and clean exports.

OnLedge is built for teams that need fast expense capture, explainable processing, and deployment on cPanel shared hosting without server-side Node builds.

## Live Project

- App URL: `https://onledge.gops.app`
- Repository: `git@github.com:agillhock7/OnLedge.git`

## Product Snapshot

- Capture receipts from camera or image picker
- Store and search receipt data with PostgreSQL full-text search
- Apply rules with explainability metadata
- Export reports as CSV
- Operate with an offline-aware frontend queue

## Tech Stack

- Frontend: Vue 3, Vite, TypeScript, Pinia, Vue Router, PWA, IndexedDB (`idb`)
- Backend: PHP 8.x REST API
- Database: PostgreSQL
- Hosting target: cPanel shared hosting

## Deployment Model (Important)

This repo is intentionally designed for hosts where the server should only copy files.

- Build happens locally
- Built artifacts are committed under `deploy/public_html`
- cPanel Git deployment runs `.cpanel.yml` to copy files into web root
- Server must not run `npm`, `vite`, or any Node build step

## Repository Layout

```txt
/
  .cpanel.yml
  README.md
  SECURITY.md
  .gitignore
  package.json
  /scripts
    prepare-deploy.sh
    generate-social-card.mjs
  /frontend
  /api
  /deploy/public_html
```

## Quick Start (Local)

Prerequisites:

- Node.js 20+
- npm
- PHP 8.x
- PostgreSQL

Install frontend dependencies:

```bash
npm --prefix frontend install
```

Run frontend dev server:

```bash
npm run dev
```

Run API locally:

```bash
php -S 127.0.0.1:8080 -t api/public
```

Optional local frontend API override (`frontend/.env.local`):

```bash
VITE_API_BASE_URL=http://127.0.0.1:8080
```

## Configuration

Create runtime config from template:

```bash
cp api/config/config.example.php api/config/config.php
```

Fill `api/config/config.php` with real values:

- `app.env`, `app.url`, `app.api_base_url`
- `app.debug_errors` (set `false` in production)
- `database.host`, `port`, `dbname`, `user`, `password`, `sslmode`
- `uploads.dir`, `max_upload_mb`, `allowed_mime_types`
- `session_cookie.secure`, `session_cookie.httponly`, `session_cookie.samesite`
- optional `smtp.*`

Reference-only env mapping exists in `api/.env.example`.

## Database Setup

Run migration:

```bash
psql "host=<HOST> port=<PORT> dbname=<DB> user=<USER> sslmode=<SSLMODE>" -f api/migrations/001_init.sql
```

Migration includes:

- `users`, `password_resets`, `receipts`, `rules`
- trigger-based `updated_at`
- `tsvector` search + GIN index
- extension-free UUID generator (`onledge_uuid_v4`) for shared hosts without `pgcrypto`

## Build and Prepare Deploy Artifacts

Build everything locally:

```bash
npm run build
```

This command:

1. Builds frontend into `deploy/public_html`
2. Recreates hardened `deploy/public_html/uploads`
3. Copies API runtime into `deploy/public_html/api`

Excluded by design:

- `api/config/config.php` (secret)
- `api/migrations/` (not required at runtime)

## cPanel Deployment

`.cpanel.yml` copies deploy artifacts to:

- `/home/gopsapp1/onledge.gops.app`

Server details used by this project:

- cPanel Git working tree: `/home/gopsapp1/repositories/onledge`
- Web root: `/home/gopsapp1/onledge.gops.app`

Typical release flow:

1. `npm run build`
2. `git add -A`
3. `git commit -m "<message>"`
4. `git push origin main`
5. Trigger cPanel deployment (or let auto-deploy run)

## Public Repo Guidance

This is a public repository. Keep it safe for forks, clones, and indexing.

Do not commit:

- `api/config/config.php`
- real DB credentials
- SMTP credentials
- private tokens/keys
- user-uploaded files

Before pushing:

1. Confirm `git status` does not include any secret-bearing file.
2. Confirm production secrets are only on server.
3. Confirm `deploy/public_html` contains no private runtime data.

For vulnerability reporting process, see `SECURITY.md`.

## Branding Assets

Primary assets:

- App icon/logo: `frontend/public/icon.svg`
- Social card: `frontend/public/social-card.png` (1200x630)

Metadata for sharing is configured in `frontend/index.html`:

- Open Graph (`og:*`)
- Twitter card (`twitter:*`)
- canonical URL
- JSON-LD `WebApplication`

If branding updates are made, regenerate and verify:

```bash
node scripts/generate-social-card.mjs
npm run build
```

## Security Baseline

- Password hashing with `password_hash()`
- User-scoped queries by `user_id`
- Secure session cookie support (`secure`, `httponly`, `samesite`)
- API hardening headers on responses
- `POST`/`PUT`/`DELETE` request guard: `X-OnLedge-Client: web`
- `.htaccess` blocks direct access to API internals (`config`, `migrations`, `src`)

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
- `POST /api/receipts/{id}/process`

Rules/Search/Export:

- `GET /api/rules`
- `POST /api/rules`
- `PUT /api/rules/{id}`
- `DELETE /api/rules/{id}`
- `GET /api/search?q=...`
- `GET /api/export/csv?from=YYYY-MM-DD&to=YYYY-MM-DD`

## Contribution Guidance

- Keep changes scoped and reviewable.
- Include deploy artifacts when frontend/API runtime behavior changes.
- Prefer security-first defaults in code and docs.
- Run checks before PRs:

```bash
npm --prefix frontend run typecheck
npm run build
```

## Compatibility Notes

Target hosting profile:

- cPanel `132.0 (build 24)`
- Apache `2.4.66` / LiteSpeed-compatible behavior
- Linux `x86_64`
- PHP `8.x`
- PostgreSQL `10+` (migration is compatible with shared-host constraints)
