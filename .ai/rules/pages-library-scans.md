---
paths:
  - 'resources/js/pages/library-scans/**'
---

# Pages Library Scans

## Show completed empty searches explicitly
After a successful Show lookup with zero matches, render an accessible status explaining that no Shows were found and suggest a simpler title or numeric TMDB ID. Keep this state hidden before lookup, while processing, and when a request error is already shown.
