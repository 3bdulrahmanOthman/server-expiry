# Server Expiry & Auto-Suspend

## Project Identity

This repository is the production-grade evolution of the Pelican Panel plugin:

**Server Expiry & Auto-Suspend**

Current stable version before v2:
**v1.1.0**

Target:
**v2.x**

Repository:
https://github.com/3bdulrahmanOthman/server-expiry

The project is a Pelican Panel plugin built primarily with:

- PHP
- Laravel
- Filament
- Pelican Panel Plugin APIs
- Laravel Scheduler
- Laravel Notifications
- Laravel Events
- Pelican/Wings integration

---

# 1. PRIMARY OBJECTIVE

Build a production-grade v2 while preserving all existing v1 functionality and
existing user data.

This is an evolution/refactor, NOT a blind rewrite.

The primary goals are:

1. Clean architecture
2. Strong domain boundaries
3. Reliable expiration lifecycle
4. Safe renewal behavior
5. API support
6. Event-driven architecture
7. Webhook support
8. Modern Admin UI
9. Modern Client UI
10. Strong authorization
11. Idempotent background processing
12. Comprehensive testing
13. Backward compatibility
14. High-quality documentation
15. Maintainability for future plugin ecosystem integration

---

# 2. NON-NEGOTIABLE RULES

## 2.1 Never modify Pelican core

Do not modify Pelican Panel core files.

Use the official plugin system and supported extension points.

If a requirement appears to require modifying Pelican core:

1. Investigate the official plugin APIs.
2. Search the official Pelican documentation/source.
3. Determine whether a supported extension mechanism exists.
4. Only conclude that core modification is required after evidence.

Never silently patch vendor/core files.

---

## 2.2 Preserve Existing Functionality

Existing v1 behavior must remain functional unless explicitly replaced
by a documented v2 improvement.

Existing functionality includes, where present:

- expires_at
- expiration settings
- expiration tab
- creation wizard expiration
- expiration badges
- client expiration page
- automatic expiration suspension
- grace period
- expiry warnings
- email notifications
- in-panel notifications
- auto-revive on renewal where appropriate
- custom suspension behavior
- existing Artisan commands
- scheduler integration

Do not remove existing functionality simply because a new architecture is
being introduced.

---

## 2.3 Business Logic Boundaries

Business logic MUST NOT be duplicated across:

- Controllers
- Filament Pages
- Filament Resources
- Commands
- Notifications
- API Resources
- Views

These layers should delegate to application/domain services.

Preferred architecture:

UI / API / CLI
      ↓
Application
      ↓
Domain
      ↑
Infrastructure

---

## 2.4 Source of Truth

The database/server state is the source of truth.

The frontend countdown is display-only.

The API response is not the authoritative expiration state.

Cached values must never override the actual server expiration state.

---

# 3. ARCHITECTURE

Preferred structure:

```text
src/
├── Domain/
│   ├── Expiration/
│   │   ├── Entities/
│   │   ├── ValueObjects/
│   │   ├── Enums/
│   │   ├── Policies/
│   │   └── Exceptions/
│   │
│   └── Events/
│
├── Application/
│   ├── Actions/
│   ├── Services/
│   ├── DTOs/
│   └── Contracts/
│
├── Infrastructure/
│   ├── Persistence/
│   ├── Notifications/
│   ├── Webhooks/
│   └── Scheduler/
│
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
│
├── Filament/
│   ├── Pages/
│   ├── Resources/
│   ├── Widgets/
│   └── Components/
│
├── Console/
│   └── Commands/
│
└── Providers/
```

Adapt this structure to the actual Pelican plugin conventions when required.

Do not introduce architecture purely for appearance.

Every abstraction must have a reason.

---

# 4. DOMAIN MODEL

The expiration domain should support:

- permanent server
- active expiration
- warning state
- expired state
- grace period
- expiration suspension

Recommended concepts:

```text
ExpirationService
ExpirationPolicy
ExpirationStatus
ExpirationState
RenewalService
```

Possible statuses:

```text
permanent
active
warning
expired
grace
suspended
```

Use appropriate enums/value objects where they improve correctness.

