# Ordered implementation backlog

Tickets are intentionally ordered. A ticket may refine implementation details but must preserve the contracts in the [product specification](product-spec.md), [architecture](architecture.md), and [configuration guide](configuration.md), or update those documents explicitly in the same change.

## MUM-000 — Documentation baseline

**Outcome:** establish the product, architecture, environment, operational, testing, and delivery contracts before framework code exists.

**Deliverables**

- Root `README.md`
- `docs/product-spec.md`
- `docs/architecture.md`
- `docs/configuration.md`
- `docs/backlog.md`
- Architecture flow, environment contract, operational warnings, cited external constraints, ordered backlog, and acceptance plan

**Acceptance**

- All five documents agree on the upload path, statuses, supported containers, chunk/fingerprint sizes, expiry, capacity formula, and deferred scope.
- External claims link to primary Jellyfin, Laravel, Cloudflare, tus/tusd, TMDB, and Herd documentation.
- No Laravel application or dependency scaffold is introduced.

## MUM-001 — Laravel foundation

**Status:** Complete.

**Outcome:** create the authenticated application shell on the target stack.

**Scope**

- Scaffold Laravel 13 for PHP 8.5 using Laravel's [official Vue starter kit](https://laravel.com/docs/13.x/starter-kits).
- Configure Inertia 3, Vue 3, TypeScript, Tailwind CSS 4, SQLite, and Pest.
- Keep Laravel/Fortify authentication; disable/remove registration.
- Remove unused starter pages and establish an authenticated responsive layout.
- Add baseline lint, type-check, unit/feature test, and build commands.
- Do not install Filament.

**Acceptance**

- A migrated SQLite database supports login/logout but no public sign-up.
- Guest and authenticated route behavior is feature-tested.
- Frontend type-check/build and the Pest baseline pass.

**Depends on:** MUM-000.

## MUM-002 — Secure first-login onboarding

**Status:** Complete.

**Outcome:** make an unconfigured private deployment recoverably secure.

**Scope**

- Add administrator, forced-credential-change, and disabled-user fields.
- Idempotently collect and create the administrator's real name and email only on a truly empty user store.
- Generate and print a random one-time password exactly once; store only its hash.
- Force first-login password replacement through middleware.
- Rate-limit login and credential flows.
- Add an audited CLI recovery command.

**Acceptance**

- Repeated boots neither create duplicates nor reprint/rotate the credential.
- A bootstrapped or reset user cannot access the app before completing changes.
- Disabled users cannot authenticate; recovery is covered by feature tests.

**Depends on:** MUM-001.

## MUM-003 — Disk configuration and health

**Status:** Complete.

**Outcome:** represent arbitrary trusted disk roots safely and report their real health/capacity.

**Scope**

- Parse stable IDs, labels, absolute paths, and reserve thresholds from the documented environment contract.
- Validate uniqueness, paths, mount identity, permissions, and staging directory access.
- Centralize root-boundary, traversal, and symlink safety checks.
- Expose authenticated disk capacity/health DTOs with safe ineligibility reasons.
- Default reserve to 20 GiB.
- Explicitly exclude scanning, adopting, importing, renaming, or deleting existing Jellyfin library content.

**Acceptance**

- Missing, read-only, duplicated, root, symlink-escaping, and unexpected/unmounted paths fail closed.
- Tests use temporary disk roots and cover permissions/capacity conversion where the platform permits.
- Client responses never disclose unnecessary absolute host paths.

**Depends on:** MUM-002.

## MUM-004 — Media and upload domain model

**Status:** Complete.

**Outcome:** persist identity, physical files, resumable sessions, and lifecycle rules.

**Scope**

- Add `media_items`, `media_files`, and `uploads` migrations/models.
- Add the eight upload statuses as a backed enum.
- Implement relationships, factories, validation/value objects, unique constraints, and immutable fields.
- Implement authorized, idempotent state transitions and reservation-active semantics.

**Acceptance**

- Every allowed and forbidden transition is unit-tested.
- Disk/path uniqueness and duplicate TMDB identity behavior are deterministic.
- Tokens are represented only by hashes; sizes/offsets support large files safely.

**Depends on:** MUM-003.

## MUM-005 — TMDB integration and filename suggestions

**Outcome:** turn user input into ranked, confirmable movie identities without exposing credentials.

**Scope**

- Implement authenticated server-side search, TMDB detail, and IMDb find endpoints.
- Add DTO mapping, caching, bounded retries/timeouts, and stable safe errors.
- Parse release filenames for candidate title/year and rank TMDB matches.
- Add Vue search/results/confirmation and required TMDB attribution.

