# Curated tool set (v1, 24 tools)

One tool per task, explicit parameters. Endpoints come from ServerAvatar's public API documentation, and the read endpoints were checked against the live API (see [api-notes.md](api-notes.md)). `O` = `/organizations/{organization}`, `S` = `/servers/{server}`, `A` = `/applications/{application}`.

The 16 ServerAvatar read tools are built and were run against a real account (7 servers, about 40 calls). The 8 action and manage tools are built and tested against a faked ServerAvatar, but **have only partly been run against the real API**: `restart_server`, `create_database`, `create_application` (five types, own domain), `add_domain`, `install_ssl`, `create_cronjob` and `update_database_user` were verified on a test server; `control_supervisor` needs a supervisor process to act on, and its request follows ServerAvatar's documentation. The live check changed some of the plan: lists are **server-scoped**, so those tools take a `server_id` (the organization-level lists answered 403 for a team token), and every result is trimmed to an allow-list of fields. A result larger than 100 KB is refused, not cut; lists are paged with `page` and say `next_page` when there is more.

## Read (level: read), 15 tools

| Tool | Endpoint | Notes |
| --- | --- | --- |
| `list_organizations` | GET `/organizations` | Entry point. Returns id and name only, never members. Verified. |
| `list_servers` | GET `/O/servers?pagination=1` | Paged with `page`. Verified. |
| `get_server` | GET `/O/S`, `/O/S/status`, `/O/S/summary` | Merged and trimmed; no admin-tool links. Verified. |
| `get_server_usage` | GET `/O/S/usage` | CPU load, memory, disk, uptime. Verified. |
| `get_server_logs` | GET `/O/S/logs`, POST `/O/S/logs` | Two steps: call without `log` to list the files, then with `log` (and `lines`, 1 to 500, default 100) for the tail of one. The name must be one the server lists. Output is capped at 20 KB and secrets in log lines are redacted. Verified (a 13,000-line syslog came back as its last lines). |
| `list_applications` | GET `/O/S/applications?pagination=1` | Needs `server_id`. The organization-level list can 403. Verified. |
| `get_application` | GET `/O/S/A` | Trim configuration and the license key. Verified. |
| `list_domains` | GET `/O/S/A/application-domains?pagination=1` | Verified. |
| `get_ssl` | GET `/O/S/A/ssl` | Installed, force-HTTPS and certificate dates. Verified. |
| `list_databases` | GET `/O/S/databases?pagination=1` | Needs `server_id`. The organization-level list can 403. Verified. |
| `list_database_users` | GET `/O/S/databases/D/database-users?pagination=1` | Needs `database_id` (from `list_databases`). Shows each user's name, who may connect (`connection_preference`: `localhost`, `specific_ip_addresses` with the allowed IPv4 addresses in `hostname`, or `everywhere`) and `remote_access`. Passwords are dropped. ServerAvatar lists 10 per page, so it pages. **Verified live** (2026-11-21) through the same client calls: a localhost user shows `hostname: ["localhost"]` and `remote_access: false`; after an update to one address it showed that address and `remote_access: true`. Not yet called through the claude.ai connector. |
| `list_cronjobs` | GET `/O/S/cronjobs?pagination=1` | Verified, including its fields. Secrets in a command are redacted. |
| `list_backups` | GET `/O/backups?pagination=1` | **Can 403 by role.** Answers with a clear permission message. Verified only as that refusal: the item shape is unseen, so items are trimmed generically. |
| `list_application_types` | none (answers locally) | Touches no ServerAvatar account. Lists the application types `create_application` can create, with the PHP versions each accepts, the server it needs, what to ask the user for and what is generated. The AI calls it first and asks the user to choose. It also returns `type_questions`: the type choice as ready-made questions of 2 to 4 options each (kind of app first, then a page of Node.js or other PHP types), sized for a client's own choice popup (Claude Code's AskUserQuestion, claude.ai's `ask_user_input`). The tool text asks the AI to use that popup for the type, the new-or-existing system user and the `www.` choice, and to take only free text (domain, name, site title, admin username) by typing. The server cannot open a popup itself: that is MCP elicitation, which needs server-to-client requests and sessions that `laravel/mcp` does not support. Whether a popup appears depends on the client and the AI following the instruction. |
| `list_system_users` | GET `/O/S/system-users?pagination=1` | Needs `server_id`. The system users ("application users") of a server with the applications that run as each, so the user can choose one for `create_application`. ServerAvatar's record carries the user's **login password and public key**: both are dropped. Verified live (record shape seen on a test server). |
| `list_supervisors` | GET `/O/S/A/supervisors?pagination=1` | Verified as an empty list; the item shape is unseen, so items are trimmed generically (secret-looking keys dropped). |

