---
paths:
  - 'resources/js/pages/movies/**'
---

# Movies

## Keep upload preparation file-first and page-local
The movie upload wizard must select a browser File before identification, keep that File only in page memory, and send no bytes during identification or destination preview. Leaving or refreshing resets the draft; Reserve capacity and Upload remain non-enterable until their backend stages ship.

## Keep upload preparation file-first and page-local
The wizard selects a browser File first and keeps the File, idempotency key, and plaintext reservation token only in page memory. Step 4 may hash only the configured first/last windows and create or cancel a pending reservation; step 5 remains locked until the uploader ships.

## MUM-007 unlocks only reservation
MUM-007 supersedes the earlier pre-MUM-007 lock: Reserve capacity (step 4) is enterable after an eligible preview and may create/cancel a pending reservation. Upload (step 5) remains non-enterable until MUM-008/MUM-009.

## Keep resumable uploader state page-local
After reservation open step 5 with explicit Start upload. Use sequential 64 MiB chunks and 0/3/5/10/20 second retries, disable tus URL persistence, and keep File, fingerprints, tus instance, and plaintext token only in page memory. Reopened recovery requires exact name, size, mtime, and first/last configured-window hashes.
