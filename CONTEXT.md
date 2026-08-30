# grav-plugin-mcp-server

MCP translation layer over grav-plugin-api: exposes that plugin's REST surface as MCP tools
served by the Grav site itself.

## Language

**Curated tool**:
An MCP tool with a compile-time-fixed method, path, parameter map, and declared permission,
mirroring one api-plugin endpoint (the contents of `classes/Tools/*`).
_Avoid_: wrapper, endpoint tool

**Raw passthrough**:
The `api_request` tool: caller supplies method, path, query, and body; the request is dispatched
through the api plugin's own router with no route filtering. The escape hatch for routes that
have no curated tool yet (or ever).
_Avoid_: proxy, generic tool

**Unlock-only permission**:
A permission that only controls whether an account can *see and invoke* a tool; it grants no
authority over what a dispatched request may do — the api plugin's per-route enforcement still
applies to every request. `mcp.raw` is unlock-only.
_Avoid_: bypass, admin gate

**Upstream enforcement**:
The api plugin's `requirePermission()` check inside each controller action — the single
authoritative permission check on every dispatch. MCP-side checks are visibility UX only.
