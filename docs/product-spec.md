# Product specification

## 1. Purpose

Media Upload Manager gives a private user a safe way to move large local movie and episodic video files onto Jellyfin storage disks. It combines human-confirmed TMDB identification, disk-capacity guidance, resumable direct-to-disk upload, media validation, deterministic naming, and narrowly scoped library reconciliation.

The product is a placement workflow, not a general-purpose file manager or library organizer.

## 2. Goals

The product roadmap must:

- turn a release-style filename, title, TMDB ID, or IMDb ID into ranked TMDB movie candidates;
- require the user to confirm the selected movie and destination path;
- recommend an eligible disk using real free space, safety reserve, and active-upload reservations;
- upload large files reliably through Cloudflare without routing movie bytes through PHP;
- resume an interrupted upload, including after a browser restart, only when the reselected local file matches;
- validate the completed object as a video and atomically finalize it using Jellyfin's recommended movie naming;
- never overwrite an untracked or conflicting file; replacement is allowed only through the explicitly confirmed MUM-011 Movie flow or equivalent MUM-019 episode flow;
- list application-tracked movies and permanently delete an authorized movie graph plus only its exact verified primary after explicit title confirmation;
- let administrators explicitly scan existing libraries, canonically import discovered movies, reconcile missing primaries only from durable provenance, and re-identify tracked movies;
- let administrators delete one exact claimed discovered file and clean only a separately previewed, manifest-pinned set of non-video residue; and
- manage TV and Anime series separately from Movies, including TMDB seasons, episodes, and Season 0 specials;
- admit a local episode, season folder, or complete series folder as one atomically reserved batch and transfer its accepted episodes sequentially;
- give series uploads, replacement, discovery, import, reconciliation, re-identification, deletion, and operations the same safety guarantees as movie management; and
- operate safely for a small number of authenticated private users.

## 3. Users and permissions

### Private user

A private user can sign in; search for a movie or series; explicitly classify a series as TV or Anime; create and manage only their own upload sessions and series batches; view disk availability; resume, retry, or cancel their transfers; and view tracked Movies and Series. They may replace or delete an episode whose tracked current primary they own. They may delete a season or whole series only when they completely own every tracked primary and related upload in that scope and no active or failed work blocks deletion. Movie deletion retains its existing ownership rules.

### Administrator

An administrator has all private-user abilities, may manage imported and mixed-owner series scopes, may delete any safely verified tracked movie or series scope, and may run and resolve the separate Movie and Series library scans. Administrators may also refresh TMDB metadata, re-identify a tracked movie or series, remap an individual episode, delete exact discovered-file findings, and confirm an unchanged cleanup manifest. Administrative activity must be authorized server-side and auditable. Web workflows to create, reset, disable, and re-enable private accounts remain the unfinished MUM-013 scope; CLI bootstrap and account recovery are available in beta.2.

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

### Series upload

1. On the separate Series upload page, the user selects one episode file, a season directory, or a complete series directory. Directory selection uses the browser directory API when available and a multi-file fallback otherwise.
2. The application identifies the TMDB TV series once. The user explicitly classifies it as `TV` or `Anime`; classification is never inferred from language, country, genre, or filename.
3. The application parses `SxxEyy` identities, fetches or lazily hydrates the required TMDB seasons and episodes, and presents one grouped mapping review. Unresolved, duplicate, multi-episode, multipart, or conflicting inputs must be corrected or excluded before admission.
4. TMDB Season 0 is shown as `Specials`. An `S00Exx` input or a manually mapped file is accepted only when it resolves to a real TMDB Season 0 episode. Non-TMDB bonus videos are excluded with a clear explanation and remain untouched on the user's local filesystem.
5. The user reviews every accepted source-to-episode mapping, the canonical paths, aggregate bytes, conflicts, and a destination disk. A series uses one home disk; its disk becomes immutable after the first admitted upload or import.
6. The server fingerprints every accepted file and reserves the complete batch in one locked transaction. Any stale mapping, conflict, unhealthy disk, or insufficient aggregate capacity rejects the whole reservation before bytes transfer.
7. Accepted episodes transfer sequentially through the shared tus transport. Each item has independent progress, pause, retry, cancellation, token refresh, validation, and crash recovery, while the batch exposes aggregate progress.
8. Once transfer begins, successfully finalized episodes remain completed if another item fails or is cancelled. The user resolves or retries remaining items individually; the batch does not roll back completed files.
9. Replacing an episode requires separate explicit confirmation of that exact tracked primary before admission. No batch operation implicitly replaces an existing episode.

