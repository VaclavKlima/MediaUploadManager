---
paths:
  - 'app/{Actions,Http,Models,Support/Media}/**'
---

# Actions Http Models Support Media

## Delete tracked movies through durable exact-file claims
Permanent movie deletion must share the global upload-admission lock, reject active/failed uploads and tus residue, and persist a write-once claim pinning the exact current primary's database identity, disk/path/size, device, and inode before unlinking. Pre-claim physical mismatch fails closed; only a valid persisted claim permits recovery when the old path is already absent. Purge only the related application graph, unlink only that claimed file, remove only an empty immediate movie directory, and never recurse or mutate Jellyfin/operator sidecars.
