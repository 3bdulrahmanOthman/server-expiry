# Server Expiry & Auto-Suspend - v2 Architecture

## Overview

This document describes the architecture of the released **v2.0.0** of the Server Expiry & Auto-Suspend plugin, following clean architecture principles with clear separation of concerns.

## Architectural Layers

### 1. Domain Layer (`src/Domain/`)
**Purpose**: Contains pure business logic with zero framework dependencies.
**Rules**:
- No dependencies on Laravel, Filament, Pelican, or any external libraries
- Contains only business rules, entities, value objects, and domain events
- Independent of any infrastructure or framework concerns

#### Sub-layers:
- **Entities**: Core business objects (ServerExpiration)
- **ValueObjects**: Immutable objects with business meaning (ExpirationDate, GracePeriod, WarningThreshold)
- **Enums**: Domain-specific enumerations (ExpirationStatus, SuspensionType)
- **Policies**: Business rules and validation logic
- **Exceptions**: Domain-specific exception types
- **Events**: Domain events for decoupling side effects

### 2. Application Layer (`src/Application/`)
**Purpose**: Contains application services and use cases that orchestrate the domain.
**Rules**:
- Depends only on the Domain layer
- Contains use cases, application services, and DTOs
- Defines contracts (interfaces) for infrastructure implementations
- Does not contain framework-specific code

#### Sub-layers:
- **Actions**: Specific use case implementations
- **Services**: Application services coordinating multiple domain operations
- **DTOs**: Data transfer objects for crossing layer boundaries
- **Contracts**: Interface definitions that infrastructure must implement

### 3. Infrastructure Layer (`src/Infrastructure/`)
**Purpose**: Implements contracts defined in the Application layer using specific frameworks/tools.
**Rules**:
- Depends on Application and Domain layers
- Contains framework-specific implementations (Eloquent, Laravel services, etc.)
- Handles external service integrations (notifications, webhooks, etc.)
- Implements repository patterns and service adapters

#### Sub-layers:
- **Persistence**: Data access implementations (Eloquent repositories)
- **Notifications**: Notification delivery mechanisms (mail, database)
- **Webhooks**: Webhook delivery infrastructure
- **Scheduler**: Task scheduling implementations

### 4. Presentation Layer
Contains framework-specific presentation code that adapts to the application layer.

#### Sub-layers:
- **HTTP** (`src/Http/`): API controllers, form requests, API resources
- **Filament** (`src/Filament/`): Admin UI components, pages, resources, widgets
- **Console** (`src/Console/`): Artisan commands
- **Providers** (`src/Providers/`): Laravel service providers

## Data Flow

```
Presentation Layer (UI/API/CLI)
          ↓
Application Layer (Use Cases/Services)
          ↓
Domain Layer (Business Logic)
          ↑
Infrastructure Layer (Framework Integrations)
```

## Key Principles

1. **Dependency Rule**: Dependencies point inward. Outer layers depend on inner layers, never vice versa.
2. **Separation of Concerns**: Each layer has a single responsibility.
3. **Testability**: Domain layer can be tested in isolation without frameworks.
4. **Framework Independence**: Business logic is decoupled from Laravel/Filament/Pelican.
5. **Extensibility**: New features can be added by extending layers without modifying existing code.
6. **Backward Compatibility**: Existing v1.2.0 functionality is preserved through careful migration.

## Layer Responsibilities

### Domain Layer
- Defines what the system does (business rules)
- Contains state that the system manages
- Independent of any technical concerns

### Application Layer
- Defines what the system allows users to do (use cases)
- Orchestrates flow data to and from the domain
- Contains business logic but delegates technical details to infrastructure

### Infrastructure Layer
- Implements ports defined by the application layer
- Handles technical concerns like databases, external services, frameworks
- Details like SQL, HTTP, queue systems live here

### Presentation Layer
- Handles HTTP requests, console commands, UI interactions
- Translates external formats to internal use cases/data formats
- Contains zero business logic - only delegates to application layer

## Boundary Between Layers

- **Domain ↔ Application**: Through interfaces (contracts) and DTOs
- **Application ↔ Infrastructure**: Through interface implementations
- **Infrastructure ↔ Presentation**: Through framework-specific adapters
- **Presentation ↔ User**: Through HTTP, CLI, or UI frameworks

