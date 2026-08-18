---
paths:
  - '{.agents/skills/chrome-devtools-browser/**,AGENTS.md}'
---

# Chrome Devtools Browser

## Clean up Chrome DevTools automation
Every Chrome DevTools MCP task must activate the project chrome-devtools-browser skill. After the final DevTools call, close task-created pages and TERM only the explicitly validated top-level automated Chrome using the exact chrome-devtools-mcp profile; never broadly kill normal Chrome or unrelated MCP processes.
