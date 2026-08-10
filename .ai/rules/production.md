---
paths:
  - 'deploy/production/**'
---

# Production

## Keep the personal beta stack private and image-pinned
Production uses MySQL 8.4 plus app, worker, scheduler, pulse:check, pinned tusd, Nginx, and cloudflared with no host-published ports. App processes share one exact release image; Nginx uses its matching release image. All media roots must be identical absolute bind mounts, and named-volume loss is accepted because beta backup/restore is intentionally excluded.