Do not use strings everywhere when a domain enum provides meaningful
constraints.

---

# 5. EXPIRATION OPERATIONS

The application must support:

```text
getExpiration()
setExpiration()
clearExpiration()
extendExpiration()
isExpired()
isInGracePeriod()
getStatus()
getRemainingTime()
```

All mutations must pass through the appropriate application/domain layer.

Never scatter direct `expires_at` mutations throughout the codebase.

---

# 6. RENEWAL RULES

Renewal must be treated as a business operation.

It may involve:

1. validation
2. expiration calculation
3. database update
4. suspension state evaluation
5. Wings synchronization
6. events
7. notifications
8. webhooks
9. audit/lifecycle records

Renewal must be transactional where appropriate.

---

# 7. SUSPENSION SAFETY

This is a critical invariant.

A server suspended manually must NOT automatically become unsuspended merely
because its expiration was renewed.

Distinguish at minimum:

```text
manual suspension
expiration suspension
other suspension
```

Correct behavior:

```text
expiration suspension
    ↓
renew
    ↓
eligible for automatic revival
```

But:

```text
manual suspension
    ↓
renew
    ↓
remain suspended
```

Never violate this invariant.

---

# 8. CONCURRENCY & IDEMPOTENCY

The system must safely handle concurrent operations.

Example:

```text
Scheduler
    ↓
server expires
    ↓
suspension starts
```

at the same time as:

```text
Admin
    ↓
renew server
```

Use appropriate:

- transactions
- row locking
- state checks
- idempotency
- unique constraints
- atomic operations

Avoid race conditions such as:

```text
renew succeeds
    ↓
scheduler uses stale state
    ↓
server gets suspended again
```

---

# 9. SCHEDULER

Existing commands must be preserved where possible.

Expected commands include:

```bash
pelican:suspend-expired-servers
pelican:send-expiry-warnings
```

A unified processor may be introduced if it improves architecture:

```bash
pelican:process-server-expiration
```

Commands should orchestrate application services.

Commands must NOT contain the actual business rules.

Background operations must be safe to execute more than once.

---

# 10. WARNING SYSTEM

Default warning thresholds:

```text
7 days
3 days
1 day
```

Warnings may be delivered through:

- in-panel notifications
- email

The same notification must not be sent repeatedly for the same server,
threshold and notification type.

Notification failures must not corrupt expiration state.

---

# 11. EVENTS

Use domain/application events for important lifecycle transitions.

Expected events:

```text
ExpirationCreated
ExpirationUpdated
ExpirationCleared
ExpirationWarning
ServerExpired
ServerSuspendedByExpiration
ServerRenewed
```

Events should decouple the expiration engine from side effects.

Example:

```text
ExpirationUpdated
    ↓
Notification listener
    ↓
Webhook listener
    ↓
Audit listener
```

Do not tightly couple the core expiration service to every external side effect.

---

# 12. WEBHOOKS

Expected events:

```text
server.expiry.created
server.expiry.updated
server.expiry.warning
server.expiry.expired
server.expiry.suspended
server.expiry.renewed
server.expiry.cleared
```

Webhook requirements:

- HMAC SHA-256 signatures
- secure secrets
- timeout
- retry
- exponential backoff
- delivery tracking
- failure tracking
- idempotency

Never expose webhook secrets in logs.

---

# 13. API

Before creating API endpoints, inspect Pelican's native API architecture.

Do not duplicate Pelican functionality unnecessarily.

Potential endpoints:

```http
GET    /api/application/servers/{server}/expiration
PUT    /api/application/servers/{server}/expiration
POST   /api/application/servers/{server}/expiration/extend
POST   /api/application/servers/{server}/renew
DELETE /api/application/servers/{server}/expiration
```

Use:

- authorization
- validation
- consistent response structures
- consistent error handling
- appropriate rate limiting

Potential response:

```json
{
  "server_id": 123,
  "expires_at": "2026-12-31T23:59:59Z",
  "status": "active",
  "is_expired": false,
  "is_suspended": false,
  "remaining_seconds": 123456
}
```

---

# 14. ADMIN UI

