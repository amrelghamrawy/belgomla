#!/usr/bin/env bash
# Provision one Ubuntu 24.04 LTS VM for BelGomla (tasks/11-release.md). No Docker (Tech §1).
# Idempotent: safe to run again after changing a config file in deploy/.
#
#   sudo REPO_URL=git@github.com:<org>/belgomla.git CERTBOT_EMAIL=ops@example.com DB_PASSWORD=... \
#        bash deploy/provision.sh
#
# Reads /etc/belgomla/ops.env when present (see deploy/env/ops.env.example); variables given on the
# command line win. Secrets never live in the repo: they go to /etc/belgomla/*.env (mode 640, root:belgomla).
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
. /etc/os-release
[[ "${ID}" == "ubuntu" && "${VERSION_ID}" == "24.04" ]] || echo "Warning: written for Ubuntu 24.04, found ${PRETTY_NAME}." >&2

if [[ -f /etc/belgomla/ops.env ]]; then
    set -a; . /etc/belgomla/ops.env; set +a
fi

APP_USER="${APP_USER:-belgomla}"
APP_DIR="${APP_DIR:-/srv/belgomla}"
BASE_DOMAIN="${BASE_DOMAIN:-gomalecommerce.app}"
PHP_VERSION="${PHP_VERSION:-8.5}"          # D66: same PHP as the test suite
NODE_MAJOR="${NODE_MAJOR:-24}"             # frontend/package.json engines
DB_NAME="${PGDATABASE:-belgomla}"
DB_USER="${PGUSER:-belgomla}"
DNS_PLUGIN="${DNS_PLUGIN:-cloudflare}"     # certbot-dns-<plugin> snap; D44 decides the DNS provider
DNS_CREDENTIALS="${DNS_CREDENTIALS:-/etc/belgomla/certbot-dns.ini}"
REPO_URL="${REPO_URL:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
SWAP_SIZE="${SWAP_SIZE:-2G}"               # next build on a 4 GB VM

DEPLOY_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export DEBIAN_FRONTEND=noninteractive

step() { printf '\n==> %s\n' "$*"; }

step "Base packages, UTC clock, swap"
apt-get update -q
apt-get install -yq ca-certificates curl gnupg unzip git acl ufw unattended-upgrades software-properties-common rclone snapd
timedatectl set-timezone UTC
if ! swapon --show | grep -q /swapfile; then
    fallocate -l "${SWAP_SIZE}" /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

step "Unattended security updates"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

step "Firewall: 22, 80, 443"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

step "PHP ${PHP_VERSION} (ondrej PPA) + Composer"
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-gd" \
    "php${PHP_VERSION}-intl" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-zip" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-opcache"
# Redis is reached through predis (composer.json), so no php-redis extension.
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-belgomla.ini" <<'EOF'
; deploy.sh reloads php-fpm, so compiled files never need a timestamp check.
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
realpath_cache_size=4096K
realpath_cache_ttl=600
EOF
if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(sha384sum /tmp/composer-setup.php | cut -d' ' -f1)"
    [[ "${expected}" == "${actual}" ]] || { echo "Composer installer checksum mismatch" >&2; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

step "PostgreSQL 16"
apt-get install -yq postgresql-16
systemctl enable --now postgresql
if [[ -n "${DB_PASSWORD}" ]]; then
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<EOF
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} LOGIN CREATEDB PASSWORD '${DB_PASSWORD}';
    END IF;
END \$\$;
EOF
    sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | grep -q 1 \
        || sudo -u postgres createdb -O "${DB_USER}" "${DB_NAME}"
    # The catalog migration creates pg_trgm; creating it here as superuser keeps the app role unprivileged.
    sudo -u postgres psql -d "${DB_NAME}" -qc 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
else
    echo "DB_PASSWORD not set: skipping role/database creation." >&2
fi

step "Redis (localhost only)"
apt-get install -yq redis-server
sed -i 's/^supervised .*/supervised systemd/' /etc/redis/redis.conf
grep -q '^bind 127.0.0.1 -::1' /etc/redis/redis.conf || sed -i 's/^#\? *bind .*/bind 127.0.0.1 -::1/' /etc/redis/redis.conf
systemctl enable --now redis-server
systemctl restart redis-server

step "Node.js ${NODE_MAJOR}"
if ! node --version 2>/dev/null | grep -q "^v${NODE_MAJOR}\."; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    apt-get install -yq nodejs
fi

step "Nginx (+ brotli when packaged)"
apt-get install -yq nginx
BROTLI=0
if apt-get install -yq libnginx-mod-http-brotli-filter libnginx-mod-http-brotli-static; then BROTLI=1; fi

step "App user ${APP_USER}, ${APP_DIR}, /etc/belgomla"
id "${APP_USER}" >/dev/null 2>&1 || useradd --system --create-home --home-dir "/home/${APP_USER}" --shell /bin/bash "${APP_USER}"
install -d -o "${APP_USER}" -g "${APP_USER}" -m 755 "${APP_DIR}"
install -d -o root -g "${APP_USER}" -m 750 /etc/belgomla
for example in backend.env frontend.env ops.env; do
    [[ -f "/etc/belgomla/${example}" ]] || install -o root -g "${APP_USER}" -m 640 "${DEPLOY_SRC}/env/${example}.example" "/etc/belgomla/${example}"
