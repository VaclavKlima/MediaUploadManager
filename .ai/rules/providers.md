---
paths:
  - 'app/Providers/**'
---

# Providers

## Keep the local queue reload-safe
Register queue:listen, not queue:work, in the local php artisan dev process group. Local code changes must be picked up without queue:restart, because the dev process runner does not respawn a worker that exits successfully after a restart signal.