## Migration Strategy from v1.2.0 (implemented and verified in v2.0.0)

1. **Phase 0**: Repository Audit (Complete)
2. **Phase 1**: Architecture Setup - Create layer structure and domain foundation
3. **Phase 2**: Data Layer - Preserve existing columns + add infrastructure tables
4. **Phase 3**: Domain Model - Implement entities, value objects, enums, events
5. **Phase 4**: Application Services - Implement use cases and service contracts
6. **Phase 5**: Infrastructure - Implement repositories and service adapters
7. **Phase 6**: Consolidated Scheduling - Unified expiration processing command
8. **Phase 7**: Event-Driven Side Effects - Implement domain event listeners
9. **Phase 8**: API Layer - Add RESTful API endpoints
10. **Phase 9**: Webhook Infrastructure - Add webhook delivery system
11. **Phase 10**: Admin UI Updates - Migrate Filament components to use application layer
12. **Phase 11**: Client UI - Ensure client layer uses application services
13. **Phase 12**: Testing - Implement comprehensive test suite
14. **Phase 13**: Documentation - Update all documentation
15. **Phase 14**: Backward Compatibility - Verify upgrade paths
16. **Phase 15**: Final QA & Release - Prepare for release

## Code Examples

### Value Object (Domain Layer)
```php
final class ExpirationDate
{
    public static function permanent(): self
    {
        return new self(null);
    }
    
    public function isPermanent(): bool
    {
        return $this->dateTime === null;
    }
    // ... more business logic
}
```

### Contract (Application Layer)
```php
interface ExpirationRepository
{
    public function getExpiration(string $serverId): ExpirationDate;
    public function setExpiration(string $serverId, ExpirationDate $expirationDate): void;
    // ... more contract methods
}
```

### Infrastructure Implementation (Infrastructure Layer)
```php
class EloquentExpirationRepository implements ExpirationRepository
{
    public function getExpiration(string $serverId): ExpirationDate
    {
        // Eloquent implementation details here
    }
    // ... more implementation details
}
```

### Use Case (Application Layer)
```php
class SetExpirationAction
{
    public function __construct(
        private ExpirationRepository $repository,
        private ExpirationService $expirationService
    ) {}
    
    public function execute(string $serverId, ExpirationDate $date): void
    {
        // Orchestrates domain operations
        $this->repository->setExpiration($serverId, $date);
        // ... more application logic
    }
}
```

### Controller (Presentation Layer)
```php
class ExpirationController
{
    public function __construct(
        private SetExpirationAction $setExpirationAction
    ) {}
    
    public function update(Request $request, string $serverId): Response
    {
        // Only translates HTTP request to use case
        $date = ExpirationDate::fromString($request->input('expires_at'));
        $this->setExpirationAction->execute($serverId, $date);
        return response()->json(['status' => 'success']);
    }
}
```

## Benefits of This Architecture

1. **Maintainability**: Clear separation makes code easier to understand and modify
2. **Testability**: Domain layer can be tested without Laravel/Filament/Pelican
3. **Flexibility**: Easy to swap implementations (e.g., different notification systems)
4. **Scalability**: Layers can be developed and scaled independently
5. **Framework Migration**: Potential to change underlying frameworks with less impact
6. **Parallel Development**: Teams can work on different layers with fewer conflicts
7. **Domain Focus**: Business logic is centralized and easy to reason about
8. **Reduced Coupling**: Changes in one layer have minimal impact on others

## Compliance with CLAUDE.md

This architecture directly addresses the requirements in CLAUDE.md:
- ✅ Clean architecture with strong domain boundaries
- ✅ Reliable expiration lifecycle through domain services
- ✅ Safe renewal behavior via application services
- ✅ API support through dedicated HTTP layer
- ✅ Event-driven architecture through domain events
- ✅ Webhook support through infrastructure layer
- ✅ Modern Admin UI through presentation layer
- ✅ Modern Client UI through presentation layer
- ✅ Strong authorization through application layer validation
- ✅ Idempotent background processing through infrastructure
- ✅ Comprehensive testing strategy enabled by separation
- ✅ Backward compatibility through careful migration strategy
- ✅ High-quality documentation through dedicated docs
- ✅ Maintainability for future plugin ecosystem through extensibility points