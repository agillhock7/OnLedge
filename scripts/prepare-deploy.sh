#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_DIR="$ROOT_DIR/deploy/public_html"
API_SRC="$ROOT_DIR/api"
API_DEPLOY="$DEPLOY_DIR/api"

# Build SPA assets directly into deploy/public_html.
npm --prefix "$ROOT_DIR/frontend" run build

# Ensure deploy runtime folders exist after Vite emptyOutDir.
mkdir -p "$DEPLOY_DIR/uploads"

# Re-create uploads hardening files.
cat > "$DEPLOY_DIR/uploads/.htaccess" <<'HTACCESS'
Options -Indexes

<FilesMatch "\\.(php|phtml|phar)$">
  Require all denied
</FilesMatch>
HTACCESS

touch "$DEPLOY_DIR/uploads/.gitkeep"

# Prepare API deploy folder.
rm -rf "$API_DEPLOY"
mkdir -p "$API_DEPLOY/config"

cp "$API_SRC/public/index.php" "$API_DEPLOY/index.php"
cp "$API_SRC/.htaccess" "$API_DEPLOY/.htaccess"
cp "$API_SRC/.env.example" "$API_DEPLOY/.env.example"
cp "$API_SRC/config/config.example.php" "$API_DEPLOY/config/config.example.php"
cp -R "$API_SRC/src" "$API_DEPLOY/src"

echo "Deploy artifacts assembled in $DEPLOY_DIR"
