#!/usr/bin/env bash
# One-time setup of a new Codespace: dependencies, database, config files.
# The keys come from Codespaces Secrets (never from the repository).
set -e
cd "$(dirname "$0")/.."
APP_DIR="$(pwd)"

echo "== EGradePro: installing PHP libraries (composer)"
composer install --no-interaction --no-progress --prefer-dist || echo "!! composer install failed - PDF/email libraries may be missing"

echo "== EGradePro: serving the app at /watdahel/"
ln -sfn "$APP_DIR" /var/www/html/watdahel

echo "== EGradePro: upload folders writable by the web server"
mkdir -p static/uploads
chown -R www-data:www-data static/uploads || true
chmod -R ug+rwX static/uploads || true

echo "== EGradePro: config files from Codespaces Secrets"
cat > config/gemini_config.php <<'PHP'
<?php
// Written by .devcontainer/setup.sh from the Codespaces secret GEMINI_API_KEY.
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', (string) getenv('GEMINI_API_KEY'));
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite');
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/interactions');
PHP
cat > config/google_config.php <<'PHP'
<?php
// Written by .devcontainer/setup.sh from Codespaces secrets.
define('GOOGLE_OAUTH_CLIENT_ID', (string) getenv('GOOGLE_OAUTH_CLIENT_ID'));
define('GOOGLE_OAUTH_CLIENT_SECRET', (string) getenv('GOOGLE_OAUTH_CLIENT_SECRET'));
define('GOOGLE_OAUTH_REDIRECT_URI', (string) (getenv('APP_URL') ?: 'http://localhost/watdahel') . '/modules/ai/google/oauth_callback.php');
define('GOOGLE_REFRESH_TOKEN', (string) getenv('GOOGLE_REFRESH_TOKEN'));
PHP
[ -f config/email.php ] || cp config/email.example.php config/email.php

echo "== EGradePro: database"
service mariadb start
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS egradepro CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
# XAMPP uses root with no password; allow that for PHP over TCP / socket.
mysql -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD(''); FLUSH PRIVILEGES;" || true
if [ "$(mysql -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='egradepro'")" = "0" ]; then
  mysql -uroot egradepro < database/egradepro.sql
  echo "   imported database/egradepro.sql"
fi

echo "== EGradePro: setup done. start.sh runs on every start."
