<p align="center">
  <img src="public/images/media-upload-manager-logo.svg" width="620" alt="Media Upload Manager">
</p>

# Media Upload Manager

Media Upload Manager is a self-hosted web application for identifying movie and episodic video files, choosing a suitable NAS disk, uploading directly with resumable transfers, validating the result, and placing it in a deterministic Jellyfin library layout.

> **Project status:** The Movie beta.2 production workflow is complete through MUM-012C, with MUM-014 deployment and MUM-015 hardening complete. The MUM-013 operational dashboard is complete; private-user administration remains in progress. MUM-016 defines the Series roadmap, and MUM-017A ships the kind-aware disk/root foundation; Series domain models and uploads remain deferred.

## Movie v1 workflow

1. Select one local video file.
2. Derive a search from its filename, or enter a title, TMDB ID, or IMDb ID.
3. Confirm the correct TMDB movie and preview the final path.
4. Accept the recommended writable disk or choose another eligible disk.
5. Upload from the browser directly to that disk with a resumable `tus` transfer.
6. Validate the completed file with `ffprobe` and atomically publish it with an exclusive same-filesystem hard link.

The final layout is:

```text
Disk/
└── Movie Title (Year) [tmdbid-12345]/
    └── Movie Title (Year) [tmdbid-12345].mkv
```

An upload is staged on the selected disk at:

```text
Disk/.media-upload-manager/incoming/<upload-uuid>.part
```

Staging and finalization happen on the same filesystem. The worker creates the final name with an exclusive hard link, verifies both names reference the same inode, then removes the staging name. This avoids a second full-size copy and cannot overwrite an existing destination. MUM-011 is the sole exception: after an irreversible confirmation, it replaces only the application-tracked current primary after full validation and never touches Jellyfin artwork, metadata, subtitles, trickplay, or other sidecars.

## Series roadmap workflow

1. Select one episode file, a season directory, or a complete series directory, with multi-file fallback when directory selection is unavailable.
2. Confirm one TMDB TV series and explicitly classify it as TV or Anime.
3. Review every parsed `SxxEyy` mapping, correcting unresolved, duplicate, or conflicting episodes before admission.
4. Map a special only to a real TMDB Season 0 episode. Non-TMDB bonus videos are explained and excluded without changing local files.
5. Choose the series home disk. The server fingerprints every accepted file and reserves the entire batch atomically against shared physical capacity.
6. Transfer episodes sequentially through the existing tus pipeline, with aggregate progress and independent pause, retry, cancellation, token refresh, and recovery.
7. Validate and finalize each episode independently. Once transfer begins, completed episodes remain completed if a later item fails.

Series uses one immutable home disk after its first admission or import. Season 0 is labelled `Specials` in the UI and stored canonically as `Season 00`. The deliberate four-level layout is:

```text
Series Root/
└── Series Title (Year) [tmdbid-123]/
    └── Season 00/
        └── Series Title (Year) S00E01 - Special Title/
            └── Series Title (Year) S00E01 - Special Title.mkv
```

Regular episodes use `Season 01`, `S01E01`, and so on. Jellyfin's [TV naming documentation](https://jellyfin.org/docs/general/server/media/shows/) shows episode files directly under season folders; the extra episode directory here is an intentional, user-validated project convention. Absolute anime numbering, multi-episode files, multipart episodes, and multiple versions remain out of scope.

## Existing-movie library workflow

An administrator can start an explicit scan of the configured movie disks. The scan is dry-run-first: it records supported discovered files and missing tracked primaries with exact filesystem snapshots, excludes `.media-upload-manager`, and does not mutate bytes by itself.

After review, the administrator can:

- identify and import a discovered movie to its canonical Jellyfin path using an exclusive same-filesystem hard link followed by unlinking the discovered name;
- restore a missing tracked primary only when durable imported-file inode provenance or uploaded-file size and bounded hash provenance proves the discovered bytes are the same;
- confirm that an unpaired tracked primary was removed externally;
- re-identify a tracked movie and repair its canonical path while preserving the exact primary inode;
- delete only an exact claimed discovered file; and
- preview and confirm a manifest-pinned cleanup of non-video residue after a finding is resolved.

These are narrow, administrator-confirmed reconciliation operations. The application does not provide arbitrary filesystem browsing, moving, bulk deletion, or general sidecar management, and it never performs automatic or continuous scans.

The planned Series scan workspace is separate from Movies and scans only configured Series roots. It groups findings by proposed series and season, maps TMDB episodes including Season 0, and records known Jellyfin extras as unmanaged rather than importing or deleting them. Grouped imports, relocation, re-identification, episode remapping, and episode/season/series deletion persist exact operation/item claims before mutation and preserve all unclaimed sidecars and extras.

## Target stack

- Laravel 13 and PHP 8.5; SQLite for local development and MySQL 8.4 for production
- Pest
- Inertia 3, Vue 3, TypeScript, and Tailwind CSS 4
- `tus-js-client` 4.3.1 and pinned `tusd` 2.10.0
- Laravel's [official Vue starter kit](https://laravel.com/docs/13.x/starter-kits) and Fortify-backed authentication
- Nginx, PHP-FPM, the official `tusd`, a queue worker, a scheduler, and `cloudflared`
- `ffprobe` for post-upload validation