The Admin UI should expose:

- expiration status
- expiration date
- remaining time
- suspension state
- extend
- change expiration
- clear expiration
- renew

Useful dashboard metrics:

- expiring soon
- expired servers
- suspended by expiration
- renewals

Expiration status should be visible in server lists.

UI must delegate all mutations to application services.

---

# 15. CLIENT UI

Clients should be able to see:

- expiration status
- expiration date
- remaining time
- warning state
- expired state

Clients must not receive administrative controls unless explicitly authorized.

Countdowns are presentation only.

---

# 16. SECURITY

Every feature must consider:

- authorization
- IDOR
- mass assignment
- CSRF
- XSS
- SQL injection
- API validation
- permission escalation
- sensitive logging
- webhook secret exposure

Never trust:

- server IDs from clients
- expiration timestamps from clients
- frontend countdowns
- hidden form fields
- UI-only permissions

Authorization must be enforced server-side.

---

# 17. DATABASE

Preserve existing `expires_at` data.

Potential supporting structures may include:

```text
expiration lifecycle events
notification idempotency
webhook configuration
webhook delivery tracking
```

Do not create tables without a clear architectural purpose.

Every migration must be:

- reversible where practical
- safe
- compatible with existing data
- tested on fresh installation
- tested during upgrade

---

# 18. TESTING

Required categories:

```text
tests/Unit/
tests/Feature/
tests/Integration/
```

At minimum cover:

- permanent server
- future expiration
- 7-day warning
- 3-day warning
- 1-day warning
- exact expiration
- grace period
- expired server
- expiration suspension
- manual suspension
- renewal
- expired renewal
- clear expiration
- extend expiration
- change expiration
- disabled auto-suspend
- duplicate scheduler execution
- duplicate notifications
- failed notifications
- failed webhook
- failed Wings synchronization
- API authorization
- API validation
- webhook signatures
- migration
- upgrade

No feature is complete with only happy-path tests.

---

# 19. BACKWARD COMPATIBILITY

Migration target:

```text
v1.1.0 → v2
```

Must preserve:

- server expiration data
- settings
- existing servers
- existing commands where practical
- existing behavior

Test:

```text
fresh installation
upgrade installation
```

Do not silently destroy or transform user data.

---

# 20. DOCUMENTATION

Maintain:

```text
README.md

docs/
├── AUDIT.md
├── ARCHITECTURE.md
├── API.md
├── WEBHOOKS.md
├── CONFIGURATION.md
├── DEVELOPMENT.md
├── TESTING.md
├── UPGRADE.md
├── TROUBLESHOOTING.md
└── RELEASE_CHECKLIST.md
```

Also maintain:

```text
CHANGELOG.md
```

Documentation should reflect the actual implementation.

Never document functionality that does not exist.

---

# 21. OFFICIAL SOURCES

When implementation depends on framework/platform behavior, prefer official
sources.

Priority:

1. Pelican official documentation/source
2. Laravel official documentation
3. Filament official documentation
4. PHP official documentation
5. Other authoritative technical sources
6. Community sources only when necessary

Do not assume Pelican behavior from memory.

Verify platform APIs before implementing against them.

---

# 22. CODE QUALITY

Prefer:

- small focused classes
- explicit naming
- strong types
- clear interfaces
- dependency injection
- immutable DTOs where useful
- domain enums
- meaningful exceptions
- readable code

Avoid:

- giant services
- god classes
- static helper abuse
- duplicated business rules
- hidden side effects
- premature abstraction
- unnecessary dependencies

---

# 23. GIT

Use conventional commits.

Examples:

```text
feat: establish v2 architecture
feat: implement expiration domain
feat: implement renewal lifecycle
feat: implement expiration scheduler
feat: add expiration events
feat: add webhook delivery
feat: add expiration API
feat: improve admin expiration UI
test: add expiration lifecycle coverage
docs: document v2 architecture
fix: prevent renewal from unsuspending manual suspensions
```

Keep commits focused.

Do not mix unrelated changes in the same commit.

---

# 24. DEFINITION OF DONE

A feature is complete only when:

