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
