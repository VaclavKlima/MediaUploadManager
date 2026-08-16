---
paths:
  - '{app/Support/Media/**,app/Console/Commands/**,config/media.php}'
---

# Commands

## Keep media root kinds explicit and Movie-compatible
Configured disks may expose `movies` and/or `series` roots. Legacy `PATH` aliases only the Movie root; `ConfiguredDiskRegistry::all()` and `find()` remain Movie-only so existing scans, uploads, cleanup, and conflict detection never enter Series roots. Root-aware code must use `allRoots()`, `forKind()`, or `findRoot()`, and markers validate both stable disk ID and root kind.

## Keep media root kinds explicit and Movie-compatible
Configured disks may expose movies and/or series roots. Legacy PATH, ConfiguredDiskRegistry::all(), and find() remain Movie-only; combined scans and all other root-aware code use allRoots(), forKind(), or findRoot(). Persist root_kind from configuration, validate markers by disk ID and root kind, and keep ScanMovieLibrary only as a serialized-job compatibility wrapper around the combined scan.

## Combined scan exception supersedes earlier wording
The earlier sentence saying existing scans never enter Series roots is obsolete. ScanMediaLibrary and the ScanMovieLibrary compatibility wrapper intentionally use allRoots(); legacy registry all()/find() remain Movie-only for other legacy callers.