- implementation exists
- architecture is respected
- authorization is verified
- edge cases are handled
- tests exist
- tests pass
- documentation is updated
- backward compatibility is reviewed
- no known regression was introduced

The entire v2 is complete only when the release checklist passes.

---

# 25. FUTURE ECOSYSTEM COMPATIBILITY

This plugin may become the foundation for a larger Pelican plugin ecosystem.

Future plugins may include:

- Server Plans
- Billing
- Provisioning
- Analytics
- Alerts
- Backup Manager
- Maintenance Mode
- Node Health

Therefore:

- avoid tightly coupling unrelated business domains
- expose stable contracts where justified
- use events for extensibility
- avoid global mutable state
- avoid assumptions that expiration is the only lifecycle concern
- keep domain concepts reusable

Do not build speculative features merely for future plugins.

Build clean boundaries that allow future extension.
```

---

# `AGENT.md`

وهذا يكون أكثر تركيزًا على **طريقة عمل Claude نفسه**:

```md
# Agent Operating Instructions

This file defines how the coding agent must operate while developing this
project.

The project-level technical rules are defined in `CLAUDE.md`.

When instructions conflict:

1. Explicit user instructions
2. CLAUDE.md
3. AGENT.md
4. Existing project conventions
5. Agent assumptions

Never override explicit user requirements.

---

# 1. GENERAL OPERATING MODE

You are the senior engineer responsible for implementing this project.

Do not behave like a code generator.

Before changing code:

1. Understand the existing implementation.
2. Identify the relevant architecture.
3. Identify constraints.
4. Select appropriate skills.
5. Create a small implementation plan.
6. Implement incrementally.
7. Test.
8. Review.
9. Commit.

Do not make large uncontrolled rewrites.

---

# 2. REPOSITORY-FIRST RULE

Before implementing anything:

Inspect the relevant repository files.

Do not assume:

- Laravel version
- Filament APIs
- Pelican APIs
- database schema
- plugin lifecycle
- existing services
- existing commands
- existing routes
- existing tests

Verify them.

For platform-specific behavior, consult authoritative documentation or source
when necessary.

---

# 3. SKILL SELECTION SYSTEM

The project has many available agent skills.

Do NOT automatically activate every skill.

Use dynamic skill selection.

The objective is:

> minimum effective skill set for the current task

not:

> maximum number of skills

---

# 4. CORE SKILLS

These are foundational skills.

Use them when relevant:

```text
context-engineering
planning-and-task-breakdown
incremental-implementation
code-review-and-quality
debugging-and-error-recovery
git-workflow-and-versioning
source-driven-development
documentation-and-adrs
```

Do not invoke them mechanically if their use provides no value for the current
task.

---

# 5. PROJECT-SPECIFIC SKILLS

This is primarily a Pelican/Laravel/Filament project.

Prefer these when their domain is involved:

```text
pelican-panel-development
laravel-filament
laravel-project-patterns
laravel-permission-development
laravel-queues
laravel-testing
laravel-tdd
security-and-hardening
```

---

# 6. FEATURE-BASED SKILL ROUTING

## API

Use when relevant:

```text
api-and-interface-design
pelican-panel-development
security-and-hardening
laravel-testing
```

---

## Filament/Admin UI

Use when relevant:

```text
laravel-filament
frontend-ui-engineering
browser-testing-with-devtools
laravel-testing
```

Only use browser testing when actual browser verification is useful.

---

## Domain/business logic

Use:

```text
laravel-project-patterns
planning-and-task-breakdown
code-review-and-quality
```

---

## Queues/Scheduler/Jobs

Use:

```text
laravel-queues
laravel-testing
```

---

## Testing

Use as appropriate:

```text
laravel-tdd
laravel-testing
test-driven-development
```

Do not blindly apply every testing skill.

---

## Security

Use:

```text
security-and-hardening
find-bugs
```

---

## Performance

Use:

```text
performance-optimization
observability-and-instrumentation
```

Only when performance is actually relevant.

---

## Debugging

Use:

```text
debugging-and-error-recovery
find-bugs
```

---

## Database/Migrations

Use:

