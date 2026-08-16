---
paths:
  - '{app/Http/Controllers/LibraryScanController.php,resources/js/pages/library-scans/**}'
---

# Library Scans

## Use Show source folders during scan identification
Show identification tasks expose their complete source_folder and derive the initial search query from the top-level folder, falling back to the filename only for root-level files. Keep that location visible in the modal and show TMDB poster artwork for search results and the selected Show.
