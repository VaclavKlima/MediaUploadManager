# Configuration

## 1. Principles

Configuration is environment-driven and secret-free in source control. Stable disk IDs are part of persisted domain identity; changing a label is harmless, while changing an ID makes the configuration describe a different disk.

The Laravel foundation implements the application/database defaults described below, MUM-003 implements movie-root disk health, MUM-007 implements movie admission, MUM-008/MUM-009 implement the protected tus transport and browser uploader, and MUM-014 assembles the hardened production topology. MUM-017 extends each stable physical disk with separate Movie and Series roots while preserving the existing movie-only environment contract.

## 2. Application and database

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `APP_NAME` | no | `Media Upload Manager` | Display/application name |
| `APP_ENV` | yes | `production` | Runtime environment |
| `APP_KEY` | yes | generated secret | Laravel encryption/signing key |
| `APP_URL` | yes | `https://media.example.com` | Canonical public origin |
| `APP_DEBUG` | no | `false` | Must be false in production |
| `APP_TIMEZONE` | no | `UTC` | Application timestamp presentation |
| `DB_CONNECTION` | no | `mysql` | Production database driver |
| `DB_HOST` | no | `mysql` | Private Compose service name |
| `DB_PORT` | no | `3306` | Private MySQL port; never host-published |
| `DB_DATABASE` | yes | `media_upload_manager` | MySQL application database |
| `DB_USERNAME` | yes | `media_upload_manager` | Restricted application user |
| `DB_PASSWORD` | yes | runtime secret | Restricted application-user password |
| `QUEUE_CONNECTION` | no | `database` | Queue backend |
| `CACHE_STORE` | no | `database` | Cache backend suitable for the small deployment |
| `SESSION_DRIVER` | no | `database` | Persistent authenticated sessions |
| `SESSION_SECURE_COOKIE` | yes in production | `true` | HTTPS-only session cookies |
| `SESSION_SAME_SITE` | no | `lax` | Same-origin application session policy |
| `LOG_CHANNEL` | no | `stderr` | Container logging destination |

MySQL 8.4 uses the Compose `mysql-data` named volume and is reachable only on the private Compose network. Root and application passwords are runtime-only values. The one-shot `migrate` service waits for MySQL readiness, applies migrations, and verifies every configured media disk before long-running services start.

## 3. TMDB

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `TMDB_API_TOKEN` | yes | secret read-access token | Server-side TMDB authentication |
| `TMDB_LANGUAGE` | no | `en-US` | Search/detail response language |
| `TMDB_API_BASE_URL` | no | `https://api.themoviedb.org/3` | Override only for tests/controlled proxying |
| `TMDB_CACHE_TTL_SECONDS` | no | `86400` | Detail/search cache policy |
| `TMDB_TIMEOUT_SECONDS` | no | `10` | Per-request timeout |

Never expose `TMDB_API_TOKEN` through Vite variables, Inertia props, browser logs, or client JSON. The same settings govern Movie and TV search/detail, season/episode hydration, and external-ID requests; Series does not require a second TMDB credential. The interface must show required TMDB attribution. Consult TMDB's [finding-data documentation](https://developer.themoviedb.org/docs/finding-data) and [FAQ/terms summary](https://developer.themoviedb.org/docs/faq) before release.

## 4. Disk definitions

### Contract

`MEDIA_DISKS` is a comma-separated ordered list of stable physical-disk IDs. An ID must match `^[a-z][a-z0-9_]*$`. For each ID, uppercase it to form its environment suffix. A disk may expose a Movie root, a Series root, or both:

```dotenv
MEDIA_DISKS=nas_a,nas_b,nas_c

MEDIA_DISK_NAS_A_LABEL="NAS A"
MEDIA_DISK_NAS_A_MOVIES_PATH=/mnt/media/nas-a-movies
MEDIA_DISK_NAS_A_SERIES_PATH=/mnt/media/nas-a-series
MEDIA_DISK_NAS_A_RESERVE_GIB=20

MEDIA_DISK_NAS_B_LABEL="NAS B"
MEDIA_DISK_NAS_B_MOVIES_PATH=/mnt/media/nas-b-movies
MEDIA_DISK_NAS_B_SERIES_PATH=/mnt/media/nas-b-series
MEDIA_DISK_NAS_B_RESERVE_GIB=20

MEDIA_DISK_NAS_C_LABEL="NAS C"
MEDIA_DISK_NAS_C_PATH=/mnt/media/nas-c-movies
MEDIA_DISK_NAS_C_SERIES_PATH=/mnt/media/nas-c-series
MEDIA_DISK_NAS_C_RESERVE_GIB=40
MEDIA_DEFAULT_RESERVE_GIB=20
MEDIA_REQUIRE_MOUNTPOINT=true
```

