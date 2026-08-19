<img src="assets/mcp-logo.svg" alt="Model Context Protocol" width="64" align="right">

# Grav MCP Server Plugin

Serves a [Model Context Protocol](https://modelcontextprotocol.io) endpoint directly from your Grav site over Streamable HTTP. Point your MCP client or LLM/AI agent at `https://yoursite.com/mcp`.

Works with **hosted connectors** (built-in OAuth 2.1 authorization server with dynamic client registration) and **CLI or desktop clients** (OAuth or a static API key header).

## How it relates to the existing pieces

- [grav-plugin-api](https://github.com/getgrav/grav-plugin-api) — REST API + API key management. **Required**: this plugin is a thin MCP translation layer over it — Bearer auth reuses its `grav_...` API keys, OAuth access tokens are minted through its `ApiKeyManager`, and tools reuse its services rather than duplicating them.
- [grav-mcp](https://github.com/getgrav/grav-mcp) — the local-process MCP server that bridges stdio → Grav REST API. This plugin replaces it by serving MCP from the site itself; the tool surface tracks the API plugin directly (see [DECISIONS.md](DECISIONS.md)).

## Requirements

- Grav 2.0.17+
- PHP 8.3+ (Grav 2 core's own floor)
- [API plugin](https://github.com/getgrav/grav-plugin-api) **1.0.19 or newer**, installed and enabled, with at least one API key:
  `bin/plugin api keys:generate --user=admin --name="MCP"`

Tools map 1:1 onto API plugin endpoints, so an older API plugin 404s on tools backed by
newer endpoints. GPM enforces the version floor; a git clone doesn't — the plugin then
logs a warning at client handshake, and `site_info` reports `api_plugin_version`.

## Installation

Clone into your site's plugin folder — the directory name must be `mcp-server`:

```bash
cd user/plugins
git clone https://github.com/sandymac/grav-plugin-mcp-server mcp-server
```

## Configuration

`user/config/plugins/mcp-server.yaml`:

```yaml
enabled: true
route: /mcp
require_auth: true   # never disable on a public site

oauth:
  enabled: true                # required for hosted connectors (interactive sign-in)
  access_token_days: 7
  refresh_token_days: 90
  require_permission: api.access  # permission needed to approve a connection ("API Access" in account permissions)
  allowed_redirect_hosts:      # localhost always allowed; empty = any https host
    - claude.ai
    - claude.com
```

Your web server must pass unmatched `/.well-known/*` paths through to Grav's `index.php` (the standard `try_files $uri /index.php?$args` nginx setup already does).

## Client setup

### Hosted connector (OAuth)

In the client's connector settings, add a custom connector with URL `https://grav.example.com/mcp`. Leave any OAuth client ID/secret fields empty — the client registers itself via dynamic client registration. When prompted, sign in with your Grav credentials on the consent screen and approve.

Behind the scenes: the client discovers `/.well-known/oauth-protected-resource/mcp` → registers at `/mcp/oauth/register` → authorization-code + PKCE flow at `/mcp/oauth/authorize` → tokens from `/mcp/oauth/token`. The access token is a real `grav_` API key (visible in `bin/plugin api keys:list`); revoke it at `/mcp/oauth/revoke` (revoking either the refresh token or access key revokes both).

### CLI or desktop client (OAuth or API key)

Every client needs the same two pieces: the endpoint URL, and — to skip the browser flow — an `Authorization: Bearer grav_...` header. For example, with Claude Code:

```bash
claude mcp add --transport http grav https://grav.example.com/mcp --header "Authorization: Bearer grav_your_key_here"
```

(omit `--header` to use the OAuth sign-in instead). Or in an `.mcp.json`:

```json
{
  "mcpServers": {
    "grav": {
      "type": "http",
      "url": "https://grav.example.com/mcp",
      "headers": {
        "Authorization": "Bearer grav_your_key_here"
      }
    }
  }
}
```

## Verify with curl

```bash
curl -s https://grav.example.com/mcp \
  -H "Authorization: Bearer grav_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'
```

## Status

49 tools across 11 domains (pages, multilingual, media, config, users, GPM, system, dashboard, webhooks, blueprints, plugins) plus `site_info`, 5 resources, and 6 prompts, tracking the API plugin's REST surface. Every tool call dispatches in-process through the API plugin's own router, so its permission scopes, page ACLs, ETag conflict handling, audit trail, and rate limiting all apply unchanged. A connected client only sees the tools its key scopes *and* its account's permissions allow — a limited bot account advertises a correspondingly small tool list. Validated end-to-end on a live deployment as both a claude.ai custom connector and a Claude Code HTTP server.

## Development

Protocol-level smoke test (no Grav install needed):

```bash
php tests/smoke.php
```
