# Design and requirements

What the app is for, the decisions behind it, and the requirements. The IDs (FR-1, NFR-7 and so on) are referred to from comments in the code.

## Purpose

A self-hostable, multi-user web app that exposes a **curated set of ServerAvatar actions as MCP tools**, so a team can use its servers through an AI client without sharing ServerAvatar credentials. An admin creates user accounts. Each user saves **one** ServerAvatar API token in the app. They then connect an AI client (mainly claude.ai as a custom connector) to the app's MCP endpoint using OAuth 2.1. The client never sees the ServerAvatar token.

## Decisions

- **No registration anywhere.** There is no sign-up page or route; people can only log in.
- **Admins are created only from the terminal** (`php artisan serveravatar:create-admin`). The dashboard creates normal users only and cannot create, promote or demote an admin.
- **One ServerAvatar token per user**, strictly per user. Stored encrypted and decrypted only at the moment of an API call. Nothing else is encrypted for later decryption: passwords are hashed (argon2id) and OAuth tokens are managed by Passport.
- **OAuth 2.1 is the only way in** for AI clients (no long-lived keys). If a client people need cannot do OAuth, that is reconsidered.
- **Three permission levels** (read, action, full), chosen per connection on the consent screen and changeable afterwards.
- **A curated set of 24 tools**, one per task with explicit parameters. No `operation` enum tools and no mirror of the whole ServerAvatar API.
- **No destructive tools in v1.** They may come later behind an explicit confirmation.
- **Every MCP request is logged** in the app's own database, for users and admins alike.
- **A user created by an admin, or whose password an admin resets, must choose a new password before doing anything else.** An admin reset also disconnects that user's AI clients.
- **Open to the internet by design.** The portal and `/mcp` accept connections from any address, over HTTPS. The login is the main protection. ServerAvatar's optional per-token IP allowlist can be used as extra hardening, but the app does not depend on it.
- **Verify against the live API before building on it.** The ServerAvatar client and fixtures follow what the API actually returns.
- Stack: PHP 8.2+, Laravel 12, MySQL or MariaDB, Inertia + React + TypeScript + Tailwind + shadcn/ui, Pest, Pint, `laravel/mcp`, Laravel Passport. MIT licence.

## Functional requirements

### Accounts
- FR-1 Email and password login (argon2id), password reset by email (SMTP configurable). No registration: `/register` does not exist (404). Any password change or reset (email, settings, forced, admin) ends the user's other browser sessions and disconnects every AI client; a change in settings keeps the session that made it.
- FR-1a The first and every other admin is created by `php artisan serveravatar:create-admin` (password from a hidden prompt or the `ADMIN_PASSWORD` variable, never an argument). There is no promote or demote path: a user never becomes an admin later.
- FR-1b A user created by an admin, or whose password an admin resets, must set a new password: until then every page (including the OAuth consent screen) redirects to `/set-password`, and only that page and logout work. Changing it clears the flag and is logged.
- FR-2 An admin creates normal users (name, email, initial password; the role is always `user`), resets their passwords, disables and deletes them. A disabled user is signed out and cannot use their MCP connections. Resetting a password, disabling or deleting a user revokes all of that user's OAuth connections, and the number revoked is recorded in the activity log.
- FR-3 Admins cannot disable or delete themselves or any other admin from the dashboard. Admins cannot read anyone's ServerAvatar token.

### Token vault
- FR-4 Each user has at most one ServerAvatar token: add, test, replace and delete it. With no token, the user cannot authorize an MCP connection (the consent screen sends them to the token page). Deleting it disconnects all their connections until a new one is saved. Replacing it keeps connections working.
- FR-5 The token is stored encrypted (key = `APP_KEY`). It is decrypted only at the moment of an outbound ServerAvatar call, held in memory for that one call, and never logged, cached in plain text, or sent back to the browser or MCP client. Only the last 4 characters are shown.
- FR-6 A token is validated against the ServerAvatar API on save, and the linked organization(s) are shown.
- FR-7 The ServerAvatar API base URL is configurable.

### MCP access
- FR-8 Remote MCP over Streamable HTTP at `/mcp`.
- FR-9 OAuth 2.1 for AI clients: discovery metadata, dynamic client registration, PKCE, and a consent screen where the signed-in user picks the permission level to grant.
- FR-10 No bearer keys in v1: every client connects through OAuth.
- FR-11 Permission levels: **read**, **action** (read plus safe actions such as restart), **full** (adds create tools). Tools above the level are not listed and are refused if called.
- FR-12 `/connections` shows a user every AI client they have connected: client name, level, connected date, last used. They can revoke any of them at once (access and refresh tokens die immediately) and change a connection's level without reconnecting. Revokes are logged.

### Tools *(M4, M5)*
- FR-13 24 tools (and a `ping`) with JSON schemas, clear descriptions and the right MCP annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`).
- FR-14 Tools return trimmed, model-friendly results: only useful fields, pagination handled, large payloads (logs) capped.
- FR-15 ServerAvatar errors and rate limits become clear tool errors. Retry with backoff on 429, and on 5xx or connection errors for GET only.
- FR-16 Tools that need an organization id accept it as a parameter, and `list_organizations` is the entry point.

### Audit and operations
- FR-17 An activity log in the app's database for every MCP request from every user and admin: time, user, role, client name, IP, user agent, MCP method, tool, redacted arguments, status, error and duration. Allowed, refused and failed calls are all logged. Sign-ins (success and failure) and admin actions are logged too.
- FR-18 `/activity` shows a user their own rows; `/admin/activity` shows every row, with filters. Rows hold redacted arguments only; secrets are never stored. Rows are kept 90 days, then pruned (configurable). `/up` is the health endpoint.

## Non-functional requirements

- NFR-1 TLS in production, CSRF, secure cookies, rate limiting on login, OAuth and MCP endpoints; secrets never logged.
- NFR-2 Tenant isolation: a connection can only reach its owner's token (explicit tests).
- NFR-3 Self-hosting: `docker compose up` with app and database; configuration by environment variables; documented backups and `APP_KEY` handling.
- NFR-4 Tool-call overhead under 100 ms beyond ServerAvatar's own latency.
- NFR-5 Works with claude.ai, Claude Desktop and Claude Code, and Cursor, over OAuth.
- NFR-6 Tests: unit, API contract tests built from real captured fixtures, MCP integration tests.
- NFR-7 Behind a reverse proxy, trusted proxies are configured so the client IP in the activity log is real and cannot be forged (tested).
- NFR-8 The portal is public, so: HTTPS only, login rate limiting and strong password rules. Two-factor sign-in is deferred to a later version.

## Out of scope for v1

Billing, SSO/SAML, self-registration or any sign-up page, creating admins from the UI, bearer API keys, teams and shared tokens, several tokens per user, destructive tools, the WordPress toolkit, Cloudflare, firewall and fail2ban, the file manager, git deployment, and any ServerAvatar area not in [tools.md](tools.md).
