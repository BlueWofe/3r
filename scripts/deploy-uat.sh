#!/usr/bin/env bash
set -euo pipefail
revision=${1:?Pass full commit SHA}
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid commit'; exit 1; }
release="/opt/3r/releases/$revision"
[[ -f "$release/compose.yml" ]] || exit 1
cd "$release"
envfile=/opt/3r/config/uat.env
grep -qx 'COMPOSE_PROJECT_NAME=r3-uat' "$envfile"
dc=(docker compose --env-file "$envfile" -p r3-uat -f compose.yml -f compose.uat.yml)
mkdir -p /srv/3r/uat/{storage,postgres,redis,backups}
dateid=$(date -u +%Y%m%dT%H%M%SZ)
docker ps --format '{{.Names}} {{.Status}}' > "/srv/3r/uat/backups/containers-before-$dateid.txt"
previous=$(readlink -f /opt/3r/current || true)
if [[ -n "$previous" && -f "$previous/compose.yml" ]]; then
    docker compose --env-file "$envfile" -p r3-uat -f "$previous/compose.yml" -f "$previous/compose.uat.yml" exec -T postgres pg_dump -U r3 -d r3 -Fc > "/srv/3r/uat/backups/$dateid.dump"
    tar -czf "/srv/3r/uat/backups/storage-$dateid.tar.gz" -C /srv/3r/uat storage
fi
export BACKEND_IMAGE="r3-backend:$revision" FRONTEND_IMAGE="r3-frontend:$revision"
"${dc[@]}" build --pull backend frontend
"${dc[@]}" up -d postgres redis backend
"${dc[@]}" exec -T backend php artisan migrate --force
if [[ -z "$previous" || ! -f "$previous/compose.yml" ]]; then
    "${dc[@]}" exec -T backend php artisan db:seed --force
fi
"${dc[@]}" up -d
for attempt in $(seq 1 30); do
    if "${dc[@]}" exec -T web wget -q -O /dev/null http://127.0.0.1/api/v1/health; then
        [[ -z "$previous" ]] || ln -sfn "$previous" /opt/3r/previous
        ln -sfn "$release" /opt/3r/current
        printf '%s\n' "$revision" > /srv/3r/uat/release.txt
        echo "UAT release healthy: $revision"
        exit 0
    fi
    sleep 2
done
echo 'Health check failed; current symlink remains unchanged. Inspect logs and follow rollback guide.' >&2
exit 1
