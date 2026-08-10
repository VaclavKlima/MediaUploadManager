---
paths:
  - 'app/{Actions,Models,Support/Media}/**'
---

# Actions Models Support Media

## Discard pre-claim replacements without touching the primary
A failed replacement with no processing claim may discard only its exact staged file and tus metadata. For same-disk/same-path replacement, the existing target is expected: allow discard only when it is still the confirmed active current primary and preserve it unchanged. Once a replacement processing claim exists, discard remains forbidden and retry/recovery must converge.
