---
paths:
  - 'app/{Actions,Http,Models,Support/Media}/**'
---

# Actions Http Models Support Media

## Delete tracked movies through durable exact-file claims
Permanent movie deletion must share the global upload-admission lock, reject active/failed uploads and tus residue, and persist a write-once claim pinning the exact current primary's database identity, disk/path/size, device, and inode before unlinking. Pre-claim physical mismatch fails closed; only a valid persisted claim permits recovery when the old path is already absent. Purge only the related application graph, unlink only that claimed file, remove only an empty immediate movie directory, and never recurse or mutate Jellyfin/operator sidecars.

## Re-identify movies through durable exact-file claims
Administrator movie re-identification must share the ordinary upload-admission lock and persist one immutable operation pinning old/new identity snapshots plus the exact current primary disk/path/size/device/inode before filesystem mutation. Keep bytes on the same disk, converge source-only/both-linked/destination-only states with an exclusive hard link, release the old MediaFile as reidentified, create one provenance-backed immutable MediaFile, and never touch sidecars. Orphan corrections are database-only; retries may not change the claimed target.
