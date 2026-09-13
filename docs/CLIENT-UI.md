# Client UI - Server Expiry & Auto-Suspend Plugin

## Overview

This document describes the client-facing expiration UI for the Server Expiry & Auto-Suspend plugin. The client UI provides server owners with visibility into their server's expiration status and allows them to perform authorized actions such as renewal.

## Design Principles

1. **Information Transparency**: Clients can see all relevant expiration information about their servers
2. **Authorization Enforcement**: Administrative actions require proper authorization
3. **Safety First**: Renewal actions respect the manual vs expiration suspension distinction
4. **Clean Presentation**: Information is presented in a clear, visually intuitive format
5. **Backward Compatibility**: Existing v1.2.0 client functionality is preserved

## Components

### Expiry Settings Page (`resources/views/filament/server/pages/expiry-settings.blade.php`)

The client expiration page is accessed via the server panel sidebar and shows:

#### Status Section
- Visual status indicator with color-coded icon:
  - Gray: Permanent (no expiration)
  - Green: Active (valid expiration date)
  - Yellow: Expiring soon (within warning period)
  - Red: Expired (past expiration date)
- Text status description

#### Expiration Details Section
- Expiration date and time (or "Permanent" if never set)
- Time remaining until expiration (human-readable format)
- Expired status badge (Yes/No with color coding)
- Grace period status badge (Yes/No with color coding)

#### Suspension Information Section (Conditional)
- Visible only when server is suspended
- Shows suspension status (Suspended/Not Suspended)
- Shows suspension reason when applicable (expiration, manual, other)

## Actions

Clients can perform the following actions if authorized:

### Renew Server
- Sets a new expiration date 30 days from now (or extends current expiration by 30 days)
- Only available to authorized users
- Shows confirmation dialog before execution
- Respects suspension safety:
  - Does NOT auto-unsuspend manually suspended servers
  - DOES auto-unsuspend expiration-suspended servers upon renewal
- Requires Gate::authorize('renew', $server) check

### Set Expiration
- Allows setting a custom expiration date
- Only available to authorized users
- Uses DateTimePicker with future-only validation (after_or_equal:today)
- Shows confirmation dialog before execution
- Respects suspension safety (same as renewal)
- Requires Gate::authorize('renew', $server) check

## Authorization

All client actions are protected by Laravel Gate authorization checks:

```php
if (! Gate::authorize('renew', $record)) {
    Notification::make()
        ->danger()
        ->body('You are not authorized to perform this action.')
        ->send();
    return;
}
```

The 'renew' gate should be defined in the application's AuthServiceProvider to determine if a user can perform expiration-related actions on a given server.

## Safety Features

### Suspension Safety Invariant
The implementation maintains the critical distinction between manual and expiration suspensions:
- Manual suspensions are preserved during renewal (server remains suspended)
- Expiration suspensions are cleared during renewal (server can be auto-unsuspended)

### Validation
- All expiration dates are validated to be in the future or today (after_or_equal:today)
- Form inputs are protected against invalid data
- Authorization checks prevent unauthorized modifications

## User Experience

### Visual Indicators
- Color-coded status icons provide immediate visual feedback
- Badges use semantic colors (green=good, yellow=warning, red=danger)
- Tooltips provide additional context for actions
- Confirmation dialogs prevent accidental actions

### Information Hierarchy
- Most critical information (status) is presented first
- Detailed expiration information follows
- Conditional suspension information appears only when relevant
- Actions are available in the page header for easy access

## Backward Compatibility

### Preserved v1.2.0 Functionality
- The expiration information display maintains all data previously shown
- URL route remains unchanged (`/server/{id}/expiry-settings`)
- Navigation label and icon are preserved
- Basic expiration status and date information is still available

### Enhanced Features
- Visual status indicators replace text-only status
- Time remaining information added
- Expiration and grace period status badges added
- Renewal capability added (when authorized)
- Custom expiration setting capability added (when authorized)
- Conditional suspension information section added

## Implementation Notes

### Separation of Concerns
- UI layer presents data and captures user intentions
- Authorization is handled via Laravel Gates
- Business logic is delegated to application services:
  - `ExpirationService::renew()`
  - `ExpirationService::setExpiration()`
  - `ExpirationService::isInGracePeriod()`
  - `ExpirationService::isNotifyOwnerOnSuspendEnabled()`

### Performance Considerations
- Since this page displays information for a single server only, N+1 queries are not a concern
- All data retrieval is optimized for single-server access
- No unnecessary relationships are loaded

### Extensibility
- The page follows Filament conventions for easy customization
- New sections can be added to the schema array
- Additional actions can be added to the header actions
- Translation keys are centrally managed in lang/en/strings.php

## Testing Considerations

### Unit Tests
- Test authorization gate integration
- Test visibility logic for suspension section
- Test action button conditions
- Test form validation rules

### Feature Tests
- Test page load displays correct expiration information
- Test renewal action with proper authorization
- Test renewal action prevents unauthorized access
- Test suspension section visibility based on server state
- Test visual status indicators match expiration state

## Security

### Authorization Enforcement
- All mutating actions require explicit authorization
- No sensitive data is exposed without proper authorization
- Authorization checks occur before any business logic execution

### Input Validation
- All form inputs are validated (future-only dates)
- CSRF protection is inherited from Filament/Laravel
- Input sanitization prevents XSS in displayed content

### Data Protection
- No expiration secrets or sensitive data are displayed
- Server IDs are not exposed in URLs beyond standard routing
- All actions use proper model binding to prevent IDOR

## Localization

All user-facing text is translatable via Laravel's localization system:
- Translation keys are defined in `lang/en/strings.php`
- Keys follow the pattern: `server-expiry::strings.key`
- Example usage: `trans('server-expiry::strings.action_renew')`

## Diagram

```
Client Expiration Page Layout
+--------------------------------------------------+
| [Status Icon]     Status: [Text Description]     |
+--------------------------------------------------+
| Expiration Date: [Date/Time or Permanent]        |
| Time Remaining: [Human readable time]            |
| [Expired:  Yes/No badge]  [Grace Period: Yes/No] |
+--------------------------------------------------+
| [IF SUSPENDED]                                   |
| Suspension Status: [Suspended/Not Suspended]     |
| [IF Reason Available] Suspension Reason: [Reason]|
+--------------------------------------------------+
| [Header Actions: Renew | Set Expiration]         |
+--------------------------------------------------+
```

## Dependencies

- Laravel Authorization (Gates)
- Filament Forms (Placeholder, DateTimePicker, Section, Split)
- Carbon (date/time manipulation)
- Application Services (ExpirationService)
- Domain Support (Expiry helper class)
- Translation System (Lang files)