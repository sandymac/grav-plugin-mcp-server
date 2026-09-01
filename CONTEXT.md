# Context

Glossary of canonical terms for this codebase. Definitions only — no implementation detail.

## Terms

### Core tool
An MCP tool hand-written in this plugin (`classes/Tools/*Tools.php`), mirroring a
grav-plugin-api REST endpoint. The set is fixed at release time.

### Plugin tool
An MCP tool a *third-party Grav plugin* publishes through the api plugin's manifest
mechanism (`mcp.yaml` at the plugin root, or the `onApiMcpTools` event), served by
`GET /mcp/tools` and registered by this server at request time. Named to match the
upstream contract (grav-mcp uses the same term), even for event-sourced entries that
have no manifest file behind them.

### Scope cap
The `api.*` scope list carried by a minted API key, limiting it to a subset of the
owning account's permissions. An **unscoped** key (empty list) is capped only by the
account's own permissions.

### Limit-nothing consent
An OAuth authorization request whose scope limits nothing: no scope, the `*` wildcard,
or the whole advertised vocabulary (what claude.ai sends by default). Shown on the
consent screen as "full account access".
