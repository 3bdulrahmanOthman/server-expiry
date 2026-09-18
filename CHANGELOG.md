# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-09-18

### Added
- Redesigned owner-facing **Expiration page** (read-only dashboard): a
  state-specific hero for all six lifecycle states — Permanent (∞ badge with a
  subtle green ambient glow, exclusive to that state), Active, Expiring Soon,
  Expired, Grace Period and Suspended — plus a live countdown (Alpine,
  display-only, server-rendered fallback), a real-data lifecycle timeline
  (configured warning thresholds, the expiration moment and the actual
  auto-suspension moment), status summary cards, a suspension-reason banner,
  dark/light mode support, mobile-friendly layout and reduced-motion support.
  All state is precomputed on the server (`ExpirationView` view model built
  from `ExpirationService` and the tenant `Server` record); the Blade view is
  presentation-only.
- **Contact Support CTA** on the owner page, driven by the new
  `SERVER_EXPIRY_SUPPORT_URL` setting; hidden cleanly when the setting is
  empty. v2.1 remains provider-only for expiration changes — there is no
  self-service renewal.
- New `.env`-backed settings (also editable in the plugin Settings dialog):
  `SERVER_EXPIRY_WEBHOOK_MAX_ATTEMPTS`, `SERVER_EXPIRY_WEBHOOK_TIMEOUT`,
  `SERVER_EXPIRY_WEBHOOK_BACKOFF_BASE` (previously hardcoded constants) and
  `SERVER_EXPIRY_SUPPORT_URL`.
- **Scheduled automatic webhook retry**: `pelican:process-webhook-deliveries`
  runs every minute and re-attempts failed deliveries using the configured
  attempts and exponential backoff (previously only manual retry existed).
- Webhook endpoint **secrets are auto-generated on create** when left empty,
  so endpoints can no longer be created unsigned (editing never touches a
  stored secret). Pre-existing v2.0.x endpoints created without a secret keep
  `secret_key = NULL` and continue to send unsigned deliveries until a secret
  is set via the endpoint's edit form.
- Regression suites for the settings surface, webhook CRUD header actions and
  resource routing, the owner-UI contracts, the config/grace boundary, the
  lifecycle suspension decision and command wiring, webhook delivery
  persistence, and the scheduler-registered webhook retry — 103 tests /
  415 assertions.

### Changed
- **Webhook admin URLs** moved under the Server Expiry route hierarchy —
  `/admin/server-expiry/webhook-endpoints` and
  `/admin/server-expiry/webhook-deliveries` (previously
  `/admin/webhook-endpoint/webhook-endpoints` and
  `/admin/webhook-delivery/webhook-deliveries`). See *Upgrade Notes* below.
- Webhook Create/Save/Cancel actions now render as **header actions** in the
  native Pelican convention (icon + tooltip + visible label). Previously they
  were Filament form-footer actions, a surface Pelican panels leave unused, so
  the webhook Create and Edit pages had no visible submit control at all.
- The owner page form still chains Pelican's `ServerFormPage::form()`
  (tenant record binding preserved); presentation moved entirely into the view
  model.

### Fixed
- **Grace-period `TypeError`**: `SERVER_EXPIRY_GRACE_HOURS` configured via
  env/Settings reaches `config()` as a *string*, and the strict
  `GracePeriod::fromHours(int)` contract rejected it — crashing the admin
  Expiration tab, the owner page and the suspension scheduler whenever a grace
  period was configured. The repository now normalizes to `int` at the
  infrastructure boundary (domain contract unchanged).
- **`suspension_reason` was never stamped at runtime**: the `Server::updating`
  hook compared the enum-cast `status` attribute against a plain string value,
  so the strict comparison never matched and reason stamping/clearing silently
  never executed. The hook now normalizes enum and string values before
  comparing; expiration suspensions reliably stamp
  `suspension_reason = 'expiration'` again (verified end-to-end through the
  scheduler command).
- **Webhook deliveries could never be recorded**: the delivery model emitted
  Eloquent `created_at`/`updated_at` columns that migration `005` does not
  define, so every delivery insert failed with `Unknown column 'updated_at'`.
  Eloquent timestamps are now disabled on the model; timing remains in the
  explicit `queued_at` / `processed_at` / `next_attempt_at` columns. Signed
  delivery was verified end-to-end against a live receiver with an
  independent HMAC-SHA256 recomputation.
- Owner page crashes under the current Filament/Livewire stack: Blade's
  single-expression `@php(...)` form is not compiled there and Livewire renders
  conditional template regions in separate fragment scopes, so
  template-assigned variables never reached them ("Undefined variable $view").
  The view model and state icon are now passed as real view data
  (`getViewData()`), and the view assigns no local variables. Also replaced a
  non-existent `Illuminate\Support\CarbonImmutable` import with
  `Carbon\CarbonImmutable`.

### Upgrade Notes (v2.0.x → v2.1.0)

**Webhook admin URL change (breaking for bookmarks/links only).**

| Resource | Old URL (v2.0.x) | New URL (v2.1.0) |
| --- | --- | --- |
| Webhook Endpoints | `/admin/webhook-endpoint/webhook-endpoints` | `/admin/server-expiry/webhook-endpoints` |
| Webhook Deliveries | `/admin/webhook-delivery/webhook-deliveries` | `/admin/server-expiry/webhook-deliveries` |

