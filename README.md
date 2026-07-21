# CIA Ticket Generator

Admin-only WordPress plugin for bulk-generating Tickera orders and tickets
for approved CIA applicants.

## What it does

- Renders a protected shortcode form: `[cia_ticket_generator]`
- Loads selectable applicants from `wp_ce_cia_applications`, filtered to:
  - Non-empty `email`
  - `application_status` in: `Approved`, `Part Fin Aid - Approved`, `Fin Aid - Approved`
- Displays applicants as a searchable, multi-select checkbox list with
  Select All Visible / Clear All controls
- For each selected applicant + chosen Tickera Event / Ticket Type:
  - Creates a Tickera order
  - Creates a Tickera ticket instance
  - Updates the applicant's row in `ce_cia_applications` (matched by `applicant_id` UUID)
- Re-validates every submitted applicant ID server-side against the same
  filters before processing anything — submitted IDs are never trusted alone
- Processes large selections in chunked AJAX batches (default 20 per batch)
  with a live progress bar and per-applicant success/error reporting, so a
  large run can't time out a single request
- Falls back to a single-request POST if JavaScript is disabled

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Tickera plugin active (`tc_events`, `tc_tickets` post types)
- A `{$wpdb->prefix}ce_cia_applications` table with at least:
  `id`, `applicant_id`, `applicant_full_name`, `first_name`, `last_name`,
  `email`, `application_status`, `tickera_sync_status`, `tickera_event_id`,
  `tickera_ticket_type_id`, `tickera_order_id`, `tickera_order_item_id`,
  `tickera_attendee_id`, `tickera_ticket_code`, `tickera_created_at`,
  `tickera_created_by`, `tickera_sync_message`, `tickera_manual_hold_reason`,
  `tickera_generation_attempts`

## Access

Restricted to logged-in users with the `manage_options` capability.

## Installation

1. Copy this folder to `wp-content/plugins/cia-ticket-generator`
2. Activate **CIA Ticket Generator** from the Plugins screen
3. Place `[cia_ticket_generator]` on any page or post

## Changelog

### 1.1.0
- Added chunked AJAX batch processing (default batch size: 20) with a live
  progress bar, replacing the single long-running form submission
- Added per-applicant, per-batch success/error reporting
- Added server-side re-validation of every batch against the email/status
  filters (not just the initial submission)
- Auto-unchecks successfully processed applicants after a run so a retry
  only targets failures
- Added graceful admin notice if Tickera is not active, instead of a fatal error
- Converted to a standalone plugin (plugin header, `CE_PLUGIN` define)

### 1.0.0
- Initial release
- Multi-select, searchable applicant checkbox list (Select All Visible / Clear All)
- Server-side re-validation of submitted applicant IDs against email/status filters
- Bulk creation of Tickera orders + ticket instances per selected applicant
- Applicant record sync back to `ce_cia_applications` by `applicant_id` (UUID)