done
if [[ ! -d "${APP_DIR}/.git" ]]; then
    [[ -n "${REPO_URL}" ]] || { echo "REPO_URL is required for the first run." >&2; exit 1; }
    sudo -u "${APP_USER}" git clone "${REPO_URL}" "${APP_DIR}"
fi
ln -sfn /etc/belgomla/backend.env "${APP_DIR}/backend/.env"
if [[ -n "${DB_PASSWORD}" ]]; then
    # backup.sh / restore.sh connect with libpq; the password stays outside the scripts and the repo.
    printf '127.0.0.1:5432:*:%s:%s\n' "${DB_USER}" "${DB_PASSWORD}" > "/home/${APP_USER}/.pgpass"
    chown "${APP_USER}:${APP_USER}" "/home/${APP_USER}/.pgpass"
    chmod 600 "/home/${APP_USER}/.pgpass"
fi
# deploy.sh runs as the app user and restarts only BelGomla's own services.
cat > /etc/sudoers.d/belgomla <<EOF
${APP_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl restart belgomla-queue@1 belgomla-queue@2 belgomla-next, /usr/bin/systemctl reload php${PHP_VERSION}-fpm, /usr/bin/systemctl reload nginx
EOF
chmod 440 /etc/sudoers.d/belgomla
visudo -cf /etc/sudoers.d/belgomla

step "PHP-FPM pool"
install -m 644 "${DEPLOY_SRC}/php-fpm/belgomla.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/belgomla.conf"
sed -i "s#/php8\.5-fpm#/php${PHP_VERSION}-fpm#" "/etc/php/${PHP_VERSION}/fpm/pool.d/belgomla.conf"
systemctl enable "php${PHP_VERSION}-fpm"
systemctl restart "php${PHP_VERSION}-fpm"

step "Wildcard certificate *.${BASE_DOMAIN} (certbot, DNS challenge via ${DNS_PLUGIN})"
snap install --classic certbot 2>/dev/null || snap refresh certbot
ln -sfn /snap/bin/certbot /usr/bin/certbot
snap set certbot trust-plugin-with-root=ok
snap install "certbot-dns-${DNS_PLUGIN}" 2>/dev/null || true
snap connect "certbot:plugin" "certbot-dns-${DNS_PLUGIN}" 2>/dev/null || true
if [[ ! -f "/etc/letsencrypt/live/${BASE_DOMAIN}/fullchain.pem" ]]; then
    [[ -n "${CERTBOT_EMAIL}" ]] || { echo "CERTBOT_EMAIL is required to issue the certificate." >&2; exit 1; }
    [[ -f "${DNS_CREDENTIALS}" ]] || { echo "Put the DNS API credentials in ${DNS_CREDENTIALS} (mode 600) first." >&2; exit 1; }
    chmod 600 "${DNS_CREDENTIALS}"
    certbot certonly --non-interactive --agree-tos -m "${CERTBOT_EMAIL}" \
        "--dns-${DNS_PLUGIN}" "--dns-${DNS_PLUGIN}-credentials" "${DNS_CREDENTIALS}" \
        --cert-name "${BASE_DOMAIN}" -d "${BASE_DOMAIN}" -d "*.${BASE_DOMAIN}"
fi
install -d /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'EOF'
#!/bin/sh
systemctl reload nginx
EOF
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

step "Nginx site"
for snippet in "${DEPLOY_SRC}"/nginx/snippets/*.conf; do
    install -m 644 "${snippet}" "/etc/nginx/snippets/$(basename "${snippet}")"
done
if [[ "${BROTLI}" == 1 ]]; then
    install -m 644 "${DEPLOY_SRC}/nginx/snippets/belgomla-brotli.conf.available" /etc/nginx/snippets/belgomla-brotli.conf
else
    rm -f /etc/nginx/snippets/belgomla-brotli.conf
fi
sed "s/gomalecommerce\.app/${BASE_DOMAIN//./\\.}/g; s#/srv/belgomla#${APP_DIR}#g" "${DEPLOY_SRC}/nginx/belgomla.conf" > /etc/nginx/sites-available/belgomla.conf
sed -i "s/gomalecommerce\.app/${BASE_DOMAIN//./\\.}/g" /etc/nginx/snippets/belgomla-tls.conf
sed -i "s#/srv/belgomla#${APP_DIR}#g" /etc/nginx/snippets/belgomla-php.conf
ln -sfn /etc/nginx/sites-available/belgomla.conf /etc/nginx/sites-enabled/belgomla.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx
systemctl reload nginx

step "systemd units"
for unit in "${DEPLOY_SRC}"/systemd/*; do
    sed "s#/srv/belgomla#${APP_DIR}#g; s/^User=belgomla/User=${APP_USER}/; s/^Group=belgomla/Group=${APP_USER}/" "${unit}" \
        > "/etc/systemd/system/$(basename "${unit}")"
done
systemctl daemon-reload
systemctl enable belgomla-queue@1 belgomla-queue@2 belgomla-next
systemctl enable --now belgomla-scheduler.timer belgomla-monitor.timer belgomla-backup.timer

cat <<EOF

Provisioning done. Next (deploy/README.md, "First install"):
  1. Fill /etc/belgomla/backend.env (APP_KEY, DB_PASSWORD, Hypersender, MONITOR_*), frontend.env, ops.env.
  2. Configure the backup remote: sudo -u ${APP_USER} rclone config   (remote name = BACKUP_REMOTE prefix)
  3. sudo -u ${APP_USER} ${APP_DIR}/deploy/deploy.sh
EOF
