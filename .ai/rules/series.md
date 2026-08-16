---
paths:
  - 'app/{Models,Support/Series,Http/Controllers/Series,Actions/Series}/**'
---

# Series

## Keep Series as a separate media domain
Series, seasons, episodes, and upload batches are distinct from Movie MediaItem identity. Share transport primitives only; never make Movie scans, imports, deletion, or re-identification traverse Series records.

## Keep Series as a separate media domain
Series, seasons, episodes, and upload batches remain distinct from Movie MediaItem identity. The neutral library scan may discover both domains in one queue, but persisted root_kind selects separate Movie or Series processors for identity, import, restore, deletion, and reconciliation; clients never choose the domain.

## Combined scan exception supersedes earlier wording
The earlier sentence forbidding Movie scans from traversing Series records is obsolete for the neutral combined orchestrator and its ScanMovieLibrary compatibility wrapper. Domain writes remain separate: persisted root_kind must dispatch to Series-specific processors.
