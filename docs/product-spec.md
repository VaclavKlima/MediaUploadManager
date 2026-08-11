# Product specification

## 1. Purpose

Media Upload Manager gives a private user a safe way to move a large local movie file onto one of several Jellyfin storage disks. It combines human-confirmed TMDB identification, disk-capacity guidance, resumable direct-to-disk upload, media validation, and deterministic naming.

The product is a placement workflow, not a general-purpose file manager or library organizer.

## 2. Goals

Version 1 must:

- turn a release-style filename, title, TMDB ID, or IMDb ID into ranked TMDB movie candidates;
- require the user to confirm the selected movie and destination path;
- recommend an eligible disk using real free space, safety reserve, and active-upload reservations;
- upload large files reliably through Cloudflare without routing movie bytes through PHP;
- resume an interrupted upload, including after a browser restart, only when the reselected local file matches;
- validate the completed object as a video and atomically finalize it using Jellyfin's recommended movie naming;
- never overwrite an untracked or conflicting file; replacement of the application-tracked current primary is allowed only through the explicitly confirmed MUM-011 flow;
- list application-tracked movies and permanently delete an authorized movie graph plus only its exact verified primary after explicit title confirmation;
- let administrators explicitly scan existing libraries, canonically import discovered movies, reconcile missing primaries only from durable provenance, and re-identify tracked movies;
- let administrators delete one exact claimed discovered file and clean only a separately previewed, manifest-pinned set of non-video residue; and
- operate safely for a small number of authenticated private users.

## 3. Users and permissions

### Private user

A private user can sign in, search for a movie, create and manage only their own upload sessions, view disk availability, resume or cancel an upload, view tracked movies, and permanently delete a movie whose current primary they own. For an orphan, they may delete only when every related upload belongs to them.

### Administrator

An administrator has all private-user abilities, may delete any safely verified tracked movie or ownerless orphan, and may run and resolve library scans, re-identify tracked movies, delete exact discovered-file findings, and confirm residue-cleanup manifests. Administrative activity must be authorized server-side and auditable. Web workflows to create, reset, disable, and re-enable private accounts remain the unfinished MUM-013 scope; CLI bootstrap and account recovery are available in beta.2.

There is no public registration. During initial setup, an idempotent guided console form captures the administrator's real name and email, creates the account only when the user store is empty, and displays a random one-time password once in that terminal. The first sign-in requires only password replacement. A CLI recovery command provides a controlled, terminal-only account-recovery path.

## 4. Primary user journey

1. The authenticated user selects a local video.
2. The client checks the extension and captures name, byte size, and last-modified time.
3. The application parses the filename and searches TMDB. The user may instead provide a title, TMDB ID, or IMDb ID.
4. The user confirms one result. The UI displays TMDB attribution and the proposed Jellyfin folder and filename.
5. The application lists disk health and recommends the eligible disk with the greatest projected usable capacity. The user may override it with another eligible disk.
6. The server creates an upload session, reserves its remaining bytes, and returns a short-lived token scoped to that upload.
7. `tus-js-client` sends sequential 64 MiB chunks to the same-origin `/uploads/tus/*` endpoint. The UI shows progress, speed, ETA, pause, retry, and cancel controls.
8. `tusd` writes directly to the chosen disk's private incoming directory and reports progress to Laravel through authenticated internal hooks.
9. After completion, a unique queue job validates the file with `ffprobe`, rechecks the destination, and publishes it with an exclusive same-filesystem hard link before unlinking the stage name.
10. The UI shows the exact final relative path and saved technical metadata.

### Existing-library administration

1. An administrator starts an explicit scan; scans are never automatic, scheduled, or continuous.
2. The scan recursively inventories supported regular movie files outside `.media-upload-manager`, records exact disk/path/size/device/inode snapshots, and reports tracked current primaries that are missing.
3. The administrator reviews deterministic findings before any mutation. Identification alone never authorizes an import or relocation.
4. A confirmed import probes the discovered movie, pins its identity, source snapshot, canonical destination, and probe result, then creates the canonical path with an exclusive hard link before unlinking the discovered name.
5. A missing primary may be restored only when its paired discovered file matches durable provenance: the original size/device/inode claim for an imported primary, or size plus the bounded first/last SHA-256 ranges for an uploaded primary. The repair creates a new immutable current media-file record and releases the old one as relocated.
6. If no proven relocation is pending, the administrator may confirm that the exact tracked path remains absent and record the primary as externally removed.
7. An administrator may correct a tracked movie's TMDB identity. The operation pins the old/new metadata and exact current primary before moving the same inode to its new canonical path; orphan corrections are database-only.
8. An unresolved discovered finding may be deleted only after its exact snapshot is claimed. After a finding is resolved, residue cleanup requires a separate preview and confirmation of an unchanged manifest; supported videos, symlinks, special files, and new or changed residue are retained.