### Existing-series administration

1. An administrator starts an explicit scan of configured series roots. Series scans are separate from movie scans and never inspect movie roots, follow symlinks, or descend into `.media-upload-manager`.
2. The application groups findings by proposed series and season, auto-maps canonical `SxxEyy` names where TMDB supplies the episode, and exposes unresolved and conflicting mappings for review. Season 0 uses the same review rules.
3. Known Jellyfin bonus material and non-TMDB videos are recorded as non-actionable unmanaged findings. Unknown supported videos remain visible until an administrator maps them to a TMDB episode or deliberately leaves them unmanaged. They are never automatically renamed, imported, cleaned, or deleted.
4. Before import, the administrator confirms a complete selected group. The application claims every selected source and destination atomically, then performs recoverable per-episode hard-link/unlink imports on the series home disk. Partial completion is permitted only after all claims persist.
5. Missing episode primaries may be restored only from durable inode or bounded-hash provenance. Filename, episode number, TMDB identity, or size alone does not prove that bytes moved.
6. An administrator may re-identify a series or remap one episode. The operation claims the old/new identities and every exact affected file before mutation; mapping permutations use claimed temporary hard links and never copy or overwrite bytes.
7. Episode, season, and whole-series deletion unlink only claimed tracked primaries and remove only directories proven empty. NFO, artwork, subtitles, trickplay, openings, trailers, and other extras remain unless an administrator separately confirms an unchanged cleanup manifest.

## 5. Identification and metadata

- TMDB calls are server-side and authenticated with `TMDB_API_TOKEN`.
- Movie text lookup uses TMDB search, direct TMDB IDs use detail lookup, and IMDb IDs use TMDB find. Series lookup uses TMDB TV search, series details, season details, episode details, and external-ID requests. See TMDB's [finding-data guidance](https://developer.themoviedb.org/docs/finding-data), [TV series details](https://developer.themoviedb.org/reference/tv-series-details), [season details](https://developer.themoviedb.org/reference/tv-season-details), and [episode details](https://developer.themoviedb.org/reference/tv-episode-details).
- Results use the configured `TMDB_LANGUAGE`, defaulting to `en-US`.
- Selected movie or series identity and versioned metadata snapshots are stored so completed records do not depend on future TMDB changes. Series snapshots cover the series and each hydrated season/episode; administrators may explicitly refresh them.
- Filename parsing may remove common release tokens, infer a likely year, and rank candidates by title/year similarity.
- Embedded media metadata is only a post-upload validation hint. Automatic identification never claims to recognize a movie from its video content.
- Every upload requires explicit result confirmation.
- The UI must include the attribution required by TMDB. Use is subject to TMDB's current terms; its [FAQ](https://developer.themoviedb.org/docs/faq) describes attribution and non-commercial API use.

## 6. Naming and file rules

The canonical movie target is:

```text
<disk-root>/<title> (<year>) [tmdbid-<id>]/<title> (<year>) [tmdbid-<id>].<ext>
```

This follows Jellyfin's [movie naming guidance](https://jellyfin.org/docs/general/server/media/movies/).

The canonical series target deliberately uses four levels:

```text
<series-root>/<series title> (<year>) [tmdbid-<id>]/
  Season <ss>/
    <series title> (<year>) S<ss>E<ee> - <episode title>/
      <series title> (<year>) S<ss>E<ee> - <episode title>.<ext>
```

For example, a special is stored as:

```text
Series Title (Year) [tmdbid-123]/
└── Season 00/
    └── Series Title (Year) S00E01 - Special Title/
        └── Series Title (Year) S00E01 - Special Title.mkv
```

Regular episodes use `Season 01`, `S01E01`, and so on. Season and episode numbers are padded to at least two digits but never truncated, so number `100` remains `100`. If TMDB has no first-air year, the `(year)` token is omitted consistently from the series, episode-directory, and filename segments. The application labels Season 0 as `Specials` in the UI while retaining `Season 00` in the canonical path. This extra episode directory is a deliberate, user-validated product convention even though Jellyfin's [TV naming documentation](https://jellyfin.org/docs/general/server/media/shows/) illustrates episode files directly inside season folders.

