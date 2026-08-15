# Personal beta production deployment

This runbook deploys one private Linux x86_64 server with Docker Compose, MySQL 8.4, Cloudflare Tunnel and Access, private GHCR images, and Pulse-only monitoring.

Release-specific upgrade procedure: [`v0.1.0-beta.2` NAS media group compatibility](v0.1.0-beta.2-release-notes.md).

> **Accepted data-loss boundary:** this beta has no backup or restore implementation. Losing the `mysql-data` or `tus-metadata` Docker volume loses application or resumable-upload state. Movie files still present on the NAS may be rediscovered with the manual library scan. Do not treat this beta as the only copy of irreplaceable data.

## What “beta production ready” means

A release candidate is ready for a personal beta only after all of these gates pass:

1. The intended changes are committed and pushed.
2. GitHub Actions passes the native `linux/amd64` container build, Compose smoke test, and release-quality suite.
3. A matching `v0.1.0-beta.N` app/Nginx image pair is published to GHCR.
4. The Linux server, NAS mounts, GHCR access, Cloudflare Tunnel, and Cloudflare Access are configured.
5. The first-install and real upload smoke tests in this runbook pass.

The local Apple Silicon rehearsal proves the production topology and browser workflow on ARM64. It does not replace native AMD64 CI or the live Linux/Cloudflare/NAS checks.

## Human and AI operating contract

Before an AI operator runs commands, it must read `AGENTS.md`, `.ai/rules/index.md`, `.ai/rules/production.md`, this runbook, and the selected release's `deploy/production/compose.yml`.

- Never display, log, commit, or paste `.env.production`, rendered Compose configuration, passwords, API tokens, `APP_KEY`, or the tunnel token.
- Use `docker compose config --quiet` for validation. Plain `docker compose config` renders interpolated secrets.
- Resolve the exact server, repository checkout, release tag, image tags, Compose project, and NAS paths before any state-changing command.
- A human should enter GHCR and administrator credentials in a trusted terminal. An AI may invoke those steps only after explicit authorization and must not repeat their output.
- Never use floating image tags, publish a host port, create a Cloudflare Access bypass, recursively `chown` a NAS, reverse migrations, or run `docker compose down --volumes` in production.
- A failed deployment keeps its containers, MySQL volume, tus volume, and NAS data intact until redacted diagnostics have been collected.

## Server prerequisites

