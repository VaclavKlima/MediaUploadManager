---
paths:
  - 'app/{Actions/Series,Http/Controllers/Series,Models}/**'
---

# Series Models

## Admit Series batches atomically
Series batch admission runs under the ordinary global admission lock and one transaction. Recompute every mapping, canonical path, conflict, replacement permission, and aggregate capacity; any invalid item rolls back the whole unstarted batch.

## Transfer Series episodes sequentially
Batch positions are immutable season/episode order. Only the first incomplete item whose predecessors are terminal may receive upload authorization; failures pause the queue and completed episodes remain committed.
