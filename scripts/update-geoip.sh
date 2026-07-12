#!/usr/bin/env bash
#
# Snip — fetch the IP→country GeoIP database used by click analytics
# (inc/geoip.php). Writes data/dbip-country-lite.mmdb (gitignored). Run before
# `docker compose build` so the DB is baked into the image, and re-run monthly
# to refresh. If this DB is absent, geo analytics degrade gracefully (Unknown).
#
# Default source: DB-IP IP-to-Country Lite — free, no license key, MMDB format,
# CC-BY-4.0 (attribution: "IP Geolocation by DB-IP" — https://db-ip.com).
# To use MaxMind GeoLite2-Country instead, set GEOIP_URL to your licensed
# download URL (that DB may NOT be redistributed — keep it out of the repo).
#
set -euo pipefail
cd "$(dirname "$0")/.."

OUT="data/dbip-country-lite.mmdb"
mkdir -p data

if [ -n "${GEOIP_URL:-}" ]; then
  URL="$GEOIP_URL"
else
  # DB-IP publishes a dated file each month; try the current and previous month.
  MONTH="$(date -u +%Y-%m)"
  PREV="$(date -u -d '1 month ago' +%Y-%m 2>/dev/null || date -u -v-1m +%Y-%m 2>/dev/null || echo "$MONTH")"
  URL=""
  for M in "$MONTH" "$PREV"; do
    CANDIDATE="https://download.db-ip.com/free/dbip-country-lite-$M.mmdb.gz"
    if curl -sfI --max-time 20 "$CANDIDATE" >/dev/null 2>&1; then URL="$CANDIDATE"; break; fi
  done
  [ -n "$URL" ] || { echo "Could not find a current DB-IP file; set GEOIP_URL." >&2; exit 1; }
fi

echo "Fetching $URL ..."
TMP="$(mktemp)"; trap 'rm -f "$TMP" "$TMP.gz"' EXIT
if [[ "$URL" == *.gz ]]; then
  curl -fsSL --max-time 120 "$URL" -o "$TMP.gz"
  gunzip -c "$TMP.gz" > "$TMP"
else
  curl -fsSL --max-time 120 "$URL" -o "$TMP"
fi
mv "$TMP" "$OUT"
echo "Wrote $OUT ($(wc -c < "$OUT") bytes)."
