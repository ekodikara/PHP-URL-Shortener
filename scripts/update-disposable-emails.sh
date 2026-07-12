#!/usr/bin/env bash
#
# Snip — refresh the disposable/temporary email-domain blocklist used by the
# registration guard (is_disposable_email() in inc/security.php). Writes
# data/disposable-email-domains.txt (committed; small). Run occasionally.
#
# Source: disposable-email-domains/disposable-email-domains (public domain).
#
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${SRC_URL:-https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf}"
OUT="data/disposable-email-domains.txt"
TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT

echo "Fetching $SRC ..."
curl -fsSL --max-time 60 "$SRC" -o "$TMP"
mkdir -p data
{
  echo "# Snip — disposable / temporary email domain blocklist (registration guard)."
  echo "# Source: disposable-email-domains/disposable-email-domains (public domain / CC0)."
  echo "# One domain per line; '#' comments ignored. Refresh: scripts/update-disposable-emails.sh"
  echo "#"
  grep -vE '^\s*#|^\s*$' "$TMP" | tr 'A-Z' 'a-z' | sort -u
} > "$OUT"
echo "Wrote $(grep -cvE '^#' "$OUT") domains to $OUT"