- Accepted container extensions are `mkv`, `mp4`, `m4v`, `avi`, `mov`, `ts`, `m2ts`, and `webm`.
- The source extension is preserved semantically and normalized to lowercase.
- Unicode is retained where the target filesystem supports it.
- Every generated segment is normalized to Unicode NFC. Unsafe characters are removed consistently, platform segment/path limits are enforced, and overlong names use deterministic truncation so preview, admission, finalization, scan, and recovery always rebuild the same path.
- Path separators, NUL, control characters, and Windows-reserved characters `< > : " / \\ | ? *` are removed or replaced consistently.
- Trailing spaces/dots and reserved path segments are handled safely.
- The generated relative path must remain beneath the configured disk root after normalization and symlink checks.
- A matching database record, staging target, final directory, or final file is a conflict. Ordinary uploads reject the conflict rather than replacing, merging, or suffixing it. Only MUM-011 for Movies or MUM-019 for an episode may replace the application-tracked current primary after explicit confirmation and complete validation.
- Series accepts exactly one source video per TMDB episode. Absolute anime numbering, multi-episode containers such as `S01E01-E02`, multipart episodes, and multiple versions are rejected.

## 7. Disk selection and capacity

Disk IDs are stable configuration keys independent of display labels or mount paths. Each physical disk may expose a separate movie root and series root. A root is eligible only when it exists, resolves safely, is readable and writable by the relevant services, has a matching kind-aware adoption marker and private incoming directory, and the physical disk has enough projected usable capacity. A series home disk is immutable after its first admission or import.

For disk `d` and proposed upload `u`:

```text
active_remaining(d) = sum(max(declared_size - confirmed_offset, 0))
                       for active movie and series sessions on d

projected_usable(d, u) = free_bytes(d)
                         - reserve_bytes(d)
                         - active_remaining(d)
                         - declared_size(u)
```

The recommendation is the eligible disk with the greatest `projected_usable` value. Capacity is aggregated by stable physical disk ID across both roots, so free space and the safety reserve are counted once. Capacity is recalculated and reserved under a database lock when a movie session or complete series batch is created. A missing, read-only, full, unsafe, or conflicting root cannot be selected. The default safety reserve is 20 GiB per disk and can be overridden per disk.

Free-space management means monitoring, reservations, safe placement, and recommendation. The product does not offer arbitrary moves or deletion: MUM-011A and MUM-023 allow only authorized claim-bound tracked deletion, while MUM-012C and the Series equivalent may delete only an exact claimed finding or an unchanged confirmed residue manifest.

## 8. Resumability

- A session remains resumable for seven days after its last activity.
- The client fingerprint includes the file name, declared size, last-modified timestamp, SHA-256 of the first 1 MiB, and SHA-256 of the last 1 MiB. Files smaller than 2 MiB may have overlapping hash regions; both ranges are still defined deterministically.
- The full video is never hashed in the browser.
- After reopening the browser, the user signs in and reselects the file. All fingerprint fields must match the stored session before the app asks `tusd` for the authoritative offset.
- The server issues a fresh, short-lived token after authentication. Stored tokens are hashed and bound to one user, session, disk, declared length, and allowed operation.
- Offset disagreements are resolved from `tusd`/the staged object and reconciled into the database; the client does not choose an offset.
- Changed files are rejected with a clear message and do not mutate the existing session.
- Each episode in a series batch follows the same fingerprint and token rules. Transfers are sequential, but pause, retry, cancellation, and reopened-browser recovery are item-specific; aggregate progress is derived from every item and never substitutes for authoritative per-upload state.

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

A series batch has its own planning/admission/transfer summary state, but each accepted episode is backed by an ordinary upload lifecycle. Batch reservation is all-or-nothing before transfer; after transfer begins, item states may diverge and completed episodes are not rolled back.

## 10. Validation and finalization

