#!/bin/sh
#
# Container entrypoint for the InternTrack API.
#
# Runs before Apache starts, every boot. Everything here is safe to repeat: a
# free-tier service spins down when idle and re-runs this on each wake.
#
set -e

echo "==> InternTrack API starting (APP_ENV=${APP_ENV:-unset})"

# ---------------------------------------------------------------------------
# Listen on the port the host assigned.
#
# Apache's stock config hardcodes 80. Render (and Fly, Railway, Cloud Run, ...)
# inject $PORT and health-check that port only, so a container still on 80 looks
# permanently unhealthy and gets killed with no useful error.
# ---------------------------------------------------------------------------
PORT="${PORT:-10000}"
echo "==> Binding Apache to port ${PORT}"
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

# ---------------------------------------------------------------------------
# Fail loudly and immediately on the two misconfigurations that are worst to
# discover later.
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "!!! APP_KEY is not set. Generate one with 'php artisan key:generate --show'" >&2
    echo "!!! and add it to this service's environment. Refusing to start: without" >&2
    echo "!!! it every session and every encrypted value is unreadable." >&2
    exit 1
fi

if [ "${APP_ENV}" = "production" ] && [ "${APP_DEBUG}" = "true" ]; then
    echo "!!! APP_DEBUG=true with APP_ENV=production leaks stack traces publicly." >&2
    echo "!!! AppServiceProvider would throw on the first request anyway." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Migrations.
#
# --force skips the interactive "are you sure" prompt, which has no answer in a
# container. Migrations are idempotent, so re-running on each wake is a no-op
# once the schema is current.
# ---------------------------------------------------------------------------
echo "==> Running migrations"
php artisan migrate --force --no-interaction

# ---------------------------------------------------------------------------
# Optional one-shot seeding, controlled entirely by environment variables so the
# decision is never baked into the image.
#
#   SEED_ON_BOOT=production  -> ProductionSeeder: departments, programs, and ONE
#                               admin from ADMIN_USERNAME / ADMIN_PASSWORD.
#   SEED_ON_BOOT=demo        -> DatabaseSeeder: the full demo dataset. ONLY for a
#                               throwaway demo instance. It creates ~30 fictional
#                               accounts whose shared password is documented in
#                               this repo's public CLAUDE.md, so DEMO_PASSWORD is
#                               required and immediately rotates all of them.
#
# Unset (the default) seeds nothing. Turn it off again after the first boot so a
# later wake cannot re-seed over live data.
# ---------------------------------------------------------------------------
case "${SEED_ON_BOOT}" in
    production)
        echo "==> Seeding: ProductionSeeder"
        php artisan db:seed --class=ProductionSeeder --force --no-interaction
        ;;
    demo)
        if [ -z "${DEMO_PASSWORD}" ]; then
            echo "!!! SEED_ON_BOOT=demo requires DEMO_PASSWORD (12+ chars)." >&2
            echo "!!! The seeded default is published in this repo — refusing to" >&2
            echo "!!! expose ~30 accounts, including an admin, on a public URL." >&2
            exit 1
        fi
        echo "==> Seeding: DatabaseSeeder (demo dataset)"
        php artisan db:seed --force --no-interaction
        echo "==> Rotating demo passwords"
        php artisan demo:set-password --password="${DEMO_PASSWORD}" --no-interaction
        ;;
    "")
        echo "==> SEED_ON_BOOT unset — skipping seeding"
        ;;
    *)
        echo "!!! SEED_ON_BOOT='${SEED_ON_BOOT}' is not valid (use 'production', 'demo', or unset)." >&2
        exit 1
        ;;
esac

# ---------------------------------------------------------------------------
# Caches. Built here rather than at image-build time because they bake in
# environment values that only exist now.
#
# Non-fatal on purpose: a cache miss costs some cold-start speed, but a failure
# here should never be the reason the app will not boot.
# ---------------------------------------------------------------------------
echo "==> Caching config, routes and views"
php artisan config:cache || echo "    (config:cache failed — continuing uncached)"
php artisan route:cache || echo "    (route:cache failed — continuing uncached)"
php artisan view:cache || echo "    (view:cache failed — continuing uncached)"

# The avatar disk is only symlinked when it is the local 'public' disk. On r2/s3
# there is nothing local to link, and storage:link would fail pointlessly.
if [ "${AVATAR_DISK:-public}" = "public" ]; then
    echo "==> Linking storage (AVATAR_DISK=public)"
    php artisan storage:link || echo "    (storage:link failed — continuing)"
    echo "    NOTE: this host's filesystem is ephemeral. Uploaded avatars are"
    echo "    discarded on redeploy. Set AVATAR_DISK=r2 to persist them."
fi

echo "==> Ready. Handing off to: $*"
exec "$@"
