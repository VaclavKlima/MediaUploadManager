---
paths:
  - config/cache.php
---

# Config

## Allowlist Pulse dashboard cache value classes
Laravel 13 disables object unserialization by default, while Pulse caches Collections containing stdClass and CarbonImmutable values. Keep the explicit three-class allowlist; do not enable arbitrary cache object unserialization.