| Variable | Required | Rules |
| --- | --- | --- |
| `MEDIA_DISKS` | production | At least one unique stable ID in production; may be empty during local setup; order is display tie-break only |
| `MEDIA_DISK_<ID>_LABEL` | yes | Human-readable physical-disk label; may change without changing disk identity |
| `MEDIA_DISK_<ID>_MOVIES_PATH` | conditionally | Movie root; unique absolute path, never `/`, identical in every media-accessing service |
| `MEDIA_DISK_<ID>_SERIES_PATH` | conditionally | Series root; unique absolute path, never `/`, identical in every media-accessing service |
| `MEDIA_DISK_<ID>_PATH` | no | Backward-compatible alias for `MEDIA_DISK_<ID>_MOVIES_PATH`; if both are set they must resolve identically |
| `MEDIA_DISK_<ID>_RESERVE_GIB` | no | Nonnegative number; defaults to `MEDIA_DEFAULT_RESERVE_GIB` |
| `MEDIA_DEFAULT_RESERVE_GIB` | no | `20` | Default capacity safety margin per disk |
| `MEDIA_REQUIRE_MOUNTPOINT` | no | `true` in production, `false` otherwise | Require the resolved root to be an exact Linux mount point |

Each configured disk must define at least one root. GiB means `1,073,741,824` bytes. Parse values strictly and fail startup/config validation on duplicate IDs, invalid numbers, missing roots, an alias mismatch, duplicate/resolved paths across any disk or kind, nested movie/series roots, or unsafe roots.

Each root has its own private staging directory; neither path is configurable per request:

```text
<movies-path>/.media-upload-manager/incoming/
<series-path>/.media-upload-manager/incoming/
```

The application also owns `<root>/.media-upload-manager/disk.json`, a versioned, kind-aware marker containing the configured stable disk ID and root kind (`movies` or `series`). A missing, malformed, legacy-incompatible, wrong-kind, or mismatched marker fails closed. The Movie and Series markers for one disk share the stable ID but are never interchangeable. No other directory beneath a root is adopted or inspected by health initialization.

Only trusted configuration chooses roots. Client input may select a known disk ID and product workflow but can never supply a root kind or mount path. A series stores the selected stable home disk ID after its first admission or import and may not switch roots later.

Capacity is physical-disk scoped, not root scoped:

```text
active_remaining(<ID>) = movie remaining bytes + series remaining bytes
projected_usable(<ID>, proposed) = free bytes - reserve bytes
                                    - active_remaining(<ID>) - proposed bytes
```

Both roots for one ID must report the same physical filesystem identity and compatible free-space values. The safety reserve is subtracted once per disk ID. A mismatch makes both roots ineligible rather than double-counting capacity or treating them as separate disks.

### Mount requirements

- Each configured Movie or Series path must be an explicit read/write bind mount in production.
- All services that inspect or mutate media must see each root at exactly the same absolute container path.
- The primary service UID/GID plus the supplemental media group need traverse, read, create, hard-link, unlink, and atomic-replacement permissions as appropriate.
- Mount ownership and NAS credentials are host/operator concerns; do not make containers recursively change NAS ownership.
- Configure container mount propagation and startup ordering so a missing host mount cannot be mistaken for a writable empty local directory.
- A health check must verify each root's kind-aware marker, expected filesystem/mount identity, and a controlled write/rename/hard-link/unlink probe before making that root selectable. It must also verify that two roots sharing an ID resolve to the same physical device/capacity source.

