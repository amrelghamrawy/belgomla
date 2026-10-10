#!/usr/bin/env bash
# Restore a backup made by backup.sh (tasks/11-release.md). Run as the app user.
#
#   restore.sh latest                         # drill: restores into database belgomla_restore_drill and
#                                             # /tmp/belgomla-restore/<stamp>/public, prints row counts
#   restore.sh 20261101T010203Z --production  # real restore over the live database and storage
#
# --production refuses to run while the app services are up: stop them first (deploy/README.md).
set -euo pipefail

set -a; . /etc/belgomla/ops.env; set +a
APP_DIR="${APP_DIR:-/srv/belgomla}"
BACKUP_REMOTE="${BACKUP_REMOTE:?BACKUP_REMOTE is not set in /etc/belgomla/ops.env}"
LIVE_DB="${PGDATABASE:-belgomla}"

stamp="${1:-}"
mode="${2:-drill}"
[[ -n "${stamp}" ]] || { sed -n '2,9p' "$0"; exit 1; }

if [[ "${stamp}" == "latest" ]]; then
    stamp="$(rclone lsf --dirs-only "${BACKUP_REMOTE}" | sed 's#/$##' | sort | tail -1)"
    [[ -n "${stamp}" ]] || { echo "No backups in ${BACKUP_REMOTE}" >&2; exit 1; }
fi

if [[ "${mode}" == "--production" ]]; then
    for unit in belgomla-next belgomla-queue@1 belgomla-queue@2; do
        if systemctl is-active --quiet "${unit}"; then
            echo "${unit} is running. Stop the app first (deploy/README.md, Restore)." >&2
            exit 1
        fi
    done
    target_db="${LIVE_DB}"
    target_storage="${APP_DIR}/backend/storage/app"
else
    target_db="belgomla_restore_drill"
    target_storage="/tmp/belgomla-restore/${stamp}"
fi

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

echo "==> Fetching ${BACKUP_REMOTE}/${stamp}"
rclone copy "${BACKUP_REMOTE}/${stamp}" "${work}"
(cd "${work}" && sha256sum --check --quiet SHA256SUMS)

echo "==> Database -> ${target_db}"
dropdb --if-exists "${target_db}"
createdb "${target_db}"
pg_restore --no-owner --no-privileges --exit-on-error --dbname="${target_db}" "${work}/db.dump"

echo "==> Storage -> ${target_storage}/public"
mkdir -p "${target_storage}"
rm -rf "${target_storage}/public"
tar -C "${target_storage}" -xzf "${work}/storage-public.tar.gz"

echo "==> Restored ${stamp}"
psql -d "${target_db}" -c "SELECT
    (SELECT count(*) FROM tenants) AS tenants,
    (SELECT count(*) FROM products) AS products,
    (SELECT count(*) FROM orders) AS orders,
    (SELECT max(created_at) FROM orders) AS last_order_at"
echo "Files: $(find "${target_storage}/public" -type f | wc -l)"

if [[ "${mode}" != "--production" ]]; then
    echo "Drill done. Clean up with: dropdb ${target_db} && rm -rf ${target_storage}"
fi
