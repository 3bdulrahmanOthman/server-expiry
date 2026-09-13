# Server Expiry & Auto-Suspend

![License](https://img.shields.io/github/license/3bdulrahmanOthman/server-expiry)
![PHP](https://img.shields.io/badge/PHP-8.2+-8892BF)
![Panel](https://img.shields.io/badge/Pelican%20Panel-supported-2e8b57)

Out-of-the-box expiration dates and automated suspension for **Pelican Panel**
servers. Set an expiry date per server and let the plugin suspend it on time,
send reminders before it happens, and revive it automatically on renewal.

## Screenshots

The expiration page for the server owner, shown in the server sidebar:

![Server sidebar expiration page](expiry-page.png)

Plugin settings in the admin panel, under Admin Area → Plugins → Settings:

![Admin panel plugin settings](expiry-settings.png)

## Features

- **Expiration field** — set an exact expiration date/time per server (blank = permanent).
- **Server edit tab** — an "Expiration" tab on the admin server edit form.
- **Creation wizard step** — set an expiration date while creating a server.
- **Status badges** — "Expires At" column in the admin server list:
  - <span style="color:#6b7280">gray</span> = permanent,
  - <span style="color:#16a34a">green</span> = active,
  - <span style="color:#f59e0b">yellow</span> = expiring within the warning window,
  - <span style="color:#dc2626">red</span> = expired.
- **Client info page** — an "Expiration" page in the server sidebar visible to
  all server members showing the expiration status, date, time remaining and
  the warning/suspension policy. It is **read-only**: the expiration date can
  only be changed by the provider via Edit Server → Expiration (admin panel).
- **Auto-revive on renewal** — when an admin sets a new (future) expiration
  date on an expiry-suspended server, the server is automatically unsuspended
  via `SuspensionService` and the warning thresholds reset.
- **Custom suspension banner** — when a server is suspended because it expired,
  the generic "server conflict" banner is replaced by a dedicated expiration
  banner with the expiry date and a "contact your service provider to renew"
  note (clients cannot change the expiration date themselves).
- **Lifecycle command** — `pelican:process-server-expiration` runs every minute
  via Laravel's scheduler; it sends idempotent warning notifications and
  suspends expired servers (after the grace period) through Pelican's native
  `SuspensionService` (marks `ServerState::Suspended`, stamps
  `suspension_reason = 'expiration'`, and tells Wings to re-sync the server
  state via the daemon API).
- **Owner notifications** — mail + in-panel database notification to the owner on
  warning **and** on suspension.
- **Grace period** — extra hours granted after expiration before suspension.
- **Settings page** — a Settings action on the plugin row in Admin Area → Plugins
  (`.env`-driven): auto-suspend toggle, grace period, warning days, owner notification.
- **Webhooks** — register HTTP endpoints (Admin Area → "Server Expiry" → Webhook
  Endpoints) that receive signed (HMAC-SHA256) JSON notifications for expiration
  lifecycle events, with per-endpoint event subscription and delivery tracking
  including manual retry from the admin UI.
- **Application API** — five Application API endpoints under
  `/api/application/servers/{server}` (see [API](#api) below) guarded by the
  panel's standard Application-API key ACL (`READ` for show, `WRITE` for
  mutations) and root-admin authentication.

## Lifecycle

1. **Pre-expiration (warning stage)** — when a server enters a warning window
   (default 7, 3 and 1 day(s) before `expires_at`), the owner receives an email
   and an in-panel notification. Each threshold is sent only once per server.
2. **Expiration (suspension stage)** — once `expires_at` plus the configured
   grace hours has passed, the consolidated scheduler command
   `pelican:process-server-expiration` calls Pelican's `SuspensionService`,
   which marks the server as suspended in the database and triggers a daemon
   sync so Wings takes the server offline. Expiration-based suspensions are
   stamped with `suspension_reason = 'expiration'`; manually suspended servers
   are never auto-revived. On the client panel, the server's "Expiration" page
   shows a dedicated banner instead of the generic conflict banner.
3. **Post-suspension notification** — the owner receives a final email and
   in-panel notification explaining the server was suspended due to expiration.
4. **Renewal** — the provider sets a new expiration date (or clears it) via
   Edit Server → Expiration in the admin panel. The server is automatically
   unsuspended and warning thresholds reset so reminders start again. Clients
   see a read-only "Expiration" page with status and countdown information.

## Requirements

- [Pelican Panel](https://pelican.dev/) — v2.0.0 is developed and verified
  against **Pelican v1.0.0-beta38** (Filament v5). Behavior on other panel
  versions is untested.
- PHP 8.2+ and MySQL/MariaDB
- A running queue worker (notifications are queued)
- A cron entry for the scheduler task (see below)

## Installation

1. Copy this folder to `<panel>/plugins/server-expiry/` (folder name must match
   the `id` in `plugin.json`).
2. Run `php artisan p:plugin:install` and select the plugin (or use the Import
   button in Admin Area → Plugins).
3. The migration adds the `expires_at` column automatically.

> Download the latest release ZIP from the [Releases](https://github.com/3bdulrahmanOthman/server-expiry/releases)
> page of this repository, or enable the plugin's `update_url` for one-click
> updates from the panel.

## Cron setup

The plugin registers `pelican:process-server-expiration` (warnings +
expiration-based suspension in one idempotent run) in Laravel's scheduler, so
you only need the standard panel cron running. Verify it exists with `crontab -e`:

```cron
* * * * * php /var/www/pelican/artisan schedule:run >> /dev/null 2>&1
```

The check runs every minute (`withoutOverlapping`), so re-runs are safe.
Optional per-run grace override (manual run only):

```bash
php /var/www/pelican/artisan pelican:process-server-expiration --grace-hours=24
```

## Configuration

Set these in the panel `.env` (or use the plugin's Settings page in
Admin Area → Plugins):

```dotenv
SERVER_EXPIRY_AUTO_SUSPEND=true        # master switch for auto-suspension
SERVER_EXPIRY_GRACE_HOURS=0            # hours after expiry before suspension
SERVER_EXPIRY_WARNING_DAYS=7,3,1       # warning thresholds (days before expiry)
SERVER_EXPIRY_NOTIFY_ON_SUSPEND=true   # send mail + panel notification on suspend
```

Full defaults live in `config/server-expiry.php`:

| Config key | Env var | Default | Description |
| --- | --- | --- | --- |
| `auto_suspend_enabled` | `SERVER_EXPIRY_AUTO_SUSPEND` | `true` | Disables the suspend step when `false` |
| `grace_period_hours` | `SERVER_EXPIRY_GRACE_HOURS` | `0` | Grace period in hours before suspension |
| `warning_days_notice` | `SERVER_EXPIRY_WARNING_DAYS` | `7,3,1` | Warning thresholds in days before expiry |
| `notify_owner_on_suspend` | `SERVER_EXPIRY_NOTIFY_ON_SUSPEND` | `true` | Send mail + database notification to the owner |

## API

The plugin exposes five Application API endpoints (root-admin API keys, panel
ACL on the `server` resource):

| Method | Path | ACL | Description |
| --- | --- | --- | --- |
| GET | `/api/application/servers/{server}/expiration` | READ | Status, expiry date, expired/grace flags, remaining seconds |
| PUT | `/api/application/servers/{server}/expiration` | WRITE | Set `expires_at` or `{"permanent": true}` |
| POST | `/api/application/servers/{server}/expiration/extend` | WRITE | Extend by `{"hours": N}` (422 if the server is permanent) |
| POST | `/api/application/servers/{server}/renew` | WRITE | Renew to a new `expires_at` (400 if renewing a dated server to permanent) |
| DELETE | `/api/application/servers/{server}/expiration` | WRITE | Clear the expiration (make permanent) |

`status` values: `0` permanent, `1` active, `2` warning, `3` expired,
`4` grace, `5` suspended (domain enum; see `docs/ARCHITECTURE.md`).

## Webhooks

Webhook endpoints are managed in Admin Area → "Server Expiry" (Webhook
Endpoints / Deliveries). Each endpoint subscribes to a subset of:

`server.expiry.created`, `server.expiry.updated`, `server.expiry.warning`,
`server.expiry.expired`, `server.expiry.suspended`, `server.expiry.renewed`,
`server.expiry.cleared`

Deliveries POST JSON with `X-Signature` = HMAC-SHA256 of
`<raw-body><X-Timestamp>` keyed by the endpoint secret, plus `X-Timestamp`,
`X-Webhook-ID` and `Content-Type: application/json`. Failed deliveries are
recorded with their HTTP status and can be retried from the Deliveries table
(automatic scheduled retries are a known gap — see Limitations). Endpoint
URLs may not point at private, reserved or link-local addresses (SSRF
protection, validated at save time).

## Upgrade path (v1.2.0 → v2.0.0)

- Install the new release over the existing `plugins/server-expiry/` folder and
  run the panel's plugin update/import; migrations `003`–`008` run
  automatically.
- Migrations `001`/`002` keep their v1.2.0 names and will **not** re-run.
- All existing `expires_at` / `expiry_warning_day` values and notification
  idempotency rows are preserved; previously-sent warnings are marked
  `sent` so nothing is re-sent after upgrading.
- New columns/tables: `servers.suspension_reason`, `expiration_lifecycle_events`,
  `webhook_endpoints`, `webhook_deliveries`, and the extended
  `notification_idempotency` columns (`status`, `attempts`, `last_attempt_at`).
- Rollback: uninstalling removes the plugin tables and columns added by the
  migrations' `down()` methods.

## Known limitations

- Webhook **manual retry** works from the admin UI; the scheduled automatic
  retry pass (`processPendingDeliveries`) exists but is not yet wired to the
  scheduler.
- Webhook destination URLs are validated against private/reserved ranges at
  save time; DNS-rebinding between validation and delivery is not mitigated.
- The Application API is root-admin-only (panel convention); there are no
  subuser-level expiration permissions yet.
- Expiration-related Filament/API/webhook behavior is unit-tested at the
  domain level only; full Pelican-runtime integration testing is planned
  (see docs/DATABASE_CHANGES.md § R12 follow-ups).

## Development

Set `PANEL_PLUGIN_DEV_MODE=true` in `.env` so plugin errors throw instead of
marking the plugin `errored`, then watch `storage/logs/laravel.log`. Clear
caches after changes:

```bash
php artisan optimize:clear
```

Run the lint and style checks locally before contributing:

```bash
find . -name '*.php' -exec php -l {} \;
./vendor/bin/pint
```

## Uninstall

Admin Area → Plugins → Uninstall. The `expires_at` column is removed by the
migration rollback.

## Support

Found a bug or want a feature? Open an issue on the
[issue tracker](https://github.com/3bdulrahmanOthman/server-expiry/issues).

## License

[MIT](LICENSE) © Abdulrahman Othman