## 5. Identification and metadata

- TMDB calls are server-side and authenticated with `TMDB_API_TOKEN`.
- Text lookup uses TMDB search, direct TMDB IDs use detail lookup, and IMDb IDs use TMDB find. See TMDB's [finding-data guidance](https://developer.themoviedb.org/docs/finding-data).
- Results use the configured `TMDB_LANGUAGE`, defaulting to `en-US`.
- Selected movie identity and a metadata snapshot are stored so a completed record does not depend on future TMDB changes.
- Filename parsing may remove common release tokens, infer a likely year, and rank candidates by title/year similarity.
- Embedded media metadata is only a post-upload validation hint. Automatic identification never claims to recognize a movie from its video content.
- Every upload requires explicit result confirmation.
- The UI must include the attribution required by TMDB. Use is subject to TMDB's current terms; its [FAQ](https://developer.themoviedb.org/docs/faq) describes attribution and non-commercial API use.

## 6. Naming and file rules

The canonical v1 target is:

```text
<disk-root>/<title> (<year>) [tmdbid-<id>]/<title> (<year>) [tmdbid-<id>].<ext>
```

This follows Jellyfin's [movie naming guidance](https://jellyfin.org/docs/general/server/media/movies/).

- Accepted container extensions are `mkv`, `mp4`, `m4v`, `avi`, `mov`, `ts`, `m2ts`, and `webm`.
- The source extension is preserved semantically and normalized to lowercase.
- Unicode is retained where the target filesystem supports it.
- Path separators, NUL, control characters, and Windows-reserved characters `< > : " / \\ | ? *` are removed or replaced consistently.
- Trailing spaces/dots and reserved path segments are handled safely.
- The generated relative path must remain beneath the configured disk root after normalization and symlink checks.
- A matching database record, staging target, final directory, or final file is a conflict. Ordinary uploads reject the conflict rather than replacing, merging, or suffixing it. Only MUM-011 may replace the application-tracked current primary after explicit confirmation and complete validation.

## 7. Disk selection and capacity

Disk IDs are stable configuration keys independent of display labels or mount paths. A disk is eligible only when its configured root exists, resolves safely, is readable and writable by the relevant services, contains or can create the private staging directory, and has enough projected usable capacity.

For disk `d` and proposed upload `u`:

```text
active_remaining(d) = sum(max(declared_size - confirmed_offset, 0))
                       for active sessions on d

projected_usable(d, u) = free_bytes(d)
                         - reserve_bytes(d)
                         - active_remaining(d)
                         - declared_size(u)
```

The recommendation is the eligible disk with the greatest `projected_usable` value. Capacity is recalculated and reserved under a database lock when the session is created. A missing, read-only, full, unsafe, or conflicting disk cannot be selected. The default safety reserve is 20 GiB per disk and can be overridden per disk.

Free-space management in v1 means monitoring, reservations, safe placement, and recommendation. The product does not offer arbitrary moves or deletion: MUM-011A may delete only an explicitly confirmed application-tracked graph and its exact verified current primary, while MUM-012C may delete only an exact claimed discovered finding or an unchanged confirmed residue manifest.

## 8. Resumability

- A session remains resumable for seven days after its last activity.
- The client fingerprint includes the file name, declared size, last-modified timestamp, SHA-256 of the first 1 MiB, and SHA-256 of the last 1 MiB. Files smaller than 2 MiB may have overlapping hash regions; both ranges are still defined deterministically.
- The full video is never hashed in the browser.
- After reopening the browser, the user signs in and reselects the file. All fingerprint fields must match the stored session before the app asks `tusd` for the authoritative offset.
- The server issues a fresh, short-lived token after authentication. Stored tokens are hashed and bound to one user, session, disk, declared length, and allowed operation.
- Offset disagreements are resolved from `tusd`/the staged object and reconciled into the database; the client does not choose an offset.
- Changed files are rejected with a clear message and do not mutate the existing session.

## 9. Upload lifecycle

Supported statuses are:

| Status | Meaning |
| --- | --- |
| `pending` | Session and reservation exist; no bytes are confirmed yet. |
| `uploading` | `tusd` has accepted the upload and activity is current. |
| `paused` | Transfer can resume and its reservation remains active. |
| `processing` | Declared bytes are present; validation/finalization is running. |
| `completed` | Validation passed and the file is at its final path. |
| `failed` | A terminal processing or transport error requires attention. |
| `cancelled` | The owner or administrator cancelled the session. |
| `expired` | The inactivity window elapsed before completion. |

