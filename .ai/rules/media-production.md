---
paths:
  - '{app/Support/Media/**,deploy/production/**}'
---

# Media Production

## Preserve protected hard links with a supplemental media group
Keep fs.protected_hardlinks=1 and preserve exclusive hard-link/unlink media imports and relocations; never add rename/copy fallbacks. Production media services retain APP_UID:APP_GID as their primary identity and receive MEDIA_GID as a supplemental group, defaulting MEDIA_GID to APP_GID for compatibility.
