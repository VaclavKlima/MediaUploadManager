---
paths:
  - 'app/{Actions,Console,Http,Support}/**'
---

# Actions Console Http Support

## Keep issued credentials terminal-only
Bootstrap and recovery generate one-time passwords internally, persist only hashes, and display plaintext exactly once in the invoking terminal. Never accept passwords through command options or include credentials/tokens in structured audit context. Setup captures the administrator's final name and email, so onboarding replaces only the password.
