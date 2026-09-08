#!/usr/bin/env sh
# Runs PHPUnit natively when the required extensions are present, otherwise
# falls back to the official composer image, which ships dom/mbstring/xmlwriter.
set -eu

missing=""
for ext in dom mbstring xmlwriter tokenizer; do
    php -m 2>/dev/null | grep -qx "$ext" || missing="$missing $ext"
done

if [ -z "$missing" ]; then
    exec vendor/bin/phpunit "$@"
fi

echo "Missing PHP extensions:$missing — running the suite in Docker instead." >&2
exec docker run --rm \
    -v "$PWD":/app -w /app \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    composer:2 \
    sh -c '[ -f vendor/autoload.php ] && [ vendor/autoload.php -nt composer.json ] \
        || composer install --no-interaction --quiet
        exec vendor/bin/phpunit "$@"' -- "$@"
