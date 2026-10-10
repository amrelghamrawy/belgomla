#!/usr/bin/env bash
# Health monitor (tasks/11-release.md), every 5 minutes from belgomla-monitor.timer, as the app user.
# Checks what PHP cannot see by itself and hands every failure to `php artisan platform:monitor`, which adds
# its in-app checks (queue backlog, failed jobs, disk, SMS phone heartbeat) and alerts the operator (D65).
# An external uptime service watching https://<tenant>/up from outside covers "the whole VM is down".
set -uo pipefail

set -a; . /etc/belgomla/ops.env; set +a
APP_DIR="${APP_DIR:-/srv/belgomla}"
BASE_DOMAIN="${BASE_DOMAIN:-gomalecommerce.app}"
SMOKE_TENANT="${SMOKE_TENANT:-alnoor}"

cd "${APP_DIR}/backend" || exit 1
reports=()

status_of() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$1:443:127.0.0.1" "https://$1$2" || true
}

# /up on the hooks host and on every active tenant host (tenant:list table: | id | subdomain | name | status | ...).
hosts=("hooks.${BASE_DOMAIN}")
while read -r subdomain; do
    [[ -n "${subdomain}" ]] && hosts+=("${subdomain}.${BASE_DOMAIN}")
done < <(php artisan tenant:list --no-ansi 2>/dev/null | awk -F'|' '$5 ~ /^ *active *$/ { gsub(/ /, "", $3); print $3 }')

for host in "${hosts[@]}"; do
    code="$(status_of "${host}" /up)"
    [[ "${code}" == "200" ]] || reports+=("--report=up:${host}=/up answered ${code}")
done

code="$(status_of "${SMOKE_TENANT}.${BASE_DOMAIN}" /)"
[[ "${code}" == "200" ]] || reports+=("--report=next=storefront / answered ${code}")

for unit in belgomla-queue@1 belgomla-queue@2 belgomla-next nginx redis-server postgresql; do
    systemctl is-active --quiet "${unit}" || reports+=("--report=service:${unit}=${unit} is not running")
done

if systemctl is-failed --quiet belgomla-backup.service; then
    reports+=("--report=backup=the last nightly backup failed (journalctl -u belgomla-backup)")
fi

exec php artisan platform:monitor --no-ansi "${reports[@]}"
