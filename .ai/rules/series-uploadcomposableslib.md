---
paths:
  - 'resources/js/{components/series-upload,composables,lib}/**'
---

# Series Uploadcomposableslib

## Keep episode review local and exact
Shows episode review stays page-local through Step 4. Preserve mappings for unchanged sources and same show/category, lazily hydrate seasons with stale-response guards, and require exact current-primary replacement confirmation before review can continue.
