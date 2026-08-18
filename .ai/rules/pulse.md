---
paths:
  - '{app/Support/Pulse/**,app/Livewire/Pulse/**,resources/views/livewire/pulse/**,config/pulse.php}'
---

# Pulse

## Keep Pulse incident exports sanitized at rest
Pulse exception context keeps only the latest sample per exception class and application location. Sanitize before writing to Pulse: never store or export request bodies, query strings, headers, cookies, sessions, route parameters, environment values, serialized job payloads, credentials, or non-application absolute paths. The built-in Exceptions card remains the source of aggregate counts.
