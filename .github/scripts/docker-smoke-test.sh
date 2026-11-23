#!/usr/bin/env bash
# Smoke test for a running ServerAvatar MCP Docker Compose stack. Run it from the folder that holds the
# compose file and .env, after `docker compose up -d`. The stack must use APP_URL=https://mcp.example.test
# and listen on localhost:8080. Used by docker.yml (image built from source) and release-image.yml (the
# candidate image, and the pushed image pulled into an empty folder), so the two cannot drift apart.
# No "pipefail" on purpose: `command | grep -q` closes the pipe as soon as grep matches, which would make
# the command on the left fail with SIGPIPE. A failing command still fails the test, because grep then
# has nothing to match.
set -eu

HOST="${SMOKE_HOST:-mcp.example.test}"
BASE="${SMOKE_BASE:-http://localhost:8080}"

echo "Waiting until the app is healthy..."
healthy=0
for _ in $(seq 1 60); do
  if curl -fsS "$BASE/up" > /dev/null; then healthy=1; break; fi
  sleep 3
done
if [ "$healthy" != 1 ]; then
  echo "The app did not become healthy in time." >&2
  docker compose logs
  exit 1
fi

echo "Smoke test..."
# The sign-in page answers on the configured host.
curl -fsS -H "Host: $HOST" "$BASE/login" | grep -q 'Sign in\|Log in\|email'
# Discovery names the configured host.
curl -fsS -H "Host: $HOST" "$BASE/.well-known/oauth-authorization-server" | grep -q "$HOST"
# Another host is refused.
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: evil.example' "$BASE/login")" = "400"
# /mcp wants a token.
test "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Host: $HOST" -H 'Content-Type: application/json' -d '{}' "$BASE/mcp")" = "401"
# Framing is forbidden.
curl -sI -H "Host: $HOST" "$BASE/login" | grep -qi '^x-frame-options: deny'
# Each security header is sent exactly once (nginx in the image must not add a second copy).
for h in x-frame-options x-content-type-options referrer-policy; do
  test "$(curl -sI -H "Host: $HOST" "$BASE/login" | grep -ci "^$h:")" = "1"
done
# The database was migrated, and the scheduler container is running.
docker compose exec -T app php artisan migrate:status | grep -q 'Ran'
docker compose ps --status running --services | grep -q scheduler

echo "Smoke test passed."
