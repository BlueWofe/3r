# dev / UAT

Run `pwsh -File scripts/setup-dev.ps1` for local dev. It creates local .env with random app key, DB and demo password, builds images, starts services, migrates and seeds. Do not publish .env. API and UI share http://localhost:3180. Database and Redis have no host ports. Ordinary restart: `docker compose up -d`; stop: `docker compose stop` (do not use `down -v` if data must survive).

## GCP

Selected target is existing instance-dmai-v3 / asia-east1-c / z51lm-424108 at 35.229.149.68 (user chose py VM after capacity inspection). Probe on 2026-09-29 found ~3.6 GB available RAM, near-idle CPU, ~19 GB free disk and port 3180 free. Docker limits cap 3r at ~1.6 GB; do not remove existing Domaine data/images. Build images locally and transfer to avoid remote build pressure. The initially considered YS instance-51 is not the deployment target. Verify pinned SSH host key before authenticating. Python tool requires paramiko and takes SSH passphrase from R3_SSH_PASSPHRASE or interactive input. Never copy legacy SSH notes or credentials into this repository.

`python scripts/gcp.py probe --host CURRENT_IP --fingerprint SHA256:VERIFIED_FINGERPRINT`

Prepare an untracked UAT environment outside the repo with fresh keys/passwords, COMPOSE_PROJECT_NAME=r3-uat, WEB_BIND=0.0.0.0, WEB_PORT=3180, APP_ENV=staging, APP_URL=http://CURRENT_IP:3180, SESSION_COOKIE=r3_uat_session, DEMO_SEED=true and mock integrations. Allow only synthetic demo data over HTTP. Determine free port using probe first. Existing GCP firewall needs a target-specific website-port allowance; do not alter rules for other VMs or expose database/Redis. The deployment tool does not modify firewall rules or format disks.

`python scripts/gcp.py deploy --host CURRENT_IP --fingerprint SHA256:VERIFIED_FINGERPRINT --env-file PATH_TO_PRIVATE_UAT_ENV`

For py VM pass `--user domaineadmin --key PATH_TO_EXISTING_ECDSA_KEY --key-type ecdsa`. Prefer `--image-bundle PATH_TO_DOCKER_SAVE_ARCHIVE` containing r3-backend:SHA and r3-frontend:SHA (same SHA as the release). No image registry credentials are transferred. The existing known_hosts entry is the source of the pinned server fingerprint.

Tool deploys only committed Git content into /opt/3r/releases/SHA. Configuration stays /opt/3r/config/uat.env (0600), persistence /srv/3r/uat. Deployment uses version-tagged images, backs up an existing database/storage, migrates and checks health before marking current. It refuses port 3180 if another service owns it and compares non-3r container identities plus existing py health before and after. First-time synthetic seed only; subsequent deployments never reset users or application data.

## Backup and rollback

Use the active compose files and env with explicit `-p r3-uat`. Backup PostgreSQL with `pg_dump -U r3 -d r3 -Fc` and private storage together, in a maintenance window to maintain consistency. Keep backup files restricted. Before schema-changing deployments, test restoration into an isolated database; never test restoration over UAT data.

For application rollback, select /opt/3r/previous, export BACKEND_IMAGE=r3-backend:PREVIOUS_SHA and FRONTEND_IMAGE=r3-frontend:PREVIOUS_SHA, then compose up -d using that release. Confirm database migration compatibility before rollback. Incompatible migrations require an explicit maintenance outage and restoration of the matching DB+storage backup, then repeat health and core-flow checks. A failed deploy does not auto-restore a database or delete new data.

Once group-only articles are stored, the backend must retain group-aware public visibility checks. Do not roll it back to a pre-group release: that older code may expose restricted articles through public listings or search. A frontend rollback may keep the newer backend. Database restoration requires a separately authorized maintenance operation and matching private-storage backup.

Daily off-VM backup storage and HTTPS production domain are prerequisites for actual personal data; the initial UAT is for synthetic acceptance tests.
