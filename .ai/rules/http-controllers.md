---
paths:
  - '{app/Actions/ProvisionLocalAgent.php,app/Http/Controllers/LocalAgentLoginController.php,routes/web.php,config/auth.php,AGENTS.md}'
---

# Http Controllers

## Keep AI browser login local and loopback-only
The credential-free AI browser login must fail with 404 unless APP_ENV is local, LOCAL_AGENT_LOGIN_ENABLED is true, and the client is loopback. It may provision only the reserved local AI administrator, must never expose its generated password, and should preserve Laravel's intended redirect.