Transitions are explicit, authorized, idempotent, and tested. Completion notifications may be repeated without queuing duplicate finalization or creating duplicate media-file records.

## 10. Validation and finalization

- `ffprobe` must successfully parse the staged object and report at least one video stream.
- The probed container, streams, codecs, duration, dimensions, and other selected technical fields are stored as structured metadata.
- File size and final `tusd` offset must equal the declared size before processing.
- Validation runs in a database-backed queue worker and is safe to retry after a worker restart.
- Immediately before finalization, the worker revalidates the disk boundary and destination conflict.
- The final directory is created safely, then the target is created exclusively as a same-filesystem hard link and the staging name is unlinked only after inode/size verification.
- An existing destination is never overwritten.
- Completed tus sidecar metadata is removed only after the database commit/finalization workflow can recover safely.
- Invalid or conflicting files remain quarantined or are cleaned according to an explicit retention policy; the failure is visible to the user.

## 11. User interface requirements

- Authentication and forced-credential-change screens
- File selection with immediate extension/size feedback
- Parsed title/year suggestion with editable search input
- Ranked movie results, movie detail confirmation, poster where available, and TMDB attribution
- Exact path preview before session creation
- Disk cards showing health, free space, reserve, active reservations, and projected capacity
- Upload progress with byte counts, percentage, speed, ETA, pause, retry, cancel, and reconnect state
- Resume/history screen that explains why the local file must be reselected
- Compact tracked-movie library with search, state filters, sorting, pagination, and an explicit irreversible-warning checkbox for permanent deletion
- Administrator scan workspace with progress, deterministic discovery/missing tasks, identity confirmation, canonical import, verified relocation, external-removal reconciliation, exact discovered-file deletion, and manifest-pinned cleanup
- Administrator tracked-movie re-identification preview with the old/new identity and exact path effect
- Clear conflict, capacity, authentication, expiry, validation, and offline errors
- Responsive, keyboard-usable Vue UI; server-side authorization remains authoritative

## 12. Non-functional requirements

- Movie bytes do not enter PHP memory, PHP request bodies, MySQL, or application storage.
- Nginx request buffering is disabled for `/uploads/tus/*`.
- One complete movie exists only as the selected-disk staging object and later the exclusively linked final object after the staging name is unlinked; processing must not require a second full-size copy.
- Ordinary web/API traffic is served by Laravel; tus traffic is isolated and authenticated.
- All UI and JSON routes require authentication except sign-in and the constrained first-login flow.
- Internal hooks require a separate service credential and are not exposed as user endpoints.
- State changes and security-sensitive administration produce structured audit logs without credentials or bearer tokens.
- MySQL 8.4 and the database queue/cache/session drivers are adequate for the intended private, low-concurrency workload.
- Restarts of Nginx, PHP, the queue worker, or `tusd` must not corrupt a valid staged upload; reconciliation repairs stale database state.

## 13. Version 1 acceptance criteria

Version 1 is accepted when an authenticated user can select and confirm a movie, see an accurate destination/recommendation, interrupt and resume a large upload from the exact confirmed offset, validate it, and obtain the expected Jellyfin path without conflict overwrite or second-copy behavior. A confirmed MUM-011 primary replacement and MUM-011A tracked deletion follow their narrower destructive contracts. Administrator-driven scans, canonical imports, verified relocations, re-identification, exact discovered-file deletion, and manifest-pinned cleanup must likewise remain explicit, claim-bound, retry-safe, and sidecar-preserving outside the confirmed manifest.

The automated release test suite must cover filename parsing, metadata mapping, Unicode/path safety, every state transition, authorization, disk failures, reservations, duplicate handling, authenticated hooks, three temporary disk roots, resume, cancellation, expiry, invalid video, and atomic finalization. The core browser journey remains a manual release smoke test.

## 14. Deferred work

The following are explicitly outside v1:

- television series and batch episode uploads;
- multiple movie versions and general sidecar management for subtitles, extras, and artwork;
- arbitrary filesystem browsing, moving, bulk deletion, or deletion of untracked files outside an exact MUM-012C discovered-file claim or confirmed cleanup manifest;
- automatic or continuous NAS/library scanning; only administrator-triggered, dry-run-first scans are supported;
- video-content fingerprint recognition;
- two-factor authentication;
- backup and restoration automation;
- Redis, Horizon, or PostgreSQL unless measured load demonstrates a need;
- external alert delivery; and
- automated browser testing.
