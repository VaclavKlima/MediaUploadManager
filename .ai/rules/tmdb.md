---
paths:
  - 'app/Support/Tmdb/**'
---

# Tmdb

## Keep filename suggestions bounded and metadata-only
Filename suggestions may issue at most three cached TMDB movie searches: year-constrained full title first, followed only by applicable yearless/subtitle/transliteration fallbacks. Keep parsed search variants internal; the public parsed metadata remains filename, title, and year, and returned metadata stays in configured TMDB_LANGUAGE.
