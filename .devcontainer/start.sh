#!/usr/bin/env bash
# Every time the Codespace starts: database + web server, the public address
# (APP_URL) for email links, and the mail settings from Codespaces Secrets.
cd "$(dirname "$0")/.."

service mariadb start >/dev/null 2>&1 || true
apache2ctl start >/dev/null 2>&1 || apache2ctl restart >/dev/null 2>&1 || true

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
