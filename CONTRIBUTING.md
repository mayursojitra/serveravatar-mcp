# Contributing

Thanks for helping. This project handles access to people's servers, so changes are held to a careful standard. Small, focused pull requests with tests are the easiest to review.

## Getting set up

Follow **Development setup** in the [README](README.md). Tests run on their own database (`serveravatar_mcp_test`), which they wipe, so never point them at real data.

## Before you open a pull request

```bash
vendor/bin/pest                                   # all tests must pass
vendor/bin/pint --test                            # PHP style (run vendor/bin/pint to fix)
npm run format:check && npx eslint . && npx tsc --noEmit
npm run build
```

- Add tests for what you change. For anything about accounts, permissions, OAuth or logging, test the refusal as well as the success: what must **not** be possible.
- If you change the interface, click through it in a browser as well. Type checks and the build do not catch every runtime mistake.
- Keep comments to the *why*. Code that handles secrets must never log them.

## Principles the project keeps

These are decisions, not oversights. A pull request that works against one will be asked to change or be declined.

- **No self-registration.** Accounts are created by admins. Admins are created only by `php artisan serveravatar:create-admin`; no page, route or request field can create or promote one.
- **A user's ServerAvatar token is theirs.** It is stored encrypted, decrypted only for an outbound call, and never shown, logged or sent to an AI client.
- **Every MCP request is logged**, for users and admins alike, with secrets removed from the arguments.
- **Permission levels are enforced on the server.** A tool above a connection's level is not listed and is refused if called. Every tool declares its level.
- **No destructive tools in v1** (delete, restore, reset). They may arrive later behind an explicit confirmation.
- **OAuth is the only way in** for AI clients. No long-lived keys pasted into configuration files.
- **Tools are task-shaped**: one tool per task, explicit parameters, trimmed and size-capped results.

## Adding a tool

Extend `App\Mcp\Tools\LeveledTool`, declare its `requiredLevel()`, set the right MCP annotations (`IsReadOnly`, `IsIdempotent`, `IsDestructive`, `IsOpenWorld`), register it in `config/serveravatar.php`, and test that it is hidden and refused below its level and that its arguments are redacted in the activity log. See `docs/tools.md` for the planned set.

## Reporting problems

For bugs and ideas, open an issue. For anything security related, follow [SECURITY.md](SECURITY.md) and do not post details publicly.
