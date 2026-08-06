<p align="center">
  <img src="public/images/media-upload-manager-logo.svg" width="620" alt="Media Upload Manager">
</p>

# Media Upload Manager

Media Upload Manager is a self-hosted web application for identifying movie files, choosing a suitable NAS disk, uploading directly with resumable transfers, validating the result, and placing it at a [Jellyfin-compatible movie path](https://jellyfin.org/docs/general/server/media/movies/).

> **Project status:** Movie identification is complete through MUM-005, including authenticated TMDB/IMDb lookup, multilingual release-filename suggestions, deterministic ranking, and explicit confirmation. Jellyfin path generation is next in MUM-006.

## Movie v1 workflow

1. Select one local video file.
2. Derive a search from its filename, or enter a title, TMDB ID, or IMDb ID.
3. Confirm the correct TMDB movie and preview the final path.
4. Accept the recommended writable disk or choose another eligible disk.
5. Upload from the browser directly to that disk with a resumable `tus` transfer.
6. Validate the completed file with `ffprobe` and atomically rename it into place.

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

Staging and finalization happen on the same filesystem, so a successful validation needs an atomic rename rather than a second full-size copy. New uploads never overwrite an existing destination. The later MUM-011 replacement workflow is the sole exception: it requires explicit confirmation, replaces only the application-tracked current primary after full validation, and never touches Jellyfin artwork, metadata, subtitles, trickplay, or other sidecars.

## Target stack

- Laravel 13, PHP 8.5, SQLite, and the database queue
- Pest
- Inertia 3, Vue 3, TypeScript, and Tailwind CSS 4
- Laravel's [official Vue starter kit](https://laravel.com/docs/13.x/starter-kits) and Fortify-backed authentication
- Nginx, PHP-FPM, the official `tusd`, a queue worker, a scheduler, and `cloudflared`
- `ffprobe` for post-upload validation

Filament is deliberately not part of the target stack. Public registration is disabled; the application is intended for a small set of private users.

## Local development

The project targets PHP 8.5 and Node 24. It uses SQLite plus the database-backed queue, cache, and session drivers. No users are seeded. Interactive `composer setup` finishes with a guided first-administrator form and prints a generated one-time password only to that terminal.

```bash
composer setup
composer run dev
```

When automation performs the install, migration, and build steps without an interactive terminal, provide the administrator identity explicitly afterward:

```bash
php artisan admin:bootstrap --name="Ada Lovelace" --email="ada@example.com" --no-interaction
```

The command succeeds without changing or revealing credentials when any user already exists. If an administrator loses access, use `php artisan admin:recover`; its generated recovery password is likewise shown once and must be replaced at login.

Local media roots are optional. To use one, configure its stable ID, label, absolute path, and optional reserve in `.env`; the root must already exist. macOS development accepts an ordinary local directory, while Linux production requires the resolved path to be an exact mount point in the process mount namespace. Then adopt it explicitly:

```dotenv
MEDIA_DISKS=movies
MEDIA_DISK_MOVIES_LABEL="Local Movies"
MEDIA_DISK_MOVIES_PATH="/Users/your-name/Movies/Jellyfin"
MEDIA_DISK_MOVIES_RESERVE_GIB=5
MEDIA_REQUIRE_MOUNTPOINT=false
```

```bash
php artisan media:disks:initialize movies
php artisan media:disks:check
php artisan media:disks:check --json
```

Initialization creates only `.media-upload-manager/disk.json` and `.media-upload-manager/incoming/` inside the chosen root. It does not scan, import, rename, or modify existing movies. See the [configuration guide](docs/configuration.md#4-disk-definitions) for production Linux examples and the full validation contract.

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

## Documentation

- [Product specification](docs/product-spec.md) — scope, user journeys, requirements, and acceptance criteria
- [Architecture](docs/architecture.md) — components, data model, interfaces, security, and upload lifecycle
- [Configuration](docs/configuration.md) — environment contract, disk definitions, Herd, Docker, and operational warnings
- [Backlog](docs/backlog.md) — ordered implementation tickets and verification plan

## Key constraints

- The browser uploads through Nginx directly to `tusd`; PHP never receives movie bytes or assembles chunks.
- Production chunks are 64 MiB. This remains below Cloudflare's documented 100 MB Free/Pro maximum request size, while the [tus protocol](https://tus.io/protocols/resumable-upload) supplies resumability. See Cloudflare's [413 guidance](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/4xx-client-error/error-413/) and the [tusd reverse-proxy guidance](https://tus.github.io/tusd/getting-started/configuration/#proxies).
- A movie file must use one of these v1 container extensions: `mkv`, `mp4`, `m4v`, `avi`, `mov`, `ts`, `m2ts`, or `webm`.
- Automatic identification parses a filename and ranks TMDB candidates. It is not content-fingerprint recognition, and the user must confirm the movie before uploading.
- Upload sessions expire after seven days without activity. Resuming in a reopened browser requires reselecting the same file and matching its metadata plus first/last 1 MiB hashes.
- Capacity decisions reserve space for active uploads and retain a per-disk safety margin of 20 GiB by default.

## Scope boundary

Version 1 handles one movie file per upload and one application-managed current primary per movie. Series, batch episode uploads, subtitles, multiple versions, general moving/deletion, automatic or continuous NAS scanning, video-content fingerprint recognition, two-factor authentication, and Cloudflare Access are deferred. MUM-011 adds only an explicitly confirmed replacement of the tracked current primary; MUM-012 later adds operator-driven discovery and reconciliation without renaming or deleting operator-managed files.

Implementation must proceed in the order described in [the backlog](docs/backlog.md). The next ticket is the Jellyfin path builder in MUM-006.
