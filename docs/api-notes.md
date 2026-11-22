# ServerAvatar API: what the live check found

Notes from running read-only requests against the real ServerAvatar API (2026-11-17), so the client and the tools follow what the API actually does and not what its documentation implies. Only `GET` requests were made, plus one read-only `POST` (fetching a log file). Nothing was restarted, toggled, created or deleted. Behaviour of the action and manage endpoints is **not verified** yet.

## Requests

- **Base URL:** `https://api.serveravatar.com`.
- **Authentication:** `Authorization: Bearer <token>`. A bare token without `Bearer` is refused with 401.
- **Accept** `application/json`.
- **Resources live under** `/organizations/{organization}`; servers, applications and so on nest below it. Git-provider account connections are tied to the user account instead and have no organization prefix.

## Responses

- **Errors** are JSON with a `message`, for example `{"message": "Unauthenticated."}`. A validation failure (422) adds an `errors` object keyed by field.
- **401** wrong, expired or missing token.
- **403** the token's user lacks permission. The message is: "You cannot perform this action. Because, You do not have permission for this action." This depends on the user's role in the organization.
- **404** unknown organization, server or application id.
- **Rate limits** are advertised in `X-RateLimit-Limit` and `X-RateLimit-Remaining`. The limit differs by bucket (150 and 479 per minute were seen), so it is not hard-coded. Whether a 429 carries `Retry-After` has not been observed; the client honours it when present and otherwise waits a second.

## Pagination

- Without `?pagination=1` a list endpoint returns the whole list as a plain array under a resource key (`servers`, `applications`, ...).
- With `?pagination=1` the same key holds a Laravel paginator: `current_page`, `data`, `last_page`, `total`, `per_page`, `next_page_url`, and so on. The key is named after the resource (`servers`, `applications`, `applicationDomains`, `databases`, `cronjobs`, `supervisors`).
- **`page`** selects the page. **`per_page`** is not honoured; the page size is chosen by the server (10 or 12).

## Endpoints checked

| Purpose | Request | Result |
| --- | --- | --- |
| The token owner's account | `GET /me` | Profile of the user the token belongs to: name, email, role, status, country, timezone, plan, credit balances, security settings (two-factor, IP allow-list, delete protection), and the server and rate limits. Contains personal and billing details, so tools must not return it as is. |
| Organizations | `GET /organizations` | `{organizations: [...]}`, each with `id`, `name`, `created_at` and a `members` list (names, emails, roles). Not paginated. |
| Servers | `GET /organizations/{o}/servers?pagination=1` | Paginated under `servers`. A server record has about 60 fields. |
| One server | `GET /organizations/{o}/servers/{s}` | `{server: {...}}` |
| Server status | `GET .../servers/{s}/status` | `record` (list of `key`, `status`, `label`, `percentage`) plus the server. |
| Server summary | `GET .../servers/{s}/summary` | `{summary: {system_users, applications, databases, cronjobs}}` |
| Server usage | `GET .../servers/{s}/usage` | disk, memory, load, cores, swap, uptime. |
| Applications | `GET .../servers/{s}/applications?pagination=1` | Paginated under `applications`. |
| One application | `GET .../servers/{s}/applications/{a}` | `{application, gitDeployment, server}` |
| Domains | `GET .../applications/{a}/application-domains?pagination=1` | Paginated under `applicationDomains`. |
| SSL | `GET .../applications/{a}/ssl` | `{installed, forceHttps, certificateInfo: {issuer, domains, issued_on, expires_on, is_expired, validity_left, type, ...}}` |
| Databases | `GET .../servers/{s}/databases?pagination=1` | Paginated under `databases`. |
| Cron jobs | `GET .../servers/{s}/cronjobs?pagination=1` | Paginated under `cronjobs`. |
| Supervisors | `GET .../applications/{a}/supervisors?pagination=1` | Paginated under `supervisors`. |
| Log files | `GET .../servers/{s}/logs` | `{logFiles: {<file>: <label>}}`. A **list of names only**, not content. |
| A log | `POST .../servers/{s}/logs` with `{"log": "<file>"}` | `{output, path}`. A read, despite the `POST`. |

**Not usable:**
- `GET /organizations/{o}/applications`, `/databases` and `/backups` answered 403 for a team organization's token. The server-scoped lists work, so tools take a `server_id`.
- `GET .../applications/{a}/status` answered 404.

