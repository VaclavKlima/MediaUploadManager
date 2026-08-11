# Ordered implementation backlog

Ticket numbers preserve the historical roadmap order. Explicit statuses and dependencies are authoritative when completed production work arrived out of that order. A ticket may refine implementation details but must preserve the contracts in the [product specification](product-spec.md), [architecture](architecture.md), and [configuration guide](configuration.md), or update those documents explicitly in the same change.

## MUM-000 — Documentation baseline

**Status:** Complete.

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
- Configure Inertia 3, Vue 3, TypeScript, Tailwind CSS 4, MySQL, and Pest.
- Keep Laravel/Fortify authentication; disable/remove registration.
- Remove unused starter pages and establish an authenticated responsive layout.
- Add baseline lint, type-check, unit/feature test, and build commands.
- Do not install Filament.

**Acceptance**

- A migrated MySQL database supports login/logout but no public sign-up.
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

**Status:** Complete.

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

- Manual browser acceptance covers normal upload, interruption, exact-offset resume, pause/cancel, token refresh, browser reopen, unchanged-file resume, and changed-file rejection.
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
- For same-disk/same-path replacement, atomically replace the tracked old primary with the validated new primary and no backup.
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
- Require an explicit irreversible-warning acknowledgement before recording a deletion claim under the global admission lock.
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

**Status:** Complete.

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

## MUM-012A — Missing-primary reconciliation and verified relocation

**Status:** Complete.

**Outcome:** reconcile missing tracked primaries and restore proven moved files without guessing from names or sizes.

**Scope**

- Include missing current primaries in each administrator-driven scan and require explicit confirmation before accepting an external removal.
- Pair a discovered file with one same-scan missing primary only when durable provenance proves the bytes: imported files use the original size/device/inode claim, while uploaded files use size plus the bounded first/last SHA-256 ranges.
- Persist the exact discovered snapshot, tracked source, canonical destination, and relocation proof before filesystem mutation.
- Restore the verified bytes to the canonical path with exclusive hard-link/unlink promotion, create a new immutable media-file record, release the old record as relocated, and resolve both findings.
- Keep filename, movie identity, and size-only matches insufficient for relocation.

**Acceptance**

- Missing-primary findings fail closed while a disk is unavailable, a tracked path has returned, or more than one candidate exists.
- Pre-claim changes retain the tracked primary and findings; retries after a claim converge across source-only, both-linked, destination-only, and database-committed states.
- Confirmed external removal makes the movie an orphan only after rechecking that the exact tracked path remains absent and no proven relocation is pending.
- Relocation never creates a second full-size copy or touches unrelated sidecars.

**Depends on:** MUM-012.

## MUM-012B — Tracked-movie re-identification and canonical path repair

**Status:** Complete.

**Outcome:** let an administrator correct a tracked movie identity and repair its canonical path without replacing its bytes.

**Scope**

- Preview the new TMDB identity and canonical destination, rejecting duplicates, conflicts, active uploads, and unsafe disk state before confirmation.
- Share the upload-admission lock and persist one immutable operation with old/new identity snapshots plus the exact current disk, source path, destination path, size, device, and inode.
- Re-identify orphans with a database-only change; for a current primary, create the canonical destination with an exclusive hard link, verify the inode, then unlink the old name.
- Release the old media-file row as reidentified, create one provenance-backed immutable replacement row, and remove only an old directory proven empty.
- Never mutate artwork, metadata, subtitles, extras, trickplay, or other sidecars.

**Acceptance**

- Only administrators may confirm re-identification, and retry must retain the originally claimed target identity.
- Source-only, both-linked, destination-only, and database-committed retries converge without duplicate current primaries.
- A changed/missing claimed file or occupied destination fails closed without adopting different bytes.
- Audit records preserve confirmation, completion, and safe failure context without credentials or absolute paths.

**Depends on:** MUM-012A.

## MUM-012C — Exact discovered-file disposition and manifest-pinned folder cleanup

**Status:** Complete.

**Outcome:** dispose of an exact administrator-reviewed scan finding and clean confirmed non-video residue without becoming a general file manager.

**Scope**

- Require administrator confirmation and persist the exact discovered file's disk, relative path, size, device, and inode before queued deletion.
- Recheck that the claimed path remains a regular file and is not tracked or claimed by an upload before unlinking only that file.
- After a finding is resolved, preview a bounded cleanup manifest containing the exact residue files/directories, physical identities, total bytes, and a canonical manifest hash.
- Block cleanup when any supported video, symbolic link, special file, unsafe path, or disk-health failure is present.
- Require explicit confirmation of the unchanged manifest; delete only its still-matching entries from leaves upward, retain new or changed residue, and report partial cleanup visibly.