```text
deprecation-and-migration
laravel-project-patterns
laravel-testing
```

---

## Documentation

Use:

```text
documentation-and-adrs
source-driven-development
```

---

## CI/CD

Use:

```text
ci-cd-and-automation
```

---

## Release

Use:

```text
shipping-and-launch
git-workflow-and-versioning
```

---

## Architecture decisions

Use:

```text
idea-refine
planning-and-task-breakdown
spec-driven-development
documentation-and-adrs
```

Only when the decision is sufficiently complex to justify them.

---

## Refactoring

Use:

```text
code-simplification
code-review-and-quality
find-bugs
```

---

# 7. FIND-SKILLS IS A FALLBACK

`find-skills` is a discovery mechanism.

It is NOT a mandatory step for every feature.

Use it only when:

1. Existing skills do not sufficiently cover the problem.
2. The task requires specialized expertise not currently available.
3. You suspect a useful skill exists but cannot identify it.
4. The problem is significantly outside the project's normal technical scope.

Do NOT invoke `find-skills` merely because the feature is large.

Do NOT invoke `find-skills` when the existing skills clearly cover the task.

---

# 8. SKILL DISCOVERY DECISION TREE

For every major feature:

```text
New Feature
    ↓
Understand requirements
    ↓
Identify technical domains
    ↓
Check available skills
    ↓
Are existing skills sufficient?
    ├── YES
    │    ↓
    │ Select minimum useful skills
    │
    └── NO
         ↓
      find-skills
         ↓
      Search for missing capability
         ↓
      Evaluate discovered skills
         ↓
      Select useful skills
```

Then:

```text
Selected Skills
      ↓
Implementation
      ↓
Testing
      ↓
Review
```

---

# 9. SKILL SELECTION REPORT

At the beginning of every major feature, briefly report:

```text
## Skill Selection

Feature:
<feature>

Selected:
- <skill>
- <skill>

Discovery:
Not required
```

If discovery is needed:

```text
## Skill Selection

Feature:
<feature>

Existing coverage:
Insufficient for <reason>

Discovery:
find-skills invoked

Discovered:
- <skill>
- <skill>

Selected:
- <skill>

Rejected:
- <skill>
Reason: <short reason>
```

Keep this concise.

Do not turn skill management into a separate project.

---

# 10. PHASED IMPLEMENTATION

Implement the project in phases.

Recommended order:

```text
Phase 0
Repository Audit

Phase 1
Architecture

Phase 2
Database

Phase 3
Expiration Domain

Phase 4
Renewal

Phase 5
Scheduler

Phase 6
Notifications

Phase 7
Events

Phase 8
Webhooks

Phase 9
API

Phase 10
Admin UI

Phase 11
Client UI

Phase 12
Security & Reliability

Phase 13
Testing

Phase 14
Documentation

Phase 15
Backward Compatibility

Phase 16
Final QA / Release
```

Do not skip architectural discovery just because implementation appears easy.

---

# 11. PHASE COMPLETION RULE

A phase is complete only after:

```text
Implementation
    ↓
Tests
    ↓
Review
    ↓
Documentation
    ↓
Git commit
```

If tests fail:

Do not continue to the next phase unless the failure is explicitly classified
as pre-existing and unrelated.

---

# 12. PRE-EXISTING ERRORS

If a check fails:

Determine whether it is:

```text
PRE-EXISTING
```

or:

```text
INTRODUCED BY CURRENT WORK
```

Never hide failures.

Never claim that tests pass when they do not.

Document pre-existing failures clearly.

---

# 13. SMALL COMMITS

Prefer focused commits.

Example:

```text
feat: establish expiration domain
```

Then:

```text
test: cover expiration domain rules
```

Then:

```text
feat: implement renewal lifecycle
```

Avoid giant commits containing unrelated features.

---

# 14. IMPLEMENTATION RULES

Before creating an abstraction, ask:

1. Does it solve a real problem?
2. Does it improve separation of concerns?
3. Does it improve testability?
4. Does it reduce duplication?
5. Is it consistent with Laravel/Pelican conventions?

If the answer is no, do not add the abstraction.

---

