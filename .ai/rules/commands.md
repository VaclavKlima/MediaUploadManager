---
paths:
  - '{app/Support/Media/**,app/Console/Commands/**,config/media.php}'
---

# Commands

## Keep media root kinds explicit and Movie-compatible
Configured disks may expose `movies` and/or `series` roots. Legacy `PATH` aliases only the Movie root; `ConfiguredDiskRegistry::all()` and `find()` remain Movie-only so existing scans, uploads, cleanup, and conflict detection never enter Series roots. Root-aware code must use `allRoots()`, `forKind()`, or `findRoot()`, and markers validate both stable disk ID and root kind.
