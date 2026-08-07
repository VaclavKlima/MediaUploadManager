---
paths:
  - 'app/{Actions,Http,Models,Support}/**'
---

# Actions Http Models Support

## Serialize ordinary admission and preserve idempotency
All ordinary upload admissions use the single database-backed `upload-admission:ordinary` cache lock, then transactionally recheck disk health, global canonical conflicts, active remaining reservations, and selected capacity. `(user_id, idempotency_key)` may replay only an exact unexpired pending reservation; rotate its token and expiry, while mismatched or inactive reuse returns `idempotency_conflict`.

## Keep tus transport control-plane only
Movie request bodies must stream from Nginx directly to tusd; PHP handles only bodyless authorization metadata and small authenticated hooks. Map POST/HEAD/PATCH/DELETE only to tus:create/read/write/terminate, keep tokens hashed and short-lived, and never serialize hook secrets or absolute media roots publicly.
