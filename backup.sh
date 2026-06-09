#!/usr/bin/env bash
#
# Snip — back up the MySQL database to S3 (cheap, off-instance durability).
#
# Usage:   S3_BUCKET=my-bucket ./backup.sh
# Cron:    0 3 * * *  cd /opt/snip && S3_BUCKET=my-bucket ./backup.sh >> /var/log/snip-backup.log 2>&1
#
# Requires: docker compose stack running, awscli configured (instance IAM role
# with s3:PutObject on the bucket is the cheapest/safest auth).

set -euo pipefail

: "${S3_BUCKET:?set S3_BUCKET (e.g. my-snip-backups)}"
COMPOSE="${COMPOSE:-docker compose -f docker-compose.prod.yml}"
RETAIN_DAYS="${RETAIN_DAYS:-30}"

STAMP="$(date -u +%Y%m%d-%H%M%S)"
FILE="snip-${STAMP}.sql.gz"
TMP="/tmp/${FILE}"

echo "[$(date -u)] dumping database…"
# --single-transaction = consistent dump of InnoDB without locking writes.
$COMPOSE exec -T db sh -c \
  'exec mysqldump -ushortener -p"$MYSQL_PASSWORD" --single-transaction --quick --no-tablespaces shortener' \
  | gzip > "$TMP"

echo "[$(date -u)] uploading to s3://${S3_BUCKET}/backups/${FILE}"
aws s3 cp "$TMP" "s3://${S3_BUCKET}/backups/${FILE}"
rm -f "$TMP"

# Best-effort retention: drop backups older than RETAIN_DAYS.
CUTOFF="$(date -u -d "-${RETAIN_DAYS} days" +%Y%m%d 2>/dev/null || date -u -v-"${RETAIN_DAYS}"d +%Y%m%d)"
aws s3 ls "s3://${S3_BUCKET}/backups/" | awk '{print $4}' | while read -r key; do
  [ -z "$key" ] && continue
  d="$(echo "$key" | sed -n 's/^snip-\([0-9]\{8\}\)-.*/\1/p')"
  if [ -n "$d" ] && [ "$d" -lt "$CUTOFF" ]; then
    aws s3 rm "s3://${S3_BUCKET}/backups/${key}"
  fi
done

echo "[$(date -u)] done."