Filament is deliberately not part of the target stack. Public registration is disabled; the application is intended for a small set of private users.

## Local development

The project targets PHP 8.5 and Node 24. It uses SQLite plus the database-backed queue, cache, and session drivers. No users are seeded. Interactive `composer setup` finishes with a guided first-administrator form and prints a generated one-time password only to that terminal.

```bash
brew install ffmpeg
composer setup
composer upload:dev
```

`composer upload:dev` prepares the pinned local tusd/Herd proxy, verifies `FFPROBE_BINARY`, queues recovery for retained `processing` uploads, and starts Vite, logs, the database queue worker, and tusd together.

When automation performs the install, migration, and build steps without an interactive terminal, provide the administrator identity explicitly afterward:

```bash
php artisan admin:bootstrap --name="Ada Lovelace" --email="ada@example.com" --no-interaction
```

The command succeeds without changing or revealing credentials when any user already exists. If an administrator loses access, use `php artisan admin:recover`; its generated recovery password is likewise shown once and must be replaced at login.

Local media roots are optional. The existing `MEDIA_DISK_<ID>_PATH` remains a Movie-root alias. Each stable physical disk may expose the explicit Movie and Series roots below; configured roots must already exist. macOS development accepts ordinary local directories, while Linux production requires every resolved root to be an exact mount point in the process mount namespace.

```dotenv
MEDIA_DISKS=local
MEDIA_DISK_LOCAL_LABEL="Local Media"
MEDIA_DISK_LOCAL_MOVIES_PATH="/Users/your-name/Movies/Jellyfin Movies"
MEDIA_DISK_LOCAL_SERIES_PATH="/Users/your-name/Movies/Jellyfin Series"
MEDIA_DISK_LOCAL_SERIES_DEFAULT_CATEGORY=tv
MEDIA_DISK_LOCAL_RESERVE_GIB=5
MEDIA_REQUIRE_MOUNTPOINT=false
```

`MEDIA_DISK_<ID>_SERIES_DEFAULT_CATEGORY=tv|anime` opts that Series root into automatic imports during an administrator's manual scan. Only paths with one unambiguous `[tmdbid-N]` tag and `SxxExx` identity confirmed by TMDB are queued; existing Shows retain their stored category. Omit the variable to keep every finding on that root in the review workflow. Scans remain administrator-triggered and do not continuously watch media roots.

```bash
php artisan media:disks:initialize local --kind=movies
php artisan media:disks:initialize local --kind=series
php artisan media:disks:check
php artisan media:disks:check --json
```

