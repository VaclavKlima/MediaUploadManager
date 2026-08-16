---
paths:
  - '{app/Actions/**,app/Enums/UploadStatus.php,resources/js/components/series-upload/**}'
---

# Series Upload

## Expired Show items require explicit skip acknowledgement
An expired Series upload may transition to cancelled only after an explicit user-confirmed skip. Preserve expired_at as audit history, discard only safe staged state, and then unlock sequential advancement.
