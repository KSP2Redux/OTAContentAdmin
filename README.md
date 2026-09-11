# Redux OTA Admin

Laravel 13 / Filament 4 application for publishing KSP2 Redux OTA localizations, main-menu vessels, and missions. The source is public at `KSP2Redux/OTAContentAdmin`, while access to the deployed administration interface remains restricted through Authentik. `KSP2Redux/Content` remains the live source of truth; this application stores drafts, validation results, publication attempts, and audit history.

GitHub hosts this application's source and container images. GitLab is only an integration used to trigger and monitor the existing Ksp2Redux localization publication job.

## Implemented workflows

- Authentik OIDC login with an independent `Rendezvous Entertainment Content Managers` group check.
- Draft changesets for vessel and mission add, replace, delete, and reorder operations.
- Private uploads, JSON inspection, lossless vessel normalization, compatibility validation, deterministic manifests, and atomic GitHub commits.
- Missing mission localization-key discovery and English source-unit creation through Weblate.
- Queued Weblate commit/push, GitLab OTA pipeline, and published localization verification.
- Publication history, stale-content protection, a global publication lock, dry-run validation, and inverse changeset rollback.
- Liveness and dependency-aware readiness endpoints.

Static validation does **not** launch KSP2 or prove gameplay behavior.

## Local setup

Requirements are PHP 8.3+, Composer, and the PHP extensions required by Laravel. For the default local SQLite configuration:

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
php artisan serve
```

Populate the integration settings in `.env` before using remote status or publication actions. Never add credentials to `.env.example`, application logs, database records, or Filament fields.

## Production setup

`docker-compose.yml` defines the HTTP application, queue worker, scheduler, PostgreSQL, and Redis. The application joins the external `caddy_net` network and listens on container port 8080. Temporary Filament uploads and durable change-set payloads use separate private Docker volumes so upload cleanup cannot remove queued publication data. This Docker Standalone deployment uses the administrator-owned external volume `ota-content-admin-secrets`, mounted read-only at `/run/secrets`. Store these files in that volume before starting the stack:

- `ota_app_key`
- `ota_db_password`
- `ota_authentik_secret`
- `ota_github_private_key`
- `ota_weblate_token`
- `ota_gitlab_token`

Set `AUTHENTIK_CLIENT_ID`, `GITHUB_APP_ID`, and `GITHUB_APP_INSTALLATION_ID` as stack environment variables. Configure Caddy to proxy `ota-admin.rendezvous.dev` to `app:8080`.

The GitHub Actions workflow tests pull requests and pushes to `main`. A successful push to `main` publishes `ghcr.io/ksp2redux/otacontentadmin:main` plus an immutable commit-SHA tag. Make the GHCR package public after its first publication, then configure Portainer to pull the image by an immutable SHA tag for production deployments.

The Authentik provider must use authorization code with PKCE, asymmetric signing, issuer `https://sso.rendezvous.dev/application/o/redux-ota-admin/`, and exact callback `https://ota-admin.rendezvous.dev/auth/callback`. Request only `openid profile email`. Enforce the publisher group in Authentik as well as in this application.

The GitHub App installation must be limited to `KSP2Redux/Content` with Metadata read and Contents read/write. The Weblate service account needs only KSP2Redux repository and source-unit operations. The GitLab project token needs only pipeline creation and status access.

Restrict management of the secret volume to Portainer administrators. Never place its contents in the Git repository, stack environment variables, or the Portainer stack editor. Docker Standalone volumes are not encrypted Docker Swarm secrets, so access to the Docker host remains privileged access to these credentials.

Rotate the GitHub and Outline credentials exposed during the earlier discovery session before any staging or production deployment.

Because this is a public repository for a privileged application, enable GitHub private vulnerability reporting, secret scanning, push protection, Dependabot alerts, and branch protection requiring the CI workflow. Runtime credentials belong only in Portainer/Docker secrets; GitHub Actions does not need production service credentials.

## Operations

- `/health/live` confirms the process is responsive.
- `/health/ready` checks PostgreSQL, Redis, Authentik discovery, GitHub, Weblate, and GitLab without mutating them.
- Run migrations during deployment with `php artisan migrate --force`.
- Draft payloads older than 30 days are deleted by the scheduler; database audit metadata is retained.
- The existing nightly localization publisher remains enabled. The application uses a non-force compare-and-update on `Content/main` to guard the final race.

## Verification

```sh
vendor/bin/pint --test
php artisan test
php artisan route:list
docker compose config
```

Staging must use a test Content repository and a non-production GitLab pipeline. Production acceptance must exercise Authentik access denial, each content operation, Weblate no-op and pending-change paths, stale same-channel edits, a concurrent localization update, rollback, and failure recovery.

## Compatibility catalog

`resources/compatibility/ota-authoring.json` is pinned to the oldest supported Ksp2Redux source SHA. It is intentionally deployed with the application. Changing the support floor requires regenerating and reviewing this catalog, then deploying the application; unknown catalog versions must not be used for publication.
