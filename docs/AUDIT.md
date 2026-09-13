# Server Expiry Plugin - Repository Audit (v1.1.0)

## Executive Summary

This document presents the findings from Phase 0 (Repository Audit) of the Server Expiry & Auto-Suspend plugin upgrade to v2.x. The audit analyzed the existing v1.1.0 implementation against the architectural guidelines and requirements specified in CLAUDE.md.

## Current Architecture Overview

The current implementation follows a monolithic structure with concerns spread across multiple layers:

### Core Components

1. **Plugin Entry Point** (`src/ServerExpiryPlugin.php`)
   - Implements Filament's Plugin interface and HasPluginSettings
   - Handles UI extensions (tabs, steps, custom pages)

2. **Service Provider** (`src/Providers/ServerExpiryServiceProvider.php`)
   - Registers Laravel scheduler commands
   - Registers UI render hooks for suspension banners

3. **Core Logic** (`src/Support/Expiry.php`)
   - Static helper methods for expiration checking
   - Status determination and text formatting
   - Warning schedule calculation

4. **Console Commands**
   - `SuspendExpiredServersCommand.php` - Auto-suspension logic
   - `SendExpiryWarningsCommand.php` - Expiry warning notifications

5. **Notifications**
   - `ServerExpiredNotification.php` - Sent on suspension
   - `ServerExpiringWarningNotification.php` - Sent before expiration

6. **Filament UI Components**
   - Admin: Expiration tab on server edit form
   - Admin: Expiration step in server creation wizard
   - Server: Read-only expiration settings page
   - Admin Table: Custom "Expires At" column with color-coded badges

7. **Data Layer**
   - Two migrations adding `expires_at` and `expiry_warning_day` columns to `servers` table
   - `.env`-driven configuration with config file fallback

8. **Localization**
   - English language strings in `lang/en/strings.php`

## Current Functionality

The plugin provides these core features:

- **Expiration Field**: Nullable timestamp on servers (null = permanent)
- **Automatic Suspension**: Via Wings API through Pelican's SuspensionService
- **Expiry Warnings**: Email + in-panel notifications at configurable intervals (default 7,3,1 days)
- **Automatic Revival**: Server auto-unsuspended when expiration date is extended
- **Grace Period**: Configurable hours after expiration before suspension
- **Owner Notifications**: Mail + database notifications for warnings and suspensions
- **Client UI**: Read-only expiration page in server sidebar
- **Status Badges**: Color-coded "Expires At" column in admin server list
- **Settings Page**: Admin panel configuration (auto-suspend, grace period, warning days, notifications)
- **Wizard Integration**: Expiration step in server creation flow
- **Tab Integration**: Expiration tab in server edit form

## Current Expiration Lifecycle

1. **Pre-expiration (Warning Stage)**
   - When server enters warning window (X days before `expires_at`)
   - Owner receives email + in-panel notification
   - Each threshold sent only once per server (tracked via `expiry_warning_day`)

2. **Expiration (Suspension Stage)**
   - When `expires_at` (minus grace hours) is reached
   - `SuspendExpiredServersCommand` calls Pelican's `SuspensionService`
   - Server marked as suspended in database + Wings API sync
   - Client panel shows dedicated expiration banner

3. **Post-Suspension Notification**
   - Owner receives final email + in-panel notification about suspension

4. **Renewal**
   - Admin sets new future expiration date via Edit Server → Expiration
   - Server automatically unsuspended via SuspensionService
   - Warning thresholds reset so reminders start again

## Problems Found

### Architectural Issues

1. **Lack of Separation of Concerns**
   - Business logic scattered across UI components, service provider, and static helpers
   - No clear domain, application, and infrastructure layer separation
   - Direct model manipulation in UI components (`expiry_warning_day` updates)

2. **Tight Coupling**
   - Heavy reliance on Laravel/Pelican specific classes (Facades, Eloquent, Enums)
   - Static helper class (`Expiry`) handles multiple unrelated concerns
   - Service provider manages both scheduling and UI hooks

