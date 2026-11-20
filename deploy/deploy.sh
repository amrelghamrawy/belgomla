#!/usr/bin/env bash
# Deploy the current branch on the production VM (tasks/11-release.md). Run as the app user:
#
#   sudo -u belgomla /srv/belgomla/deploy/deploy.sh            # deploys DEPLOY_REF (default main)
#
# Zero downtime is not a V1 requirement: services restart in place. Ends with post-deploy assertions
# (tenant:list, /up on hooks and the smoke tenant, the storefront page, a store API call, wa-gateway /health); any failure
# exits non-zero and says what to look at.
set -euo pipefail

set -a; . /etc/belgomla/ops.env; set +a
APP_DIR="${APP_DIR:-/srv/belgomla}"
BASE_DOMAIN="${BASE_DOMAIN:-belgomla.app}"
SMOKE_TENANT="${SMOKE_TENANT:-alnoor}"
DEPLOY_REF="${DEPLOY_REF:-main}"
PHP_VERSION="${PHP_VERSION:-8.5}"

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "DEPLOY CHECK FAILED: $*" >&2; exit 1; }

[[ "$(id -un)" != "root" ]] || fail "run as the app user, not root (sudo -u belgomla $0)"

step "git: ${DEPLOY_REF}"
cd "${APP_DIR}"
git fetch --prune origin
git checkout -q "${DEPLOY_REF}"
git pull --ff-only -q origin "${DEPLOY_REF}"
echo "Deploying $(git log --oneline -1)"

step "backend: composer, migrations, caches"
cd "${APP_DIR}/backend"
[[ -L .env ]] || fail "backend/.env must be a symlink to /etc/belgomla/backend.env (provision.sh)"
grep -q '^APP_KEY=base64:' .env || fail "APP_KEY is empty: php artisan key:generate --show, paste into /etc/belgomla/backend.env"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
php artisan migrate --force
[[ -L public/storage ]] || php artisan storage:link
# route:cache bakes in the hooks domain (TENANT_BASE_DOMAIN), so backend.env must be final before this.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

step "frontend: npm ci, next build"
cd "${APP_DIR}/frontend"
npm ci --no-audit --no-fund
npm run build

step "wa-gateway: npm ci, build, prune dev dependencies"
# tsc is a dev dependency, so the build needs a full install; prune leaves only what dist/ runs on.
[[ -f /etc/belgomla/wa-gateway.env ]] || fail "/etc/belgomla/wa-gateway.env is missing (provision.sh, then fill it)"
cd "${APP_DIR}/wa-gateway"
npm ci --no-audit --no-fund
npm run build
npm prune --omit=dev --no-audit --no-fund

step "restart services"
sudo /usr/bin/systemctl reload "php${PHP_VERSION}-fpm"
sudo /usr/bin/systemctl restart belgomla-queue@1 belgomla-queue@2 belgomla-next
# Restarting drops the live store-number sessions for a few seconds; gateway.started makes Laravel reconcile.
sudo /usr/bin/systemctl restart belgomla-wa-gateway

step "post-deploy assertions"
cd "${APP_DIR}/backend"
php artisan tenant:list || fail "tenant:list (database or config)"

# Through this VM's nginx regardless of DNS, so a deploy is verified before DNS points here.
check() {
    local host="$1" path="$2" expected="${3:-200}" code
    for _ in $(seq 1 30); do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "${host}:443:127.0.0.1" "https://${host}${path}" || true)"
        [[ "${code}" == "${expected}" ]] && { echo "ok   ${code} https://${host}${path}"; return 0; }
        sleep 2
    done
    fail "https://${host}${path} answered ${code}, expected ${expected}"
}

check "hooks.${BASE_DOMAIN}" /up
check "${SMOKE_TENANT}.${BASE_DOMAIN}" /up
check "${SMOKE_TENANT}.${BASE_DOMAIN}" /api/store/tenant      # nginx -> php-fpm, tenant from Host, database
check "${SMOKE_TENANT}.${BASE_DOMAIN}" /                      # nginx -> next start
check "hooks.${BASE_DOMAIN}" /api/webhooks/hypersender/sms 405 # webhooks routed to Laravel only (GET not allowed)

# wa-gateway listens on loopback only (D70); /health needs no token.
GATEWAY_PORT="$(sed -n 's/^WA_GATEWAY_PORT=//p' /etc/belgomla/wa-gateway.env)"
GATEWAY_PORT="${GATEWAY_PORT:-3100}"
for _ in $(seq 1 15); do
    curl -sf --max-time 5 "http://127.0.0.1:${GATEWAY_PORT}/health" >/dev/null && break
    sleep 2
done
curl -sf --max-time 5 "http://127.0.0.1:${GATEWAY_PORT}/health" || fail "wa-gateway /health on 127.0.0.1:${GATEWAY_PORT} (journalctl -u belgomla-wa-gateway)"
echo
echo "ok   wa-gateway 127.0.0.1:${GATEWAY_PORT}/health"

echo
echo "Deployed $(git -C "${APP_DIR}" rev-parse --short HEAD) at $(date -u +%FT%TZ)."
