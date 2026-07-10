#!/usr/bin/env bash
#
# Snip — restore the MySQL database from an S3 backup made by backup.sh.
# The companion that makes backups actually trustworthy: test it periodically.
#
# Usage:
#   S3_BUCKET=my-snip-backups ./restore.sh                  # restore the LATEST
#   S3_BUCKET=my-snip-backups ./restore.sh snip-20260710-030000.sql.gz
#
# Encrypted (.gpg) backups are decrypted with your local gpg key automatically.
# This OVERWRITES the current database — it prompts before doing so.

set -euo pipefail

: "${S3_BUCKET:?set S3_BUCKET}"
COMPOSE="${COMPOSE:-docker compose -f docker-compose.prod.yml}"
KEY="${1:-}"

if [ -z "$KEY" ]; then
  echo "Finding latest backup in s3://${S3_BUCKET}/backups/ …"
  KEY="$(aws s3 ls "s3://${S3_BUCKET}/backups/" | awk '{print $4}' | grep '^snip-' | sort | tail -1)"
  [ -n "$KEY" ] || { echo "no backups found" >&2; exit 1; }
fi
echo "Restoring from: $KEY"

TMP="/tmp/${KEY}"
aws s3 cp "s3://${S3_BUCKET}/backups/${KEY}" "$TMP"

printf 'This will OVERWRITE the current "shortener" database. Type YES to proceed: '
read -r CONFIRM
[ "$CONFIRM" = "YES" ] || { echo "aborted"; rm -f "$TMP"; exit 1; }

decrypt() {
  case "$KEY" in
    *.gpg) gpg --batch --quiet --decrypt "$TMP" ;;
    *)     cat "$TMP" ;;
  esac
}

echo "[$(date -u)] restoring…"
decrypt | gunzip | $COMPOSE exec -T db sh -c 'exec mysql -ushortener -p"$MYSQL_PASSWORD" shortener'
rm -f "$TMP"
echo "[$(date -u)] restore complete. Run scripts/migrate.php if the dump predates recent migrations."
