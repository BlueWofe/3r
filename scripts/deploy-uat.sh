#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Pass full commit SHA}
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid commit'; exit 1; }
release="/opt/3r/releases/$revision"
[[ -f "$release/compose.yml" ]] || exit 1
cd "$release"
envfile=/opt/3r/config/uat.env
grep -qx 'COMPOSE_PROJECT_NAME=r3-uat' "$envfile"
grep -qx 'WEB_PORT=3180' "$envfile"
dc=(docker compose --env-file "$envfile" -p r3-uat -f compose.yml -f compose.uat.yml)
mkdir -p /srv/3r/uat/{storage,postgres,redis,backups}
available_kb=$(df -Pk /srv/3r | awk 'NR==2 {print $4}')
[[ "$available_kb" -gt 8388608 ]] || { echo 'Need at least 8 GiB free disk before deploy'; exit 1; }
dateid=$(date -u +%Y%m%dT%H%M%SZ)
docker ps --format '{{.Names}} {{.Status}}' > "/srv/3r/uat/backups/containers-before-$dateid.txt"
# Record all non-3r running container identities. Deployment must not restart them.
docker ps --format '{{.ID}} {{.Names}}' | awk '$2 !~ /^r3-uat-/' | sort > "/srv/3r/uat/backups/identities-before-$dateid.txt"
curl -fsS --max-time 20 http://127.0.0.1/api/debug/health > "/srv/3r/uat/backups/py-health-before-$dateid.json"
previous=$(readlink -f /opt/3r/current || true)
if [[ -n "$previous" && -f "$previous/compose.yml" ]]; then
    docker compose --env-file "$envfile" -p r3-uat -f "$previous/compose.yml" -f "$previous/compose.uat.yml" exec -T postgres pg_dump -U r3 -d r3 -Fc > "/srv/3r/uat/backups/$dateid.dump"
    tar -czf "/srv/3r/uat/backups/storage-$dateid.tar.gz" -C /srv/3r/uat storage
fi
export BACKEND_IMAGE="r3-backend:$revision" FRONTEND_IMAGE="r3-frontend:$revision"
sed -i '/^BACKEND_IMAGE=/d; /^FRONTEND_IMAGE=/d' "$envfile"
printf '\nBACKEND_IMAGE=%s\nFRONTEND_IMAGE=%s\n' "$BACKEND_IMAGE" "$FRONTEND_IMAGE" >> "$envfile"
if [[ "${R3_PREBUILT:-false}" != true ]]; then
    "${dc[@]}" build --pull backend frontend
fi
"${dc[@]}" up -d postgres redis backend
"${dc[@]}" exec -T backend php artisan migrate --force
if [[ -z "$previous" || ! -f "$previous/compose.yml" ]]; then
    "${dc[@]}" exec -T backend php artisan db:seed --force
fi
"${dc[@]}" up -d
for attempt in $(seq 1 30); do
    if "${dc[@]}" exec -T web wget -q -O /dev/null http://127.0.0.1/api/v1/health; then
        docker ps --format '{{.ID}} {{.Names}}' | awk '$2 !~ /^r3-uat-/' | sort > "/srv/3r/uat/backups/identities-after-$dateid.txt"
        diff -u "/srv/3r/uat/backups/identities-before-$dateid.txt" "/srv/3r/uat/backups/identities-after-$dateid.txt"
        curl -fsS --max-time 20 http://127.0.0.1/api/debug/health > "/srv/3r/uat/backups/py-health-after-$dateid.json"
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
