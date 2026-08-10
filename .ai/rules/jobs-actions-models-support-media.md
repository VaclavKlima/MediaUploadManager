---
paths:
  - 'app/{Jobs,Actions,Models,Support/Media}/**'
---

# Jobs Actions Models Support Media

## Import discovered movies by same-filesystem claim
Manual library imports are administrator-owned and remain on their configured disk. Persist a finding claim that pins disk/path/size/device/inode and canonical Jellyfin destination before mutation; validate with ffprobe, create an exclusive hard link, verify the inode, then unlink only the claimed source. source_upload_id stays null and import provenance plus importing administrator identify these files.

## Restore moved movies only with durable provenance
Pair a discovered file with a same-scan missing current primary only when bytes match durable provenance: imported files require the original size/device/inode claim; uploaded files require size plus bounded first/last SHA-256 ranges. Restore under the ordinary admission lock with a durable claim, create a new immutable MediaFile, release the old row as relocated, and resolve both findings atomically. Filename, movie identity, or size alone must never authorize relocation.
