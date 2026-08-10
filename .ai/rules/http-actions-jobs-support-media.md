---
paths:
  - 'app/{Http,Actions,Jobs,Support/Media}/**'
---

# Http Actions Jobs Support Media

## Expose scans as a prioritized task queue
Library scan UI data is a flat queue: identity/conflict tasks first, import and safe retries second, missing tracked files third. Import/delete processing stays out of the actionable queue and is counted separately; automatic folder cleanup remains background maintenance.
