---
paths:
  - 'app/Support/Media/**'
---

# Media

## Keep media writes inside adopted guarded roots
All media filesystem access must resolve through MediaPathGuard and a configured stable disk ID. Production roots require an exact /proc/self/mountinfo mount-point match, and adopted disks require a matching versioned marker. The application owns only .media-upload-manager/disk.json and .media-upload-manager/incoming; never scan or mutate existing library content as part of disk health or initialization.
