#!/usr/bin/env bash
set -e

# Configure Apache ServerName if provided
if [[ -n "${APACHE_SERVER_NAME}" ]]; then
  printf "ServerName %s\n" "$APACHE_SERVER_NAME" > /etc/apache2/conf-available/servername.conf
  a2enconf servername >/dev/null 2>&1 || true
fi

# Make Apache listen on $PORT if provided by PaaS (default 80)
LISTEN_PORT="${PORT:-80}"
if [[ "$LISTEN_PORT" != "80" ]]; then
  sed -ri "s/^Listen 80$/Listen ${LISTEN_PORT}/" /etc/apache2/ports.conf || true
  sed -ri "s#<VirtualHost \*:80>#<VirtualHost *:${LISTEN_PORT}>#" /etc/apache2/sites-available/000-default.conf || true
fi

exec "$@"