**Acceptance**

- Mocked upstream tests cover text, TMDB ID, IMDb ID, empty results, rate limits, timeouts, malformed responses, and cache behavior.
- Parser/ranker tests cover common release tokens, years, Unicode, and ambiguous titles.
- The UI never claims content fingerprinting and always requires confirmation.

**Depends on:** MUM-004.

## MUM-006 — Jellyfin path builder

**Status:** Complete.

**Outcome:** preview one deterministic, safe destination for a confirmed movie/source file.

**Scope**

- Generate canonical folder/file names with title, year, and TMDB ID.
- Accept only the v1 extension allowlist, preserving it in normalized lowercase.
- Sanitize forbidden/reserved characters while retaining safe Unicode.
- Detect database and filesystem conflicts without mutation or overwrite.
- Expose authenticated path preview.

**Acceptance**

- Unit tests cover every allowed/rejected extension, Unicode, control/reserved characters, trailing characters, empty results, traversal, long names, and normalization collisions.
- Preview and finalization call the same path-builder implementation.
- Existing directory/file/database targets block the upload clearly.

**Depends on:** MUM-005.

## MUM-007 — Capacity reservations and session creation

**Status:** Complete.

**Outcome:** admit an upload only when a selected disk can safely reserve it.

**Scope**

- Calculate free, reserve, active remaining, and projected usable bytes.
- Recommend the eligible disk with greatest projected usable capacity.
- Under a lock, recheck disk health, target conflicts, and capacity before creating a session.
- Persist the local-file fingerprint and immutable target.
- Issue/hash a short-lived token scoped to user, upload, disk, length, and operation.
- Allow user override only among eligible disks.

**Acceptance**

- Concurrent creation cannot overcommit according to reservation accounting.
- Missing, read-only, full, conflicting, and unsafe disks are rejected.
- Completed/cancelled/expired sessions release reservations; active sessions reserve only remaining bytes.
- Token scope and expiry tests cover cross-user/upload/disk misuse.

**Depends on:** MUM-006.

## MUM-008 — tusd transport and authorization

**Status:** Complete.

**Outcome:** stream resumable bytes straight to selected-disk staging through a protected same-origin route.

**Scope**

- Add a local official `tusd` sidecar/process configuration.
- Add production `tusd` service and Nginx `/uploads/tus/*` routing with buffering disabled.
- Authorize every `POST`/`PATCH`/`HEAD`/`DELETE` operation through a bodyless Laravel subrequest with the scoped upload token.
- Use the local filestore's supported pre-create `Storage.Path` override to map trusted upload identity to `<disk>/.media-upload-manager/incoming/<uuid>.part` without client path control; keep only small tus info/lock metadata on persistent local storage.
- Implement separately authenticated, idempotent create/progress/completion/termination hooks.
- Reconcile hook/database/tus offsets and document secured Herd proxy setup.

**Acceptance**

- Integration tests stream to three temporary roots and reject missing/expired/wrong-scope tokens.
- Nginx effective configuration proves request buffering is off and preserves externally correct HTTPS `Location` headers.
- A transfer does not enter PHP memory/storage and creates no second complete staging copy.
- Repeated/out-of-order hooks converge safely.

**Depends on:** MUM-007.

## MUM-009 — Resumable Vue uploader

**Status:** Complete.

**Outcome:** give users a responsive, recoverable large-file upload experience.

**Scope**

- Integrate `tus-js-client` with sequential 64 MiB chunks and retry delays `0/3/5/10/20s`.
- Compute metadata plus first/last 1 MiB hashes.
- Show progress, transferred bytes, speed, ETA, pause, retry, cancel, and reconnect state.
- Recover history from the server; require reselection and full fingerprint match after browser reopen.
- Refresh scoped tokens after authentication and resume from the server-confirmed offset.

**Acceptance**

- Browser tests cover normal upload, interruption, exact-offset resume, pause/cancel, token refresh, browser reopen, unchanged-file resume, and changed-file rejection.
- The full file is not hashed or retained in browser persistence.
- UI state reconciles from the server and remains accessible/responsive.

**Depends on:** MUM-008.

## MUM-010 — Validation and atomic finalization

**Status:** Complete.

**Outcome:** turn a completed untrusted stage file into one verified Jellyfin movie without overwrite/copy.

**Scope**

