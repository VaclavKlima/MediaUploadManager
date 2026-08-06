# Configuration

## 1. Principles

Configuration is environment-driven and secret-free in source control. Stable disk IDs are part of persisted domain identity; changing a label is harmless, while changing an ID makes the configuration describe a different disk.

The Laravel foundation implements the application/database defaults described below, and MUM-003 implements the disk variables and operational commands. Variables for later upload, TMDB, proxy, and deployment tickets remain an explicit future contract until their owning tickets are implemented.

## 2. Application and database

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `APP_NAME` | no | `Media Upload Manager` | Display/application name |
| `APP_ENV` | yes | `production` | Runtime environment |
| `APP_KEY` | yes | generated secret | Laravel encryption/signing key |
| `APP_URL` | yes | `https://media.example.com` | Canonical public origin |
| `APP_DEBUG` | no | `false` | Must be false in production |
| `APP_TIMEZONE` | no | `UTC` | Application timestamp presentation |
| `DB_CONNECTION` | no | `sqlite` | Version 1 database driver |
| `DB_DATABASE` | yes | `/var/lib/media-upload-manager/database.sqlite` | Persistent SQLite path |
| `QUEUE_CONNECTION` | no | `database` | Queue backend |
| `CACHE_STORE` | no | `database` | Cache backend suitable for the small deployment |
| `SESSION_DRIVER` | no | `database` | Persistent authenticated sessions |
| `SESSION_SECURE_COOKIE` | yes in production | `true` | HTTPS-only session cookies |
| `SESSION_SAME_SITE` | no | `lax` | Same-origin application session policy |
| `LOG_CHANNEL` | no | `stderr` | Container logging destination |

SQLite and Laravel storage must reside on a persistent local volume, not a NAS media mount and not an ephemeral container layer. Run a single migration process during deployment and ensure application/worker/scheduler processes share compatible filesystem permissions.

## 3. TMDB

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `TMDB_API_TOKEN` | yes | secret read-access token | Server-side TMDB authentication |
| `TMDB_LANGUAGE` | no | `en-US` | Search/detail response language |
| `TMDB_API_BASE_URL` | no | `https://api.themoviedb.org/3` | Override only for tests/controlled proxying |
| `TMDB_CACHE_TTL_SECONDS` | no | `86400` | Detail/search cache policy |
| `TMDB_TIMEOUT_SECONDS` | no | `10` | Per-request timeout |

Never expose `TMDB_API_TOKEN` through Vite variables, Inertia props, browser logs, or client JSON. The interface must show required TMDB attribution. Consult TMDB's [finding-data documentation](https://developer.themoviedb.org/docs/finding-data) and [FAQ/terms summary](https://developer.themoviedb.org/docs/faq) before release.

## 4. Disk definitions

### Contract

`MEDIA_DISKS` is a comma-separated ordered list of stable IDs. An ID must match `^[a-z][a-z0-9_]*$`. For each ID, uppercase it to form its environment suffix:

```dotenv
MEDIA_DISKS=nas_a,nas_b,nas_c

MEDIA_DISK_NAS_A_LABEL="Movies A"
MEDIA_DISK_NAS_A_PATH=/mnt/media/nas-a
MEDIA_DISK_NAS_A_RESERVE_GIB=20

MEDIA_DISK_NAS_B_LABEL="Movies B"
MEDIA_DISK_NAS_B_PATH=/mnt/media/nas-b
MEDIA_DISK_NAS_B_RESERVE_GIB=20

MEDIA_DISK_NAS_C_LABEL="Movies C"
MEDIA_DISK_NAS_C_PATH=/mnt/media/nas-c
MEDIA_DISK_NAS_C_RESERVE_GIB=40
MEDIA_DEFAULT_RESERVE_GIB=20
MEDIA_REQUIRE_MOUNTPOINT=true
```

| Variable | Required | Rules |
| --- | --- | --- |
| `MEDIA_DISKS` | production | At least one unique stable ID in production; may be empty during local setup; order is display tie-break only |
| `MEDIA_DISK_<ID>_LABEL` | yes | Human-readable; may change without changing disk identity |
| `MEDIA_DISK_<ID>_PATH` | yes | Unique absolute path; never `/`; identical path in app, worker, scheduler, and `tusd` containers |
| `MEDIA_DISK_<ID>_RESERVE_GIB` | no | Nonnegative number; defaults to `MEDIA_DEFAULT_RESERVE_GIB` |
| `MEDIA_DEFAULT_RESERVE_GIB` | no | `20` | Default capacity safety margin per disk |
| `MEDIA_REQUIRE_MOUNTPOINT` | no | `true` in production, `false` otherwise | Require the resolved root to be an exact Linux mount point |

GiB means `1,073,741,824` bytes. Parse values strictly and fail startup/config validation on duplicate IDs, invalid numbers, missing variables, duplicate/resolved paths, or unsafe roots.

The private staging directory is not configurable per request:

```text
<configured-path>/.media-upload-manager/incoming/
```