## Things the tools must handle

- **A server record carries fields that grant access:** admin-tool links (phpMyAdmin, file manager) and their slugs, root-login settings. An application record carries a license key and raw configuration. An organization lists its members' names and emails. The client keeps only an allow-list of fields (`Trim`), so a field ServerAvatar adds later is dropped until it is chosen.
- **Logs can be very large.** A system log came back at about 3 MB. Tools must take the tail and cap the size.
- **`php_versions` is not always a list:** a server record can carry it as a JSON-encoded string. `Trim` turns both into a list.
- **Permissions vary by role.** A 403 is an expected answer, not a failure of the app.

## Not verified yet

- **Creating an application** (`POST .../servers/{s}/applications`): the permission check comes first. A member whose role in the organization is a custom role got **403 "Unauthorized!"** on an empty request. With a token that was allowed, the body passed validation but the call failed with HTTP 500 (see the M8 section below), so a successful create has not been seen yet. Write endpoints depend on the role of the token's user in that organization, so a write test needs a token whose user may create applications.

- The action and manage endpoints (restart, supervisor control, add domain, install SSL, create database, create cron job): request fields, responses, and whether a restart is a `GET` that changes state.
- The shape of a cron job, supervisor or database-user item (the test account had none).
- Retry behaviour on a 429.

## Read tools, live check (M4)

All 13 read tools were run against a real account with 7 servers. Findings:

- `GET /logs` lists files; `POST /logs {"log": file}` returns the whole file, so the tool keeps only the tail (a 13,000-line syslog is several MB).
- `list_backups` returns 403 for a member-role token. The tool turns it into a plain permission message.
- Cron jobs were seen live (id, server_id, system_user, name, schedule, command, custom_scheduling, enabled, created_at). Supervisors came back empty, so their item shape is still a generic guess. Backups were refused, so theirs is too.
- An application's `system_user` object includes the system user's login **password**. The tool keeps only id, username and group. Tests now fail if any result has a key that looks like a credential.
- Server status and summary can be refused separately from the server record; the tool then returns the server with a note.

## Write endpoints, live check on a test server (M5)

