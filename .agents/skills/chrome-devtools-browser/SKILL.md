---
name: chrome-devtools-browser
description: Use for local application verification through the Chrome DevTools MCP, including inspecting pages, network requests, console messages, and authenticated Laravel routes. Always activate when using any mcp__chrome_devtools__ tool in this project. Requires closing created pages and terminating only the dedicated automated Chrome process after the final DevTools call.
---

# Chrome DevTools Browser

Verify the local Herd application with Chrome DevTools and leave no automated Chrome process holding the shared DevTools profile.

## Verification workflow

1. Resolve project URLs with Laravel Boost `get-absolute-url`.
2. Open verification pages in a named isolated context when available, and track every page created.
3. When authentication redirects to login, visit the protected target first and then navigate to `/local/agent-login` so Laravel restores the intended URL.
4. Inspect the resulting page, relevant network requests, and current console messages. Treat old Laravel browser-log entries as stale.
5. Perform all DevTools inspection before cleanup. Do not issue another DevTools call after terminating the automated Chrome process.

## Required cleanup

Cleanup is part of completing every DevTools task, including failed or partial verification:

1. Close every page created for the task with `mcp__chrome_devtools__close_page`. Keep only a pre-existing blank page when the tool refuses to close its final page.
2. Inspect candidate processes before terminating anything. Resolve explicit PIDs and confirm the top-level command contains all of:
   - `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`
   - `--enable-automation`
   - `--user-data-dir=/Users/vaclavklima/.cache/chrome-devtools-mcp/chrome-profile`
   - no `--type=` helper-process argument
3. Send `TERM` only to the validated top-level automated Chrome PID. Use an explicit PID; never use `pkill`, `killall`, a wildcard, or a command that could match the user's ordinary Chrome browser.
4. If process inspection or termination needs elevated permission, request it through the execution tool. If cleanup cannot be completed, state that clearly in the final response.

Stopping this dedicated automation process is authorized for project DevTools cleanup. It does not authorize stopping unrelated MCP servers, helper processes, or normal Chrome sessions.
