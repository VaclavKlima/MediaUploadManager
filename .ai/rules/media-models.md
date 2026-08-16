---
paths:
  - 'app/{Actions,Jobs,Support/Media,Models}/**'
---

# Media Models

## Replace episodes only by explicit claim
Episode replacement requires per-item confirmation of the exact current primary MediaFile. Reuse the durable exact-inode claim workflow, preserve sidecars, and permit only the owner or an administrator to replace.
