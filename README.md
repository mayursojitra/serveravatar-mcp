# ServerAvatar MCP

[![tests](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/tests.yml/badge.svg)](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/tests.yml)
[![lint](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/lint.yml/badge.svg)](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/lint.yml)
[![audit](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/audit.yml/badge.svg)](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/audit.yml)
[![docker](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/docker.yml/badge.svg)](https://github.com/mayursojitra/serveravatar-mcp/actions/workflows/docker.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> ServerAvatar MCP is an independent open-source project. It is not affiliated with, endorsed by or sponsored by ServerAvatar.

A self-hosted, multi-user web app that exposes a curated set of [ServerAvatar](https://serveravatar.com) actions to AI clients over MCP, so a team can use its servers without sharing ServerAvatar credentials.

**Status: version 1.0.2.** Accounts, OAuth, the MCP endpoint, the activity log and dashboard, the encrypted ServerAvatar token vault, the 16 read-only tools and the 8 tools that change things (restart, supervisors, applications, domains, SSL, databases, database user access, cron jobs) are built and tested. Seven of the eight (restart, create database, create application, add a domain, install SSL, change database user access, create a cron job) were also run on a test server. Of the 14 application types, five were created live (plain PHP, WordPress, phpMyAdmin, Joomla, Statamic); the other nine follow ServerAvatar's documentation and have not been run. The eighth write tool, `control_supervisor`, also follows the documentation only. A security review, a dependency audit and a load test are done (see [SECURITY.md](SECURITY.md)). The Docker setup was run by hand and is built and smoke-tested by CI on every push.

See the [changelog](CHANGELOG.md), the [design notes](docs/design.md), [what the live ServerAvatar API does](docs/api-notes.md) and the [tools](docs/tools.md).

## How it works

- **No sign-up.** People can only log in. **Admins are created only from the terminal**; the dashboard creates normal users, never admins.
- A user created by an admin, or whose password an admin resets, must choose a new password before doing anything else.
- An AI client (claude.ai, Claude Code, Cursor) connects to `https://your-host/mcp` using **OAuth 2.1**: it registers itself, the user signs in here and chooses a **permission level** (read, read + safe actions, or full), and the client receives short-lived tokens. Tools above that level are not listed and are refused if called.
- Each user saves **one ServerAvatar API token** on the **ServerAvatar token** page. It is checked with ServerAvatar before it is saved, stored encrypted, and never shown again (only its last four characters). A user without a token is sent to that page before they can connect an AI client. Deleting the token disconnects their AI clients.
- Users see and manage their own connections on **Connections**: change the level (it applies at once) or revoke.
- Every sign-in, admin action and MCP request is written to the activity log (kept 90 days): who, which client, which tool, the result and the time taken, with secrets removed from the arguments.
- When an admin resets a user's password, disables them or deletes them, that user's AI clients are disconnected at once.

## Development setup

Requires PHP 8.2+ (with `sodium` and `pdo_mysql`), Composer, Node 22+ and MySQL or MariaDB.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan passport:keys          # signing keys for access tokens (never committed)

# Database: create it once, then migrate. (.env uses 127.0.0.1:3306, user root, no password.)
mysql -uroot -e "CREATE DATABASE serveravatar_mcp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate

# The first admin (password from a hidden prompt, or the ADMIN_PASSWORD variable; never an argument)
php artisan serveravatar:create-admin you@example.com --name="Your Name"

npm run build     # or `npm run dev` while working on the UI
php artisan serve
```

Then open http://localhost:8000 and sign in. The admin creates users from **Admin → Users**. Run `php artisan schedule:work` (or a cron entry for `schedule:run`) so the daily clean-ups run.

`serveravatar:create-admin` refuses an email that already exists, so an existing account can never be turned into an admin.

## Connecting an AI client

Step-by-step guides for claude.ai, Claude Code and Cursor, and what to do when something goes wrong, are in [docs/setup.md](docs/setup.md). To run the app for a team, see [docs/self-hosting.md](docs/self-hosting.md). An optional [skill](skills/serveravatar-mcp/SKILL.md) teaches the AI to use the tools well. In short:

On the **Connections** page the app shows its MCP address (`…/mcp`). In claude.ai: Settings → Connectors → Add custom connector, paste the address. In Claude Code: `claude mcp add --transport http serveravatar <address>`, then `/mcp` to sign in. The client opens this app's sign-in and consent pages; after approval, ask Claude something like “list my servers” to check the connection. If you connected before the tools were added, remove and re-add the connector so the client reloads the tool list.

claude.ai calls your server from the internet, so it needs a public **HTTPS** address. To try it from a laptop with a tunnel (for example Cloudflare Tunnel or ngrok):

1. Start the tunnel to `http://127.0.0.1:8000` and note its `https://…` address.
2. In `.env` set `APP_URL=https://that-address` (the app answers **only** to this host, so every time the tunnel gets a new address, update it and run `php artisan config:clear`, or you will see `400 Invalid host`), `TRUSTED_PROXIES=127.0.0.1` (the tunnel program reaches the app from the same machine) and **`APP_DEBUG=false`**. A tunnel is public, and debug mode would show stack traces and request data to anyone. Then run `php artisan config:clear`.
3. Open `https://that-address/.well-known/oauth-authorization-server` and check that every URL in it starts with exactly `https://that-address/`. A URL starting with `http://` means `TRUSTED_PROXIES` is not set correctly; a different host means the tunnel rewrites the Host header.
4. Add `https://that-address/mcp` as a custom connector in claude.ai and approve it.

Settings in `.env` (see `.env.example`): `SERVERAVATAR_API_URL` (the API address), `SERVERAVATAR_TIMEOUT`, `SERVERAVATAR_SLOW_TIMEOUT` (for creating applications) and `SERVERAVATAR_MAX_RETRIES` for calls to ServerAvatar, `MCP_REDIRECT_DOMAINS` limits where a client may be sent after approval (never `*`), `OAUTH_ACCESS_TOKEN_MINUTES` and `OAUTH_REFRESH_TOKEN_DAYS` set token lifetimes, `MCP_RATE_LIMIT` and `OAUTH_REGISTER_RATE_LIMIT` set request limits.

## Tests and checks

Tests run on their own database, because they wipe it:

```bash
mysql -uroot -e "CREATE DATABASE serveravatar_mcp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
vendor/bin/pest          # backend tests (Vite is faked, no build needed; signing keys are created if missing; no test can reach the real ServerAvatar API)

# Optional, read-only checks against the real ServerAvatar API (skipped unless a token is given):
SERVERAVATAR_TOKEN=your-token vendor/bin/pest tests/Integration
vendor/bin/pint --test   # PHP style
npm run format:check && npx eslint . && npx tsc --noEmit
```

Password rules: at least 12 characters with upper and lower case letters and a number (plus a breach check in production). Passwords are hashed with argon2id.

## Behind a reverse proxy

Set `TRUSTED_PROXIES` (comma-separated IPs or CIDR ranges, or `*` only if the app is reachable solely through your own proxy). By default no proxy is trusted, so a forged `X-Forwarded-For` cannot change the IP recorded in the activity log. Whatever you trust, the app only answers to the host in `APP_URL` (plus `TRUSTED_HOSTS`, if set, and `localhost`), so a forged `X-Forwarded-Host` cannot redirect OAuth addresses or password-reset links to another domain.

To run it in containers, see the Docker Compose section of [docs/self-hosting.md](docs/self-hosting.md). Each release is also published as a ready-made image (`ghcr.io/mayursojitra/serveravatar-mcp`, Intel/AMD and ARM), so you can run it without building.

## Contributing, security and licence

- [docs/setup.md](docs/setup.md) and [docs/self-hosting.md](docs/self-hosting.md): connecting clients and running the app in production.
- [CONTRIBUTING.md](CONTRIBUTING.md): how to set up, test and submit changes, and the principles the project keeps.
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md): how we expect people to treat each other.
- [SECURITY.md](SECURITY.md): how to report a vulnerability, how credentials are protected, and a checklist for running it.
- [docs/oauth.md](docs/oauth.md) and [docs/tools.md](docs/tools.md): how the connection layer works, and the planned tools.
- Licence: [MIT](LICENSE).
