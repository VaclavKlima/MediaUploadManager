---
paths:
  - 'app/Support/Media/**'
---

# Media

## Keep media writes inside adopted guarded roots
All media filesystem access must resolve through MediaPathGuard and a configured stable disk ID. Production roots require an exact /proc/self/mountinfo mount-point match, and adopted disks require a matching versioned marker. The application owns only .media-upload-manager/disk.json and .media-upload-manager/incoming; never scan or mutate existing library content as part of disk health or initialization.

## Ordinary movie conflicts are global
Treat one TMDB-backed movie as one ordinary current copy across the installation. A live/current database copy, any non-cancelled/non-expired upload, or a matching canonical target on any configured disk blocks ordinary upload admission on every disk; replaced/removed media rows are audit history only.

## Reconcile deterministic tus resources safely
Tus resource ID equals the immutable upload UUID and pre-create alone chooses the guarded selected-disk .part Storage.Path. Serialize lifecycle mutations per upload; offsets only advance. Reconciliation must agree across tusd HEAD length/offset, guarded staging path, physical size, disk health, and mount identity before processing or cancellation.