- `ffprobe` must successfully parse the staged object and report at least one video stream.
- The probed container, streams, codecs, duration, dimensions, and other selected technical fields are stored as structured metadata.
- File size and final `tusd` offset must equal the declared size before processing.
- Validation runs in a database-backed queue worker and is safe to retry after a worker restart.
- Immediately before finalization, the worker revalidates the disk boundary and destination conflict.
- The final directory is created safely, then the target is created exclusively as a same-filesystem hard link and the staging name is unlinked only after inode/size verification.
- Series finalization uses the shared validation and promotion machinery, but resolves the staging and destination paths through the episode subject and the series home disk.
- An existing destination is never overwritten.
- Completed tus sidecar metadata is removed only after the database commit/finalization workflow can recover safely.
- Invalid or conflicting files remain quarantined or are cleaned according to an explicit retention policy; the failure is visible to the user.

## 11. User interface requirements

- Authentication and forced-credential-change screens
- Operational dashboard with scoped upload aggregates, bounded failure/expiry warnings, owner-only recovery links, and per-visit disk health
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
- Separate `/series`, `/series/{series}`, and `/series/upload` pages and navigation, wired to Laravel through Wayfinder-generated routes/actions
- Searchable Series grid with explicit TV/Anime filters and a detail page with expandable season cards
- Series directory selection with multi-file fallback, grouped mapping review, aggregate progress, and per-episode pause/retry/cancel/recovery
- Every TMDB episode shown as uploaded, missing, processing, failed, or not uploaded, with its path, owner, size, and technical metadata; Season 0 is labelled `Specials`
- Administrator-only `/series/scans` workspace for grouped findings, Season 0 mapping, unmanaged extras, imports, relocation, series re-identification, and episode remapping
- Clear conflict, capacity, authentication, expiry, validation, and offline errors
- Responsive, keyboard-usable Vue UI; server-side authorization remains authoritative

## 12. Non-functional requirements

- Movie and episode bytes do not enter PHP memory, PHP request bodies, MySQL, or application storage.
- Nginx request buffering is disabled for `/uploads/tus/*`.
- One complete movie or episode exists only as the selected-root staging object and later the exclusively linked final object after the staging name is unlinked; processing must not require a second full-size copy.
- Ordinary web/API traffic is served by Laravel; tus traffic is isolated and authenticated.
- All UI and JSON routes require authentication except sign-in and the constrained first-login flow.
- Internal hooks require a separate service credential and are not exposed as user endpoints.
- State changes and security-sensitive administration produce structured audit logs without credentials or bearer tokens.
- MySQL 8.4 and the database queue/cache/session drivers are adequate for the intended private, low-concurrency workload.
- Restarts of Nginx, PHP, the queue worker, or `tusd` must not corrupt a valid staged upload; reconciliation repairs stale database state.

## 13. Release acceptance criteria

The movie release remains accepted under its existing criteria. The Series milestone is accepted when a user can identify one TV or Anime series, review an episode/season/series selection including TMDB specials, atomically reserve the accepted batch, sequentially transfer and independently recover each episode, and obtain the documented four-level paths without overwriting or copying bytes. Series replacement, grouped scans/imports, missing-file recovery, series re-identification, episode remapping, and episode/season/series deletion must remain explicit, claim-bound, retry-safe, ownership-aware, and sidecar-preserving outside a separately confirmed cleanup manifest.

The automated release test suite must cover filename parsing, TMDB Movie/TV metadata mapping, Season 0, lazy hydration, Unicode/path safety, every state transition, authorization, separate-root health and isolation, shared reservations, batch conflicts, duplicate handling, authenticated hooks, three temporary physical disks, sequential resume, partial completion, cancellation, expiry, invalid video, atomic finalization, grouped scan recovery, destructive-operation claims, and movie regressions. The core movie browser journey and a Jellyfin smoke test for regular episodes and Season 0 specials in the four-level layout remain manual release checks.

## 14. Deferred work

The following remain explicitly out of scope:

- multiple movie versions and general sidecar management for subtitles, extras, and artwork;
- series multiple versions, absolute anime numbering, multi-episode files, multipart episodes, and management of non-TMDB bonus videos;
- arbitrary filesystem browsing, moving, bulk deletion, or deletion of untracked files outside an exact MUM-012C discovered-file claim or confirmed cleanup manifest;
- automatic or continuous NAS/library scanning; only administrator-triggered, dry-run-first scans are supported;
- video-content fingerprint recognition;
- two-factor authentication;
- backup and restoration automation;
- Redis, Horizon, or PostgreSQL unless measured load demonstrates a need;
- external alert delivery; and
- automated browser testing.
