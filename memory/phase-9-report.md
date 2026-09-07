# Phase 9: Webhooks Implementation Report

## Overview
Implemented a robust webhook system for the Server Expiry & Auto-Suspend plugin that sends HTTP POST requests to configured endpoints when expiration lifecycle events occur.

## Components Created

### 1. Core Infrastructure (`src/Infrastructure/Webhooks/`)

**WebhookSignature.php**
- Handles HMAC-SHA256 signature generation and validation
- Provides timestamp generation for replay protection
- Uses `hash_equals()` for secure comparison to prevent timing attacks

**WebhookDeliveryService.php**
- Manages webhook delivery with retry logic and exponential backoff
- Implements atomic claiming pattern similar to notification idempotency
- Features:
  - Configurable max attempts (default 3)
  - Exponential backoff (1s, 2s, 4s, ...)
  - HTTP timeout (10 seconds)
  - Proper error handling and logging
  - Success/failure tracking on webhook endpoints
  - Method to process pending retries (for scheduler execution)

**Eloquent Models**
- **WebhookEndpoint**: Represents a webhook configuration with fields for name, URL, secret key, subscribed events, active status, and failure tracking
- **WebhookDelivery**: Tracks individual delivery attempts with payload, attempt count, status, HTTP response, and timing

**WebhookService.php**
- Main dispatch service that finds active endpoints subscribed to events
- Queues deliveries through WebhookDeliveryService
- Isolated from core expiration logic - failures don't affect expiration state

### 2. Event Listeners (`src/Infrastructure/Webhooks/Listeners/`)
One listener per expiration event type:
- SendExpirationCreatedWebhook.php
- SendExpirationSetWebhook.php
- SendExpirationClearedWebhook.php
- SendExpirationWarningWebhook.php
- SendServerSuspendedByExpirationWebhook.php
- SendServerExpiredWebhook.php
- SendServerRenewedWebhook.php

Each listener:
- Extracts relevant data from the event
- Builds a standardized payload with server ID, timestamp, and event-specific data
- Dispatches via WebhookService
- Uses appropriate webhook event type (e.g., 'server.expiry.updated')

### 3. Service Provider (`src/Providers/WebhookServiceProvider.php`)
- Registers all webhook event listeners using Laravel's Event::listen()
- Listens to all expiration lifecycle events including the newly added ExpirationCreated

### 4. Plugin Registration (`plugin.json`)
- Added WebhookServiceProvider to the providers array
- Updated version to 2.0.0 to reflect the webhook functionality

### 5. Domain Enhancements (`src/Domain/Events/ExpirationCreated.php`)
- Added new event to track when a server's expiration is initially created (vs. updated)
- Allows webhooks to distinguish between creation and update events

### 6. Application Service Updates (`src/Application/Services/ExpirationService.php`)
- Modified setExpiration() to dispatch ExpirationCreated when transitioning from permanent to expiration state
- Maintains backward compatibility - no changes to existing method signatures

## Webhook Payload Format
All webhooks send a JSON payload with:
```json
{
  "server_id": "123",
  "timestamp": "2026-09-08T10:30:00+00:00",
  // Event-specific fields:
  "expiration_date": "2026-12-31T23:59:59Z", // For create/set/renew events
  "warning_threshold_days": 7               // For warning events
}
```

## Webhook Event Types
- `server.expiry.created` - When expiration is first set on a permanent server
- `server.expiry.updated` - When expiration date is changed
- `server.expiry.cleared` - When expiration is removed (made permanent)
- `server.expiry.warning` - When a warning threshold is reached
- `server.expiry.expired` - When server reaches expiration date (defined but not currently used)
- `server.expiry.suspended` - When server is suspended due to expiration
- `server.expiry.renewed` - When server's expiration is renewed

## Security Features
- **HMAC-SHA256 Signatures**: Each request includes X-Signature header with HMAC of payload+timestamp
- **Timestamp Header**: X-Timestamp header prevents replay attacks (should be validated by receiver within a reasonable window)
- **Secret Management**: Secret keys stored encrypted in database (Laravel's default encryption)
- **Secret Masking**: Model accessors mask secret keys in UI/display contexts
- **Timeout Protection**: 10-second HTTP timeout prevents hanging requests
- **User-Agent**: Identifiable User-Agent header for easy filtering/logging
- **Isolation**: Webhook failures never roll back or affect expiration state

## Reliability Features
- **Idempotency-Ready**: Deliveries are tracked with attempt counts - safe to retry
- **Exponential Backoff**: Reduces load on failing endpoints
- **Retry Logic**: Failed deliveries are retried up to 3 times with increasing delays
- **Failure Tracking**: Endpoints track failure counts and timestamps
- **Atomic Operations**: Database transactions prevent race conditions in delivery claiming
- **Graceful Degradation**: Individual endpoint failures don't affect others or core expiration processing

## Configuration
Webhooks are managed through the database (webhook_endpoints table):
- `name`: Human-readable identifier
- `url`: Target HTTP endpoint
- `secret_key`: Optional HMAC-SHA256 secret (nullable for no signatures)
- `events`: JSON array of event types to subscribe to
- `active`: Boolean to enable/disable endpoint
- `failure_count`: Auto-incremented counter for monitoring
- `last_failed_at`/`last_success_at`: Timestamps for monitoring

## Backward Compatibility
- No changes to existing database schema beyond the Phase 2 webhook tables
- No changes to existing API, CLI commands, or UI
- All existing expiration behavior preserved
- Webhook system is additive - doesn't interfere with existing functionality

## Testing Verification (Static/Source-Level)
- All PHP files pass lint checks (`php -l`)
- Composer validate passes
- Composer autoload-dump passes
- No syntax errors in new or modified files

## Next Steps
This completes Phase 9: Webhooks implementation. The system is ready for:
- Phase 10: Admin UI (webhook management interface)
- Phase 11: Client UI
- Phase 12: Security & Reliability review
- Phase 13: Testing
- Phase 14: Documentation
- Phase 15: Backward Compatibility verification
- Phase 16: Final QA / Release