- Queue idempotent completion processing.
- Verify declared size/offset and run bounded `ffprobe` JSON validation.
- Require a video stream and store technical metadata.
- Recheck disk/path safety and conflicts immediately before finalization.
- Atomically create the final name with an exclusive same-filesystem hard link, unlink the staging name, and create the `media_files` record.
- Clean tus sidecar metadata only when recovery remains safe.

**Acceptance**

- Valid fixtures complete with exact paths/metadata; invalid/truncated/non-video fixtures fail visibly.
- Existing targets are never overwritten.
- Worker crashes/retries at each boundary converge without duplicate records or lost files.
- Tests demonstrate identical staging/final inodes and no second full-size copy.

**Depends on:** MUM-009.

## MUM-011 — Explicit current-primary replacement

**Status:** Complete.

**Outcome:** replace only the application-managed current primary after explicit confirmation and full validation.

**Scope**

- Require a movie with a tracked current primary and show the exact file that will become unrecoverable.
- Persist the replacement target and confirmation before upload admission.
- Fully upload and validate declared size and `ffprobe` results before any destructive operation.
- For same-disk/same-path replacement, atomically rename the new primary over the tracked old primary with no backup.
- For cross-disk replacement, finalize the new primary first, then delete only the old tracked primary.
- Preserve historical database rows and never recursively delete a movie directory or touch artwork, metadata, subtitles, trickplay, or other sidecars.

**Acceptance**

- No replacement session is admitted without explicit confirmation tied to the tracked current primary.
- Validation failure leaves the old primary untouched and current.
- Successful replacement switches the current relation exactly once and retains auditable old/new relationships.
- Same-path and cross-disk crash/retry tests converge without two current primaries or deletion of untracked files.

**Depends on:** MUM-010.

## MUM-011A — Tracked movie management and deletion

**Status:** Complete.

**Outcome:** list application-tracked movies and permanently delete an authorized movie graph plus its exact verified current primary.

**Scope**

- Provide a compact, responsive movie library with search, state filters, title/newest sorting, and pagination.
- Allow the current primary's upload owner or an administrator to delete; ownerless records are administrator-only, while an orphan with uploads may be deleted by a nonadministrator only when every related upload belongs to them.
- Require the exact displayed title before recording an irreversible deletion claim under the global admission lock.
- Reject active, processing, failed, physically inconsistent, offline, symlinked, or tus-residue-bearing graphs.
- Pin the exact current primary's disk, relative path, size, device, and inode before unlinking it; retry a persisted claim deterministically after a crash.
- Hard-purge the application's movie, upload, and media-file graph only after the exact claimed primary is absent.
- Never recursively delete, scan, or mutate artwork, NFO metadata, subtitles, extras, trickplay, or other operator-managed sidecars; remove only an empty obsolete movie directory.

**Acceptance**

- List serialization and Vue states expose the exact tracked file and server-authoritative deletion eligibility without leaking absolute paths.
- Owner, administrator, orphan, confirmation, lifecycle, disk, path, inode, residue, and concurrency rules are feature-tested.
- Pre-claim failures retain all database rows and bytes; post-claim retries converge to a purged graph without broad filesystem deletion.
- Credential-free confirmation and completion audit events remain after the hard purge.

**Depends on:** MUM-011.

## MUM-012 — Existing-library discovery and reconciliation

**Outcome:** inventory existing Jellyfin libraries safely after upload finalization exists, without silently adopting or changing operator-managed files.

**Scope**

- Inventory configured disk roots while excluding `.media-upload-manager` entirely.
- Identify supported existing movie files and candidate Jellyfin identities without renaming or deleting anything.
- Detect filesystem/database identity and destination conflicts for operator review.
- Provide dry-run output and require an explicit import action before creating application records.
- Keep automatic, scheduled, and continuous library scanning out of scope.

**Acceptance**

- Discovery never mutates existing library files or directories.
- The hidden application tree and unsafe/symlink-escaping paths are never scanned.
- Dry-run results are deterministic, conflicts are visible, and imports require explicit confirmation.
- Repeated discovery/import runs reconcile idempotently without duplicate records.

**Depends on:** MUM-011A.

## MUM-013 — Dashboard and private-user administration

**Outcome:** expose operational state and complete the small private-user workflow.

**Scope**

- Add disk health/capacity cards, active/recent uploads, failures, and expiry warnings.
- Add owner-scoped history/details and actionable recovery states.
- Add administrator-only create, reset, disable, and enable actions.
- Add audit events for sensitive operations.

**Acceptance**

