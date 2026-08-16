---
paths:
  - 'app/{Models,Actions,Support,Http}/**/Series*.php'
---

# Models Actions Support Http

## Keep TMDB episode titles separate from custom titles
Store user overrides only in nullable series_episodes.custom_name. TMDB name remains immutable metadata; display and future canonical paths use custom_name ?? name, and resetting the override must relocate current media back to the TMDB-derived path.
