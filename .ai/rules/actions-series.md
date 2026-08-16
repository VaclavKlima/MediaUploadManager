---
paths:
  - 'app/Actions/Series/**'
---

# Actions Series

## Delete Show media through durable exact-file manifests
Episode/season/Show deletion must share the ordinary admission lock and persist exact current-primary disk/path/size/device/inode claims before unlinking. Episode and season deletion retain TMDB structure plus upload/media history so episodes become missing; whole-Show deletion purges the graph but retains the deletion operation/security audit. Never delete sidecars or traverse above the configured Series root.
