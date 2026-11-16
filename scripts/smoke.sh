#!/usr/bin/env bash
# smoke.sh: the live check of the Definition of Done (rules/00 item 3): real HTTP
# against `php artisan serve`, through the tenant host, Sanctum CSRF and the session
# cookie, so middleware order and session behaviour are exercised the way a browser does.
#
#   scripts/smoke.sh login [email] [password]          dashboard login (default: seeded owner)
#   scripts/smoke.sh login-customer <phone> <password> storefront login
#   scripts/smoke.sh call METHOD /api/path [json]      prints "HTTP <status>" then the body
#   scripts/smoke.sh logout                            forgets the cookie jar
#
# Env: SMOKE_TENANT (default alnoor), SMOKE_PORT (default 8000).
# Needs `php artisan serve` on 127.0.0.1:$SMOKE_PORT and a seeded DB
# (`php artisan migrate:fresh --seed` gives owner@alnoor.test / password).

set -euo pipefail

TENANT="${SMOKE_TENANT:-alnoor}"
PORT="${SMOKE_PORT:-8000}"
HOST="$TENANT.gomal.test"
BASE="http://$HOST:$PORT"
JAR="${TMPDIR:-${TEMP:-/tmp}}/belgomla-smoke-$TENANT.cookies"

curl_tenant() {
    curl -sS --resolve "$HOST:$PORT:127.0.0.1" -b "$JAR" -c "$JAR" \
        -H "Origin: $BASE" -H "Referer: $BASE/" \
        -H "Accept: application/json" -H "X-Requested-With: XMLHttpRequest" "$@"
}

xsrf() {
    local raw
    raw="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR" 2>/dev/null | tail -1)"
    [ -n "$raw" ] && node -e 'process.stdout.write(decodeURIComponent(process.argv[1]))' "$raw"
}

ensure_csrf() {
    [ -n "$(xsrf)" ] || curl_tenant -o /dev/null "$BASE/sanctum/csrf-cookie"
}

call() {
    local method="$1" path="$2" body="${3:-}"
    ensure_csrf
    local args=(-X "$method" -H "X-XSRF-TOKEN: $(xsrf)" -w '\n%{http_code}')
    [ -n "$body" ] && args+=(-H "Content-Type: application/json" --data "$body")
    local out status
    out="$(curl_tenant "${args[@]}" "$BASE$path")"
    status="${out##*$'\n'}"
    echo "HTTP $status"
    echo "${out%$'\n'*}"
}

if ! curl -s -o /dev/null --max-time 3 "http://127.0.0.1:$PORT/up"; then
    echo "nothing answers on 127.0.0.1:$PORT: start it with: cd backend && php artisan serve" >&2
    exit 3
fi

case "${1:-}" in
    login)
        rm -f "$JAR"
        call POST /api/dashboard/auth/login \
            "{\"email\":\"${2:-owner@alnoor.test}\",\"password\":\"${3:-password}\"}"
        ;;
    login-customer)
        [ $# -ge 3 ] || { echo "usage: $0 login-customer <phone> <password>" >&2; exit 64; }
        rm -f "$JAR"
        call POST /api/store/auth/login "{\"phone\":\"$2\",\"password\":\"$3\"}"
        ;;
    call)
        [ $# -ge 3 ] || { echo "usage: $0 call METHOD /api/path [json]" >&2; exit 64; }
        call "$2" "$3" "${4:-}"
        ;;
    logout)
        rm -f "$JAR"
        echo "cookie jar removed"
        ;;
    *)
        sed -n '2,13p' "$0"
        exit 64
        ;;
esac
