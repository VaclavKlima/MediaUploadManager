---
paths:
  - '{app/Support/Media/LocalTusDevelopmentEnvironment.php,deploy/tus/**}'
---

# Tus

## Use tusd v2.10 CLI flags
For pinned tusd v2.10, termination is enabled by default: never pass the removed `-enable-termination` flag and do not pass `-disable-termination`. Use `-upload-dir` (not `-dir`) for local metadata storage, and pass hook backoff as a Go duration such as `1s`.
