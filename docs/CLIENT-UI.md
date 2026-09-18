# Client UI — Owner Expiration Page (v2.1)

## Overview

The Server Expiry & Auto-Suspend plugin gives every server owner a dedicated
**Expiration** page in the server panel sidebar. Since v2.1 the page is a
read-only status dashboard: it presents the server's real expiration state —
computed from the same domain service and server record the admin tab uses —
and **provides no self-service actions**.

> **v2.1 does not provide self-service renewal, self-service expiration
> changes, payments, invoices, renewal packages, pricing, SLA/hardware
> information, emergency services, or a lifecycle event log.** Expiration
> changes are provider/admin-controlled (Edit Server → Expiration in the
> admin panel, or the Application API). The only owner-facing call to action
> is the optional Contact Support link described below.

## Route and access

- **Route:** `/server/{server}/expiry-settings` (unchanged since v1.2.0),
  registered on the server panel via plugin page discovery.
- **Navigation:** "Expiration" item in the server sidebar, with a calendar
  icon.
- **Access:** the page is a Pelican `ServerFormPage` for the tenant server,
  so standard server-panel tenancy and authorization apply. The page exposes
  **no header actions**; clients cannot modify any expiration state from it.

## Page structure

The page renders, top to bottom:

1. **Header** — page title and a short description of what the page shows.
2. **Status hero** — a state-specific banner (icon, colored status pill, and a
   one-sentence status explanation; see the state matrix below).
3. **Countdown** (when applicable) — a live days / hours / minutes / seconds
   display (see "Countdown behavior").
4. **Lifecycle timeline** (when applicable) — a horizontal track marking the
   configured warning thresholds, the expiration moment, and (when a grace
   period is configured) the real auto-suspension moment, with a "now"
   indicator positioned between them (see "Timeline behavior").
