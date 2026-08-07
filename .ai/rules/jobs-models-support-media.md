---
paths:
  - 'app/{Jobs,Models,Support/Media}/**'
---

# Jobs Models Support Media

## Replacement claims pin both old and new inodes
A confirmed replacement claim must pin the staged device/inode and exact old media-file ID, source upload, disk, path, size, device, and inode before destruction. Same-disk/same-path uses atomic rename; all other layouts exclusively link and unlink the validated stage before deleting only the verified old file. Once claimed, cancellation/discard is forbidden and retries accept an absent old file only when the exact claimed new inode is already at target; never recurse or mutate Jellyfin/operator sidecars.