Omitting `--kind` initializes Movies for backward compatibility. Initialization creates only a kind-aware `.media-upload-manager/disk.json` and `.media-upload-manager/incoming/` inside the chosen root. A matching legacy version-1 Movie marker is upgraded atomically; Series roots never accept version-1 markers. Initialization does not scan, import, rename, or modify existing media. See the [configuration guide](docs/configuration.md#4-disk-definitions) for compatibility rules, production Linux examples, and the full validation contract.

Laravel Herd serves the configured workspace at [https://media-upload-manager.test](https://media-upload-manager.test). For a fresh local clone, create the same isolated site with:

```bash
herd link media-upload-manager --secure --isolate=8.5 --update-env
herd isolate-node 24 --site=media-upload-manager
```

The primary quality commands are:

```bash
composer lint
composer lint:check
composer types:check
composer test
composer ci:check
```

`composer ci:check` runs Composer validation, frontend lint/format/type/build checks, PHP formatting/static analysis/Pest, and Composer/npm dependency audits.

## Linux beta production quick start

The production target is one private `linux/amd64` server running Docker Engine, Docker Compose, MySQL 8.4, Nginx, `tusd`, workers, Pulse, and Cloudflare Tunnel. It publishes no host ports. Use an exact `v0.1.0-beta.N` release for both application images; never deploy a branch, `latest`, or mismatched app/Nginx tags.

This is a deliberately small personal beta. MySQL and tus metadata use Docker volumes, and this release does **not** provide backup or restore automation. Keep independent copies of irreplaceable movies and do not destroy production volumes.

1. Pass the GitHub Actions AMD64 container, smoke, and release-quality jobs, then publish an exact beta tag.
2. Install Docker Engine and the Compose plugin from Docker's [official Linux instructions](https://docs.docker.com/engine/install/). Verify `docker info` and `docker compose version`.
3. Mount each NAS filesystem at its permanent absolute Linux path. The container UID/GID must be able to traverse and write there, and each configured path must remain a real mountpoint.
4. Create a Cloudflare Tunnel routed to `http://nginx:8080`. Protect the entire hostname—including `/uploads/tus/*`—with one Cloudflare Access application and no bypass policy.
5. Check out the same release tag on the server and create the secret environment file:

```bash
git clone YOUR_REPOSITORY_URL media-upload-manager
cd media-upload-manager
git checkout v0.1.0-beta.N
umask 077
cp deploy/production/.env.production.example deploy/production/.env.production
```

6. Fill every blank in `.env.production`, use exact matching GHCR image tags, set the public HTTPS `APP_URL`, and set the real absolute NAS paths. Keep `APP_UID:APP_GID` as the primary process identity and set `MEDIA_GID` to the NAS media group (it defaults to `APP_GID`). Authenticate to private GHCR images with a read-only `read:packages` token.
7. Validate without printing the interpolated secret-bearing configuration, then follow the first-install runbook:

```bash
docker compose --env-file deploy/production/.env.production \
  -f deploy/production/compose.yml config --quiet
```

8. Run the migrations, initialize each media disk once, bootstrap the administrator, start the stack, and complete the [production deployment runbook](docs/production-deployment.md).
9. Sign in through the Cloudflare hostname, replace the one-time password, inspect `/pulse`, and complete the release smoke test before calling the tag deployed.

### Instructions for an AI operator

An AI assisting with production must read `AGENTS.md`, `.ai/rules/index.md`, and `.ai/rules/production.md` first. It must preserve these boundaries:

- Never read back, print, commit, or paste `.env.production`, passwords, tokens, `APP_KEY`, or rendered Compose output.
- Use `docker compose config --quiet`; plain `docker compose config` may expose interpolated secrets.
- Confirm the exact release tag, image pair, Compose project, and absolute NAS mountpoints before changing state.
- Pause for the human to enter or capture administrator and registry credentials unless the human explicitly authorizes otherwise.
- Never run `docker compose down --volumes`, remove named volumes, recursively change NAS ownership, expose a host port, or add a Cloudflare Access bypass.
- On failure, preserve containers and volumes, collect redacted logs, diagnose first, and prefer code rollback over reversing migrations.

## Documentation

- [Product specification](docs/product-spec.md) — scope, user journeys, requirements, and acceptance criteria
- [Architecture](docs/architecture.md) — components, data model, interfaces, security, and upload lifecycle
- [Configuration](docs/configuration.md) — environment contract, disk definitions, Herd, Docker, and operational warnings
- [Production deployment](docs/production-deployment.md) — human/AI Linux installation, upgrades, rollback, and smoke testing
- [Backlog](docs/backlog.md) — ordered implementation tickets and verification plan

## Key constraints

- The browser uploads through Nginx directly to `tusd`; PHP never receives movie or episode bytes or assembles chunks.
- Production chunks are 64 MiB. This remains below Cloudflare's documented 100 MB Free/Pro maximum request size, while the [tus protocol](https://tus.io/protocols/resumable-upload) supplies resumability. See Cloudflare's [413 guidance](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/4xx-client-error/error-413/) and the [tusd reverse-proxy guidance](https://tus.github.io/tusd/getting-started/configuration/#proxies).
- A movie file must use one of these v1 container extensions: `mkv`, `mp4`, `m4v`, `avi`, `mov`, `ts`, `m2ts`, or `webm`.
- Series uses the same extension allowlist, one source video per TMDB episode, and sequential transfers within an atomically reserved batch.
- Automatic identification parses filenames and ranks TMDB candidates. It is not content-fingerprint recognition, and the user must confirm the movie or series mappings before uploading.
- Upload sessions expire after seven days without activity. Resuming in a reopened browser requires reselecting the same file and matching its metadata plus first/last 1 MiB hashes.
- Capacity decisions combine active Movie and Series uploads across both roots of a stable physical disk and retain one per-disk safety margin of 20 GiB by default.

## Scope boundary

The shipped Movie workflow handles one file per upload and one application-managed current primary per movie. MUM-011 supports explicitly confirmed replacement; MUM-011A supports listing and permanently deleting only application-tracked movie graphs and their exact verified current primaries. MUM-012 through MUM-012C add administrator-driven dry-run discovery, canonical hard-link/unlink import, provenance-verified relocation, tracked-movie re-identification, exact discovered-file deletion, and manifest-pinned residue cleanup.

MUM-017 through MUM-024 add separate Series domain/storage, TMDB TV and Specials, atomic batch uploads, Series UI, grouped discovery/import, missing-file recovery, re-identification/remapping, exact scoped deletion, and release hardening. General Jellyfin extras remain preserved but unmanaged; existing server content enters only through explicit administrator scans.

Arbitrary filesystem browsing, moving, bulk deletion, general sidecar management, multiple versions, absolute anime numbering, multi-episode or multipart files, non-TMDB bonus-video management, automatic or continuous NAS scanning, video-content fingerprint recognition, two-factor authentication, backups/restoration, Redis/Horizon, external alerts, and broad automated browser coverage remain deferred. Cloudflare Tunnel and Access are deployed production boundaries, not deferred work.

Ticket numbers retain historical roadmap order; explicit backlog statuses and dependencies are authoritative. The remaining private-user administration work is tracked in [MUM-013](docs/backlog.md#mum-013--dashboard-and-private-user-administration); the next Series implementation milestone is [MUM-017](docs/backlog.md#mum-017--series-storage-and-domain-foundation).
