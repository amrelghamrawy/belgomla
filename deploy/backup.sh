#!/usr/bin/env bash
# Nightly backup (tasks/11-release.md, D64): PostgreSQL dump + storage/app/public (product images, logos)
# to the S3-compatible bucket configured as an rclone remote. Keeps BACKUP_RETENTION_DAYS (30) days.
# Run by belgomla-backup.timer as the app user. Restore: deploy/restore.sh.
#
# Layout in the bucket:  ${BACKUP_REMOTE}/<UTC timestamp>/{db.dump, storage-public.tar.gz, SHA256SUMS}
set -euo pipefail

set -a; . /etc/belgomla/ops.env; set +a
APP_DIR="${APP_DIR:-/srv/belgomla}"
BACKUP_REMOTE="${BACKUP_REMOTE:?BACKUP_REMOTE is not set in /etc/belgomla/ops.env (e.g. belgomla-s3:belgomla-backups/prod)}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
# pg_dump reads PGHOST/PGPORT/PGDATABASE/PGUSER from ops.env and the password from ~/.pgpass.

log() { logger -t belgomla-backup -- "$*"; echo "$*"; }
trap 'log "FAILED at line ${LINENO}"' ERR

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

pg_dump --format=custom --no-owner --no-privileges --file="${work}/db.dump"
tar -C "${APP_DIR}/backend/storage/app" -czf "${work}/storage-public.tar.gz" public
(cd "${work}" && sha256sum db.dump storage-public.tar.gz > SHA256SUMS)

rclone copy --no-traverse "${work}" "${BACKUP_REMOTE}/${stamp}"
rclone check --one-way "${work}" "${BACKUP_REMOTE}/${stamp}"

rclone delete --min-age "${BACKUP_RETENTION_DAYS}d" "${BACKUP_REMOTE}"
rclone rmdirs --leave-root "${BACKUP_REMOTE}"

log "ok ${stamp}: db $(du -h "${work}/db.dump" | cut -f1), storage $(du -h "${work}/storage-public.tar.gz" | cut -f1)"