- `GET /servers/{server}/restart` works: it answers `{"message": "Server restarted successfully."}`, and the server's reported uptime afterwards was a few minutes, so the reboot did happen.
- `POST /servers/{server}/databases` answers **HTTP 500** when `connection_preference` is left out, although the documentation lists it as optional. With `connection_preference: "localhost"` it answers 200 `{"message": "Database has been created successfully."}`. The tool always sends it. `connection_preference` also takes `everywhere` and `specific_ip_addresses`; the latter needs `hostname`, which the documentation types as an array of IPv4 addresses. `specific_ip_addresses` with `hostname` as an array of one IPv4 address was **verified live** (2026-11-21): HTTP 200 "Database has been created successfully.", and the database listed with `remote_access: true`. The list does not show which addresses are allowed. `everywhere` was also verified live: HTTP 200 and `remote_access: true`.
- **phpMyAdmin live check (2026-11-21):** `create_application` with `framework: phpmyadmin`, PHP 8.2, a new system user and its own hostname (no admin fields, nothing generated but the system user) answered 200 "Phpmyadmin application has been installed successfully.", the application listed as `phpmyadmin`, and the site answered HTTP 200 with the title "phpMyAdmin".
- **Joomla live check (2026-11-21):** `framework: joomla`, PHP 8.2, a new system user, own hostname and no other fields answered 200 "Joomla application has been installed successfully."; the application listed as `joomla`, and the site redirected to `/installation/index.php` with the title "Joomla Installer", so the user finishes setup there (as the documentation says). Also `install_ssl` (`force_https` false) on the phpMyAdmin application: issued a Let's Encrypt certificate for its domain (89 days left) and HTTPS answered 200 with a verifying certificate.
- **Statamic live check (2026-11-21), and a timeout found:** `framework: statamic`, PHP 8.2, a new system user, own hostname and an admin email. The call ran longer than the client's 20-second timeout, so the tool answered "Could not reach ServerAvatar", yet ServerAvatar had created the application: it listed as `statamic` and the site answered 200 (title "Home"). Because the answer never arrived, the generated admin password and system user password were lost. Earlier installs finished in about 8 seconds, so how long an install takes depends on the type. The client now waits up to 120 seconds (`SERVERAVATAR_SLOW_TIMEOUT`) for creating an application. If that wait still ends, `create_application` answers with `pending: true` and still hands over the generated passwords (the application is probably being installed), and tells the AI to check `list_applications` and not create it again; other changes that time out say they may still have been made. An Akaunting install took longer than 90 seconds, and the AI client gave up at about the same moment, which is why the passwords of that first attempt were lost. A second attempt with the 120-second wait ended the same way: the tool finished at 120 seconds with its `pending` answer, but the AI client had already reported "The operation timed out.", so those passwords were lost too. The client's own limit therefore seems shorter than 120 seconds (not measured), and `SERVERAVATAR_SLOW_TIMEOUT` should stay below it for the `pending` answer to be seen. Akaunting is not verified yet.
- **Statamic rerun (2026-11-21):** with the 90-second timeout the same request took about 28 seconds, answered 200 "Statamic application has been installed successfully." and returned the generated admin password and system user password. The site answered 200 and `/cp` redirected to `/cp/auth/login`. **Statamic is verified live.**
- `install_ssl` **verified live** (2026-11-21) on a plain PHP application on a real domain whose DNS pointed at the server: ServerAvatar answered "SSL certificate has been installed successfully.", `get_ssl` then showed an automatic Let's Encrypt certificate for that domain only (89 days left), and the site answered over HTTPS with a certificate that verifies. The application also had a ServerAvatar temporary domain (no DNS propagation); it was left out of the certificate and did not make the install fail. Tested with `force_https` false only. **Second live run (2026-11-21), after `add_domain`:** running it again on the same application (two real domains, DNS pointing at the server) issued one certificate covering both domains (`get_ssl` listed both, 89 days left), and both answered over HTTPS with a certificate that verifies, so the "run install_ssl again after add_domain" advice holds. **`force_https: true` verified live too** (2026-11-21, on a WordPress application with its own domain): the install answered "SSL certificate has been installed successfully.", `get_ssl` showed `force_https: true` with a Let's Encrypt certificate (89 days left), plain HTTP answered 301 to the HTTPS address (also for `/wp-login.php`), and HTTPS answered 200 with a certificate that verifies.
- `update_database_user` **verified live** (2026-11-21) on a test database user: `GET /databases/{id}/database-users?pagination=1` answers `{"databaseUsers": {"data": [{id, database_id, username, password (never kept), connection_preference, hostname: [ips], remoteAccess, ...}], paging...}, "databaseName": ...}`; `PATCH /databases/{id}/database-users/{user_id}` with `username`, `password`, `connection_preference` and `hostname` (an array) answered "Database user has been updated successfully." and the list then showed the new address list (it replaces the old one). Without `password` it answers 422 "The password field is required.".
- `add_domain` **verified live** (2026-11-21) on a plain PHP application (`app.example.com`, created for the test): `POST .../applications/{a}/application-domains` with the host name answered "Domain has been added successfully.", and the domain list then showed it (`dns_propagation` true, `autossl` false) next to the primary domain and the temporary domain ServerAvatar adds to every application. SSL was not touched.
- `create_cronjob` **verified live** (2026-11-21) on a WordPress application's system user: `POST .../servers/{s}/cronjobs` with the name, command, a preset schedule and the system user answered "Cronjob has been created successfully."; `list_cronjobs` then showed it (`schedule` `*/5 * * * *`, enabled, command kept as sent); and the server's syslog recorded `CRON[...]: (<system user>) CMD (...)` at the first 5-minute mark, so the job really ran as that user. The command was a harmless `date -u >> "$HOME/mcp-cron-test.log"`.
- Not run live: `control_supervisor` (needs a supervisor process on an application).

## Creating an application, live check on a test server (M8)

- **WordPress live check (2026-11-21):** a request without an admin email was refused by ServerAvatar with 422 "The email field is required." although its documentation lists the email as optional. Nothing was created. The tool now requires `admin_email` for WordPress. With the email the same request succeeded: 200 "Wordpress application has been installed successfully." (body: `method: one_click`, `framework: wordpress`, a new system user, PHP 8.2, own hostname, no temporary domain), the application listed as `wordpress`, and the site answered HTTP 200 with the title "MX Test" and a working `/wp-login.php`. The One Click list in the dashboard for a PHP and nginx server (Custom, WordPress, Mautic, Moodle, Joomla, PrestaShop, phpMyAdmin, Akaunting, Nextcloud, Statamic, Craft CMS, and a greyed-out Reseller Panel) matches the tool's PHP types; the Node types need a Node server (for example MERN), not just Node.js installed. So **WordPress is verified live**; the field names and the generated admin password and database name were accepted. The other types that list the email as optional (Akaunting, Nextcloud, Moodle, PrestaShop) may behave the same way; that is not verified.