5. **Status summary cards** — Status, Expiration date & time, Time remaining,
   and the warning schedule ("the owner is notified 7, 3, 1 day(s) before
   expiration"), each derived from real configuration.
6. **Suspension banner** (suspended servers only) — a danger banner stating
   the suspension reason in plain language.
7. **Support section** (only when `support_url` is configured) — a "Need more
   time?" block with a **Contact Support** button.

## State matrix

The page presents exactly six states. All of them are driven by the backend
(`ExpirationService` + the server record); nothing is inferred in the view.

| State | Hero presentation | Expiry date shown | Countdown | Timeline |
| --- | --- | --- | --- | --- |
| **Permanent** | `∞ Permanent` badge, green treatment with a subtle green ambient glow (the glow is exclusive to this state), active indicator | No — "No expiration date set" copy; **no** fabricated date/progress | None | None |
| **Active** | Neutral/green pill, "Active until &lt;date&gt;" | Yes (exact date & time) | Expiry countdown | None |
| **Expiring Soon** | Amber warning pill, "Expiring soon on &lt;date&gt;" | Yes | Expiry countdown | Yes |
| **Expired** | Red pill, "This server expired at &lt;date&gt;" | Yes | None — elapsed time instead ("Expired 3d 0h") | Yes (now indicator clamped at the end) |
| **Grace Period** | Amber hourglass pill, "In grace period until &lt;auto-suspension moment&gt;" | Yes (in the cards) | **Auto-suspension countdown** — targets the real moment (`expires_at` + configured grace hours), not the expiry itself | Yes |
| **Suspended** | Red pill, "This server is currently suspended." | Yes (in the cards) | None | None |

The warning window (and therefore the Expiring Soon state) begins at the
**largest** configured warning threshold before `expires_at` (default 7 days).
Warning thresholds, the grace window and the suspension gate are the admin's
plugin settings; the page never invents dates, milestones or progress.

### Suspension reasons

When a server is suspended, the banner explains why:

- `suspension_reason = expiration` → "This server was suspended automatically
  because its expiration date passed."
- `suspension_reason = manual` → "This server was suspended manually by your
  provider."
- any other value → a neutral "This server is currently suspended."

> **Note on stock panels:** Pelican itself blocks owner access to suspended
> servers (the "server conflict" gate), so on an unmodified panel owners
> typically cannot reach this page while the server is suspended. The
> Suspended presentation above is implemented and verified; whether owners can
> see it depends on the panel's conflict-blocking behavior.

## Countdown behavior

- **Active / Expiring Soon:** counts down to the expiration moment.
- **Grace Period:** counts down to the real automatic-suspension moment
  (`expires_at` + configured grace hours). The label reads "Automatic
  suspension in". If auto-suspend is disabled, no grace countdown is shown —
  nothing will suspend the server automatically.
- The ticker is **display-only** (Alpine.js): it starts from the
  server-rendered values and never crosses zero. A page reload re-syncs it
  with the authoritative backend state.
- **Permanent** servers have no countdown at all — by design, not by omission.

## Timeline behavior

For Expiring Soon, Grace Period and Expired servers the page renders a
lifecycle timeline built only from real configuration:

- one marker per configured warning threshold (e.g. 7 / 3 / 1 days), plus the
  expiration moment, plus the auto-suspension moment when a grace period is
  configured;
- a "now" indicator at the true relative position, clamped to the track ends
  once elapsed;
- if no warning thresholds are configured, the timeline is hidden entirely.

Permanent and Active servers show no timeline.

## Support-only CTA

The `support_url` plugin setting (Admin Area → Plugins → Server Expiry →
Settings → "Owner Expiration Page") controls the only call to action:

- **`support_url` set** → a "Need more time?" section with a **Contact
  Support** button. The link opens in a new tab
  (`target="_blank" rel="noopener noreferrer"`) and points exactly at the
  configured URL.
- **`support_url` empty (default)** → the whole support section is hidden.

There is deliberately no "Renew" or "Extend" action anywhere on the page:
renewal is a provider conversation, not a client action.

## Visual behavior

- **Dark and light mode:** the page follows the panel theme (Filament's
  `html.dark` convention) with dedicated token sets for both.
- **Responsive:** desktop layout (hero, countdown, cards) stacks cleanly on
  mobile widths; no horizontal overflow at 390 px.
- **Motion:** the Permanent glow and countdown use subtle animation and are
  disabled for users with `prefers-reduced-motion`.
- The Permanent green glow is applied **only** to the Permanent hero — never
  to any other state.

## Implementation notes

- The page is presentation-only: `ExpirySettingsPage::getViewData()` hands the
  Blade view a precomputed view model (`SquadronStrike\ServerExpiry\Application\DTOs\ExpirationView`)
  built from `ExpirationService` and the tenant `Server` record. The Blade
  template contains no business logic and assigns no local variables (this
  stack's Blade does not compile the single-expression `@php(...)` form, and
  Livewire renders conditional regions in separate fragment scopes — only real
  view data reaches them).
- All styles are scoped under the `se-expiry-` class prefix inside an
  `@once` block, so the plugin cannot leak CSS into the rest of the panel.
- All copy is translatable via `lang/en/strings.php`
  (`server-expiry::strings.*`).

## Testing

The implementation is covered by source-contract tests that pin the owner
experience (`tests/UI/OwnerExpirationUiTest.php`,
`tests/UI/ClientPageRegressionTest.php`,
`tests/UI/OwnerPageSchemaBindingTest.php`): the six-state mapping, the
Permanent-only glow, the absence of permanent countdown/timeline, the
conditional support CTA, the absence of any renewal/self-service actions or
fabricated commerce modules, raw-SVG icon rendering, view-data delivery of the
view model, and translation-key completeness. The full state matrix (all six
states, all three suspension reasons, both support-CTA variants, dark/light,
desktop/mobile, countdown tick and reload re-sync) was additionally verified
at runtime against a real panel.

## Security

- The page is strictly read-only; there are no POST/PUT surfaces, no
  Livewire actions and no header actions exposed to owners.
- The support URL is admin-configured and rendered with Blade escaping; the
  link uses `rel="noopener noreferrer"`.
- All state derives from the tenant server via the panel's standard
  authorization; no cross-server data access is possible.
