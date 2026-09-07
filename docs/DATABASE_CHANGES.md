# Database Changes for Server Expiry v2.0.0

## Overview

This document describes the database schema changes introduced in Server Expiry v2.0.0. The changes are designed to be backward compatible with v1.1.0 while providing the foundation for new v2 features.

## Migration Strategy

All migrations are designed to be:
- **Safe for fresh installations**: Tables are created if they don't exist
- **Safe for upgrades**: Uses `ifNotExists` checks and preserves existing data
- **Backward compatible**: No existing columns are modified or removed
- **Reversible**: All migrations can be rolled back safely

## New Tables

### 1. expiration_lifecycle_events
Tracks all expiration-related events for audit trails and analytics.

**Columns:**
- `id` (primary key, auto-increment)
- `server_id` (string, indexed) - References the server ID
- `event_type` (string, indexed) - Type of expiration event (set, cleared, expired, suspended, etc.)
- `event_data` (JSON, nullable) - Additional event-specific data
- `occurred_at` (timestamp) - When the event occurred
- `processed_at` (timestamp, nullable) - When the event was processed by listeners

**Indexes:**
- `server_id` + `occurred_at` (for querying events by server and time)
- `event_type` + `occurred_at` (for querying events by type and time)

### 2. webhook_endpoints
Stores webhook endpoint configurations for expiration events.

**Columns:**
- `id` (primary key, auto-increment)
- `name` (string, unique) - Unique identifier for the endpoint
- `url` (string) - Webhook URL
- `secret_key` (string, nullable) - HMAC-SHA256 secret for signature verification
- `events` (JSON, default: []) - List of event types to listen to
- `active` (boolean, default: true) - Whether the endpoint is active
- `failure_count` (integer, default: 0) - Consecutive failure count
- `last_failed_at` (timestamp, nullable) - Last failure timestamp
- `last_success_at` (timestamp, nullable) - Last success timestamp
- `created_at`, `updated_at` (timestamps)

**Indexes:**
- `active` (for querying active endpoints)
- `url` (for lookup by URL)

### 3. webhook_deliveries
Tracks webhook delivery attempts for retry logic and failure reporting.

**Columns:**
- `id` (primary key, auto-increment)
- `webhook_endpoint_id` (unsigned big integer, foreign key) - References webhook_endpoints
- `event_type` (string) - Type of event being delivered
- `server_id` (string) - ID of the server related to the event
- `payload` (JSON) - The webhook payload
- `attempt` (integer, default: 1) - Current attempt number
- `max_attempts` (integer, default: 3) - Maximum retry attempts
- `status` (enum: pending, success, failed, default: pending) - Delivery status
- `http_status_code` (integer, nullable) - HTTP status code from webhook response
- `error_message` (text, nullable) - Error message if delivery failed
- `queued_at` (timestamp) - When the delivery was queued
- `processed_at` (timestamp, nullable) - When the delivery was processed
- `next_attempt_at` (timestamp, nullable) - When the next retry attempt will occur

**Foreign Key:**
- `webhook_endpoint_id` references `webhook_endpoints(id)` with CASCADE delete

**Indexes:**
- `webhook_endpoint_id` + `status` (for processing pending deliveries)
- `status` + `next_attempt_at` (for finding retries due)
- `server_id` + `event_type` (for querying deliveries by server/event)
- `queued_at` (for FIFO processing)

### 4. notification_idempotency
Prevents duplicate notifications from being sent for the same server/event/threshold combination.

**Columns:**
- `id` (primary key, auto-increment)
- `server_id` (string) - ID of the server
- `notification_type` (string) - Type of notification (expiry_warning, server_expired, etc.)
- `identifier` (string, nullable) - Specific identifier (e.g., warning threshold days, or null for one-time events)
- `sent_at` (timestamp) - When the notification was sent

**Unique Constraint:**
- `server_id` + `notification_type` + `identifier` (prevents duplicate notifications)

**Indexes:**
- `server_id` + `notification_type` (for cleanup queries)
- `sent_at` (for finding old records to clean up)

## Preserved v1.1.0 Schema

The following existing columns from v1.1.0 are **preserved unchanged** to ensure backward compatibility:

### servers table
- `expires_at` (timestamp, nullable) - Expiration timestamp
- `expiry_warning_day` (unsigned tiny integer, nullable) - Last warning threshold notified

These columns continue to work exactly as they did in v1.1.0. The v2 infrastructure reads from and writes to these columns through the EloquentExpirationRepository implementation.

## Migration Numbers

To maintain proper ordering with existing v1.1.0 migrations:
- `001_add_expires_at_to_servers_table.php` (existing v1.1.0)
- `002_add_expiry_warning_day_to_servers_table.php` (existing v1.1.0)
- `003_create_expiration_lifecycle_events_table.php` (new v2.0.0)
- `004_create_webhook_endpoints_table.php` (new v2.0.0)
- `005_create_webhook_deliveries_table.php` (new v2.0.0)
- `006_create_notification_idempotency_table.php` (new v2.0.0)

## Installation and Upgrade Behavior

### Fresh Installation
1. Runs `001` - Adds expires_at column to servers table
2. Runs `002` - Adds expiry_warning_day column to servers table
3. Runs `003` - Creates expiration_lifecycle_events table
4. Runs `004` - Creates webhook_endpoints table
5. Runs `005` - Creates webhook_deliveries table
6. Runs `006` - Creates notification_idempotency table

### Upgrade from v1.1.0
1. Skips `001` and `002` (tables/columns already exist)
2. Runs `003` - Creates expiration_lifecycle_events table (if not exists)
3. Runs `004` - Creates webhook_endpoints table (if not exists)
4. Runs `005` - Creates webhook_deliveries table (if not exists)
5. Runs `006` - Creates notification_idempotency table (if not exists)

All migrations use `Schema::hasColumn()` and `Schema::hasTable()` checks where appropriate to ensure they are safe to run multiple times.

## Data Preservation

- **No data loss**: Existing `expires_at` and `expiry_warning_day` values are preserved
- **No schema modifications**: Existing columns are not altered in type or constraints
- **Graceful degradation**: If new tables don't exist yet, the system falls back to v1.1.0 behavior
- **Forward compatibility**: New v2 features work when tables exist, gracefully degrade when they don't

## Infrastructure Implementation

The `EloquentExpirationRepository` class in `src/Infrastructure/Persistence/` implements the `ExpirationRepository` contract and handles all interactions with the preserved v1.1.0 schema columns (`expires_at` and `expiry_warning_day`) while preparing for future use of the new v2 tables.

## Testing

All migrations have been validated for:
- Syntax correctness (`php -l`)
- Fresh installation compatibility
- Upgrade compatibility from v1.1.0
- Rollback safety