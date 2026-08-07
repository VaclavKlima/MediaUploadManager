---
paths:
  - 'app/{Console,Support/Media}/**'
---

# Console Support Media

## Keep local tus startup one-command and loopback-only
Use `composer upload:dev` / `php artisan upload:dev` for Herd development. It must remain local-only, checksum the pinned tusd binary, keep runtime metadata and secret-bearing includes under ignored application storage, back up the site-specific Herd config, and bind tusd/hooks only to loopback.
