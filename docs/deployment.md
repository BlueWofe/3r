# dev / UAT

Run `pwsh -File scripts/setup-dev.ps1` for local dev. It creates local .env with random app key, DB and demo password, builds images, starts services, migrates and seeds. Do not publish .env. API and UI share http://localhost:3180. Database and Redis have no host ports. Ordinary restart: `docker compose up -d`; stop: `docker compose stop` (do not use `down -v` if data must survive).

## GCP

Target is existing instance-51 / us-central1-c / z51lm-424108. Its external address is ephemeral: check current GCP Console address after start; never assume an old IP identifies the correct VM. Verify pinned SSH host key before authenticating. Python tool requires paramiko and takes SSH passphrase from R3_SSH_PASSPHRASE or interactive input. Never copy the legacy YS SSH notes or credentials into this repository.

`python scripts/gcp.py probe --host CURRENT_IP --fingerprint SHA256:VERIFIED_FINGERPRINT`

Prepare an untracked UAT environment outside the repo with fresh keys/passwords, COMPOSE_PROJECT_NAME=r3-uat, WEB_BIND=0.0.0.0, WEB_PORT=3180, APP_ENV=staging, APP_URL=http://CURRENT_IP:3180, SESSION_COOKIE=r3_uat_session, DEMO_SEED=true and mock integrations. Allow only synthetic demo data over HTTP. Determine free port using probe first. Existing GCP firewall needs a target-specific website-port allowance; do not alter rules for other VMs or expose database/Redis. The deployment tool does not modify firewall rules or format disks.

`python scripts/gcp.py deploy --host CURRENT_IP --fingerprint SHA256:VERIFIED_FINGERPRINT --env-file PATH_TO_PRIVATE_UAT_ENV`

Tool deploys only committed Git content into /opt/3r/releases/SHA. Configuration stays /opt/3r/config/uat.env (0600), persistence /srv/3r/uat. Deployment builds version-tagged images, backs up an existing database/storage, migrates and checks health before marking current. Review web+API and YS health after deployment. First-time synthetic seed only; subsequent deployments never reset users or application data.

## Backup and rollback

Use the active compose files and env with explicit `-p r3-uat`. Backup PostgreSQL with `pg_dump -U r3 -d r3 -Fc` and private storage together, in a maintenance window to maintain consistency. Keep backup files restricted. Before schema-changing deployments, test restoration into an isolated database; never test restoration over UAT data.

For application rollback, select /opt/3r/previous, export BACKEND_IMAGE=r3-backend:PREVIOUS_SHA and FRONTEND_IMAGE=r3-frontend:PREVIOUS_SHA, then compose up -d using that release. Confirm database migration compatibility before rollback. Incompatible migrations require an explicit maintenance outage and restoration of the matching DB+storage backup, then repeat health and core-flow checks. A failed deploy does not auto-restore a database or delete new data.

Daily off-VM backup storage and HTTPS production domain are prerequisites for actual personal data; the initial UAT is for synthetic acceptance tests.
