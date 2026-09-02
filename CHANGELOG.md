# v1.2.1
## 2026-09-01

1. [](#bugfix)
    * OAuth throttle keys and log lines now see the real caller address on hosts where Grav's `Uri::ip()` reports `UNKNOWN` (it reads `getenv()`, which some SAPIs never populate), by falling back to `REMOTE_ADDR` the way the api plugin's audit trail does. Before, every caller shared one `UNKNOWN` bucket there: 10 registrations from anyone locked out every client's registration, and the per-IP consent-login lockout applied to everyone at once (issue #7). When no address is available at all, each request gets its own key instead of a shared bucket; the per-username lockout still applies.

# v1.2.0
## 2026-09-01

1. [](#new)
    * The OAuth consent screen lets the approving user narrow the grant: every permission it lists is a checkbox, and the minted key is capped at whatever stayed ticked (issue #1). Unticking anything on a "full account access" request turns it into an explicit cap; unticking everything re-renders the form with an error instead of minting or denying. The token response reports the narrower `scope`.
1. [](#improved)
    * Rejected OAuth client registrations now land in `grav.log` with the reason, the caller IP, and the request body. Hosted connectors (Gemini Spark, claude.ai) show only a generic "redirect URL was rejected" message, so the site log is where the offending `redirect_uri` can be seen; the `error_description` names the rejected URI too. Registration throttling (429) is logged as well.
    * Gemini (Spark custom connected apps) connects out of the box: its three redirect hosts (`oauth-redirect.googleusercontent.com` plus the `-sandbox` and `-test` variants) are in the default `allowed_redirect_hosts`. Gemini registers all three in one request, and a registration is refused if any listed `redirect_uri` is off the allowlist, so sites that customized the list need all three.

# v1.1.0
## 2026-09-01

1. [](#new)
    * Plugin tools: MCP tools that third-party Grav plugins publish (an `mcp.yaml` manifest, or the `onApiMcpTools` event) are now served alongside the built-in tools, fetched from the api plugin's `GET /mcp/tools`. Controlled by a new `plugin_tools` config toggle (on by default); `discover_plugins` now also reports which installed plugins publish tools of their own.
1. [](#improved)
    * OAuth consent that limits nothing (no scope, the wildcard, or the whole advertised vocabulary — what claude.ai requests by default) now mints an unscoped API key instead of one capped to the advertised list, so tools a plugin publishes under its own permissions are reachable too. The consent screen explains that full access includes tools plugins add later, and offers a checkbox to freeze the grant to today's listed vocabulary instead. Existing connections are unaffected until they re-consent.
    * `run_scheduler` now supports the api plugin's new run modes: `mode` picks which jobs run — `overdue` (the default: everything that has missed its scheduled time), `due` (this exact minute only), or `all` — and `job` runs a single job by id. The response reports which jobs ran and each one's outcome.
    * Requires api plugin 1.0.22+ (was 1.0.19) — the release the run modes actually shipped in, despite being announced in 1.0.21's changelog. 1.0.22 also brings the fix for API-key requests failing on sites where `user/data` is not writable by the web server.

# v1.0.3
## 2026-08-20

1. [](#improved)
    * OAuth clients' requested `scope` is now honored: recognized entries (`api.*`, `admin.super`, `*`) cap the minted API key, are echoed in the token response, and are shown on the consent screen — named "full account access" when the request limits nothing (no scope, the wildcard, or the whole advertised vocabulary, which is what claude.ai requests by default), with what that covers behind a collapsed disclosure and always a note that access is capped by the signed-in account's own permissions. A request whose entries are all unrecognized is refused with `invalid_scope` instead of silently receiving an unscoped key. Discovery metadata now advertises `scopes_supported`, derived from the tool surface.
    * MCP tool calls audit with the real caller: the api plugin's audit trail now records the caller's IP and User-Agent, and its per-IP rate limiting keys on the real address.
    * OAuth security events land in `grav.log`: consent approvals (user, grant, client, host, IP), lockouts after repeated failed consent logins, and refresh-token replays.
1. [](#bugfix)
    * Replaying a rotated-away refresh token now revokes the whole token family — the descendant refresh token and its access key — instead of leaving the successor alive (OAuth 2.1 treats rotation reuse as theft).
    * The OAuth store serializes mutations under an exclusive lock, so two simultaneous token requests can no longer both redeem the same single-use code or refresh token.
    * The consent page sends `X-Frame-Options: DENY` (RFC 6749 §10.13) and OAuth JSON responses send `Cache-Control: no-store` (§5.1).
    * Stateless `/mcp` responses no longer plant the shared front-end session cookie (port of the api plugin's `ApiRouter::protectSharedSession()`).

# v1.0.2
## 2026-08-19

1. [](#improved)
    * The public OAuth dynamic client registration endpoint (RFC 7591) is now bounded: at most 10 registrations per IP per 15-minute window (HTTP 429 beyond), and the OAuth store keeps at most 200 unconsented client registrations, evicting oldest-first — a registration flood can no longer grow `oauth.json` without bound. Clients holding a live code or refresh token are never evicted.
    * README: GPM (`bin/gpm install mcp-server`) documented as the preferred installation path
    * Dropped the redundant composer classmap for the plugin entry file (Grav loads it by slug convention)

# v1.0.1
## 2026-08-19

1. [](#bugfix)
    * The admin blueprint's generated permission → tool table no longer fatals Grav's GPM package enumeration when evaluated outside the plugin's own boot — e.g. `bin/gpm` on the CLI, or the plugin installed but disabled. The blueprint callable now registers the class autoloader itself and fails soft.

# v1.0.0
## 2026-08-19

1. [](#new)
    * Initial public release: a Model Context Protocol (MCP) endpoint served directly from a Grav site over Streamable HTTP
    * 50 tools across 11 domains tracking grav-plugin-api's REST surface (pages, multilingual, media, config, users, GPM, system, dashboard, webhooks, blueprints, plugins), plus 5 resources and 6 prompts
    * Every tool call dispatches in-process through the API plugin's own router — its permission checks, page ACLs, ETag conflict handling, audit trail, and rate limiting apply unchanged
    * Tool visibility mirrors the account's resolved permissions and API-key scopes; `whoami` reports the current account's grants and what each missing permission would unlock
    * Built-in OAuth 2.1 authorization server for hosted connectors: RFC 8414/9728 discovery, RFC 7591 dynamic client registration, PKCE S256, rotating refresh tokens, consent-screen 2FA and brute-force lockout, RFC 7009 revocation — access tokens are real `grav_` API keys minted through the API plugin
    * Admin configuration with connection instructions and a generated permission → tool reference table
    * Test suite: protocol smoke test, request/permission contract tests against the pinned API plugin release, OAuth flow security tests, PHPStan level 6 — all in CI, with a weekly workflow watching new API plugin releases for drift
