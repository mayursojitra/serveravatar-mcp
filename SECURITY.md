# Security

This app sits between AI clients and people's servers, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Report it privately through the repository's **Security** tab ("Report a vulnerability"), and include:

- what you found and where (file, route or setting),
- steps to reproduce, and the version or commit you tested,
- what an attacker could do with it.

You will get an acknowledgement, and the fix and a credited advisory once it is resolved. Please give a reasonable time to fix it before you disclose it publicly.

## Supported versions

Until 1.0, only the latest commit on the default branch is supported. After 1.0, the latest release.

## How credentials are protected

| What | How |
| --- | --- |
| Passwords | Hashed with argon2id (one-way). Minimum 12 characters with upper and lower case letters and a number; checked against known breaches in production. |
| Accounts | No self-registration. Admins are created only from the terminal. A user created by an admin, or whose password an admin resets, must choose a new password before doing anything else. |
| AI client access | OAuth 2.1 with PKCE. Access tokens last 60 minutes and refresh tokens 30 days by default. The user picks a permission level (read, read + safe actions, full) at consent. Tools above the level are not listed and are refused if called. |
| Revoking access | Users can revoke any connection on **Connections** and change its level at any time. Disabling a user, deleting them, or **any password change or reset (an admin reset, the email reset, a change in settings, the forced change)** disconnects their AI clients at once: access tokens, refresh tokens, unexchanged authorization codes and the grant itself. The same events end the user's other browser sessions: with the database session driver their session rows are deleted, and with any driver Laravel's `AuthenticateSession` signs out a session whose stored password hash no longer matches. A change in settings keeps only the session that made it. |
| ServerAvatar API token | One per user. It is checked with ServerAvatar before it is saved, stored encrypted with the app key, hidden from every serialization, decrypted only when a request to ServerAvatar is made, and never logged, put in the session, shown again or sent to an AI client. The page shows only its last four characters, and admins see only whether a user has one. Deleting it disconnects the user's AI clients. Records returned from ServerAvatar are cut down to an allow-list of fields, so admin-tool links, license keys and member emails never reach an AI model. |
| Activity log | Every sign-in, admin action and MCP request is recorded with the user, client and result. Tool arguments are stored with secrets removed (tokens, passwords, authorization headers, and similar keys) and long values cut. Rows are kept 90 days. |
| Redirects | An AI client can only be sent back to addresses in `MCP_REDIRECT_DOMAINS` (claude.ai, claude.com and localhost by default). `*` is never a default. |
| Which address it answers to | Every request must name the host in `APP_URL` (or one listed in `TRUSTED_HOSTS`, or `localhost`); anything else gets `400`. Without this, a forged `Host` or `X-Forwarded-Host` header could make the app write a visitor's chosen domain into OAuth discovery documents and into the link of a password-reset e-mail. Reverse proxies and tunnels pass those headers through, so trusting the proxy does not prevent it. |
| Browser protections | Every response forbids framing (`X-Frame-Options: DENY` and `frame-ancestors 'none'`), so the consent page cannot be loaded invisibly inside another site; it also sets `X-Content-Type-Options`, `Referrer-Policy` and, over HTTPS, `Strict-Transport-Security`. A full script and style Content-Security-Policy is not set yet. |
| Abuse limits | Login attempts are limited per email and IP, and per IP. `/mcp` is limited per user, and open client registration is limited per IP. |

## If you run it, please do these

- Serve it over **HTTPS** and keep `APP_DEBUG=false` in anything reachable from the internet.
- Set `APP_URL` to the exact address people use, and update it whenever that address changes (a tunnel gets a new one each time it restarts). Until you do, the app answers `400 Invalid host`.
- Back up `APP_KEY` safely. Without it, stored tokens cannot be read; with it and a database dump, they can. Treat both as secrets.
- Keep the Passport signing keys (`storage/oauth-*.key`, or `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`) private, and do not commit them.
- Set `TRUSTED_PROXIES` to your reverse proxy's address, not `*`, unless the app can only be reached through that proxy. Otherwise a caller can forge the IP written to the activity log.
- Use a long, unique password for admin accounts. Two-factor sign-in is not available yet.
- Optionally, restrict each ServerAvatar API token to your server's IP address in ServerAvatar, so a stolen token is useless elsewhere.
- Review **All activity** now and then, and keep dependencies up to date.

## Known and accepted

- **phpseclib 4.0.1** has a published advisory (a flaw in parsing encrypted keys, PFX and CMS files that can use a lot of CPU). Laravel Passport pins that exact version, and the app uses phpseclib only in `passport:keys` to create its own signing key, never to read anything a visitor supplies, so it cannot be reached. It is listed in `composer.json` under `config.audit.ignore` and will be removed when Passport allows a fixed version.
- A client that registers itself for OAuth is always created as a public client without a secret, so client-credentials tokens cannot exist; this is covered by a test.
- Passport's `POST /oauth/token/refresh` route is still registered. It needs a signed-in browser session and cannot reach `/mcp`.

## Not covered yet

- No independent penetration test has been done. v1.0 has had a code review by its author, a dependency audit, and the automated tests, nothing more.
- No full Content-Security-Policy for scripts and styles.

- The 8 tools that change things (restart, supervisors, applications, domains, SSL, databases, database user access, cron jobs) are tested against a faked ServerAvatar, and seven (restart, create database, create application, add a domain, install SSL, change database user access, create a cron job) were also run on a test server. The other one, `control_supervisor`, follows ServerAvatar's documentation and has not been run live.
- Two-factor sign-in.
- Destructive tools (delete, restore): deliberately not offered in v1.

## Third-party notice

This is an independent project. It is not affiliated with, endorsed by or sponsored by ServerAvatar.
