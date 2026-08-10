---
paths:
  - 'app/{Actions,Jobs,Support/Media}/**'
---

# Actions Jobs Support Media

## Clean old folders only from confirmed manifests
Old-folder cleanup is always separate from import or discovered-file deletion. Never offer it for the disk root or while any supported video remains beneath the folder; reject symlinks and special files, persist the complete confirmed residue manifest, delete only entries whose size/device/inode still match, and leave anything added later with a partial result.

## Delete all confirmed non-video residue
Folder cleanup may include Jellyfin sidecars and any other regular non-video files when no supported video remains beneath the cleanup scope. Expand through ancestors below the configured disk root only while their subtree has no supported video, symlink, or special file; show the complete expanded manifest before deletion.

## Automatically clean resolved source folders
This supersedes the earlier separate-confirmation rule for recursive library findings. After an imported or deleted finding resolves, automatically persist and execute a guarded residue manifest when its source tree contains no supported video; include regular non-video sidecars, never delete the configured disk root, and treat an already-absent folder as complete/no work. Re-scan queues the same idempotent cleanup for historical resolved findings.
