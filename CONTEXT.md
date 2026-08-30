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

**Live route table**:
The complete set of (method, path, handler) routes the api plugin's router would serve right
now on this site — core routes plus everything third-party plugins registered. Knowable only
at runtime.
_Avoid_: route cache, route list

**Route detail**:
What a route needs and enforces — its permission, the query/body keys its controller reads,
required fields — recovered by static analysis of the controller's source, never by executing it.

**Route introspection**:
The `list_api_routes` tool: the live route table, each row carrying route detail where the
analyzer can recover it. The discovery companion of the raw passthrough.
_Avoid_: api docs, catalog
