---
paths:
  - 'app/{Actions/Series,Http/Controllers/Series,Support/Series}/**'
---

# Series Support Series

## Selected episode IDs are authoritative
Series review may manually correct or omit filename episode hints. Batch admission derives identity and destination from the selected series-scoped SeriesEpisode; filename parsing still rejects unsafe names, unsupported formats, extras, multipart, and multi-episode sources.