- What to update: any admin bookmarks, dashboard links, or internal docs that
  point at the old URLs. Route names changed accordingly (now
  `resources.server-expiry.webhook-endpoints.*` /
  `resources.server-expiry.webhook-deliveries.*`).
- Navigation is unchanged: both resources remain in the admin sidebar group
  "Server Expiry".
- **Webhook receivers are unaffected**: delivery payloads, HTTP method and the
  `X-Signature` / `X-Timestamp` / `X-Webhook-ID` headers are unchanged.
- The Application API endpoints under `/api/application/servers/{server}/…`
  and the owner page route `/server/{server}/expiry-settings` are unchanged.
- No database migrations ship in 2.1.0; all new settings are additive env
  keys with safe defaults.

## [2.0.1] - 2026-09-16

### Fixed
- Pelican server IDs are integers, but the plugin contracts required strings
  under `strict_types`, causing `TypeError` crashes in the admin UI, the
  client Expiration page, the Application API, and the expiration scheduler.
  All service/repository/event contracts now accept `int|string`.
- Repository write operations silently no-opped when the plugin's schema
  columns were missing (masking incomplete installations). They now log and
  throw; reads keep a safe fallback while logging the condition. Schema
  checks are memoized per process.
- Expiration status UI hardening: the client page's status-color match now
  handles the `danger` state and includes a `default` fallback (previously an
  `UnhandledMatchError` for expired/suspended servers); the static status SVG
  is rendered correctly instead of escaped text; Bootstrap `badge-*` classes
  replaced with Tailwind-compatible styling.
- Added missing translation keys (`action_renew`, `action_extend`,
  `action_clear`, `suspension_label`, `state_suspended`, `state_active`,
  `none`).

### Changed / Security
- Client self-service **Renew** and **Set Expiration** actions were removed
  from the client Expiration page. Expiration changes remain
  provider/admin-controlled, matching the documented policy. Administrators
  manage expiration through the admin panel as before.


## [2.0.0] - 2026-09-13

### Added
- Domain-driven expiration core: `ExpirationService`, `ExpirationDate` value
  object, `ExpirationStatus` enum (PERMANENT/ACTIVE/WARNING/GRACE/EXPIRED/SUSPENDED),
  domain events and an Eloquent persistence layer.
- Webhooks: endpoint management (Filament resources), HMAC-SHA256 signed
  deliveries (`X-Signature`, `X-Timestamp`, `X-Webhook-ID`), per-endpoint event
  subscriptions, delivery tracking with failure recording and manual retry,
  SSRF protection on endpoint URLs.
- Application API: show / update / extend / renew / clear expiration endpoints
  under `/api/application/servers/{server}`, guarded by the panel's
  Application-API ACL (READ for show, WRITE for mutations).
- Consolidated lifecycle command `pelican:process-server-expiration`
  (warnings + expiration suspension in one idempotent scheduler run).
- `suspension_reason` stamping (`expiration` vs `manual`); only
  expiration-based suspensions are auto-revived on renewal.
- Notification idempotency claim table for warnings/suspension notices.
- PHPUnit test suite (domain status matrix, timezone normalization,
  remaining-time semantics, HMAC signing, migration ordering invariants).

### Fixed
- Scheduler: single consolidated command registered (legacy duplicate
  registrations removed); lifecycle never suspends before expiration + grace.
- Client Expiration page authorization now owner-based (previously relied on a
  root-admin server policy that 403'd non-admin owners).
- Admin server list/edit actions no longer misuse `Gate::authorize()` as a
  boolean; status badges use shared domain color semantics.
- API routes use the panel's real application-API middleware stack (the
  previous `auth:application` guard does not exist) and return 422 (not 500)
  when extending a permanent server.
- Migrations: unique sequential prefixes (duplicate `007` renamed), MySQL-safe
  JSON column (no DEFAULT on JSON), safe NOT NULL conversion with identifier
  backfill.
- Webhooks: HMAC signing uses the real secret (previously a masked
  `********` placeholder), Filament resources actually registered with the
  admin panel, retry action functional, `events = NULL` handled safely.
- Webhook payload serialization no longer calls the nonexistent
  `ExpirationDate::toString()` (fatal on dispatch).

### Changed
- Expiry helpers deprecated in favor of `ExpirationService`.
- README documents API contract, webhook behavior, upgrade path and known
  limitations.

## [1.1.0] - 2026-08-16

### Added
- Server expiration settings reachable from Admin Area → Plugins (`.env`-driven):
  auto-suspend toggle, grace period, warning days, owner notification on suspension.
- Grace period support: a configurable number of hours granted after expiration
  before the server is actually suspended.
- Automatic revival of expiry-suspended servers when an admin sets a new (future)
  expiration date via Edit Server → Expiration.

### Changed
- Warning thresholds are now configurable (`SERVER_EXPIRY_WARNING_DAYS`, default `7,3,1`).
- Suspension banner replaced the generic conflict banner on the client panel when
  a server expired.

### Fixed
- Owner notifications (warning + suspension) now use Laravel's queued notifications.
- Idempotent warning delivery per threshold per server (`expiry_warning_day`).

## [1.0.0] - 2026-01-01

### Added
- Initial release: expiration date field on servers, expiry status badges in the
  admin server list, client-facing expiration page, auto-suspension via
  `SuspensionService` and expiry warnings by mail + in-panel notification.