- Policies prevent cross-user access and nonadministrator account management.
- One-time reset credentials force changes and are never retrievable afterward.
- Dashboard figures reconcile with disk/session state and handle offline disks safely.

**Depends on:** MUM-012.

## MUM-014 — Docker and Cloudflare deployment

**Outcome:** provide a reproducible production topology with explicit persistence and mounts.

**Scope**

- Build/pin application and Nginx images.
- Add app, Nginx, worker, scheduler, official `tusd`, and `cloudflared` services.
- Add health checks, restart behavior, three explicit NAS mount examples, persistent SQLite/app storage, and additional-disk override instructions.
- Configure trusted proxies/hosts, secure cookies, tunnel token injection, and same-origin tus routing.
- Document deployment, migration, backup, restore, and credential recovery.

**Acceptance**

- Container recreation preserves database/app state.
- Missing NAS mounts fail closed.
- Docker smoke tests exercise authentication, a streamed resumable upload, worker processing, and exact final path.
- Secrets do not enter images, source, Compose defaults, or logs.

**Depends on:** MUM-013.

## MUM-015 — Hardening and release verification

**Outcome:** demonstrate safe recovery and readiness for the v1 production workload.

**Scope**

- Schedule inactive-session expiry, safe partial cleanup, health checks, and reconciliation.
- Recover idempotently after worker, app, Nginx, and `tusd` restarts.
- Add structured lifecycle/audit logs with secret redaction.
- Run dependency/security checks and complete the production checklist.
- Resolve documentation drift found during end-to-end verification.

**Acceptance**

- Restart/failure injection proves no overwrite, duplicate finalization, corrupted offsets, or silently lost reservations.
- Seven-day expiry and cleanup are tested against active/stale hooks and partial files.
- The production path uses 64 MiB requests, buffering is off, PHP never holds a complete movie, no second complete copy is required, and existing media is never overwritten outside the explicitly confirmed MUM-011 current-primary replacement.
- Backup/restore and administrator recovery are rehearsed.

**Depends on:** MUM-014.

## Cross-cutting test plan

### Unit

- Release-name parsing and ranked suggestions
- TMDB DTO mapping and errors
- Jellyfin path sanitization, Unicode, normalization, and extension allowlist
- Disk capacity/reservation arithmetic
- File fingerprint ranges and matching
- Token scope/expiry
- Every lifecycle transition and idempotency rule

### Feature

- Authentication on every UI/API route
- Forced credential changes, disabled users, and administrator policies
- Mocked TMDB behavior and attribution data
- Disk health failures, capacity admission, override, and concurrent reservations
- Duplicate/conflict blocking
- Owner scoping, token refresh, cancellation, completion, and idempotent hooks

### Integration

- Nginx-to-`tusd` streaming with buffering disabled
- Direct staging on three temporary disk roots
- Interrupted transfer and exact-offset resume
- Insufficient space, mount loss, cancellation, and expiry
- Invalid video and bounded `ffprobe`
- Atomic finalization, crash recovery, and sidecar cleanup

### Browser

- Select a file, parse/search, confirm the movie, preview the exact path, and select/recommend a disk
- Observe progress/speed/ETA and use pause/retry/cancel
- Interrupt the network, reconnect, and resume
- Reopen the app, reselect an unchanged file, and resume
- Reject a changed file
- Complete and display the exact Jellyfin path

### Production verification

- Inspect effective Nginx routing/buffering configuration.
- Observe sequential 64 MiB PATCH requests through the tunnel.
- Confirm movie bytes do not enter PHP memory, request storage, SQLite, or the application volume.
- Confirm staging and final paths share a filesystem and finalization is an atomic rename.
- Confirm the ordinary workflow never creates a second full-size copy or overwrites an existing destination; separately verify the narrow MUM-011 replacement contract.

## Assumptions retained for v1

- The expected deployment is private and low-concurrency; SQLite and a database queue are sufficient.
- Incomplete uploads expire after seven inactive days.
- Completed tus metadata is removed after successful, recoverable finalization.
- Free-space management is monitoring, reservation, recommendation, and safe placement only.
- Series, batch episodes, subtitles, multiple versions, arbitrary filesystem moving/deleting, automatic or continuous NAS scanning, content-fingerprint recognition, 2FA, and Cloudflare Access remain deferred. MUM-011 adds explicit current-primary replacement, MUM-011A adds exact application-tracked deletion, and MUM-012 adds only explicit, dry-run-first existing-library discovery and reconciliation.