The supported release target is a 64-bit `linux/amd64` host. Install Docker Engine and the Compose plugin from the [official Docker Engine instructions](https://docs.docker.com/engine/install/) and [Compose plugin instructions](https://docs.docker.com/compose/install/linux/), not the convenience script intended for development.

Verify the daemon, architecture, and Compose command:

```bash
docker info --format '{{.ServerVersion}} {{.Architecture}}'
docker compose version
```

Check out the exact release that matches the images you will deploy:

```bash
git clone YOUR_REPOSITORY_URL media-upload-manager
cd media-upload-manager
git checkout v0.1.0-beta.N
test "$(git describe --tags --exact-match)" = "v0.1.0-beta.N"
```

Do not deploy from a dirty checkout.

```bash
test -z "$(git status --porcelain)"
```

## Host and Cloudflare preparation

Install Docker Engine with Compose v2. Mount every NAS filesystem on the host before starting Compose. The three configured paths must support the primary service UID/GID, the supplemental media GID, hard links, and the required read/write/hard-link/unlink operations. Atomic same-path replacement also requires rename permission.

In Cloudflare Zero Trust:

1. Create a remotely managed tunnel whose public hostname routes to `http://nginx:8080`.
2. Create one self-hosted Access application covering the complete hostname, including `/uploads/tus/*`.
3. Add an Allow policy restricted to membership in the owner's Cloudflare account. Do not create a bypass policy for tus, hooks, health, or assets.
4. Keep the Access session cookie scoped to the application hostname. The browser's same-origin tus `POST`, `HEAD`, `PATCH`, and `DELETE` requests then carry the same Access cookie as Laravel requests.
5. During release verification, inspect one 64 MiB (`67,108,864` byte) PATCH in browser developer tools and confirm it reaches `tusd` without a 413 response.

Use Cloudflare's [remotely managed tunnel instructions](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/get-started/create-remote-tunnel-api/) and keep the tunnel token out of shell history and source control.

For each NAS, verify that the configured path is the permanent mountpoint itself—not merely a directory beneath another mount—and that the chosen application UID/GID can use it. Repeat these checks for every configured path:

```bash
findmnt --mountpoint /mnt/media/nas-a
namei -l /mnt/media/nas-a
```

Choose `MEDIA_GID` as the numeric NAS group permitted to modify legacy movie files. It is added as a supplemental group only to `migrate`, `app`, `worker`, `scheduler`, and `tusd`; their primary identity remains `APP_UID:APP_GID`. Keep the kernel's protected-hard-link policy enabled:

```bash
test "$(sysctl -n fs.protected_hardlinks)" = 1
```

Do not lower that policy to fix an import. Correct the NAS group permissions or `MEDIA_GID`, recreate the five media-accessing services, and retry through the administrator interface.

Configure host startup ordering so Docker starts only after the NAS mounts are available. Do not make the container entrypoint repair NAS ownership recursively.

## Private GHCR login

Create a GitHub token with read-only package access. Authenticate on the server without writing the token into the repository or Compose file:

```bash
read -r -s GHCR_TOKEN
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username YOUR_GITHUB_USER --password-stdin
unset GHCR_TOKEN
```

Confirm both GHCR packages are private before installing them.

GitHub documents private GHCR downloads with a classic personal access token carrying only `read:packages`; see [Working with the Container registry](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).

Create the untracked secret file with owner-only permissions:

```bash
umask 077
cp deploy/production/.env.production.example deploy/production/.env.production
chmod 600 deploy/production/.env.production
```

Fill every blank secret and select exact matching `v0.1.0-beta.N` app and Nginx image tags. Set `APP_UID` and `APP_GID` to the primary numeric process identity. Set `MEDIA_GID` to the numeric NAS media group; it defaults to `APP_GID` when omitted. Generate `APP_KEY` with `php artisan key:generate --show` on a trusted workstation and generate independent MySQL and `TUS_HOOK_SECRET` values with a cryptographically secure password generator. Never reuse the app key, database password, hook secret, TMDB token, or Cloudflare token.

Use this alias in the commands below:

```bash
export COMPOSE_FILE=deploy/production/compose.yml
export COMPOSE_ENV=deploy/production/.env.production
```

The base Compose file remains the Movie-only deployment path. `MEDIA_DISK_<ID>_MOVIES_PATH` is preferred and falls back to the legacy `MEDIA_DISK_<ID>_PATH`. Series roots are opt-in: set all three `MEDIA_DISK_<ID>_SERIES_PATH` values and include `deploy/production/compose.series.yml` in every validation, pull, run, up, exec, logs, ps, and down command for that deployment. For example, add the second `-f` argument to every command shown below:

```bash
export SERIES_COMPOSE_FILE=deploy/production/compose.series.yml
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" -f "$SERIES_COMPOSE_FILE" config --quiet
```

Never enable the override for only some commands or services; Laravel, the worker, scheduler, migration preflight, and `tusd` must see identical Series bind mounts.

Before changing any container state, validate required values and the merged Compose model without rendering secrets:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" config --quiet
```

Confirm `APP_URL` is the final HTTPS Cloudflare origin, `TRUSTED_HOSTS` contains only its hostname, both image tags name the same release, and every media path exactly matches its host mountpoint.

## First installation

Pull the exact release, create the state volumes, and migrate MySQL:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" pull
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" up --detach --wait --wait-timeout 120 mysql
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps volume-init
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan migrate --force
```

Initialize every configured media disk independently. This creates only `.media-upload-manager/disk.json` and the private incoming directory:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan media:disks:initialize nas_a
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan media:disks:initialize nas_b
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan media:disks:initialize nas_c
```

When the Series override is enabled, initialize every Series root explicitly as well:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan media:disks:initialize nas_a --kind=series
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan media:disks:initialize nas_b --kind=series
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan media:disks:initialize nas_c --kind=series
```

An existing matching version-1 Movie marker is upgraded atomically during Movie initialization. Series roots reject version-1 markers and require their own version-2 marker.

Bootstrap the sole administrator and capture the one-time password from that terminal:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm --no-deps --entrypoint php migrate artisan admin:bootstrap
```

This is a human credential boundary: do not save the one-time password to a file, ticket, chat transcript, or command log. It must be changed on first login.

Start the complete stack and wait for application/database/cache liveness:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" up --detach --wait --wait-timeout 180
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan media:disks:check
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan schedule:list
```

Sign in, replace the one-time password, open `/pulse`, and confirm scheduler, queue worker, Pulse server, failed jobs, pipeline, and disk cards are healthy.

Also confirm that only `cloudflared` provides ingress and that MySQL, PHP-FPM, Nginx, and `tusd` have no published host ports:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" ps
```

## Manual deployment

The workflow publishes private linux/amd64 images only for `v0.1.0-beta.N` tags or a validated manual release. It tags each image with the release and commit SHA. On the server:

1. Confirm the selected app and Nginx tags refer to the same release.
2. Enter maintenance mode.
3. Pull images, run the one-shot migration/media preflight, recreate processes, wait for health, restart Pulse, and leave maintenance mode.

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan down
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" pull app migrate volume-init nginx
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" run --rm migrate
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" up --detach --force-recreate app worker scheduler pulse tusd nginx cloudflared
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" up --detach --wait --wait-timeout 180
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan pulse:restart
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan up
```

After the application and media-disk checks are healthy, run this one-time post-deploy backfill for existing current movie files:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan media:metadata:backfill-dynamic-range
```

The command never modifies movie bytes and can be rerun safely. If any row fails its disk, path, file, size, or probe checks, do not roll back the release: successful rows remain enriched and affected cards simply omit HDR until the command succeeds on a later rerun.

## Code rollback

Set only `APP_IMAGE` and `NGINX_IMAGE` back to the previous exact release, pull them, and recreate the application processes. Do not reverse database migrations:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" pull app nginx
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" up --detach --force-recreate app worker scheduler pulse nginx
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" up --detach --wait --wait-timeout 180
```

Every post-beta migration must therefore remain compatible with the immediately previous application release.

## Administrator recovery

Run recovery from a trusted terminal. The replacement password is printed once and is never logged:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan admin:recover
```

## Safe diagnostics

Start with bounded status and recent logs. Review output for credentials before sharing it:

```bash
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" ps
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" logs --tail 200 app worker scheduler pulse tusd nginx cloudflared
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec app php artisan media:disks:check
docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" exec nginx nginx -T
```

Do not include `.env.production`, cookies, authorization headers, hook payload secrets, tunnel tokens, or full rendered Compose output in a support bundle.

## Release smoke test

Before every beta tag, manually verify:

- Cloudflare denies an unauthorized identity and allows only the owner account; no tus-path bypass exists.
- Laravel login, forced password change, administrator authorization, and Pulse work.
- A real movie uploads in sequential 64 MiB PATCH requests, pauses, resumes at the exact offset, validates, and lands at the displayed final path.
- Restarting `worker` and `tusd` during their respective safe phases recovers without duplicate finalization or offset loss.
- Unmounting one test media root makes disk checks and upload operations fail closed while the administration UI and `/up` remain available.
- `docker compose exec nginx nginx -T` shows request buffering disabled and the public `X-Media-Upload-Expiry` header erased.
- `ffprobe -version`, the scheduler entries, queue/Pulse heartbeats, and every container health/restart state are visible and healthy.

## beta.2 production verification record — 2026-08-11

The live `v0.1.0-beta.2` personal deployment completed the failure-injection smoke test on 2026-08-11. This non-secret record intentionally omits the hostname, account identity, media names and paths, addresses, container IDs, and credentials.

- Cloudflare Access rejected an unauthorized identity, allowed the authorized owner, and exposed no tus-path bypass.
- A real upload used sequential 64 MiB PATCH requests, paused, resumed from the exact confirmed offset, validated, and reached its displayed canonical path.
- Controlled `worker` and `tusd` restarts recovered without duplicate finalization, corrupted offsets, or a lost reservation.
- Controlled loss of one media mount made that disk's health and upload operations fail closed while the administration UI and Laravel health endpoint remained available.
- Effective Nginx configuration showed tus request buffering disabled and the internal expiry header removed from the public response.
- Administrator-only Pulse access, scheduler/queue/Pulse health, `ffprobe`, and recovery visibility were confirmed.
- MySQL, app, worker, scheduler, Pulse, `tusd`, Nginx, and `cloudflared` all reported the expected running/healthy and restart state after the injections.

This dated result satisfies the beta.2 MUM-015 production gate; it does not replace the release smoke test required for later tags.
