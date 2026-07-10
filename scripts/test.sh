#!/usr/bin/env bash
#
# Snip — run the PHPUnit suite in a throwaway PHP/Composer container (no local
# PHP toolchain needed). Installs dev deps from the committed composer.lock and
# runs the tests. vendor/ is gitignored.
#
set -euo pipefail
cd "$(dirname "$0")/.."

docker run --rm -v "$PWD":/app -w /app composer:2 sh -c \
  'composer install --no-interaction --prefer-dist -q && vendor/bin/phpunit'
