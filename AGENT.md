# Agent Operating Instructions

> Extracted from CLAUDE.md (R14-B). This file defines how the coding agent
> operates. CLAUDE.md remains the authority for project architecture, domain
> rules, technical constraints, compatibility requirements and product behavior.
> When instructions conflict: explicit user instructions, then CLAUDE.md, then
> AGENT.md, then project conventions, then agent assumptions.


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

> Status: this checklist was satisfied and verified for the v2.0.0 release
> (R12 runtime verification + R13 release flow). It is retained as the
> procedure for future releases.

Before a release:

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