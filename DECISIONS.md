# Decisions

Settled questions, kept so they don't get re-litigated. If you're about to reverse one,
read its rationale first — most were validated against a live deployment.

## Architecture

1. **Stateless Streamable HTTP**: single POST endpoint, one JSON-RPC message per request,
   plain JSON responses. No SSE, no sessions, no batching — all optional for a stateless
   server in the MCP revisions we support, and newer revisions (2026-07-28) removed
   sessions and batching from the protocol outright. claude.ai and Claude Code's HTTP
   transports work against exactly this.
2. **Translation layer over grav-plugin-api**: the api plugin is a hard dependency, and
   this plugin duplicates none of its logic. Auth reuses `ApiKeyAuthenticator` /
   `ApiKeyManager`; every tool dispatches in-process through the api plugin's own
   `ApiRouter::process()` (via `classes/ApiBridge.php`), which runs its entire pipeline —
   auth, scope cap, AdminProxy, audit context, demo gate, rate limit, RFC 7807 errors — so
   we never re-derive authority.
3. **OAuth access tokens ARE `grav_` API keys**: the OAuth layer (needed by claude.ai
   custom connectors) mints keys via `ApiKeyManager::generateKey()` with day-based expiry,
   and refresh-token rotation revokes the superseded key. One credential system, one
   revocation path (`bin/plugin api keys:list`), zero token-validation code of our own.
4. **The target surface is grav-plugin-api** (2026-08-19): The design authority is the api
   plugin's route table and permission tree, and the goal is feature parity with its REST
   surface — excluding UI plumbing that serves the admin SPA (script bundles,
   field/widget/panel discovery, the SPA's own translation dictionary), binary downloads,
   and the public auth/login flows. Tool names and schemas may diverge from grav-mcp
   wherever the API warrants it.

## Non-decision: the OAuth server stays inside this plugin

No second consumer exists, and `classes/OAuth/` touches the plugin at only two points
(route dispatch in the entry file, `WWW-Authenticate` in McpServer) — extraction stays
cheap if a real consumer ever appears. The generic capability mostly exists without a
split: access tokens are `grav_` API keys, so any client that completes DCR + code/PKCE
gets a token valid for the whole `/api` surface; integrating another tool is just adding
its host to `oauth.allowed_redirect_hosts`.

**Consequence to remember**: an OAuth token approved for claude.ai also works on `/api/*`
with the approving user's full permissions. If MCP and API access ever need separating,
that's API-key scopes — not a plugin split.

## Non-decision: no conflict with login-oauth2 (or other Grav OAuth plugins)

trilbymedia/grav-plugin-login-oauth2 and its kin are OAuth *clients* — external-provider
login for site users, hooked via Grav tasks — with no route or storage overlap with our
OAuth *server*. Nothing to reuse either: they wrap league/oauth2-client, which an
authorization server doesn't need. One interaction: SSO-only accounts (no local password)
can't sign in on our consent form; if that ever matters, session-based consent is the fix
(same shape as the 2FA support).

## Deliberately absent

- **Audit logging** — already covered: every tool call dispatches through `ApiRouter`, so
  the api plugin audits it exactly as it audits a REST call.
- **Origin-header validation (DNS-rebinding guard)** — that guard protects localhost
  servers with *ambient* auth. This endpoint accepts only `Authorization: Bearer` (no
  cookies, no session), so a hostile page can't forge an authenticated request no matter
  what Origin it sends. Revisit only if cookie/session auth is ever added.
- **Rate limiting** — the api plugin's own rate limiting already applies to every tool
  call; add more only if abuse shows up.
- **SSE / sessions** — see Architecture #1.

## Testing strategy

- `tests/smoke.php` — protocol dispatch with bare PHP, no Grav install.
- `tests/param-map.php` — runs every tool handler against a recording ApiBridge and
  cross-references what it sends (method, path, query/body keys, permission) against the
  api plugin's route table and the matched controller's own source. Born from an audit
  that found five shipped param-name bugs; this is the drift detector the `upstream-drift`
  workflow runs against new api releases (see CLAUDE.md's triage runbook).
- `tests/permission-gate.php` — the consent-screen permission check against real Grav +
  api-plugin classes (skips cleanly without a `.gravtest/` install).
- `tests/oauth-flow.php` — drives the OAuth server through register → authorize →
  consent → token → refresh → revoke, one child process per request (the handlers
  respond-and-exit like real HTTP). Asserts the security behaviors: redirect-host
  allowlist, open-redirect guard, consent-form HMAC, brute-force lockout by IP and
  username, PKCE S256, single-use codes, refresh rotation revoking the superseded
  key, and oracle-free revocation. Also needs `.gravtest/`, skips cleanly without.
- Integration environment (first run ~5 min; `.gravtest/` is gitignored): unzip
  [grav-admin latest](https://getgrav.org/download/core/grav-admin/latest) into
  `.gravtest/grav-admin`, then run it with `php -S 0.0.0.0:8000 system/router.php` (Docker
  image: php:8.3-cli + zip/gd) with this repo mounted at `user/plugins/mcp-server`. Inside:
  `bin/gpm install api -y`, `bin/plugin login new-user`, `bin/plugin api keys:generate`.
  Gotcha: in PowerShell 7, quote curl JSON bodies plainly (`-d '{"a":1}'`) — `\"`-escaping
  sends literal backslashes.
