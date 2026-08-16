---
paths:
  - 'app/{Actions,Http/Controllers,Jobs,Support/Media}/**/*.php'
---

# Controllers Jobs Support Media

## Derive scan media type from the configured root
Every manual library scan traverses all healthy Movie and Series roots into one prioritized queue. Persist root_kind from the configured root and dispatch finding actions only from that persisted value; clients must never choose or override media type. Isolate unhealthy roots and never infer missing files from an unhealthy root.