3. **Missing Abstractions**
   - No DTOs or value objects for expiration data
   - No domain events for lifecycle transitions
   - No application services layer
   - No repository pattern for data access
   - Magic strings throughout codebase

### Code Quality Issues

1. **Inconsistent Error Handling**
   - Mixed use of try/catch with varying approaches
   - Some exceptions logged, others bubbled up
   - No domain-specific exception types

2. **Testing Gaps**
   - No test directory or test files present
   - Zero test coverage for core functionality

3. **Documentation Gaps**
   - Missing architecture documentation
   - No API documentation (no API exists yet)
   - Limited inline code documentation

### Missing v2 Features (per CLAUDE.md)

1. **No API Endpoints**
2. **No Webhook Support**
3. **No Event-Driven Architecture**
4. **No Comprehensive Testing Strategy**
5. **No Proper Domain Model with Enums/Value Objects**
6. **Limited Authorization** (relies on Filament defaults)
7. **No Audit/Lifecycle Records**
8. **Limited Idempotency Guarantees**

### Concurrency Risks

1. **Potential Race Conditions**
   - Manual renewal vs scheduler expiration check
   - Concurrent renewal attempts
   - No transactions or row locking around state changes

### Compatibility Risks

1. **Schema Fragility**
   - Direct dependence on specific column names (`expiry_warning_day`)
   - Tight coupling to Pelican's `SuspensionService` and `ServerState` enums
   - Assumes specific database schema (servers table structure)
   - Direct Laravel scheduler usage without abstraction layer

## Recommended v2 Architecture

Following CLAUDE.md guidelines, the proposed architecture organizes code into clear layers:

```
src/
├── Domain/                 # Pure business logic, no framework dependencies
│   ├── Expiration/         # Expiration domain
│   │   ├── Entities/       # Domain entities
│   │   ├── ValueObjects/   # Immutable value objects
│   │   ├── Enums/          # Domain-specific enums
│   │   ├── Policies/       # Business policies
│   │   └── Exceptions/     # Domain-specific exceptions
│   │
│   └── Events/             # Domain events for decoupling
│
├── Application/            # Use cases and application services
│   ├── Actions/            # Specific use case implementations
│   ├── Services/           # Application services
│   ├── DTOs/               # Data transfer objects
│   └── Contracts/          # Interface definitions
│
├── Infrastructure/         # Framework and external service integrations
│   ├── Persistence/        # Data access implementations
│   ├── Notifications/      # Notification delivery mechanisms
│   ├── Webhooks/           # Webhook delivery infrastructure
│   └── Scheduler/          # Task scheduling implementations
│
├── Http/                   # HTTP layer (controllers, requests, resources)
│   ├── Controllers/        # HTTP controllers
│   ├── Requests/           # Form request validation
│   └── Resources/          # API resources
│
├── Filament/               # Filament-specific UI components
│   ├── Pages/              # Filament pages
│   ├── Resources/          # Filament resources
│   ├── Components/         # Custom Filament components
│   └── Widgets/            # Dashboard widgets
│
├── Console/                # Console commands
│   └── Commands/           # Artisan commands
│
└── Providers/              # Service providers
```

### Key Improvements

1. **Clean Architecture Layers**
   - Domain layer contains pure business logic with zero framework dependencies
   - Application layer orchestrates use cases
   - Infrastructure layer handles framework integrations
   - Presentation layers (HTTP, Filament, Console) are thin adapters

2. **Domain Modeling**
   - Strongly typed value objects (ExpirationDate, GracePeriod, etc.)
   - Domain enums for statuses and suspension types
   - Rich domain events for lifecycle transitions
   - Custom exception types for domain-specific errors

3. **Separation of Concerns**
   - UI components delegate to application services
   - Application services use domain model and contracts
   - Infrastructure implements contracts without leaking details upward

4. **Extensibility Points**
   - Event-driven architecture for side effects
   - Clear contracts for swapping implementations
   - Webhook infrastructure built-in
   - API layer separate from UI

