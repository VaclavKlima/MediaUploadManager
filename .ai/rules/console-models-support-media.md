---
paths:
  - 'app/{Console,Models,Support/Media}/**'
---

# Console Models Support Media

## Enrich dynamic range without mutating physical metadata
Dynamic-range backfills may add only a missing allowlisted dynamic_range field to existing video streams; all other MediaFile physical metadata remains immutable. Process current files only, require a healthy configured disk, guarded non-symlink regular path, matching size and stable device/inode across ffprobe, continue per-row failures, and never modify movie bytes.
