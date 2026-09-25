#!/usr/bin/env bash
# Every time the Codespace starts: database + web server, the public address
# (APP_URL) for email links, and the mail settings from Codespaces Secrets.
cd "$(dirname "$0")/.."

# Secrets pasted from Notepad often carry an invisible Enter / spaces at the end:
# clean them before anything uses them.
clean() { printf '%s' "$1" | tr -d '\r\n' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//'; }
for n in MAIL_PASSWORD MAIL_USERNAME GEMINI_API_KEY GOOGLE_OAUTH_CLIENT_ID GOOGLE_OAUTH_CLIENT_SECRET GOOGLE_REFRESH_TOKEN; do
  export "$n=$(clean "${!n}")"
done
export MAIL_PASSWORD="${MAIL_PASSWORD// /}"

# Config files read the (cleaned) keys from the environment.
cat > config/gemini_config.php <<'PHP'
<?php
// Written by .devcontainer/start.sh from the Codespaces secret GEMINI_API_KEY.
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', trim((string) getenv('GEMINI_API_KEY')));
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite');
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/interactions');
PHP
cat > config/google_config.php <<'PHP'
<?php
// Written by .devcontainer/start.sh from Codespaces secrets.
define('GOOGLE_OAUTH_CLIENT_ID', trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID')));
define('GOOGLE_OAUTH_CLIENT_SECRET', trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET')));
define('GOOGLE_OAUTH_REDIRECT_URI', trim((string) (getenv('APP_URL') ?: 'http://localhost/watdahel')) . '/modules/ai/google/oauth_callback.php');
define('GOOGLE_REFRESH_TOKEN', trim((string) getenv('GOOGLE_REFRESH_TOKEN')));
PHP
[ -f config/email.php ] || cp config/email.example.php config/email.php

service mariadb start >/dev/null 2>&1 || true

# Serve the app at /watdahel/ (BASE_URL) with index.php as the folder page; / goes there too.
APP_DIR="$(pwd)"
rm -f /var/www/html/watdahel 2>/dev/null || true
cat > /etc/apache2/conf-available/egradepro-app.conf <<CONF
Alias /watdahel "${APP_DIR}"
<Directory "${APP_DIR}">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php index.html
</Directory>
RedirectMatch 302 ^/$ /watdahel/
CONF
a2enconf egradepro-app >/dev/null 2>&1 || true
# full stop/start so Apache (PHP) gets the cleaned keys from this shell
apache2ctl stop >/dev/null 2>&1 || true
sleep 1
apache2ctl start >/dev/null 2>&1 || true

if [ -n "$CODESPACE_NAME" ]; then
  APP_URL="https://${CODESPACE_NAME}-80.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}/watdahel"
else
  APP_URL="http://localhost/watdahel"
fi

cat > .env <<ENV
# Written by .devcontainer/start.sh (Codespaces). Do not commit.
APP_URL=${APP_URL}
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=${MAIL_USERNAME:-jaynunez017@gmail.com}
MAIL_PASSWORD=${MAIL_PASSWORD}
MAIL_FROM_ADDRESS=${MAIL_USERNAME:-jaynunez017@gmail.com}
MAIL_FROM_NAME="EGRADEPRO Grading & Enrollment System"
GEMINI_API_KEY=${GEMINI_API_KEY}
GOOGLE_OAUTH_CLIENT_ID=${GOOGLE_OAUTH_CLIENT_ID}
GOOGLE_OAUTH_CLIENT_SECRET=${GOOGLE_OAUTH_CLIENT_SECRET}
GOOGLE_REFRESH_TOKEN=${GOOGLE_REFRESH_TOKEN}
ENV
chmod 640 .env; chgrp www-data .env 2>/dev/null || true

# Apache does not inherit the Codespace variables: hand the keys to PHP.
{
  echo "SetEnv APP_URL \"${APP_URL}\""
  for v in GEMINI_API_KEY GOOGLE_OAUTH_CLIENT_ID GOOGLE_OAUTH_CLIENT_SECRET GOOGLE_REFRESH_TOKEN; do
    echo "SetEnv $v \"${!v}\""
  done
} > /etc/apache2/conf-available/egradepro-env.conf
a2enconf egradepro-env >/dev/null 2>&1 || true
apache2ctl graceful >/dev/null 2>&1 || true

# Make the link reachable from any phone (public port).
gh codespace ports visibility 80:public -c "$CODESPACE_NAME" >/dev/null 2>&1 \
  && echo "Port 80 is public." \
  || echo "Make port 80 Public: Ports tab > right-click port 80 > Port Visibility > Public."

echo ""
echo "=============================================================="
echo " EGradePro is running:  ${APP_URL}/"
echo " Student Login:         ${APP_URL}/student_login.php"
echo "=============================================================="
