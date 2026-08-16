---
paths:
  - '{config/media.php,app/Support/Media/{ConfiguredMediaDisk.php,ConfiguredDiskRegistry.php,MediaLibraryScanProcessor.php},deploy/production/**}'
---

# Support Media Production

## Opt in normalized Show auto-imports per Series root
A Series root auto-queues normalized scan findings only when MEDIA_DISK_<ID>_SERIES_DEFAULT_CATEGORY is tv or anime. Unset roots remain manual; existing Series.category always overrides the configured default. Queue only unambiguous TMDB-confirmed single episodes after duplicate and relocation matching, through QueueLibraryFindingImport.
