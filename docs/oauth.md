# How OAuth and MCP access work

Notes for people working on the connection layer. It is built on [`laravel/mcp`](https://github.com/laravel/mcp) and [Laravel Passport](https://laravel.com/docs/passport).

## The flow

1. An AI client calls `POST /mcp` without a token and gets **401** with a `WWW-Authenticate` header that points at `/.well-known/oauth-protected-resource/mcp`.
2. It reads the discovery metadata (`/.well-known/oauth-protected-resource…` and `/.well-known/oauth-authorization-server…`), then registers itself with `POST /oauth/register` (dynamic client registration, public client, PKCE).
3. It sends the user to `/oauth/authorize`. The user signs in (and, if they have a temporary password, sets a new one first), then sees the **consent screen** and chooses a permission level.
4. Approving records a **grant** (`mcp_grants`: user, OAuth client, level) and redirects to the client with an authorization code. The client swaps it for an access token and a refresh token at `POST /oauth/token`.
5. Each `/mcp` request goes through `auth:api` (Passport checks the token) and then `ResolveMcpConnection`, which finds the user's grant for that client. No grant, a disabled user, or a user with a pending password change means the request is refused. The grant's level is read on every request, so a level change applies at once.

A connection is one `mcp_grants` row per user and OAuth client. **Deleting the row, together with revoking the tokens, is how a connection is revoked.**

## Decisions and findings

- **Consent always shows.** Passport skips the consent screen when the user already has live tokens for the client, which would issue tokens without the level choice and keep the old level. The app subclasses Passport's `AuthorizationController` and answers "not granted" in `hasGrantedScopes`.
- **Swapping Passport's controllers keeps the routes.** Passport builds its controllers through the container, so binding our subclass over Passport's replaces them while the route names (`passport.authorizations.*`, which the MCP discovery metadata links to), URLs and middleware stay. Passport also gives its controller a guard through a binding keyed to its own class, so the subclass needs the same `when(...)->needs(StatefulGuard::class)` binding.
- **Approve takes the client from the session**, from the stored authorization request, never from the submitted form. The level is validated before the request is pulled from the session, so a bad choice can be corrected without starting over.
- **The consent page uses a plain form post.** Approving answers with a redirect to the client's own address, which a background (Inertia) request cannot follow.
- **Revocation has four parts:** access tokens, refresh tokens (a refresh does not re-check the user), authorization codes not yet exchanged, and the grant. `oauth_access_tokens.user_id` has no foreign key, so deleting a user must revoke first. Every password change or reset revokes all of a user's connections the same way (`AccountAccess`), because a changed password often means the old one was exposed.
- **Machine endpoints answer in JSON.** Without that, a client that lists `text/event-stream` before `application/json` would be redirected to the login page instead of getting the 401 that starts its discovery.
- **Legacy-style MCP requests need no protocol headers.** A request without `_meta` is treated as legacy. Newer clients send the protocol version and mirrored headers.
- **Parameterless tools:** `laravel/mcp` emits `"properties": []` for a tool with no parameters, which is not a valid JSON Schema. `LeveledTool::toArray()` corrects it to `{}`.
- **Switched off:** the device-code flow (routes and grant). Passport's JSON API routes are off by default.
- **Registration is open by design** (clients register themselves), so it is rate limited per IP, and a daily job removes clients that never became a connection.

## Responses worth knowing

| Situation | Response |
| --- | --- |
| `/mcp` without a valid token | 401 with `WWW-Authenticate` |
| `/mcp`, token valid but connection revoked | 401 |
| `/mcp`, user disabled or password change pending | 403 |
| JSON-RPC error from `laravel/mcp` (for example a tool above the level) | HTTP 400 with a JSON-RPC error |
| Wrong one-time token on approve | 403 |
| Revoked or invalid refresh token | 400 `invalid_grant` |
| Wrong PKCE verifier | 400 (the code is not burned) |
| Authorization code used twice | 400 |

## Running behind a tunnel or proxy

Discovery URLs are built from the request, and the request's host must be the one in `APP_URL` (anything else is refused with 400, so a forged `X-Forwarded-Host` cannot poison them). Without `TRUSTED_PROXIES` they come out as `http://` behind a TLS-terminating proxy, and clients such as claude.ai fail to connect. Set `APP_URL` and `TRUSTED_PROXIES` as described in the [README](../README.md).

## Settings

`MCP_REDIRECT_DOMAINS`, `MCP_CUSTOM_SCHEMES`, `MCP_ALLOWED_ORIGINS`, `OAUTH_ACCESS_TOKEN_MINUTES`, `OAUTH_REFRESH_TOKEN_DAYS`, `MCP_RATE_LIMIT` and `OAUTH_REGISTER_RATE_LIMIT`. See `.env.example`.
