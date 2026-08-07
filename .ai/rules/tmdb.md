---
paths:
  - 'app/Support/Tmdb/**'
---

# Tmdb

## Keep filename suggestions bounded and metadata-only
Filename suggestions may issue at most three cached TMDB movie searches: year-constrained full title first, followed only by applicable yearless/subtitle/transliteration fallbacks. Keep parsed search variants internal; the public parsed metadata remains filename, title, and year, and returned metadata stays in configured TMDB_LANGUAGE.

## Normalize and adapt text searches like filenames
Normalize title input to Unicode NFC. Manual title search must use the same bounded ranked fallback pipeline as filename suggestions, including subtitle and materially distinct transliteration variants, while keeping the response source as text.
