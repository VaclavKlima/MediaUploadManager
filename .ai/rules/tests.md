---
paths:
  - 'tests/**'
---

# Tests

## Never run tests against the application database
Feature tests may use only the exact MySQL database `media_upload_manager_testing` or SQLite `:memory:`. Keep the guard in `Tests\TestCase` ahead of refresh hooks, and keep local/CI test configuration on the dedicated database.