## Action (level: action), 2 tools

Both change state with a plain `GET`, so they are sent once and never repeated after a server error or dropped connection (the first request may already have been carried out). Both are marked destructive for the client.

| Tool | Endpoint | Notes |
| --- | --- | --- |
| `restart_server` | GET `/O/S/restart` | Every site on the server is down while it restarts. Verified live on a test server. |
| `control_supervisor` | GET `/O/S/A/supervisors/{id}/{start,stop,restart}` | `action` is one of start, stop, restart. Built, **not verified live**. |

## Manage (level: full), 5 tools

Kept narrow in v1 so nothing sensitive passes through the model. `create_database`, `create_application` (own domain), `add_domain`, `install_ssl` and `create_cronjob` were verified live; `control_supervisor` is built and **not verified live** (it needs a supervisor process).

| Tool | Endpoint | Notes |
| --- | --- | --- |
| `create_application` | POST `/O/S/applications` | The user picks a type (call `list_application_types` first); `framework` is that type and each type has its own payload, see the table below. **The user's own domain is required** (`hostname`, always sent with `temp_domain: false`): the AI asks for it, and its DNS must point at the server. ServerAvatar's free temporary domains are not used, because creating one answered HTTP 500 on two different servers (and each failed attempt left a stray system user). **System user:** the AI asks whether to use a new one or an existing one. New (the default): a random username and a strong password are generated and the password is returned once; the user may instead give their own (`system_user_username`: 3 to 32 lowercase letters, digits, hyphens or underscores, starting with a letter, never `root`, `admin` and similar; `system_user_password`: 8 to 64 characters, no spaces), and a password the user chose is not echoed back. Existing: the AI calls `list_system_users`, the user chooses, and the id goes in `system_user_id` (sent as `systemUser: existing`; the name and password are then not used and are reported as `ignored_inputs`). The `name` takes letters, digits, hyphens and underscores only: ServerAvatar rejects a space (found live). **Admin passwords and database names are never taken from the model**: they are generated here, returned once in the result and kept out of the activity log. An input that belongs to another type is not sent and is reported as `ignored_inputs`; a missing required input is refused with its name before anything is sent. The request fields ServerAvatar checks first (name, method, framework, temp_domain, systemUser and the system user fields) were confirmed live. **Verified live on the user's own domain for five types: plain PHP 8.2, WordPress, phpMyAdmin, Joomla and Statamic** (ServerAvatar answered with a success message and the application, domain and new system user exist). The other nine types (Akaunting, Nextcloud, Moodle, PrestaShop, Craft CMS, Uptime Kuma, n8n, Node-RED, NodeBB) follow ServerAvatar's documentation and are **not verified live**. |
| `add_domain` | POST `/O/S/A/application-domains` | A bare host name only (no scheme, path, port or wildcard), lower-cased. SSL has to be installed again afterwards. **Verified live** on a plain PHP application: the domain was listed afterwards. |
| `install_ssl` | POST `/O/S/A/ssl` | **Automatic (Let's Encrypt) only**, plus `force_https`. Custom certificates are not offered: they would send a private key through the model. **Verified live** with `force_https` false on an application with DNS pointing at the server; `force_https` true was also run live: HTTP then redirected (301) to HTTPS. |
| `create_database` | POST `/O/S/databases` | **The AI must ask the user for the database name, the user name and who may connect, and must not invent them** (a live chat once created `shop_db` and chose local-only without asking, so the tool text now says so in strong terms; it is still only an instruction to the AI). **Who may connect is the user's choice**, asked by the AI: `connection_preference` is `localhost` (the default: the server itself only), `specific_ip_addresses` (only the IPv4 addresses in `hostname`, a list of up to 10; required for this value; IPv6, names, ranges and `0.0.0.0` are refused) or `everywhere` (any computer: the AI offers it as one of the three choices, warns that it exposes the database to the internet and recommends another). `connection_preference` is always sent: leaving it out makes the live API answer 500. The result's `allowed_from` says who may connect, and `hostname` given with another value is dropped and reported as `ignored_inputs`. **Verified live for `localhost` and for `specific_ip_addresses`** (`hostname` sent as an array, as ServerAvatar documents it: it answered "Database has been created successfully." and the database then listed with `remote_access: true` and the server's IP as host). `everywhere` was also **verified live** the same way. `list_databases` does not show which addresses are allowed. The password is generated here, 24 random letters and digits, and returned once in the result. It is not in the activity log. |
| `update_database_user` | GET `/O/S/databases/D/database-users`, then PATCH `.../database-users/{id}` | Changes **who may connect** as an existing database user, with the same three choices as `create_database` (`localhost`, `specific_ip_addresses` with a `hostname` list, `everywhere`). The database (`database_id`, from `list_databases`) and the user's **name** are given; the tool finds the user's id itself. **The address list replaces the old one**, so the AI is told to include the addresses that must keep access. **It resets the user's password**: ServerAvatar documents `password` as required on update, so a new strong one is generated here and returned once, and whatever uses the old password stops working. The AI is told to warn the user first. Needs the full level. **Verified live** (2026-11-21): the list of a database's users (key `databaseUsers`, one page of `data`; each user has `id`, `username`, `connection_preference` and `hostname`, the allowed addresses) and the PATCH, which changed the allowed addresses of a test database user. Leaving `password` out is refused ("The password field is required"), which is why the password is always reset. |
| `create_cronjob` | POST `/O/S/cronjobs` | **Verified live:** created, listed and executed as the system user. **Preset schedules only** (no custom minute/hour fields). One-line command (at most 1000 characters), run on the server as the given system user: this is the most powerful tool in the set. Secrets in the command are removed from the activity log. |

### Application types

Built from ServerAvatar's documentation, one payload per type. Wherever a password or database name is generated, it is returned once in the result as `admin_password`, `database_name` and `system_user_password`. `php_version` is sent as a number or a string as the documentation says for that type. The WordPress LiteSpeed cache plugin is switched on only when the server reports OpenLiteSpeed. Statamic and Craft CMS send their fixed web roots (`public`, `web`); the Node types send `npm` and `fork`. Admin passwords are 24 random characters (14 for Craft CMS, whose rule allows 6 to 15) with an upper-case letter, a lower-case letter, a digit and one of `@`, `-`, `_`.

**Verified live** on a test server: `custom`, `wordpress`, `phpmyadmin`, `statamic` and `joomla` (Joomla has no admin fields: the site opens the Joomla installer). The other types follow the documentation. Node types need a Node server (for example MERN), not just Node.js installed.

**Not offered:** Mautic (it needs the user's real SMTP password, which would pass through the model) and the Git-based applications (they need SSH keys and connected git accounts).

| `framework` | Type | PHP versions | You provide (\*required) | Generated and returned once |
| --- | --- | --- | --- | --- |
| `custom` | Custom PHP application | 7.2 to 8.5 | nothing | nothing |
| `wordpress` | WordPress | 7.2 to 8.5 | `site_title`*, `admin_username`*, `admin_email`*, `timezone` | admin password, database name |
| `joomla` | Joomla | 8.1 to 8.5 | nothing | nothing |
| `phpmyadmin` | phpMyAdmin | 7.4 to 8.5 | nothing | nothing |
| `akaunting` | Akaunting | 8.1 to 8.5 | `admin_email` | admin password |
| `nextcloud` | Nextcloud | 8.2 to 8.4 | `admin_username`*, `admin_email` | admin password, database name |
| `moodle` | Moodle | 8.0 to 8.5 | `full_name`*, `short_name`*, `summary`*, `admin_username`*, `admin_email` | admin password, database name |
| `prestashop` | PrestaShop | 8.1 to 8.5 | `first_name`*, `last_name`*, `admin_email` | admin password, database name |
| `statamic` | Statamic | 8.2 to 8.5 | `admin_email`* | admin password |
| `craftcms` | Craft CMS | 8.2 to 8.5 | `admin_username`*, `admin_email`* | admin password, database name |
| `uptimekuma` | Uptime Kuma | none (Node.js server) | nothing | nothing |
| `n8n` | n8n | none (Node.js server) | nothing | nothing |
| `nodered` | Node-RED | none (Node.js server) | `admin_username`* | admin password |
| `nodebb` | NodeBB | none (Node.js server) | `admin_username`*, `admin_email`* | admin password |

All eight write tools answer with `ok`, a short message and ServerAvatar's reply trimmed of secret-looking fields. The message says the request was *sent*: ServerAvatar finishes some of these in the background.

## Deliberately left out of v1

All DELETE/destroy and restore endpoints, server creation, root password change, the file manager, git deployment, the WordPress toolkit, Cloudflare, firewall and fail2ban, SSH/SFTP credentials and the WordPress magic link. A later release can add them behind a `confirm` guard.

## What the AI does when no tool fits

The server's instructions tell the AI that when the user asks for something none of these tools can do (for example deleting a database, an application or a system user), it must say so plainly and must not fall back to a browser, Claude in Chrome or computer use on its own. It should name what is missing, ask whether the user wants it to try the browser, and act only after a yes. This is an instruction to the AI, not something the server can enforce.
