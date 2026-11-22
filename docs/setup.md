# Connecting an AI client

Everything starts on the **Connections** page of your ServerAvatar MCP app, which shows the address of the MCP endpoint. It looks like `https://your-host/mcp`.

Before you connect:

1. Save your ServerAvatar API token on the **ServerAvatar token** page. The app checks it with ServerAvatar first. Without a token there is nothing for an AI client to use.
2. Make sure the app is reachable by the AI client over **HTTPS**. claude.ai calls your server from the internet, so it cannot use `localhost`. See [self-hosting](self-hosting.md).

When a client connects it opens this app's sign-in page, then a consent page where you choose a **permission level**:

| Level | What the client can do |
| --- | --- |
| Read only | Look at organizations, servers, applications, domains, SSL, databases, cron jobs, supervisors, backups and server logs. |
| Read + safe actions | Everything in Read only, plus restart a server and start, stop or restart a supervisor process. |
| Full | Everything above, plus add a domain, install a free SSL certificate, create a database and create a cron job. |

Tools above the chosen level are not shown to the client and are refused if it calls them anyway. You can change the level later on the **Connections** page, and it applies at once. Nothing in v1 deletes anything.

## claude.ai

1. Settings, then Connectors, then **Add custom connector**.
2. Paste the MCP address and add it.
3. Sign in to the app when asked, choose a level and approve.
4. Ask something like "list my ServerAvatar servers".

If you connected before new tools were added, remove the connector and add it again so Claude reloads the tool list.

## Claude Code

```bash
claude mcp add --transport http serveravatar https://your-host/mcp
```

Then run `/mcp` inside Claude Code, pick `serveravatar` and choose to authenticate. A browser window opens for sign-in and consent.

## Cursor

Add the server to `~/.cursor/mcp.json` (or the project's `.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "serveravatar": { "url": "https://your-host/mcp" }
  }
}
```

Then open Cursor's MCP settings and connect it. Cursor finishes sign-in with a `cursor://` link, which the app allows by default (`MCP_CUSTOM_SCHEMES`).

> Cursor has not been tested with this app yet. If it does not connect, please open an issue with what you see.

## If something goes wrong

- **The page says `400 Invalid host`.** The address in the browser or connector is not the one in `APP_URL` (or `TRUSTED_HOSTS`). Fix `APP_URL` in `.env` (a tunnel address changes every time the tunnel restarts) and run `php artisan config:clear`.
- **The client says it cannot connect or sign in.** Open `https://your-host/.well-known/oauth-authorization-server` in a browser. Every address in it must start with your real `https://` host. If one starts with `http://`, set `TRUSTED_PROXIES` as described in [self-hosting](self-hosting.md).
- **The client connects but lists only `ping`, or fewer tools than expected.** You chose a lower level, or the client cached an old list. Check the level on **Connections** and reconnect.
- **A tool answers that ServerAvatar rejected the token.** The saved token expired or was revoked. Replace it on the token page.
- **A tool answers that ServerAvatar refused it.** The role of the token's owner in ServerAvatar does not allow that action. Ask an organization owner.
- **A call is slow or refused with "rate limit".** ServerAvatar limits how often a token may call its API. Wait a minute.
- **See what happened.** Every call is on the **Activity** page: which client, which tool, the result and the time taken.
