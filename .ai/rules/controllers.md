---
paths:
  - 'app/{Jobs,Support/Media,Actions,Http/Controllers}/**'
---

# Controllers

## Combined scan rules supersede legacy Movie-only scan wording
Any older rule saying library scans never enter Series roots is obsolete. New scans use ScanMediaLibrary across healthy Movie and Series roots; ScanMovieLibrary is only a serialized-job compatibility wrapper and delegates to the same combined processor. Root-derived strategies still keep Movie and Series domain mutations separate.
