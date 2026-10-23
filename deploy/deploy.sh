#!/bin/sh
# Usage: ./deploy.sh <tag>
# Run on the server, in the folder that holds docker-compose.prod.yml and .env.prod.
set -u

TAG="${1:?usage: deploy.sh <tag>}"
IMAGE_PREFIX="${IMAGE_PREFIX:-ghcr.io/ajaybhimani74}"
PORT="${PORT:-80}"
PROJECT="${PROJECT:-shipkit}"
HEALTH_URL="http://127.0.0.1:${PORT}/status"

export TAG IMAGE_PREFIX PORT
compose() { docker compose -p "$PROJECT" -f docker-compose.prod.yml "$@"; }

previous=""
[ -f .current_tag ] && previous="$(cat .current_tag)"

echo "Deploying $TAG (previous: ${previous:-none})"

if [ -z "${SKIP_PULL:-}" ]; then
    compose pull || { echo "Could not pull images for $TAG"; exit 1; }
fi

# the database must be up before migrating
compose up -d --wait mysql redis

if ! compose run --rm --no-deps app php artisan migrate --force; then
    echo "Migration failed, nothing was switched. Still running: ${previous:-nothing}"
    exit 1
fi

compose up -d --remove-orphans

wait_healthy() {
    for i in $(seq 1 30); do
        if curl -fs "$HEALTH_URL" >/dev/null; then return 0; fi
        sleep 2
    done
    return 1
}

if wait_healthy; then
    echo "$TAG" > .current_tag
    echo "Deploy of $TAG finished and is healthy."
    exit 0
fi

echo "Health check failed for $TAG."
if [ -n "$previous" ]; then
    echo "Rolling back to $previous"
    TAG="$previous" compose up -d --remove-orphans
    if wait_healthy; then echo "Rolled back to $previous and it is healthy."; else echo "Rollback is not healthy either, check the servers."; fi
else
    echo "No previous release to roll back to."
fi
exit 1
