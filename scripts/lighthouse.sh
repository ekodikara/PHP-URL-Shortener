#!/usr/bin/env bash
#
# Snip — Lighthouse test pipeline (manual runner).
#
#   scripts/lighthouse.sh                # audit the running app (localhost:8088)
#   LIGHTHOUSE_BASE_URL=… scripts/lighthouse.sh   # audit another base URL
#
# Pages + score thresholds live in lighthouserc.json (single source of truth —
# the post-commit hook and the GitHub Actions workflow run this same config).
# Reports land in .lighthouseci/reports/ (gitignored).
#
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${LIGHTHOUSE_BASE_URL:-http://localhost:8088}"
BASE="${BASE%/}"

if ! curl -sf -o /dev/null "$BASE/"; then
  echo "✂ Lighthouse: app not reachable at $BASE" >&2
  echo "  Start it first:  docker compose up -d --build" >&2
  exit 1
fi

echo "✂ Lighthouse: auditing $BASE (/, /login, /register, /enterprise)…"
npx --yes @lhci/cli@0.14.x autorun \
  --config=lighthouserc.json \
  --collect.url="$BASE/" \
  --collect.url="$BASE/login" \
  --collect.url="$BASE/register" \
  --collect.url="$BASE/enterprise"

echo "✂ Lighthouse: all assertions passed. Reports: .lighthouseci/reports/"