## Recommended Migration Strategy

A phased approach ensuring backward compatibility and incremental delivery:

### Phase 0: Repository Audit (Current)
- ✅ Complete - Understanding existing implementation

### Phase 1: Architecture Setup
- Create domain layer foundation
- Define core value objects, enums, exceptions
- Establish namespace conventions

### Phase 2: Data Layer Enhancement
- **Preserve existing columns** (`expires_at`, `expiry_warning_day`) for backward compatibility
- Add new tables:
  - `expiration_lifecycle_events` - Audit trail of expiration changes
  - `webhook_endpoints` - Configured webhook URLs
  - `webhook_deliveries` - Delivery tracking with retry logic
  - `notification_idempotency` - Prevent duplicate notifications
- Create migrations that are safe for fresh installs and upgrades

### Phase 3: Domain Model Implementation
- Implement `ServerExpiration` entity
- Create value objects: `ExpirationDate`, `GracePeriod`, `WarningThreshold`
- Define enums: `ExpirationStatus`, `SuspensionType`
- Implement domain events for all lifecycle transitions
- Create domain exception types

### Phase 4: Application Services
- Create `ExpirationService` as main domain interface
- Implement use case actions:
  - `SetExpirationAction`
  - `ClearExpirationAction`
  - `ExtendExpirationAction`
  - `RenewServerAction`
- Create DTOs for service boundaries
- Define repository contracts

### Phase 5: Infrastructure Implementations
- Eloquent implementation of expiration repository
- Notification senders (mail, database)
- Webhook delivery manager with:
  - HMAC-SHA256 signature verification
  - Exponential backoff retry
  - Delivery tracking
  - Idempotency support
- Scheduler service abstraction

### Phase 6: Consolidated Scheduling
- Replace individual commands with unified `ProcessExpiration` command
- Handle both warning checks and expiration processing
- Maintain `withoutOverlapping()` protection
- Support configurable intervals

### Phase 7: Event-Driven Side Effects
- Implement event listeners for:
  - Notifications (mail, database, in-panel)
  - Webhook delivery
  - Audit logging
  - Automatic renewal handling
- Ensure loose coupling between domain and side effects

### Phase 8: API Layer
- Create RESTful API endpoints:
  - GET `/api/servers/{server}/expiration`
  - PUT `/api/servers/{server}/expiration`
  - POST `/api/servers/{server}/expiration/extend`
  - POST `/api/servers/{server}/renew`
  - DELETE `/api/servers/{server}/expiration`
- Implement proper authentication, validation, and error handling
- Use API resources for consistent responses

### Phase 9: Webhook Infrastructure
- Webhook endpoint management UI
- Signature verification (HMAC-SHA256)
- Retry mechanism with exponential backoff
- Delivery tracking and failure reporting
- Idempotency support for webhook receivers

### Phase 10: Admin UI Updates
- Migrate Filament components to use application services
- Expiration tab/server edit form
- Expiration step/creation wizard
- Settings page (enhanced with webhook config)
- Custom components (badges, widgets)
- Maintain backward compatibility where possible

### Phase 11: Client UI Enhancements
- Keep existing read-only expiration page
- Ensure it uses application services for data
- No administrative controls exposed to clients

### Phase 12: Testing Implementation
- **Unit Tests**: Domain model, value objects, services
- **Feature Tests**: API endpoints, console commands
- **Integration Tests**: Event flows, webhook delivery, notification sending
- Test scenarios covering:
  - Permanent servers
  - Future expiration dates
  - Warning thresholds (7,3,1 days)
  - Exact expiration moments
  - Grace period handling
  - Expired server states
  - Expiration suspension
  - Manual suspension vs expiration suspension
  - Renewal workflows
  - Expired server renewal
  - Clear expiration
  - Extend expiration
  - Change expiration
  - Disabled auto-suspend
  - Duplicate scheduler execution
  - Duplicate notification prevention
  - Failed notification handling
  - Failed webhook delivery
  - Failed Wings synchronization
  - API authorization and validation
  - Webhook signature verification
  - Migration paths
  - Upgrade scenarios

