# grav-plugin-mcp-server

A Grav CMS plugin (PHP 8.3+, matching Grav 2 core; Grav 2.0.17+) that serves an MCP endpoint over Streamable HTTP from the site itself. See DECISIONS.md for architecture decisions and settled non-decisions; README.md for usage.

## Layout

- `mcp-server.php` — plugin entry (`McpServerPlugin`): route interception (MCP endpoint, `/.well-known/oauth-*`, `{route}/oauth/*`), autoload
- `classes/McpServer.php` — Streamable HTTP transport + JSON-RPC dispatch (stateless: POST only, no SSE/sessions/batching)
- `classes/ToolRegistry.php` — MCP tool descriptors + dispatch; this is where the tool surface grows
- `classes/OAuth/OAuthServer.php` — OAuth 2.1 AS for claude.ai: discovery metadata, DCR, login/consent, token endpoint (PKCE S256 mandatory)
- `classes/OAuth/OAuthStore.php` — JSON store (`user/data/mcp-server/oauth.json`) for clients, code hashes, refresh-token hashes
- `tests/smoke.php` — protocol smoke test, runs with bare PHP, no Grav install (`php tests/smoke.php`)

## Key facts

- **Architecture**: this plugin is a thin MCP translation layer over grav-plugin-api (hard dependency). Never duplicate API-plugin logic — reuse its classes.
- Auth reuses grav-plugin-api's key store (`Grav\Plugin\Api\Auth\ApiKeyAuthenticator::authenticate(ServerRequestInterface): ?UserInterface`). Fail closed if that plugin is absent.
- **OAuth access tokens ARE `grav_` API keys** minted via `ApiKeyManager::generateKey()` (day-granularity expiry); refresh rotation revokes the old key. So the MCP request path has exactly one auth mechanism regardless of how the client obtained its token.
- Reference repo: [getgrav/grav-plugin-api](https://github.com/getgrav/grav-plugin-api). Its route table (`classes/Api/ApiRouter.php`) and permission tree are the design authority for the tool surface: the goal is feature parity with that REST API (UI-plumbing endpoints excluded — see DECISIONS.md). A tool's schema comes from what the api controller reads and enforces, not from any other MCP server.
- **Permissions are `api.*`, not `admin.*`**: Grav 2 + Admin2 use the `api.login` / `api.super` tree; `admin.login` / `admin.super` are the legacy classic-Admin (Grav 1.7) tree. To ask "does this account hold permission X?" outside a login session, use grav-plugin-api's `PermissionResolver::resolve()` — **never** `$user->authorize()`, which needs the `authenticated` property that only the Login plugin's session flow sets, and whose two core implementations disagree on what a scope argument means.
- Never commit API keys. Keys look like `grav_...` and live only in local config / `.mcp.json` files.
- No local PHP or Composer required — run PHP via Docker (e.g. `docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/smoke.php`).
- Keep it lazy: no new dependencies, no speculative abstractions. The composer.json exists for GPM metadata/autoload; the spl fallback in `mcp-server.php` means `vendor/` is optional.
- On a deployed site, PHP class changes take effect immediately; **YAML config changes need `bin/grav clearcache`** (the command is `clearcache`, not `clear-cache`).

## Keeping current with grav-plugin-api

This plugin mirrors the api plugin's endpoint surface, so **every api-plugin release needs
triage here**. The machinery:

- `tests/api-plugin.pin` — the last api release triaged against. `ci` clones this exact
  release, so ci answers "is this code good?" and never reddens from upstream movement.
- `.github/workflows/upstream-drift.yml` — weekly (and `workflow_dispatch`), runs
  `tests/param-map.php` against the api plugin's **latest GitHub release**. Red = a new
  release needs triage. (GitHub disables schedules after 60 days of repo inactivity —
  re-enable from the Actions tab if the repo has been quiet.)

### Triage runbook (when upstream-drift is red, or a new api release ships)

1. **See what changed.** Clone the new release and read its changelog; the endpoint-level
   truth is in its `classes/` controllers:
   ```
   git clone -c core.longpaths=true --depth 1 -b <NEW> https://github.com/getgrav/grav-plugin-api /tmp/api-new
   ```
2. **Reproduce.** `API_PLUGIN_DIR=/tmp/api-new php tests/param-map.php` (via Docker on a
   machine without PHP). Failures name the tool and the mismatched parameter/permission.
3. **Fix drift.** Tool descriptors + handlers live in `classes/Tools/*Tools.php`. A tool's
   param names must be what the api controller *reads*, and its `permission` must match
   what the route *enforces* — param-map checks both. Routes with identity- or
   argument-dependent enforcement carry a reviewed entry in param-map's
   `$permissionPolicy`. Mind the ceilings marked `ponytail:` in `tests/param-map.php` —
   some request sides are skipped as opaque.
4. **New endpoints default to adoption** — the target is feature parity with the api
   plugin's REST surface. Exceptions: UI plumbing that serves the admin SPA (script
   bundles, field discovery, SPA dictionaries) and binary downloads — record a skip in
   DECISIONS.md. Follow the pattern of the endpoint's domain in `classes/Tools/`
   (register new domains in `ToolRegistry`); the api controller's parameter reads and
   permission checks are the schema authority.
5. **Record the triage: bump `tests/api-plugin.pin` to the new release.** A pin bump with
   no code change is a valid outcome — it records "reviewed, nothing to do". If new
   endpoints were adopted, raise the api floor in all three places: `blueprints.yaml`
   dependencies, `McpServer::MIN_API_VERSION` (smoke asserts these two match), and the
   README Requirements line.
6. **Verify + ship.** Smoke, param-map, PHPStan all green → bump plugin version
   (blueprints.yaml + `McpServer::VERSION`, smoke asserts they match) → changelog entry →
   GitHub release. GPM picks up updates automatically.

Machine- and deployment-specific notes live in `CLAUDE.local.md` (untracked).
