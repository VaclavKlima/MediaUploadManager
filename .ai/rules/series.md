---
paths:
  - 'app/{Models,Support/Series,Http/Controllers/Series,Actions/Series}/**'
---

# Series

## Keep Series as a separate media domain
Series, seasons, episodes, and upload batches are distinct from Movie MediaItem identity. Share transport primitives only; never make Movie scans, imports, deletion, or re-identification traverse Series records.
