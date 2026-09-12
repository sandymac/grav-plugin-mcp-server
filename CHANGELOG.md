# v1.3.2
## 2026-09-12

1. [](#new)
    * `clear_twig_content_events` empties the Twig-in-Content diagnostics log — the tokens the sandbox refused in page content, which `run_reports` reads — once the flagged pages have been dealt with. The endpoint was a deliberate skip in 1.3.0 because it was a destructive route behind a read permission ([getgrav/grav-plugin-api#35](https://github.com/getgrav/grav-plugin-api/issues/35)); api plugin 1.0.30 moved its gate to `api.system.write`, so the tool declares the permission the route enforces.
    * `site_info` reports `markdown_output`: whether Grav 2.1's Markdown output is on, in which case any page can be read as rendered Markdown at its URL plus `.md`. `null` on a Grav that predates the feature.
1. [](#improved)
    * Triaged against api plugin 1.0.30, 1.0.31 and 1.0.32 (the Grav 2.1 releases). **The api floor rises to 1.0.30** — the release with the corrected gate above, and the last one a Grav 2.0 site can install, since 1.0.31 and 1.0.32 require Grav 2.1.0. 1.0.30 also makes an unscoped key's account the active Grav user for the duration of a call, so plugins and plugin-published tools see who is calling instead of a guest (a key with a scope list still looks like a guest to them). 1.0.31 accepts licence keys from stores other than Grav Premium; 1.0.32 fixes the Grav-upgrade override so it also lifts a pending-plugin-updates block. No other endpoint changes.

# v1.3.1
## 2026-09-10

1. [](#new)
    * `translate_ui_strings` prompt: the workflow for filling a language's missing interface strings — check machine-translation readiness with `get_translations`, find the gaps with `search_translation_keys`, propose with `machine_translate`, review the proposals, commit with `manage_translation_overrides`, re-check coverage.
1. [](#bugfix)
    * The `site_health_check` prompt still named `list_backups`, which 1.3.0 folded into `manage_backups`; it now says `manage_backups` with action "list". Smoke now renders every prompt and fails if one names a tool that does not exist.
1. [](#improved)
    * Triaged against api plugin 1.0.29: no endpoint changes (the route table is unchanged at 211 routes; param-map is green against it). 1.0.29 fixes modular-page preview and the listing of pages whose header sets an empty route alias. The api floor stays at 1.0.22.

# v1.3.0
## 2026-09-09

1. [](#new)
    * Closes the REST-parity gap against api plugin 1.0.28: every api route is now either reached by a tool or recorded as a deliberate skip, and `tests/param-map.php` fails when a route is neither — so an api release that adds an endpoint turns the upstream-drift workflow red until it is triaged.
    * User groups: `get_groups` lists groups or shows one; `manage_groups` creates, updates and deletes them (super-admin only). `get_blueprint` gains the `group` and `group_new` types.
    * Invitations: `manage_invitations` lists, creates, deletes and resends account invitations; create returns the accept link to hand to the invitee.
    * Media metadata: `get_media_meta` and `update_media_meta` read, set and clear alt text, title, caption, description and tags on page and site media, including one update across up to 50 site files; `rename_media` renames or moves a site media file; `manage_media_folder` gains an `order` action for a folder's display order.
    * Audit trail: `get_audit_log` reads the api plugin's audit status, filtered events and facets (super-admin only).
    * Translations editor, a new domain under `api.translations.*`: `get_translations`, `search_translation_keys`, `manage_translation_overrides` and `machine_translate` cover the twelve `/i18n/*` routes — finding untranslated strings, editing `user/languages/<lang>.yaml`, importing from the legacy translation-strings plugin, and proposing machine translations through the ai-translate plugin.
    * Housekeeping: `clear_log` empties a log file (super-admin only); `revert_config` drops configuration overrides or resets a scope to inherited values; `get_logs` gains `file` and a `files` view; `get_dashboard` gains `popularity`, `feed` and `security_probe` views; `run_reports` gains the Twig-in-Content scan, per-page status and sandbox-policy reports; `manage_page_translation` gains `sync`; `get_page_preview_token` mints a preview link for an unpublished page; `get_webhooks` shows one webhook when `webhook_id` is given.
1. [](#improved)
    * **Renamed:** `create_environment` is now `manage_environments` (actions create, delete), and `create_backup` plus `list_backups` are now `manage_backups` (actions list, create, delete). Clients pick up the new names on their next tools/list.
    * The OAuth consent screen and `scopes_supported` now offer `api.super`, `api.translations.read` and `api.translations.write`, derived from the tool surface as before.
    * `whoami` now reports the key's scope list and splits hidden tools into `hidden_by_key_scope` and `hidden_by_account_permission`, with a note explaining the first, and the tools/call error for a hidden tool says which of the two applies. A key's scopes are fixed at consent and never widened silently, so after an upgrade that adds permissions — this release adds `api.translations.*` and `api.super` — the account can hold a permission the key was never granted, and the old single `hidden_by_missing_permission` list made that look like a missing grant. Reconnecting the connector re-runs consent with the current scopes. `tool_access` also counts plugin-published tools now (`core_tools` and `plugin_tools`), so its total reconciles with `discover_plugins`; `discover_plugins` says that everything it lists is filtered to the caller's permissions.
    * The consent screen's full-access headline no longer promises "tools that plugins add later" unconditionally; it now says that holds unless the limit box below is ticked, which freezes the grant to the listed permissions. The limit box's label no longer says only *future* plugin tools are excluded: the offered list is the core tools' permissions, so plugin-published tools whose permissions live outside it — Flex Objects today, anything added later — are excluded by a frozen grant whether or not the plugin is already installed.
    * Deliberate skips — public auth flows, binary downloads, Admin Next SPA plumbing and preferences, 2FA, avatars, demo mode, GPM repository extras, and the two Twig-content writes (see DECISIONS.md #4 and [getgrav/grav-plugin-api#35](https://github.com/getgrav/grav-plugin-api/issues/35)) — are recorded in param-map's `$skippedRoutes` with a reason each.

# v1.2.7
## 2026-09-09

1. [](#improved)
    * Triaged against api plugin 1.0.26, 1.0.27 and 1.0.28: no endpoint changes affect the tool surface. 1.0.26 adds a `settings_page` key to plugin listings, which `get_packages` already passes through; 1.0.27 fixes secret masking when a plugin's settings are saved; 1.0.28 ships the manifest version 2 `body` key ([getgrav/grav-plugin-api#32](https://github.com/getgrav/grav-plugin-api/issues/32)) that this plugin has read since 1.2.5. The api floor stays at 1.0.22.
    * README: a manifest that names a `body` argument (version 2, as Flex Objects 1.4.13 ships) is only served by api plugin 1.0.28 or later; an older api plugin skips the whole manifest with a warning, so those tools do not appear until the api plugin is updated.

# v1.2.6
## 2026-09-03

1. [](#bugfix)
    * A plugin tool whose manifest requires its `body` envelope now refuses a call that omits it, or passes something other than an object, before anything reaches the api, instead of sending a request with no body. An envelope the manifest leaves optional still sends no body when omitted, matching grav-mcp.
    * A query argument that cannot be JSON-encoded (invalid UTF-8) is reported as a tool error instead of being sent as the string "false".

# v1.2.5
## 2026-09-03

1. [](#new)
    * Plugin tools whose manifest names a `body` envelope property (manifest version 2, [getgrav/grav-plugin-api#32](https://github.com/getgrav/grav-plugin-api/issues/32)) now send that one argument's value verbatim as the request body, so blueprint fields never share a namespace with path or query parameters ([#14](https://github.com/sandymac/grav-plugin-mcp-server/issues/14)). An older api plugin that never sends `body` degrades safely to the existing query/body split.
1. [](#bugfix)
    * An object- or array-typed argument routed to the query string is now JSON-encoded instead of arriving at the api route as the literal string "Array".
1. [](#improved)
    * Triaged against api plugin 1.0.24 and 1.0.25: no endpoint changes affect the tool surface (1.0.24 adds a GPM generation check on plugin install, 1.0.25 has `onApiPageUpdated` report the previous template), so the api floor stays at 1.0.22.

# v1.2.4
## 2026-09-03

1. [](#bugfix)
    * Plugin tools whose manifest input schema sets `additionalProperties: true` at the root now pass undeclared arguments through to the api route (GET as query, other methods into the JSON body) instead of silently dropping them. The api plugin's manifest spec allows the keyword there for free-form bodies such as blueprint-defined fields; before, such a tool validated and listed fine but every undeclared field vanished on the way to the route. Tools without the keyword are unchanged.

# v1.2.3
## 2026-09-03

1. [](#improved)
    * Requires Grav 2.0.23+, the release whose `Uri::ip()` reads the caller address from `$_SERVER` (getgrav/grav#4275). The plugin's own `$_SERVER` fallback for OAuth throttle keys and log lines is gone with it (issue #9); an address Grav cannot validate still gets a random per-request key rather than a shared bucket.
    * Triaged against api plugin 1.0.23: no endpoint changes affect the tool surface (its additions are the plugin `settings_route` field, an admin font choice, Page Statistics user-agent exclusions, and media events), so the api floor stays at 1.0.22.

# v1.2.2
## 2026-09-02

1. [](#improved)
    * `grok.com` joins the default `oauth.allowed_redirect_hosts`, so Grok's connector can register without a config change (issue #10). Sites that already set the list in `user/config/plugins/mcp-server.yaml` override the default and need to add the host themselves.

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
