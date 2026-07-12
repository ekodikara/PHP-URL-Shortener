#!/usr/bin/env bash
#
# Snip — regenerate the bundled adult-content domain blocklist
# (data/adult-domains.txt) from the StevenBlack "porn-only" hosts snapshot.
#
# Run occasionally to refresh the list, then commit the result. The admin panel
# (blocked_domains table) covers anything this list misses in the meantime, and
# IPQualityScore (if IPQS_API_KEY is set) catches the long tail at runtime.
#
set -euo pipefail
cd "$(dirname "$0")/.."

SRC_URL="${SRC_URL:-https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/porn-only/hosts}"
OUT="data/adult-domains.txt"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

echo "Fetching $SRC_URL ..."
curl -fsSL --max-time 60 "$SRC_URL" -o "$TMP"

mkdir -p data
{
  echo "# Snip bundled adult-content domain blocklist."
  echo "# Source: StevenBlack/hosts — alternates/porn-only (public domain hosts data)."
  echo "# Format: one registrable domain per line; '#' comments and blank lines ignored."
  echo "# Regenerate with scripts/update-adult-blocklist.sh. Admin-added domains live"
  echo "# in the blocked_domains table and are applied on top of this list."
  echo "#"
  grep -E '^0\.0\.0\.0 ' "$TMP" | awk '{print $2}' \
    | grep -vE '^(0\.0\.0\.0|localhost)$' | sort -u
} > "$OUT"

echo "Wrote $(grep -cvE '^#' "$OUT") domains to $OUT"
