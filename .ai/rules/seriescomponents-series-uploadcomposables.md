---
paths:
  - 'resources/js/{pages/series,components/series-upload,composables}/**'
---

# Seriescomponents Series Uploadcomposables

## Continue uploads on the same Show
Upload more episodes resets source, review, batch, queue, and transfer state but retains the confirmed Show. The next source selection refreshes that Show for the newly requested seasons and proceeds directly to episode review.
