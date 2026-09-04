#!/bin/sh
set -eu
for name in APP_KEY DB_PASSWORD AUTHENTIK_CLIENT_SECRET GITHUB_APP_PRIVATE_KEY WEBLATE_TOKEN GITLAB_TOKEN; do
    eval file="\${${name}_FILE:-}"
    if [ -n "$file" ] && [ -f "$file" ]; then
        value=$(cat "$file")
        export "$name=$value"
    fi
done
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
exec "$@"