- The request body for a one-click Node application (`uptimekuma`, with `package_manager: npm`, `process_mode: fork`, a new system user and `temp_domain: true`) passed ServerAvatar's validation, but the call answered **HTTP 500 "Something went really wrong while creating temporary domain. Try again shortly."** Nothing was created (the server still listed 0 applications). It was sent once and not repeated. Cause unconfirmed: temporary domains may be unavailable for that account or server. The tool's description tells the model to ask for a domain and retry with `hostname`.
- One-click Node frameworks listed by the documentation: `uptimekuma`, `nodered`, `n8n`, `nodebb`. The tool offers `uptimekuma` and `n8n` (the other two need an admin username, password and, for nodebb, an e-mail).
- **Second live check, new test server:** an application `name` with a space is rejected ("must only contain letters, numbers, dashes and underscores"), so the tool refuses spaces. With a valid name the temporary-domain request answered the same HTTP 500 again, on a different server of the same account, so the failure is probably account-wide. The failed request did not create an application, but the server's system-user count went from 0 to 1: the new system user is created before the temporary-domain step fails. A third attempt (plain PHP 8.2, temporary domain) failed the same way and the count went from 2 to 3, which confirms that every failed attempt leaves one system user behind. The tool therefore always sends `temp_domain: false` with the user's own `hostname`, and that worked: a plain PHP 8.2 application (`method: custom`, `framework: custom`, `www: false`, a new system user, `php_version: 8.2`) on `demo.example.com` answered 200 `{"message": "Custom application has been installed successfully.", "applicationDomainWww": null, "database": null}`, and the server then listed the application (primary domain the given hostname, `ssl` null) and one more system user. Sending a name that already exists is refused with 422 "Application name already exists!" and leaves nothing behind. DNS did not have to point at the server yet (the test domain resolved to Cloudflare addresses, not the server). The own-`hostname` path is untested.
- **One endpoint, many payloads.** The documentation lists a different body for each application type (plain PHP, WordPress, Mautic, Moodle, Joomla, PrestaShop, Akaunting, Statamic, Nextcloud, phpMyAdmin, Craft CMS, Git, Node). They differ in `method` (`custom`, `one_click` or `git`), in `php_version` (a number for some types, a string for others, and a different range for each), in admin fields (`username`, `email`, `title`, `firstname` and so on), in a required `database_name` for the CMS types, in fixed web roots (Statamic, Craft CMS) and in `install_litespeed_cache_plugin` for WordPress. The tool builds each from one map (`app/Services/ServerAvatar/ApplicationTypes.php`). None of these bodies except the plain PHP one has been sent live yet, so field names, the Craft CMS web root value and the password rules are as documented, not confirmed.
- **What ServerAvatar's validator enforces first (probed live, name left out so nothing could be created):** always `name`, `method`, `framework`, `temp_domain` and `systemUser`; with `systemUser: new` also `systemUserInfo.username` and `systemUserInfo.password`; with `systemUser: existing` also `systemUserId`. The same answer came for all 14 types. Fields the documentation marks required but that this first stage did not mention (`php_version`, `www`, `temp_sub_domain_name`, `hostname`, and every type-specific field such as `title` or `database_name`) are checked in a later stage, or not at all, so they cannot be learned from a nameless request. Finding them would need real create requests.
- **System users:** `GET .../servers/{s}/system-users?pagination=1` answers a paginator under `systemUsers`. Each record has `id`, `server_id`, `username`, **`password`**, `public_key`, `group`, `root_access`, `created_at`, `updated_at`, `deleted_at`, `ssh_access` and `applications`; the tool keeps everything except the password, the key and the dates. A failed temporary-domain create showed up here as an extra user (the tool cannot delete one).
- The API has no endpoint to make a domain primary. `GET .../application-domains/{id}/edit` switches the temporary domain on or off.
