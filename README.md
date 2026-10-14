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
- A vessel editor that converts assembly and legacy workspace uploads, previews private part geometry, edits individual or bulk deployment states, rotates the whole vessel, and saves into a draft change set.

Static validation does **not** launch KSP2 or prove gameplay behavior.

## Vessel preparation and preview

Open **OTA content > Vessel editor**, or choose **Preview / edit** on a published or staged vessel. Upload an OAB workspace or serialized assembly JSON. Assemblies are wrapped automatically, legacy embedded assembly strings are decoded, the main assembly is placed first, and saved numeric tokens are preserved during deployment edits. Uploaded source files can be up to 50 MiB; the converted OTA payload must still fit within 10 MiB. Missing assembly mass is estimated from the private part definitions and stored resources; enter it manually when a part or resource is unknown.

The Three.js preview uses untextured part models with a consistent metallic silver material and soft reflections. Saved paint colours remain in the downloaded and staged workspace. The editor uses the site's normal light or dark theme; only the 3D viewport has a dark background. Editing a vessel preserves the camera's orbit, zoom and pan; **Frame vessel** explicitly resets framing.

Saved procedural wings, stabilizers, control surfaces, fins and body flaps select their authored mesh variant, apply slider limits and bone constraints, and retain mirrored geometry and submesh visibility. Ordinary grid fins and airbrakes use their control-surface deployment fields and authored hinge transforms, including the default deployment angle when a save contains `NaN`. Fairings, engine shrouds and procedural tubes are built from their saved cross-sections, with the part definition supplying the correct axis, model transform, wall thickness and cap settings. Parts without exported prefab metadata report an approximation in the preview.

Select a part in the viewport or list to deploy/retract it, or use the bulk controls. For fairings and shrouds, **jettisoned** removes the shell; clearing it restores the saved profile. Procedural control surfaces use their saved deployment angle. Fixed parts have no deployment control. Changes update both the preview and the downloadable workspace, preserving unrelated procedural settings and numeric tokens. Whole-vessel quarter-turns help align engines and the vessel's front with the travel arrow, which stays outside the vessel's projected bounds. Both upright and leaning menu poses are available. Fly-in and fly-out use the game's +Y nose direction, cubic easing, 2.5/5-second timing and approximate renderer-relative travel distances. This preview does not reproduce textures, exhaust or game lighting exactly.

Saving from the editor stages a draft operation; existing validation, publication, cancellation, stale-content protection and rollback remain in use. No edit publishes directly. **Preview / edit** on a draft operation reopens its stored payload.

### Private model library

Generated game geometry must stay out of the public application repository and Docker image. The `vessel-models` disk and `ota_models` Docker volume are private. Models and the catalog are served only to authenticated publishers. Build a package on the Windows SDK machine from the AssetRipper export:

```powershell
python scripts/export-vessel-models.py --assets "C:\Games\KSP Stuff\KSP 2 Modding\SDK\Exports\KSP2-Export\ExportedProject\Assets" --redux-definitions "C:\Games\KSP Stuff\KSP 2 Modding\SDK\Ksp2Redux\Assets\ReduxAssets\Definitions" --output storage/app/model-package
```

The exporter selects the full part prefab by its part definition, preserving inherited model scale even when AssetRipper gives a model leaf the unsuffixed filename. It reads prefab transforms, nested prefabs, meshes, skinning and deployment animation endpoints without executing Unity scripts. Collision and reentry helpers are excluded through their entire hierarchy. It generates retracted and extended GLBs, private part/resource definitions, hashes and an export report. Python needs `numpy` and `PyYAML`; `rg.exe` must be on PATH. Remove `guid-index.json` when changing the source asset export so its reference cache is rebuilt.

Procedural rigs and fairing defaults are stored in private `.procedural.json` files alongside the GLBs. To enrich an existing package without rebaking the static models, add `--procedural-only` to the export command, then reimport it. The catalog and every referenced file must be transferred together; imports verify their SHA-256 checksums. Procedural data uses the same authenticated publisher routes as the models.

Redux FBX models absent from the stock export can be added with an installed Blender in a separate background process:

```powershell
& "C:\Program Files\Blender Foundation\Blender 5.2\blender.exe" --background --factory-startup --python scripts/export-fbx-models.py -- --definitions "C:\Games\KSP Stuff\KSP 2 Modding\SDK\Ksp2Redux\Assets\ReduxAssets\Definitions" --package storage/app/model-package
php artisan vessels:import-models storage/app/model-package
```

The FBX supplement bakes imported animation endpoints; prefab offsets and controller animations outside the FBX are approximate. Unsupported parts use wireframe approximations and are counted in the preview. Copy the model package privately to the server and run the same import command inside the app container with its `ota_models` volume mounted. The package is separate from the application image; never add the generated GLBs to Git. Reimport after changing or extending the part library.

For browser acceptance checks, `tests/browser/vessel-editor.mjs` exercises a local isolated, authenticated fixture with Edge through Playwright. It checks visible rendering, individual deployment, both poses and flight directions, rotation, numeric-preserving downloads and draft staging. `tests/browser/procedural-vessel.mjs` additionally checks saved wing shapes and mirroring, control-surface deployment, shroud coverage against the actual engine bounds, jettison/restoration, bulk controls and unchanged downloaded profiles. These scripts expect an imported private model library and the local fixture session under the SDK `.agents/ota-admin-preview-20261014` directory; those fixture helpers are not deployed.

## Local setup

Requirements are PHP 8.3+, Composer, and the PHP extensions required by Laravel. For the default local SQLite configuration:

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan test
npm run test:geometry
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