The application also owns `<configured-path>/.media-upload-manager/disk.json`, a versioned marker containing the configured stable disk ID. A missing, malformed, or mismatched marker fails closed. No other directory beneath the root is adopted or inspected by MUM-003.

Only trusted configuration chooses `<configured-path>`. Client input may select a known disk ID but can never supply a mount path.

### Mount requirements

- Each configured path must be an explicit read/write bind mount in production.
- All services that inspect or mutate media must see the disk at exactly the same absolute container path.
- The service UID/GID needs traverse, read, create, rename, and delete-sidecar permissions as appropriate.
- Mount ownership and NAS credentials are host/operator concerns; do not make containers recursively change NAS ownership.
- Configure container mount propagation and startup ordering so a missing host mount cannot be mistaken for a writable empty local directory.
- A health check must verify the expected filesystem/mount identity and a controlled write/rename probe before making a disk selectable.

Linux mount validation reads [`/proc/self/mountinfo`](https://www.kernel.org/doc/html/v6.15/filesystems/proc.html), decodes its escaped path fields, and requires an exact resolved-root match in the application's mount namespace. A parent mount is not sufficient. This also covers explicit container bind mounts and prevents a missing mount from silently becoming a writable directory on the container layer. macOS development may set `MEDIA_REQUIRE_MOUNTPOINT=false` and use an ordinary absolute directory; marker, symlink, permission, probe, and capacity checks still apply. Capacity comes from PHP's native [`disk_total_space`](https://www.php.net/disk-total-space) and [`disk_free_space`](https://www.php.net/disk-free-space) functions against the verified root.

### Development and production examples

Ordinary macOS development folder:

```dotenv
MEDIA_DISKS=movies
MEDIA_REQUIRE_MOUNTPOINT=false
MEDIA_DISK_MOVIES_LABEL="Local Movies"
MEDIA_DISK_MOVIES_PATH="/Users/your-name/Movies/Jellyfin"
MEDIA_DISK_MOVIES_RESERVE_GIB=5
```

Exact Linux production mounts:

```dotenv
MEDIA_DISKS=nas_a,nas_b,nas_c
MEDIA_REQUIRE_MOUNTPOINT=true
MEDIA_DEFAULT_RESERVE_GIB=20

MEDIA_DISK_NAS_A_LABEL="Movies A"
MEDIA_DISK_NAS_A_PATH=/mnt/media/nas-a

MEDIA_DISK_NAS_B_LABEL="Movies B"
MEDIA_DISK_NAS_B_PATH=/mnt/media/nas-b

MEDIA_DISK_NAS_C_LABEL="Movies C"
MEDIA_DISK_NAS_C_PATH=/mnt/media/nas-c
MEDIA_DISK_NAS_C_RESERVE_GIB=40
```

Every configured root must already exist. Adopt and verify each disk explicitly:

```bash
php artisan media:disks:initialize nas_a
php artisan media:disks:check
php artisan media:disks:check --json
```

Initialization shows the selected label and root before confirmation. It creates only the hidden marker and incoming directory, is idempotent for a matching marker, and never overwrites a malformed or mismatched marker. The health command exits nonzero for invalid configuration or any unhealthy disk. Its JSON form and authenticated `GET /disks` response omit absolute paths and expose only allowlisted reason codes and messages.

Compose will include three explicit example slots. Add more disks in a local Compose override; do not bake site-specific NAS paths into the base image.

## 5. Upload and tus settings

| Variable | Required | Default/example | Purpose |
| --- | --- | --- | --- |
| `TUS_PUBLIC_PATH` | no | `/uploads/tus/` | Same-origin browser path routed by Nginx |
| `TUS_INTERNAL_URL` | yes | `http://tusd:1080/files/` | Trusted service URL for server reconciliation |
| `TUS_HOOK_URL` | yes | `http://nginx-internal/internal/tus/hooks` | Private service-authenticated hook target |
| `TUS_HOOK_SECRET` | yes | random secret | Authenticates `tusd` to Laravel; distinct from app key |
| `UPLOAD_TOKEN_TTL_MINUTES` | no | `15` | Lifetime of a scoped tus/resume token |
| `UPLOAD_INACTIVITY_DAYS` | no | `7` | Expiry window since last confirmed activity |
| `UPLOAD_CHUNK_SIZE_BYTES` | no | `67108864` | Browser chunk size; production invariant is 64 MiB |
| `UPLOAD_RETRY_DELAYS_MS` | no | `0,3000,5000,10000,20000` | Ordered `tus-js-client` retry delays |
| `UPLOAD_FINGERPRINT_WINDOW_BYTES` | no | `1048576` | First/last hash window; changing invalidates resume matching |
| `FFPROBE_BINARY` | no | `/usr/bin/ffprobe` | Validator executable |
| `FFPROBE_TIMEOUT_SECONDS` | no | `120` | Hard validation timeout |

Production should treat chunk size and fingerprint window changes as migrations/compatibility decisions, not casual tuning. A 64 MiB request is below Cloudflare's documented 100 MB Free/Pro request maximum; see [Cloudflare's 413 guidance](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/4xx-client-error/error-413/). `tusd` and Nginx must follow the official [proxy guidance](https://tus.github.io/tusd/getting-started/configuration/#proxies), including forwarded headers and an externally correct base path.

The Nginx location for `TUS_PUBLIC_PATH` must authorize every tus method with a bodyless internal Laravel subrequest, then disable request buffering and proxy buffering on the `tusd` proxy. Do not set a body limit below the chunk size plus protocol overhead. The authorization subrequest may reach PHP; the movie request and its body must never do so.

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

Public registration remains disabled. Private users are created by an administrator and receive forced-change one-time credentials. Email delivery is not a v1 prerequisite.

## 8. Local development with Laravel Herd

The secured application site runs at `https://media-upload-manager.test`, isolated to PHP 8.5 and Node 24. Local `.env` uses SQLite, database queue/cache/session drivers, secure cookies, and logged mail. The application has no seeded users; setup creates the administrator through the guided terminal form.

For a fresh clone:

```bash
composer setup
herd link media-upload-manager --secure --isolate=8.5 --update-env
herd isolate-node 24 --site=media-upload-manager
```

The intended macOS flow is:

1. Serve the Laravel application with Herd at its normal secured local hostname.
2. Run `tusd` locally (binary or container) with a development disk root and authenticated hooks back to Laravel.
3. Configure a separate secured Herd proxy subdomain that forwards to the local `tusd` listener, or add a site-specific Nginx location that mirrors production routing.
4. Set the browser tus endpoint to the resulting HTTPS URL/path and confirm `Location` responses also use HTTPS.
5. Keep development disk roots outside any real Jellyfin library.

Herd documents local proxy sites and site-level server customization in its [CLI advanced-usage documentation](https://herd.laravel.com/docs/macos/advanced-usage/herd-cli). Exact commands will be added with the MUM-008 implementation because they depend on the committed sidecar ports and Nginx template.

Using `http://localhost` for tus from an HTTPS Herd page will be blocked as mixed content. Do not disable browser security to work around it.

## 9. Production Compose contract

The later deployment ticket must provide:

- immutable application/Nginx images with compatible PHP 8.5 and `ffprobe`;
- `app`, `nginx`, `worker`, `scheduler`, official `tusd`, and `cloudflared` services;
- one persistent volume for SQLite and application storage with a documented backup path;
- three explicit read/write NAS bind-mount examples shared consistently;
- health checks and restart policies that do not hide an absent NAS mount;
- no public port for `tusd` or internal hooks; only Nginx exposes application traffic;
- a controlled migration/deployment process; and
- a Compose override pattern for additional disks and site-specific paths.

Pin image versions/digests during implementation. Never use an unreviewed floating tag for the production data path.

## 10. Operational warnings

Before accepting uploads, operators must understand these failure modes:

- **A missing NAS mount can look like an empty local directory.** Fail closed using mount-identity checks; otherwise a movie may fill the container host disk.
- **SQLite needs consistent persistence and backups.** Copying a live database file without the proper SQLite backup/checkpoint procedure can be inconsistent.
- **Reservations are safety accounting, not filesystem quotas.** External writers can consume free space after a session is admitted; recheck throughout upload/finalization and surface disk-full failures.
- **Atomic rename requires one filesystem.** Never stage in application storage or `/tmp`; cross-filesystem moves can copy and are not atomic.
- **Do not overwrite conflicts.** Manual files, stale directories, or duplicate database paths must stop ordinary finalization for review. MUM-011 is the sole exception and may replace only the explicitly confirmed application-tracked current primary after full validation; it never recursively deletes the movie directory or touches Jellyfin/user-managed sidecars.
- **Incoming files are untrusted.** Do not execute them; run `ffprobe` with bounded resources and keep the staging tree outside the web root.
- **Proxy buffering defeats the architecture.** Verify the effective Nginx configuration and observe a real transfer before release.
- **Tokens are credentials.** Redact application, tus, TMDB, hook, and Cloudflare tokens from logs and support bundles.
- **Seven-day expiry needs careful cleanup.** Expire state and release reservations idempotently; delete partial data only after reconciling current tus activity.
- **Backups have two scopes.** Application state and NAS media need separate, tested recovery plans.

## 11. Release-time configuration checks

- Configuration parses with no duplicate IDs or paths.
- Every disk root is an explicit expected mount and passes write/rename/free-space checks.
- Application, worker, scheduler, and `tusd` use the same disk paths and UID/GID access.
- SQLite/application data survive container recreation and have a tested backup/restore.
- `APP_URL`, trusted hosts/proxies, cookies, and tus `Location` all resolve to HTTPS.
- A 64 MiB PATCH reaches `tusd` with request buffering disabled.
- `tusd` rejects missing, invalid, expired, wrong-upload, and wrong-size authorization.
- Internal hooks are unreachable publicly and reject the wrong secret.
- `ffprobe` is present and bounded by timeout/resource policy.
- Logs and error responses contain no secrets or unnecessary absolute filesystem paths.