# 15. PLATFORM VERIFICATION

When implementing Pelican-specific behavior:

Do not rely on assumptions.

Verify:

- plugin lifecycle
- service providers
- routes
- permissions
- Filament extension points
- server suspension APIs
- Wings synchronization
- scheduler behavior
- API middleware
- version compatibility

Prefer official Pelican documentation/source.

---

# 16. UI RULES

Do not implement business logic in UI components.

Filament should call application services.

Views should display state.

Frontend countdowns must not determine server state.

Never rely on frontend validation as a security boundary.

---

# 17. API RULES

Every mutating endpoint must consider:

```text
Authentication
Authorization
Validation
State transition
Concurrency
Error handling
Audit/event behavior
```

Do not expose internal models unnecessarily.

Prefer explicit request DTOs/resources where useful.

---

# 18. DATABASE RULES

Before modifying schema:

1. Inspect existing migrations.
2. Understand existing production data.
3. Determine upgrade implications.
4. Create the smallest safe migration.
5. Test fresh installation.
6. Test upgrade.

Never casually rename/delete existing columns.

---

# 19. TESTING STRATEGY

Prefer testing business rules at the lowest useful level.

Example:

Expiration calculation:

```text
Unit test
```

API authorization:

```text
Feature test
```

Pelican/Wings integration:

```text
Integration test
```

Do not test everything exclusively through the UI.

---

# 20. DEBUGGING

When debugging:

1. Reproduce the issue.
2. Identify the failure boundary.
3. Inspect logs/state.
4. Form a hypothesis.
5. Verify the hypothesis.
6. Apply the smallest correct fix.
7. Add regression coverage.
8. Re-run affected tests.

Do not patch symptoms without understanding the cause.

---

# 21. SECURITY CHECKPOINT

Before completing any feature that changes:

- server state
- permissions
- API behavior
- expiration
- suspension
- renewal
- webhooks

perform a security review.

Ask:

```text
Can an unauthorized user perform this operation?

Can a client modify data they should only read?

Can concurrent requests bypass state checks?

Can secrets leak?

Can malformed input corrupt state?
```

---

# 22. DOCUMENTATION CHECKPOINT

If implementation changes behavior:

Update the appropriate documentation before marking the feature complete.

Never allow:

```text
implementation != documentation
```

---

# 23. BACKWARD COMPATIBILITY CHECKPOINT

For every change affecting existing behavior ask:

```text
Does this break v1 behavior?

Does this change existing data?

Does this change existing commands?

Does this change configuration?

Does this change existing URLs/routes?

Does this change existing permissions?

Does this change existing UI behavior?
```

If yes:

Document the migration path.

---

# 24. NO SPECULATIVE FEATURES

Do not implement future ecosystem functionality unless explicitly requested.

Examples:

Do not build Billing just because Billing may exist later.

Do not build Plans just because Plans may exist later.

Instead:

Build clean interfaces/events/contracts that allow future plugins to integrate.

---

# 25. FINAL REVIEW

Before declaring a feature complete:

```text
[ ] Requirements satisfied
[ ] Existing architecture respected
[ ] Correct skills selected
[ ] Security reviewed
[ ] Edge cases handled
[ ] Tests added
[ ] Tests passing
[ ] Documentation updated
[ ] No unnecessary dependencies
[ ] No duplicated business logic
[ ] No Pelican core modifications
[ ] Git commit created
```

---

# 26. FINAL RELEASE REVIEW

Before v2 release:

```text
[ ] v1 functionality preserved
[ ] fresh installation tested
[ ] v1.1.0 upgrade tested
[ ] database migrations verified
[ ] expiration lifecycle verified
[ ] renewal verified
[ ] suspension safety verified
[ ] scheduler verified
[ ] notifications verified
[ ] events verified
[ ] webhooks verified
[ ] API verified
[ ] Admin UI verified
[ ] Client UI verified
[ ] security review passed
[ ] tests passed
[ ] documentation complete
[ ] CHANGELOG updated
[ ] plugin.json updated
[ ] update.json updated
[ ] release checklist complete
```

Only then recommend a v2 release.
```