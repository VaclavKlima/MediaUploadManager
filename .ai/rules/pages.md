---
paths:
  - '{app/Support/Media/**,resources/js/pages/Dashboard.vue}'
---

# Pages

## Group dashboard capacity by filesystem only
Dashboard storage capacity groups guarded roots by the health request's filesystem device ID and never sums shared capacity; reserves remain per stable configured disk ID. Commands, /disks, Pulse, and upload selection remain root/disk-ID based, and neither device IDs nor absolute roots may enter the Inertia payload.
