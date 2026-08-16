---
paths:
  - 'app/{Models,Actions,Jobs,Support/Media}/**'
---

# Models Actions Jobs Support Media

## Require exactly one upload subject
Every Upload and MediaFile belongs to exactly one subject: a Movie MediaItem or a SeriesEpisode. Subject-aware code must branch explicitly and must never accept both or neither.
