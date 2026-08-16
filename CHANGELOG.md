# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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