### Phase 13: Documentation
- Update README with v2 features
- Create:
  - `docs/ARCHITECTURE.md`
  - `docs/API.md`
  - `docs/WEBHOOKS.md`
  - `docs/CONFIGURATION.md`
  - `docs/DEVELOPMENT.md`
  - `docs/TESTING.md`
  - `docs/UPGRADE.md`
  - `docs/TROUBLESHOOTING.md`
  - `docs/RELEASE_CHECKLIST.md`
- Maintain CHANGELOG.md

### Phase 14: Backward Compatibility Verification
- Ensure v1.1.0 data migrates correctly
- Verify existing functionality preserved
- Test upgrade path from v1.1.0 to v2.0.0
- Confirm existing `.env` variables still work
- Validate existing commands still function

### Phase 15: Final QA & Release
- Run full test suite
- Perform manual verification of all features
- Update plugin.json version
- Prepare release assets
- Final documentation review

## Compatibility Risks and Mitigation

### Identified Risks

1. **Database Schema Changes**
   - Risk: Adding new tables/columns could break installs
   - Mitigation: Use nullable columns with defaults, preserve existing columns

2. **Configuration Changes**
   - Risk: Changing .env variable names or behavior
   - Mitigation: Maintain backward compatibility with fallback logic

3. **API Changes**
   - Risk: Removing or altering existing UI extension points
   - Mitigation: Use Filament's official extension points, maintain existing hooks

4. **Behavioral Changes**
   - Risk: Altering expiration lifecycle behavior
   - Mitigation: Preserve exact v1.1.0 behavior as default, make enhancements opt-in

### Migration Safety

1. **Data Preservation**
   - Existing `expires_at` data remains unchanged
   - Existing `expiry_warning_day` data preserved
   - No column removals in initial v2 release

2. **Configuration Compatibility**
   - All existing .env variables continue to work
   - New features opt-in via new configuration keys
   - Default behaviors match v1.1.0 exactly

3. **API Additive Approach**
   - New API endpoints don't interfere with existing UI
   - Existing UI continues to work via same Filament extension points

## Selected Skills and Rationale

For this audit phase, the following skills were implicitly used:

1. **source-driven-development** - Verified implementation against official Laravel/Filament/Pelican documentation rather than assumptions
2. **planning-and-task-breakdown** - Structured the audit approach and migration strategy
3. **code-review-and-quality** - Analyzed code quality, architectural issues, and maintainability factors
4. **documentation-and-adrs** - Creating this audit documentation
5. **laravel-project-patterns** - Evaluated adherence to Laravel conventions and identified deviations
6. **pelican-panel-development** - Assessed Pelican-specific integration points and extension usage
7. **context-engineering** - Set up proper analysis context for the codebase review

These skills were appropriate because:
- The task required understanding existing code rather than generating new code
- Needed to evaluate current implementation against stated architectural goals
- Required identifying gaps between current state and desired v2 state
- Needed to document findings in a structured, actionable format

## Recommendation: Proceed to Phase 1

**Yes, I recommend proceeding to Phase 1 (Architecture)**. 

The audit has established a clear understanding of:
- Current v1.1.0 implementation strengths and weaknesses
- Specific areas requiring improvement per CLAUDE.md v2 requirements
- Compatibility constraints that must be maintained
- A viable migration path forward

Phase 1 should focus on establishing the domain layer foundation with proper value objects, enums, exceptions, and event structures - all while maintaining zero breaking changes to the existing v1.1.0 public interface.

This approach ensures:
- Immediate value through better code organization
- Foundation for all subsequent v2 features
- Risk mitigation through incremental changes
- Clear progress markers for the v2 upgrade effort

The existing codebase provides a solid functional foundation to build upon, and the architectural improvements will address the maintainability, extensibility, and quality issues identified while preserving all existing user functionality and data.