---
paths:
  - 'app/{Jobs,Support/Media}/**'
---

# Jobs Support Media

## Finalize uploads with claims and exclusive hard links
Persist the validated processing claim before promotion and never overwrite or copy movie bytes. Recheck guarded paths, mount identity, sizes, conflicts, and regular-file state immediately before creating an exclusive same-filesystem hard link; retries may only recover stage-only, same-inode stage+target, claimed target-only, or already-committed states. Any different inode, symlink, wrong size, missing claim, mount change, or contradictory database state must fail closed; only delete the exact completed tus .info sidecar after the transaction commits.
