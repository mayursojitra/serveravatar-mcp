# Self-hosting

ServerAvatar MCP is a standard Laravel 12 application. It needs PHP 8.2 or newer (with `sodium` and `pdo_mysql`), a MySQL or MariaDB database, Composer and Node 22 (Node only to build the interface), and a web server that can serve PHP over **HTTPS**.

There are two routes: [Docker Compose](#docker-compose) (the app, a scheduler and a database in three containers) or the manual install below.

## 1. Install

```bash
git clone <your copy of the repository> serveravatar-mcp && cd serveravatar-mcp
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
php artisan passport:keys
```

Create an empty database and a database user for it, then fill in `.env` (see below) and run:

```bash
php artisan migrate --force
php artisan serveravatar:create-admin you@example.com --name="Your Name"
php artisan config:cache
```

The admin command asks for a password on a hidden prompt (or reads `ADMIN_PASSWORD`). It is the only way to create an admin. There is no sign-up page, and admins cannot make other admins from the web interface.

## 2. Settings that matter in production

| Setting | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false`. Debug mode shows stack traces and request data to visitors. |
| `APP_URL` | The public address, with `https://`. The app answers **only** to this host (and `localhost`): a request that names any other host gets `400 Invalid host`. If you change the address, change this too. |
| `TRUSTED_HOSTS` | Extra hostnames the app may be opened at, comma-separated. Usually empty. |
| `SESSION_SECURE_COOKIE` | `true`, so the session cookie is only ever sent over HTTPS. |
| `DB_*` | Your database. Use a user that can only reach this database. |
| `TRUSTED_PROXIES` | The IP address(es) of your reverse proxy, comma-separated. Leave it empty if there is no proxy. Use `*` only if the app is reachable solely through your own proxy. |
| `MAIL_*` | A real mail server, so that "forgot password" can send mail. With `MAIL_MAILER=log` the reset link only goes to the log. |
| `MCP_REDIRECT_DOMAINS` | Where an AI client may be sent after approval. Keep the defaults (claude.ai, claude.com, localhost). Never `*`. |
| `ACTIVITY_LOG_RETENTION_DAYS` | How long activity rows are kept (90 by default). |

Everything else in `.env.example` has a sensible default.

## 3. Web server and HTTPS

Point the document root at the `public/` directory and send everything else to `index.php`, as for any Laravel application. Terminate HTTPS in front of it (nginx, Caddy, Apache or your hosting panel) with a normal certificate. The portal and `/mcp` use the same address.

A minimal nginx location block:

```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.2-fpm.sock; }
```

If a proxy sits in front of PHP, set `TRUSTED_PROXIES` to that proxy's address. Trusting the proxy is not enough on its own: most proxies pass a visitor's `X-Forwarded-Host` header straight through, which is why the app also checks the host against `APP_URL`. Then open `https://your-host/.well-known/oauth-authorization-server` and check that every address in it starts with `https://your-host/`.

## 4. The scheduler

Add one cron entry so the daily clean-ups run (activity older than the retention period, expired OAuth records, unused client registrations):

```
* * * * * cd /path/to/serveravatar-mcp && php artisan schedule:run >> /dev/null 2>&1
```

## 5. Back up three things

- **The database.** It holds users, connections and the activity log.
- **`APP_KEY` in `.env`.** Every saved ServerAvatar token is encrypted with it. If you lose it, the saved tokens cannot be decrypted, and each user has to save theirs again.
- **`storage/oauth-private.key` and `storage/oauth-public.key`.** They sign the access tokens. If you lose them, every AI client has to reconnect.

Keep `.env` and the key files out of version control and readable only by the web server's user.

## 6. Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
```

With Docker: `git pull && docker compose up -d --build` (the app migrates the database when it starts).

## Docker Compose

The repository has a `Dockerfile` and a `docker-compose.yml`: the app (nginx and PHP-FPM, port 8080), a scheduler container for the daily clean-ups, and MariaDB.

```bash
cp .env.docker.example .env          # then edit it: APP_URL, DB_PASSWORD and APP_KEY
echo "APP_KEY=base64:$(openssl rand -base64 32)"    # generate the key; paste this line into .env
echo "DB_PASSWORD=$(openssl rand -hex 24)"          # and, if you like, a database password
docker compose up -d --build
docker compose exec app php artisan serveravatar:create-admin you@example.com --name="Your Name"
```

`APP_KEY` must be in `.env` before any `docker compose` command, including the build, or Compose stops with "required variable APP_KEY is missing a value". Generate it once and keep it: it encrypts every saved ServerAvatar token. If you prefer to let Laravel make it, build once with a placeholder key and run `docker run --rm --entrypoint php serveravatar-mcp:local artisan key:generate --show` (without `--entrypoint`, the container refuses to start while `APP_KEY` is empty).

The container speaks plain HTTP on port 8080: put an HTTPS reverse proxy in front of it (nginx, Caddy, Traefik, or your host's panel), send it `X-Forwarded-Proto`, and set `TRUSTED_PROXIES` to the proxy's address. The `storage` volume holds the OAuth signing keys, which the app creates on first start. Back up that volume, the database volume and `APP_KEY` (section 5).

The Docker files were run by hand (build, health check, sign-in, saving a token) and are built and smoke-tested by this repository's CI workflow (`.github/workflows/docker.yml`) on every push. If something does not work, please open an issue.

## ServerAvatar's own IP allowlist

ServerAvatar can limit an API token to certain IP addresses. If you use that, allow the public IP address of the machine running this app, because that is where the calls to ServerAvatar come from. It is optional extra protection: if the token ever leaks, it is useless from anywhere else.

## Checking dependencies

Run `composer audit` and `npm audit` now and then (`composer audit` needs Composer 2.4 or newer). The repository's CI does both every week, and both currently report no known vulnerabilities.

## Security checklist

- HTTPS on, `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL` set to your real address.
- Strong, unique passwords for admins. Two-factor sign-in is not available yet.
- `APP_KEY`, the OAuth keys and the database backed up and private.
- Use ServerAvatar tokens with the least role that does the job: a user who only needs to read can use a token whose ServerAvatar role is read-only, whatever level they choose here.
- Look at **All activity** now and then. See also [SECURITY.md](../SECURITY.md).
