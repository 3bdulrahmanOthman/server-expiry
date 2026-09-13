# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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