**Acceptance**

- Retried discovered-file deletion accepts absence only after the immutable claim exists and never deletes a replacement at the same path.
- Folder cleanup cannot target a configured disk root and never follows symlinks or deletes a supported video.
- A changed manifest cannot be confirmed, and post-confirmation changes are retained rather than broadened into the deletion set.
- Confirmation/completion audit events survive and retries converge without recursive, unbounded deletion.

**Depends on:** MUM-012B.

## MUM-013 — Dashboard and private-user administration

**Status:** In progress.

**Outcome:** expose operational state and complete the small private-user workflow.

**Completed scope**

- Beta.2 owner-scoped resumable upload history, details, and recovery actions.
- Beta.2 authenticated disk-health endpoint.
- Beta.2 administrator-only Pulse operations dashboard and CLI account recovery.
- Authenticated operational dashboard cards with personal aggregates for private users and installation-wide owner-attributed aggregates for administrators.
- Bounded failure and 24-hour expiry warnings with owner-only recovery links, plus deferred per-visit disk-health cards.

**Remaining scope**

- Add administrator web workflows to create, reset, disable, and enable private users.
- Complete authorization and audit coverage for those user-management workflows.

**Acceptance**

- Policies prevent cross-user access and nonadministrator account management.
- One-time reset credentials force changes and are never retrievable afterward.
- Dashboard figures reconcile with disk/session state and handle offline disks safely.

**Depends on:** MUM-012C.

## MUM-014 — Docker and Cloudflare deployment

**Status:** Complete.

**Outcome:** provide a reproducible production topology with explicit persistence and mounts.

**Scope**

- Build/pin application and Nginx images.
- Add app, Nginx, worker, scheduler, official `tusd`, and `cloudflared` services.
- Add health checks, restart behavior, MySQL 8.4 and tus metadata volumes, and identical absolute NAS mounts.
- Configure trusted proxies/hosts, secure cookies, tunnel token injection, and same-origin tus routing.
- Document initial installation, Cloudflare Access, GHCR login, deployment, code rollback, disk initialization, credential recovery, and release smoke testing.

**Acceptance**

- Container recreation preserves MySQL and tus metadata while named-volume destruction is explicitly accepted data loss.
- Missing NAS mounts fail closed.
- Container smoke tests boot ephemeral MySQL and media mounts and verify migrations, disks, `ffprobe`, Nginx, `/up`, worker, scheduler, and Pulse; the real upload journey remains a manual release check.
- Secrets do not enter images, source, Compose defaults, or logs.

**Depends on:** MUM-012C.

## MUM-015 — Hardening and release verification

**Status:** Complete.

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
- Administrator recovery and code-only rollback are rehearsed; backup/restore is outside the beta gate.
- The live beta.2 failure-injection smoke test confirms Cloudflare Access, 64 MiB upload and exact-offset resume, restart recovery, mount-loss isolation, effective Nginx protections, Pulse, and container health.

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

### Manual browser acceptance

- Select a file, parse/search, confirm the movie, preview the exact path, and select/recommend a disk
- Observe progress/speed/ETA and use pause/retry/cancel
- Interrupt the network, reconnect, and resume
- Reopen the app, reselect an unchanged file, and resume
- Reject a changed file
- Complete and display the exact Jellyfin path

### Production verification

- Inspect effective Nginx routing/buffering configuration.
- Observe sequential 64 MiB PATCH requests through the tunnel.
- Confirm movie bytes do not enter PHP memory, request storage, MySQL, or the application container layer.
- Confirm staging and final paths share a filesystem and finalization uses exclusive hard-link/unlink promotion.
- Confirm the ordinary workflow never creates a second full-size copy or overwrites an existing destination; separately verify the narrow MUM-011 replacement contract.

## Assumptions retained for v1

- The expected deployment is private and low-concurrency; MySQL 8.4 and database-backed queues, cache, and sessions are sufficient.
- Incomplete uploads expire after seven inactive days.
- Completed tus metadata is removed after successful, recoverable finalization.
- Free-space management is monitoring, reservation, recommendation, and safe placement only.
- Series, batch episodes, multiple versions, content-fingerprint recognition, 2FA, backups/restoration, Redis, Horizon, external alerts, continuous scanning, and automated browser tests remain deferred. Arbitrary filesystem browsing, moving, bulk deletion, and general sidecar management also remain deferred; MUM-011A and MUM-012C expose only their explicitly claimed narrow deletions, while MUM-012 through MUM-012C provide administrator-driven discovery, verified relocation, re-identification, and manifest-pinned cleanup.