Linux mount validation reads [`/proc/self/mountinfo`](https://www.kernel.org/doc/html/v6.15/filesystems/proc.html), decodes its escaped path fields, and requires an exact match for every resolved configured root in the application's mount namespace. A parent mount is not sufficient. Operators may expose sibling Movie and Series directories from one physical NAS filesystem as separate explicit bind mounts; the device/capacity check still proves they share the configured disk ID. This prevents a missing mount from silently becoming a writable directory on the container layer. macOS development may set `MEDIA_REQUIRE_MOUNTPOINT=false` and use ordinary absolute directories; marker, symlink, permission, probe, same-device, and capacity checks still apply. Capacity comes from PHP's native [`disk_total_space`](https://www.php.net/disk-total-space) and [`disk_free_space`](https://www.php.net/disk-free-space) functions against the verified roots.

### Production process and media groups

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `APP_UID` | production | `1000` | Primary numeric UID for application processes |
| `APP_GID` | production | `1000` | Primary numeric GID for application processes and writable application state |
| `MEDIA_GID` | no | value of `APP_GID` | Supplemental numeric NAS media group for the five media-accessing services |

`APP_UID:APP_GID` remains the primary process identity. Compose adds `MEDIA_GID` with `group_add` only to `migrate`, `app`, `worker`, `scheduler`, and `tusd`; Nginx, MySQL, Pulse, `cloudflared`, and volume initialization do not receive it. Set `MEDIA_GID` to the numeric group that owns or is permitted to modify legacy movie and series files. When no separate NAS group is needed, omitting it makes the supplemental group default to `APP_GID`.

Supplemental membership does not replace ordinary directory and file modes. The media group must be able to traverse source and destination parents, write where canonical directories/files are created, and unlink confirmed source names. Linux `fs.protected_hardlinks=1` must remain enabled. Do not lower or disable it to make an import pass; correct `MEDIA_GID` and the NAS group permissions, recreate the five media-accessing services, and retry through the administrator workflow.

### Development and production examples

Ordinary macOS development folder:

```dotenv
MEDIA_DISKS=local
MEDIA_REQUIRE_MOUNTPOINT=false
MEDIA_DISK_LOCAL_LABEL="Local Media"
MEDIA_DISK_LOCAL_MOVIES_PATH="/Users/your-name/Movies/Jellyfin Movies"
MEDIA_DISK_LOCAL_SERIES_PATH="/Users/your-name/Movies/Jellyfin Series"
MEDIA_DISK_LOCAL_RESERVE_GIB=5
```

Exact Linux production mounts:

```dotenv
MEDIA_DISKS=nas_a,nas_b,nas_c
MEDIA_REQUIRE_MOUNTPOINT=true
MEDIA_DEFAULT_RESERVE_GIB=20

MEDIA_DISK_NAS_A_LABEL="NAS A"
MEDIA_DISK_NAS_A_MOVIES_PATH=/mnt/media/nas-a-movies
MEDIA_DISK_NAS_A_SERIES_PATH=/mnt/media/nas-a-series

MEDIA_DISK_NAS_B_LABEL="NAS B"
MEDIA_DISK_NAS_B_MOVIES_PATH=/mnt/media/nas-b-movies
MEDIA_DISK_NAS_B_SERIES_PATH=/mnt/media/nas-b-series

MEDIA_DISK_NAS_C_LABEL="NAS C"
MEDIA_DISK_NAS_C_MOVIES_PATH=/mnt/media/nas-c-movies
MEDIA_DISK_NAS_C_SERIES_PATH=/mnt/media/nas-c-series
MEDIA_DISK_NAS_C_RESERVE_GIB=40
```

Every configured root must already exist. Adopt each root explicitly with its kind and verify all configured roots:

```bash
php artisan media:disks:initialize nas_a --kind=movies
php artisan media:disks:initialize nas_a --kind=series
php artisan media:disks:check
php artisan media:disks:check --json
```

Omitting `--kind` continues to initialize the Movie root for backward compatibility. The command shows the selected label, root kind, and path before confirmation. It creates only that root's hidden marker and incoming directory, is idempotent for a matching marker, and never overwrites a malformed, wrong-kind, or mismatched marker. Matching version-1 markers remain healthy for Movie roots and are atomically upgraded to version 2 on the next explicit Movie initialization; Series roots reject version-1 markers. The health command checks every configured root by default, accepts `--kind=movies|series` as a filter, and exits nonzero for invalid configuration or any unhealthy root in scope. Its JSON form omits absolute paths and identifies both disk ID and root kind; authenticated Movie disk responses remain Movie-only and unchanged.

Compose will include three explicit physical-disk slots with separate Movie and Series bind mounts. Add more disks or omit unused root mounts in a local Compose override; do not bake site-specific NAS paths into the base image.

## 5. Upload and tus settings

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `UPLOAD_TUS_PUBLIC_PATH` | no | `/uploads/tus/` | Same-origin public endpoint returned with a reservation |
| `TUS_INTERNAL_URL` | production | `http://tusd:1080/uploads/tus/` | Trusted private tusd URL for bounded HEAD/DELETE reconciliation |
| `TUS_HOOK_URL` | deployment | `http://nginx:8081/internal/tus/hooks` | Private listener that injects the hook secret |
| `TUS_HOOK_SECRET` | yes | random secret | Authenticates `tusd` to Laravel; distinct from app key |
| `UPLOAD_INTERNAL_CONNECT_TIMEOUT_SECONDS` | no | `2` | Internal tus connection timeout |
| `UPLOAD_INTERNAL_TIMEOUT_SECONDS` | no | `5` | Total bounded internal tus request timeout |
| `UPLOAD_TOKEN_TTL_SECONDS` | no | `900` | Lifetime of the scoped tus authorization token |
| `UPLOAD_TOKEN_REFRESH_LEEWAY_SECONDS` | no | `60` | Refresh authorization this long before expiry |
| `UPLOAD_INACTIVITY_SECONDS` | no | `604800` | Expiry window since last confirmed activity |
| `UPLOAD_CHUNK_SIZE_BYTES` | no | `67108864` | Browser chunk size; production invariant is 64 MiB |
| `UPLOAD_RETRY_DELAYS_MS` | no | `0,3000,5000,10000,20000` | Ordered `tus-js-client` retry delays |
| `UPLOAD_FINGERPRINT_WINDOW_BYTES` | no | `1048576` | First/last hash window; changing invalidates resume matching |
| `FFPROBE_BINARY` | no | `/usr/bin/ffprobe` | Validator executable |
| `FFPROBE_TIMEOUT_SECONDS` | no | `120` | Hard validation timeout |
| `FFPROBE_MAX_OUTPUT_BYTES` | no | `1048576` | Maximum accepted bounded probe JSON |
| `FFPROBE_MAX_STREAMS` | no | `64` | Maximum stream records accepted from one file |
| `TUS_METADATA_PATH` | production | `/var/lib/tusd` | Persistent tus `.info` volume mounted read/write in tusd, app, and worker containers |
| `UPLOAD_PROCESSING_JOB_TIMEOUT_SECONDS` | no | `180` | Finalization job timeout; must exceed the probe timeout |
| `UPLOAD_PROCESSING_JOB_UNIQUE_SECONDS` | no | `3600` | Per-upload unique queue lock lifetime |
| `UPLOAD_PROCESSING_JOB_BACKOFF_SECONDS` | no | `15,60,180` | Bounded transient processing retries |
| `UPLOAD_PROCESSING_POLL_INTERVAL_MS` | no | `1500` | Client validation-status polling interval, bounded to 500–10000 ms |
| `DB_QUEUE_RETRY_AFTER` | no | `240` | Database queue retry window; must exceed the processing job timeout |

Production should treat chunk size and fingerprint window changes as migrations/compatibility decisions, not casual tuning. A 64 MiB request is below Cloudflare's documented 100 MB Free/Pro request maximum; see [Cloudflare's 413 guidance](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/4xx-client-error/error-413/). `tusd` and Nginx must follow the official [proxy guidance](https://tus.github.io/tusd/getting-started/configuration/#proxies), including forwarded headers and an externally correct base path.

The Nginx location for `UPLOAD_TUS_PUBLIC_PATH` must authorize every tus method with a bodyless internal Laravel subrequest, then disable request buffering and proxy buffering on the `tusd` proxy. Do not set a body limit below the chunk size plus protocol overhead. The authorization subrequest may reach PHP; the media request and its body must never do so. Series batches reuse these settings and enforce sequential transfers in application/client orchestration rather than with a second tus endpoint. The pinned reusable fragments are [`deploy/tus/docker-compose.fragment.yml`](../deploy/tus/docker-compose.fragment.yml), [`deploy/nginx/tus-public.location.conf`](../deploy/nginx/tus-public.location.conf), and [`deploy/nginx/tus-hooks.server.conf.template`](../deploy/nginx/tus-hooks.server.conf.template).

## 6. Proxy, URL, and Cloudflare settings

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `TRUSTED_PROXIES` | yes | explicit proxy CIDRs/services | Laravel forwarded-header trust boundary |
| `TRUSTED_HOSTS` | yes | `media.example.com` | Accepted public hosts |
| `CLOUDFLARE_TUNNEL_TOKEN` | production | secret | Authenticates `cloudflared` tunnel |
| `ASSET_URL` | no | same origin | Asset base; same-origin is preferred |

Set `APP_URL` to the public HTTPS origin. Forward the original host and scheme through Cloudflare and Nginx, and trust only known proxies. Cookies must be secure in production. The tus `Location` URL returned to the browser must remain on the same HTTPS origin to avoid CORS and mixed-content failures.

Do not commit the tunnel token, place it in Compose YAML, print it in diagnostics, or reuse it as a hook/upload secret. Prefer the deployment platform's secret mechanism.

## 7. First administrator and private users

Interactive `composer setup` ends with `admin:bootstrap`, which collects the first administrator's real name and email. The command creates an account only when the user table is empty. For unattended setup, invoke it directly:

```bash
php artisan admin:bootstrap --name="Ada Lovelace" --email="ada@example.com" --no-interaction
```

There is intentionally no environment variable or command option for a plaintext password. The application generates a 32-character high-entropy one-time password, stores only its hash, displays the credential exactly once in the invoking terminal, records a credential-free structured audit event, and forces password replacement at first login.

Operators must capture the terminal output securely. The one-time password is never written to application logs or delivered by email. If it is lost, run `php artisan admin:recover` interactively, or use `--email`, `--enable`, `--force`, and `--no-interaction` for a controlled scripted recovery. Recovery revokes sessions and remember tokens, preserves disabled status unless `--enable` is selected, and prints the replacement once. Re-running bootstrap never rotates or reprints a credential.

Public registration remains disabled. Beta.2 provides administrator bootstrap and CLI recovery, but the administrator web workflows to create, reset, disable, and enable private users remain MUM-013 work. Those workflows will issue forced-change one-time credentials; email delivery is not a v1 prerequisite.

## 8. Local development with Laravel Herd

The secured application site runs at `https://media-upload-manager.test`, isolated to PHP 8.5 and Node 24. Local `.env` uses a dedicated MySQL development database, database queue/cache/session drivers, secure cookies, and logged mail. The application has no seeded users; setup creates the administrator through the guided terminal form.

For a fresh clone:

```bash
composer setup
herd link media-upload-manager --secure --isolate=8.5 --update-env
herd isolate-node 24 --site=media-upload-manager
```

The intended macOS flow is:

1. Serve the Laravel application with Herd at its normal secured local hostname.
2. Run pinned `tusd` 2.10.0 on loopback port `1080` with `-base-path=/uploads/tus/`, downloads/CORS disabled, termination enabled, local metadata storage, the documented hook events, and `-hooks-http=http://127.0.0.1:1081/internal/tus/hooks`.
3. Add [`deploy/herd/tus-site.location.conf.example`](../deploy/herd/tus-site.location.conf.example) to the secured site and load [`deploy/herd/tus-hooks.server.conf.example`](../deploy/herd/tus-hooks.server.conf.example) as a separate loopback-only Nginx server after replacing its secret placeholder locally.
4. Set `TUS_INTERNAL_URL=http://127.0.0.1:1080/uploads/tus/`, keep `UPLOAD_TUS_PUBLIC_PATH=/uploads/tus/`, and confirm `Location` responses use `https://media-upload-manager.test/uploads/tus/...`.
5. Keep development disk roots outside any real Jellyfin library.

Herd documents local proxy sites and site-level server customization in its [CLI advanced-usage documentation](https://herd.laravel.com/docs/macos/advanced-usage/herd-cli). Restart Herd after installing the site-specific and loopback hook-listener fragments.

Install the local validator with `brew install ffmpeg`, set `FFPROBE_BINARY` to the resulting absolute `ffprobe` path, and use `composer upload:dev` to prepare and run the complete local process set. Using `http://localhost` for tus from an HTTPS Herd page will be blocked as mixed content. Do not disable browser security to work around it.

## 9. Production Compose contract

The committed production stack provides:

- immutable application/Nginx images with compatible PHP 8.5 and `ffprobe`;
- `app`, `nginx`, `worker`, `scheduler`, official `tusd`, and `cloudflared` services;
- named volumes for MySQL and tus metadata, with accepted loss if those volumes are destroyed;
- three explicit physical-disk examples with separate read/write Movie and Series bind mounts shared consistently;
- `MEDIA_GID` as a supplemental group on only the five media-accessing services, defaulting to `APP_GID` without changing their primary identity;
- health checks and restart policies that do not hide an absent NAS mount;
- no host-published ports; Cloudflare Tunnel reaches Nginx over the private Compose network;
- a manual migration/preflight/deployment process; and
- a Compose override pattern for additional disks and site-specific paths.

Pin image versions/digests during implementation. Never use an unreviewed floating tag for the production data path.

## 10. Operational warnings

Before accepting uploads, operators must understand these failure modes:

- **A missing NAS mount can look like an empty local directory.** Fail closed per root using marker and mount-identity checks; otherwise a movie or episode may fill the container host disk.
- **Docker volume loss is accepted beta data loss.** There is no backup or restoration workflow for MySQL or tus metadata in this release.
- **Reservations are safety accounting, not filesystem quotas.** External writers can consume free space after a session or batch is admitted; aggregate both root kinds by disk ID and recheck throughout upload/finalization.
- **Exclusive hard-link promotion requires one filesystem.** Never stage in application storage or `/tmp`; unsupported/cross-filesystem links fail closed and never fall back to copy or overwrite.
- **Do not overwrite conflicts.** Manual files, stale directories, or duplicate database paths must stop ordinary finalization for review. MUM-011 covers only explicitly confirmed Movie primary replacement; MUM-019 applies the equivalent exact-primary rule to one Series episode. Neither workflow recursively deletes directories or touches Jellyfin/user-managed sidecars.
- **Incoming files are untrusted.** Do not execute them; run `ffprobe` with bounded resources and keep the staging tree outside the web root.
- **Proxy buffering defeats the architecture.** Verify the effective Nginx configuration and observe a real transfer before release.
- **Tokens are credentials.** Redact application, tus, TMDB, hook, and Cloudflare tokens from logs and support bundles.
- **Seven-day expiry needs careful cleanup.** Expire state and release reservations idempotently; delete partial data only after reconciling current tus activity.
- **NAS scanning is not application-state restoration.** Separate administrator-triggered scans may rediscover surviving movies or episodes, but they cannot restore users, upload history, batches, sessions, or Pulse state.

## 11. Release-time configuration checks

- Configuration parses with no duplicate IDs or paths.
- Every configured Movie and Series root is an explicit expected mount with a matching kind-aware marker and passes write/hard-link/unlink/free-space checks.
- Roots sharing a stable disk ID report the same physical filesystem/capacity source; reservations and the safety reserve aggregate once across both kinds.
- `migrate`, application, worker, scheduler, and `tusd` use the same disk paths and receive the intended supplemental `MEDIA_GID`; all application processes retain `APP_UID:APP_GID` as their primary identity.
- Linux `fs.protected_hardlinks` remains `1`.
- MySQL and tus metadata survive ordinary container recreation; deliberate named-volume loss is accepted and unrecoverable in this beta.
- `APP_URL`, trusted hosts/proxies, cookies, and tus `Location` all resolve to HTTPS.
- A 64 MiB PATCH reaches `tusd` with request buffering disabled.
- `tusd` rejects missing, invalid, expired, wrong-upload, and wrong-size authorization.
- Internal hooks are unreachable publicly and reject the wrong secret.
- `ffprobe` is present and bounded by timeout/resource policy.
- Logs and error responses contain no secrets or unnecessary absolute filesystem paths.
