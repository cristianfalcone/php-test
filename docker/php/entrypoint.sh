#!/bin/sh
set -e

APP_DIR=${APP_DIR:-/var/www}
uid=${UID:-$(stat -c '%u' "$APP_DIR" 2>/dev/null || echo 0)}
gid=${GID:-$(stat -c '%g' "$APP_DIR" 2>/dev/null || echo 0)}

# fallback when repo appears as root (Docker Desktop, etc.)
if [ "$uid" = "0" ]; then
    uid=1000
fi

if [ "$gid" = "0" ]; then
    gid=1000
fi

current_gid=$(getent group www-data | cut -d: -f3 2>/dev/null || echo "")
if [ -z "$current_gid" ]; then
    addgroup -g "$gid" www-data
elif [ "$current_gid" != "$gid" ]; then
    groupmod -g "$gid" www-data
fi

current_uid=$(id -u www-data 2>/dev/null || echo "")
if [ -z "$current_uid" ]; then
    adduser -D -H -u "$uid" -G www-data www-data
elif [ "$current_uid" != "$uid" ]; then
    usermod -u "$uid" www-data
fi

if [ "$(id -g www-data)" != "$gid" ]; then
    usermod -g "$gid" www-data
fi

exec su-exec www-data "$@"
