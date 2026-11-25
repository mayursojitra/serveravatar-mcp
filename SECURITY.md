# Security

This app sits between AI clients and people's servers, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Report it privately through the repository's **Security** tab ("Report a vulnerability"), and include:

- what you found and where (file, route or setting),
- steps to reproduce, and the version or commit you tested,
- what an attacker could do with it.

You will get an acknowledgement, and the fix and a credited advisory once it is resolved. Please give a reasonable time to fix it before you disclose it publicly.

## Supported versions

Only the latest release is supported. Fixes land on the default branch first.

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
| Browser protections | Every response forbids framing (`X-Frame-Options: DENY` and `frame-ancestors 'none'`), so the consent page cannot be loaded invisibly inside another site; it also sets `X-Content-Type-Options`, `Referrer-Policy` and, over HTTPS, `Strict-Transport-Security`. Pages also carry a Content-Security-Policy: scripts and styles run only if they come from the app itself or carry a one-time code (nonce) made for that request, fonts and images come from the app only, and plugins are off, so an injected `<script>` or `<style>` tag does not run. It has no `form-action` rule on purpose, because the consent page must be able to send you back to your AI client's own address. A page that fails with a server error shows Laravel's plain error page, whose inline styles the policy blocks, so it appears unstyled. Set `CSP_MODE=report-only` to have the browser only log problems, or `off` to drop the page policy (framing stays banned). |
| Two-factor sign-in | Optional per user (an authenticator app, TOTP), mandatory for everyone with `REQUIRE_TWO_FACTOR=true`. After the right password nobody is signed in yet: the session holds only the user's id for 5 minutes, and no page is reachable until a valid code or recovery code is entered. Because connecting an AI client starts with signing in, it also protects new connections. A code works once (the step it belongs to is saved with a conditional update, so two simultaneous requests cannot both pass), wrong codes are limited per user and IP, per user, and per IP, and five wrong codes on one sign-in send the user back to the password. The secret is stored encrypted with the app key; the 8 recovery codes are stored only as keyed hashes and each works once. Turning it on signs the user out of other browsers; turning it off, making new codes and starting a setup ask for the password again. A password reset does not skip it. If a user loses their app and codes, an admin can turn it off for them on **Admin → Users**, and `php artisan serveravatar:reset-two-factor <email>` does the same for anyone including admins; there is deliberately no email link, which would make it only as strong as the mailbox. Changing `APP_KEY` makes saved secrets unreadable, so reset two-factor for those users afterwards. Not offered yet: passkeys, SMS or email codes, remember-this-device. |
| Abuse limits | Login attempts are limited per email and IP, and per IP; wrong two-factor codes have their own limits. `/mcp` is limited per user, and open client registration is limited per IP. |

## If you run it, please do these

- Serve it over **HTTPS** and keep `APP_DEBUG=false` in anything reachable from the internet.
- Set `APP_URL` to the exact address people use, and update it whenever that address changes (a tunnel gets a new one each time it restarts). Until you do, the app answers `400 Invalid host`.
- Back up `APP_KEY` safely. Without it, stored tokens cannot be read; with it and a database dump, they can. Treat both as secrets.
- Keep the Passport signing keys (`storage/oauth-*.key`, or `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`) private, and do not commit them.
- Set `TRUSTED_PROXIES` to your reverse proxy's address, not `*`, unless the app can only be reached through that proxy. Otherwise a caller can forge the IP written to the activity log.
- Use a long, unique password for admin accounts, and turn on two-factor sign-in for them (**Settings → Two-factor**). Set `REQUIRE_TWO_FACTOR=true` to make it mandatory for everyone.
- Optionally, restrict each ServerAvatar API token to your server's IP address in ServerAvatar, so a stolen token is useless elsewhere.
- Review **All activity** now and then, and keep dependencies up to date.

## Known and accepted

- A client that registers itself for OAuth is always created as a public client without a secret, so client-credentials tokens cannot exist; this is covered by a test.
- Passport's `POST /oauth/token/refresh` route is still registered. It needs a signed-in browser session and cannot reach `/mcp`.

## Not covered yet

- No independent penetration test has been done. v1.0 has had a code review by its author, a dependency audit, and the automated tests, nothing more.

- The 8 tools that change things (restart, supervisors, applications, domains, SSL, databases, database user access, cron jobs) are tested against a faked ServerAvatar, and seven (restart, create database, create application, add a domain, install SSL, change database user access, create a cron job) were also run on a test server. The other one, `control_supervisor`, follows ServerAvatar's documentation and has not been run live.
- Destructive tools (delete, restore): deliberately not offered in v1.

## Third-party notice

This is an independent project. It is not affiliated with, endorsed by or sponsored by ServerAvatar.
