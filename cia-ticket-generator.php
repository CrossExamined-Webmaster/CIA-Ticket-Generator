<?php
/**
 * Plugin Name:       CIA Ticket Generator
 * Plugin URI:        https://crossexamined.org/
 * Description:       Admin-only tool to bulk-generate Tickera orders/tickets for approved CIA applicants, with a searchable multi-select list and chunked batch processing. Shortcode: [cia_ticket_generator]
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CrossExamined.org
 * License:           All Rights Reserved
 * Text Domain:       cia-ticket-generator
 *
 * What this does:
 * 1. Renders a protected frontend form
 * 2. Loads selectable applicants from wp_ce_cia_applications:
 *    - Only rows with a non-empty email
 *    - Only rows with application_status in:
 *        Approved, Part Fin Aid - Approved, Fin Aid - Approved
 *    - Rendered as searchable checkboxes (Select All Visible / Clear All)
 * 3. For each selected applicant + chosen Tickera Event / Ticket Type:
 *    - Creates a Tickera order
 *    - Creates a Tickera ticket instance
 *    - Updates AlpdbF_ce_cia_applications using applicant_id (UUID)
 * 4. Re-validates all submitted IDs server-side against the same filters
 *    before processing anything (submitted IDs are never trusted alone).
 * 5. Processes large selections in chunked AJAX batches (not one long
 *    request), with a live progress bar and per-applicant error reporting.
 *
 * IMPORTANT:
 * - Designed for standalone Tickera usage - requires Tickera to be active.
 * - Restricts access to logged-in admins by capability.
 * - Test with one real event + one real ticket type first.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('CE_PLUGIN', true);

/**
 * Bail out gracefully (with an admin notice) if Tickera isn't active,
 * rather than fatal-erroring on missing post types / functions.
 */
add_action('plugins_loaded', 'cia_ticket_generator_check_dependencies');
function cia_ticket_generator_check_dependencies() {
	if (!post_type_exists('tc_events') || !post_type_exists('tc_tickets')) {
		add_action('admin_notices', function () {
			if (current_user_can('manage_options')) {
				echo '<div class="notice notice-error"><p><strong>CIA Ticket Generator</strong> requires Tickera to be installed and active. The [cia_ticket_generator] shortcode will not function until Tickera is enabled.</p></div>';
			}
		});
	}
}

/**
 * Register shortcode
 */
add_shortcode('cia_ticket_generator', 'cia_ticket_generator_shortcode');

/**
 * AJAX: Get ticket types for selected event
 */
add_action('wp_ajax_cia_get_ticket_types', 'cia_get_ticket_types_ajax_handler');
function cia_get_ticket_types_ajax_handler() {
	if (!is_user_logged_in() || !current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	$event_id = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;

	if (!$event_id) {
		wp_send_json(array());
	}

	$all_tickets = get_posts(array(
		'post_type'      => 'tc_tickets',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
	));

	$matched_tickets = array();

	foreach ($all_tickets as $ticket) {
		$ticket_id = (int) $ticket->ID;

		$possible_event_values = array(
			get_post_meta($ticket_id, 'event_name', true),
			get_post_meta($ticket_id, 'event_id', true),
			get_post_meta($ticket_id, 'tc_event', true),
			get_post_meta($ticket_id, 'tc_event_id', true),
		);

		$is_match = false;

		foreach ($possible_event_values as $value) {
			if (empty($value)) {
				continue;
			}

			if (is_array($value)) {
				$normalized = array_map('intval', $value);
				if (in_array($event_id, $normalized, true)) {
					$is_match = true;
					break;
				}
			} else {
				$parts = array_map('trim', explode(',', (string) $value));
				$parts = array_filter($parts, 'strlen');
				$parts = array_map('intval', $parts);

				if (in_array($event_id, $parts, true)) {
					$is_match = true;
					break;
				}
			}
		}

		if ($is_match) {
			$matched_tickets[] = array(
				'id'    => $ticket_id,
				'title' => $ticket->post_title,
			);
		}
	}

	wp_send_json($matched_tickets);
}

/**
 * AJAX: Process one batch of applicant IDs.
 *
 * Called repeatedly by the frontend (in chunks) instead of relying on a
 * single long-running form POST. Each batch independently re-validates its
 * IDs against the DB filters (email + status) - submitted IDs are never
 * trusted on their own, whether they arrive via this endpoint or the
 * no-JS form fallback.
 */
add_action('wp_ajax_cia_process_ticket_batch', 'cia_process_ticket_batch_ajax_handler');
function cia_process_ticket_batch_ajax_handler() {
	if (!is_user_logged_in() || !current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	check_ajax_referer('cia_create_tickera_ticket_action', 'nonce');

	$event_id_value       = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
	$ticket_type_id_value = isset($_POST['ticket_type_id']) ? absint($_POST['ticket_type_id']) : 0;

	$submitted_ids = isset($_POST['applicant_ids']) && is_array($_POST['applicant_ids'])
		? array_values(array_unique(array_filter(array_map('absint', $_POST['applicant_ids']))))
		: array();

	if (!$event_id_value || !$ticket_type_id_value || empty($submitted_ids)) {
		wp_send_json_error(array('message' => 'Missing event, ticket type, or applicant IDs for this batch.'), 400);
	}

	// Re-validate this batch's IDs against the DB filters. Anything that
	// doesn't match (wrong status, empty email, doesn't exist) is dropped
	// and reported back as skipped rather than processed.
	$validated_applicants = cia_get_selectable_applicants($submitted_ids);
	$validated_ids        = wp_list_pluck($validated_applicants, 'id');

	$results = array();

	foreach ($submitted_ids as $submitted_id) {
		if (!in_array((int) $submitted_id, array_map('intval', $validated_ids), true)) {
			$results[] = array(
				'id'      => $submitted_id,
				'name'    => '(ID ' . $submitted_id . ')',
				'status'  => 'error',
				'message' => 'Skipped - no longer matches eligible applicant filters (email/status).',
			);
		}
	}

	foreach ($validated_applicants as $applicant) {
		$result = cia_phase1_create_tickera_order_and_ticket(array(
			'first_name'     => $applicant->first_name,
			'last_name'      => $applicant->last_name,
			'email'          => $applicant->email,
			'applicant_id'   => $applicant->applicant_id,
			'event_id'       => $event_id_value,
			'ticket_type_id' => $ticket_type_id_value,
		));

		if (is_wp_error($result)) {
			cia_phase1_update_application_tickera_state($applicant->applicant_id, array(
				'tickera_sync_status'         => 'error',
				'tickera_sync_message'        => $result->get_error_message(),
				'tickera_generation_attempts' => 1,
			));

			$results[] = array(
				'id'      => $applicant->id,
				'name'    => $applicant->applicant_full_name,
				'status'  => 'error',
				'message' => $result->get_error_message(),
			);
			continue;
		}

		$update_result = cia_phase1_update_application_tickera_state($applicant->applicant_id, array(
			'tickera_sync_status'         => 'created',
			'tickera_event_id'            => $event_id_value,
			'tickera_ticket_type_id'      => $ticket_type_id_value,
			'tickera_order_id'            => $result['order_post_id'],
			'tickera_order_item_id'       => null,
			'tickera_attendee_id'         => $result['ticket_instance_id'],
			'tickera_ticket_code'         => $result['ticket_code'],
			'tickera_created_at'          => current_time('mysql'),
			'tickera_created_by'          => get_current_user_id(),
			'tickera_sync_message'        => 'Created manually via CIA Ticket Generator shortcode (bulk, batched).',
			'tickera_manual_hold_reason'  => null,
			'tickera_generation_attempts' => 1,
		));

		if (is_wp_error($update_result)) {
			$results[] = array(
				'id'      => $applicant->id,
				'name'    => $applicant->applicant_full_name,
				'status'  => 'error',
				'message' => 'Ticket created (Order #' . intval($result['order_post_id']) . ', ' . $result['ticket_code'] . ') but DB update failed: ' . $update_result->get_error_message(),
			);
			continue;
		}

		$results[] = array(
			'id'      => $applicant->id,
			'name'    => $applicant->applicant_full_name,
			'status'  => 'success',
			'message' => 'Order #' . intval($result['order_post_id']) . ' / Ticket ' . $result['ticket_code'],
		);
	}

	wp_send_json_success(array('results' => $results));
}

/**
 * Shared allow-list of application statuses eligible for ticket creation.
 */
function cia_get_allowed_application_statuses() {
	return array(
		'Approved',
		'Part Fin Aid - Approved',
		'Fin Aid - Approved',
	);
}

/**
 * Retrieve selectable applicants from AlpdbF_ce_cia_applications.
 *
 * Applies the email + application_status filters unconditionally.
 * If $ids is provided, also restricts to that set of internal `id` values -
 * this is used to re-validate submitted selections server-side so submitted
 * IDs are never trusted by themselves.
 *
 * @param array|null $ids Optional list of internal `id` values to restrict to.
 * @return array Array of stdClass rows with: id, applicant_id, applicant_full_name,
 *               first_name, last_name, email, application_status.
 */
function cia_get_selectable_applicants($ids = null) {
	global $wpdb;

	$table = $wpdb->prefix . 'ce_cia_applications';

	$allowed_statuses = cia_get_allowed_application_statuses();
	$status_placeholders = implode(',', array_fill(0, count($allowed_statuses), '%s'));

	$sql = "SELECT id, applicant_id, applicant_full_name, first_name, last_name, email, application_status
			FROM {$table}
			WHERE email IS NOT NULL
			AND email <> ''
			AND application_status IN ({$status_placeholders})";

	$params = $allowed_statuses;

	if (is_array($ids)) {
		$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

		if (empty($ids)) {
			// Nothing valid was submitted - short-circuit to an empty result set.
			return array();
		}

		$id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$sql .= " AND id IN ({$id_placeholders})";
		$params = array_merge($params, $ids);
	}

	$sql .= " ORDER BY applicant_full_name ASC";

	$prepared = $wpdb->prepare($sql, $params);
	$results  = $wpdb->get_results($prepared);

	return $results ? $results : array();
}

/**
 * Shortcode renderer
 */
function cia_ticket_generator_shortcode() {
	if (!is_user_logged_in() || !current_user_can('manage_options')) {
		return '<div style="padding:16px;border:1px solid #ddd;background:#fff;">You do not have permission to access this form.</div>';
	}

	$message      = '';
	$message_type = 'success';

	$event_id_value       = 0;
	$ticket_type_id_value = 0;
	$selected_ids         = array();

	if (
		isset($_POST['cia_create_tickera_ticket']) &&
		check_admin_referer('cia_create_tickera_ticket_action', 'cia_create_tickera_ticket_nonce')
	) {
		$event_id_value       = isset($_POST['tickera_event_id']) ? absint($_POST['tickera_event_id']) : 0;
		$ticket_type_id_value = isset($_POST['tickera_ticket_type_id']) ? absint($_POST['tickera_ticket_type_id']) : 0;

		// Sanitize submitted IDs as positive integers only. These are NOT
		// trusted on their own - they get re-checked against the DB filters
		// via cia_get_selectable_applicants() below.
		$submitted_ids = isset($_POST['applicant_ids']) && is_array($_POST['applicant_ids'])
			? array_values(array_unique(array_filter(array_map('absint', $_POST['applicant_ids']))))
			: array();

		$selected_ids = $submitted_ids;

		if (!$event_id_value || !$ticket_type_id_value || empty($submitted_ids)) {
			$message = 'Please choose an event, a ticket type, and at least one applicant.';
			$message_type = 'error';
		} else {
			// Re-query the DB with the same email + status filters, restricted
			// to the submitted IDs. Anything submitted that doesn't match the
			// filters (or doesn't exist) is silently dropped here.
			$validated_applicants = cia_get_selectable_applicants($submitted_ids);

			if (empty($validated_applicants)) {
				$message = 'None of the selected applicants are eligible (check email and application status).';
				$message_type = 'error';
			} else {
				$success_count = 0;
				$error_lines   = array();

				foreach ($validated_applicants as $applicant) {
					$result = cia_phase1_create_tickera_order_and_ticket(array(
						'first_name'     => $applicant->first_name,
						'last_name'      => $applicant->last_name,
						'email'          => $applicant->email,
						'applicant_id'   => $applicant->applicant_id,
						'event_id'       => $event_id_value,
						'ticket_type_id' => $ticket_type_id_value,
					));

					if (is_wp_error($result)) {
						cia_phase1_update_application_tickera_state($applicant->applicant_id, array(
							'tickera_sync_status'         => 'error',
							'tickera_sync_message'        => $result->get_error_message(),
							'tickera_generation_attempts' => 1,
						));

						$error_lines[] = $applicant->applicant_full_name . ': ' . $result->get_error_message();
						continue;
					}

					$update_result = cia_phase1_update_application_tickera_state($applicant->applicant_id, array(
						'tickera_sync_status'         => 'created',
						'tickera_event_id'            => $event_id_value,
						'tickera_ticket_type_id'      => $ticket_type_id_value,
						'tickera_order_id'            => $result['order_post_id'],
						'tickera_order_item_id'       => null,
						'tickera_attendee_id'         => $result['ticket_instance_id'],
						'tickera_ticket_code'         => $result['ticket_code'],
						'tickera_created_at'          => current_time('mysql'),
						'tickera_created_by'          => get_current_user_id(),
						'tickera_sync_message'        => 'Created manually via CIA Ticket Generator shortcode (bulk).',
						'tickera_manual_hold_reason'  => null,
						'tickera_generation_attempts' => 1,
					));

					if (is_wp_error($update_result)) {
						$error_lines[] = $applicant->applicant_full_name . ': ticket created (Order #' . intval($result['order_post_id']) . ', Ticket ' . esc_html($result['ticket_code']) . ') but DB update failed: ' . $update_result->get_error_message();
						continue;
					}

					$success_count++;
				}

				if ($success_count > 0 && empty($error_lines)) {
					$message = 'Success. Created ' . intval($success_count) . ' ticket(s).';
					$message_type = 'success';
					$selected_ids = array(); // clear selections on full success
				} elseif ($success_count > 0 && !empty($error_lines)) {
					$message = 'Created ' . intval($success_count) . ' ticket(s), but ' . count($error_lines) . ' failed:' . "\n" . implode("\n", $error_lines);
					$message_type = 'error';
				} else {
					$message = 'No tickets were created. Errors:' . "\n" . implode("\n", $error_lines);
					$message_type = 'error';
				}
			}
		}
	}

	$events = get_posts(array(
		'post_type'      => 'tc_events',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
	));

	$applicants = cia_get_selectable_applicants();

	ob_start();
	?>
	<div class="cia-ticket-generator-wrap" style="max-width:900px;margin:30px auto;padding:24px;border:1px solid #ddd;background:#fff;">
		<h2 style="margin-top:0;">CIA Ticket Generator</h2>

		<?php if (!empty($message)) : ?>
			<div style="margin-bottom:20px;padding:14px;border:1px solid <?php echo esc_attr($message_type === 'error' ? '#c62828' : '#2e7d32'); ?>;background:<?php echo esc_attr($message_type === 'error' ? '#ffebee' : '#e8f5e9'); ?>;color:<?php echo esc_attr($message_type === 'error' ? '#b71c1c' : '#1b5e20'); ?>;white-space:pre-line;">
				<?php echo esc_html($message); ?>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field('cia_create_tickera_ticket_action', 'cia_create_tickera_ticket_nonce'); ?>

			<p style="margin-bottom:16px;">
				<label for="tickera_event_id" style="display:block;font-weight:600;margin-bottom:6px;">Tickera Event</label>
				<select name="tickera_event_id" id="tickera_event_id" required style="width:100%;padding:10px;">
					<option value="">Select Event</option>
					<?php foreach ($events as $event) : ?>
						<option value="<?php echo esc_attr($event->ID); ?>" <?php selected($event_id_value, $event->ID); ?>>
							<?php echo esc_html($event->post_title . ' (ID: ' . $event->ID . ')'); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p style="margin-bottom:20px;">
				<label for="tickera_ticket_type_id" style="display:block;font-weight:600;margin-bottom:6px;">Ticket Type</label>
				<select name="tickera_ticket_type_id" id="tickera_ticket_type_id" required style="width:100%;padding:10px;">
					<option value="">Select Ticket Type</option>
				</select>
			</p>

			<div style="margin-bottom:12px;">
				<label for="cia_applicant_search" style="display:block;font-weight:600;margin-bottom:6px;">Applicants</label>
				<input type="text" id="cia_applicant_search" placeholder="Search by name, email, or applicant ID..." style="width:100%;padding:10px;box-sizing:border-box;margin-bottom:10px;">

				<div style="display:flex;gap:10px;margin-bottom:10px;">
					<button type="button" id="cia_select_all_visible" style="padding:8px 14px;border:1px solid #111;background:#fff;color:#111;cursor:pointer;">
						Select All Visible
					</button>
					<button type="button" id="cia_clear_all" style="padding:8px 14px;border:1px solid #111;background:#fff;color:#111;cursor:pointer;">
						Clear All
					</button>
					<span id="cia_selected_count" style="align-self:center;color:#555;font-size:13px;"></span>
				</div>

				<div id="cia_applicant_list" style="max-height:340px;overflow-y:auto;border:1px solid #ddd;padding:8px;">
					<?php if (empty($applicants)) : ?>
						<p style="margin:8px;color:#555;">No eligible applicants found.</p>
					<?php else : ?>
						<?php foreach ($applicants as $applicant) : ?>
							<?php
							$search_blob = strtolower(
								$applicant->applicant_full_name . ' ' .
								$applicant->first_name . ' ' .
								$applicant->last_name . ' ' .
								$applicant->applicant_id . ' ' .
								$applicant->email
							);
							$is_checked = in_array((int) $applicant->id, $selected_ids, true);
							?>
							<label class="cia-applicant-row" data-search="<?php echo esc_attr($search_blob); ?>" style="display:flex;align-items:center;gap:10px;padding:8px 6px;border-bottom:1px solid #f0f0f0;cursor:pointer;">
								<input type="checkbox" class="cia-applicant-checkbox" name="applicant_ids[]" value="<?php echo esc_attr($applicant->id); ?>" <?php checked($is_checked); ?>>
								<span>
									<strong><?php echo esc_html($applicant->applicant_full_name); ?></strong>
									&nbsp;&mdash;&nbsp;<?php echo esc_html($applicant->email); ?>
									<br><span style="color:#777;font-size:12px;">Applicant ID: <?php echo esc_html($applicant->applicant_id); ?> &middot; Status: <?php echo esc_html($applicant->application_status); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<p style="margin-bottom:0;margin-top:20px;">
				<button type="submit" name="cia_create_tickera_ticket" value="1" id="cia_submit_btn" style="padding:12px 20px;border:none;background:#111;color:#fff;cursor:pointer;">
					Generate Tickera Tickets
				</button>
				<noscript>
					<span style="display:block;margin-top:10px;color:#8a6d00;font-size:13px;">
						JavaScript is disabled - this will run as a single request. For large selections, enabling JavaScript is recommended so processing happens in safe batches.
					</span>
				</noscript>
			</p>

			<div id="cia_progress_wrap" style="display:none;margin-top:20px;">
				<div style="background:#eee;border-radius:4px;overflow:hidden;height:18px;">
					<div id="cia_progress_bar" style="background:#111;height:100%;width:0%;transition:width 0.2s;"></div>
				</div>
				<p id="cia_progress_text" style="margin:8px 0 0;color:#333;font-size:13px;"></p>
				<div id="cia_progress_errors" style="display:none;margin-top:10px;max-height:220px;overflow-y:auto;border:1px solid #eeb4b4;background:#fff5f5;padding:10px;font-size:13px;color:#8a1c1c;white-space:pre-line;"></div>
			</div>
		</form>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function () {
		const eventSelect = document.getElementById('tickera_event_id');
		const ticketSelect = document.getElementById('tickera_ticket_type_id');
		const selectedTicketTypeId = <?php echo (int) $ticket_type_id_value; ?>;
		const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

		function loadTicketTypes(eventId, preselectedTicketId = 0) {
			ticketSelect.innerHTML = '<option value="">Loading...</option>';

			if (!eventId) {
				ticketSelect.innerHTML = '<option value="">Select Ticket Type</option>';
				return;
			}

			fetch(ajaxUrl + '?action=cia_get_ticket_types&event_id=' + encodeURIComponent(eventId), {
				credentials: 'same-origin'
			})
			.then(function(res) {
				return res.json();
			})
			.then(function(data) {
				ticketSelect.innerHTML = '<option value="">Select Ticket Type</option>';

				if (!Array.isArray(data) || !data.length) {
					return;
				}

				data.forEach(function(ticket) {
					const option = document.createElement('option');
					option.value = ticket.id;
					option.textContent = ticket.title + ' (ID: ' + ticket.id + ')';

					if (parseInt(preselectedTicketId, 10) === parseInt(ticket.id, 10)) {
						option.selected = true;
					}

					ticketSelect.appendChild(option);
				});
			})
			.catch(function() {
				ticketSelect.innerHTML = '<option value="">Could not load ticket types</option>';
			});
		}

		eventSelect.addEventListener('change', function () {
			loadTicketTypes(this.value, 0);
		});

		if (eventSelect.value) {
			loadTicketTypes(eventSelect.value, selectedTicketTypeId);
		}

		// --- Applicant multi-select: search, select all visible, clear all ---
		const searchInput   = document.getElementById('cia_applicant_search');
		const rows          = Array.prototype.slice.call(document.querySelectorAll('.cia-applicant-row'));
		const selectAllBtn  = document.getElementById('cia_select_all_visible');
		const clearAllBtn   = document.getElementById('cia_clear_all');
		const countLabel    = document.getElementById('cia_selected_count');

		function isVisible(row) {
			return row.style.display !== 'none';
		}

		function updateCount() {
			const total = document.querySelectorAll('.cia-applicant-checkbox:checked').length;
			countLabel.textContent = total + ' selected';
		}

		function applySearch() {
			const term = searchInput.value.trim().toLowerCase();

			rows.forEach(function (row) {
				const haystack = row.getAttribute('data-search') || '';
				row.style.display = (!term || haystack.indexOf(term) !== -1) ? '' : 'none';
			});
		}

		if (searchInput) {
			searchInput.addEventListener('input', applySearch);
		}

		if (selectAllBtn) {
			selectAllBtn.addEventListener('click', function () {
				rows.forEach(function (row) {
					if (isVisible(row)) {
						const checkbox = row.querySelector('.cia-applicant-checkbox');
						if (checkbox) {
							checkbox.checked = true;
						}
					}
				});
				updateCount();
			});
		}

		if (clearAllBtn) {
			clearAllBtn.addEventListener('click', function () {
				document.querySelectorAll('.cia-applicant-checkbox').forEach(function (checkbox) {
					checkbox.checked = false;
				});
				updateCount();
			});
		}

		document.querySelectorAll('.cia-applicant-checkbox').forEach(function (checkbox) {
			checkbox.addEventListener('change', updateCount);
		});

		updateCount();

		// --- Chunked AJAX submission (progressive enhancement over the plain POST fallback) ---
		const BATCH_SIZE = 20;
		const form           = document.querySelector('.cia-ticket-generator-wrap form');
		const submitBtn      = document.getElementById('cia_submit_btn');
		const progressWrap   = document.getElementById('cia_progress_wrap');
		const progressBar    = document.getElementById('cia_progress_bar');
		const progressText   = document.getElementById('cia_progress_text');
		const progressErrors = document.getElementById('cia_progress_errors');
		const nonceInput     = document.getElementById('cia_create_tickera_ticket_nonce');

		function chunk(array, size) {
			const out = [];
			for (let i = 0; i < array.length; i += size) {
				out.push(array.slice(i, i + size));
			}
			return out;
		}

		function setControlsDisabled(disabled) {
			submitBtn.disabled = disabled;
			selectAllBtn.disabled = disabled;
			clearAllBtn.disabled = disabled;
			searchInput.disabled = disabled;
			eventSelect.disabled = disabled;
			ticketSelect.disabled = disabled;
			document.querySelectorAll('.cia-applicant-checkbox').forEach(function (cb) {
				cb.disabled = disabled;
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			const checkedBoxes = Array.prototype.slice.call(document.querySelectorAll('.cia-applicant-checkbox:checked'));
			const ids = checkedBoxes.map(function (cb) { return cb.value; });

			if (!eventSelect.value || !ticketSelect.value) {
				alert('Please choose an event and a ticket type.');
				return;
			}

			if (!ids.length) {
				alert('Please select at least one applicant.');
				return;
			}

			const batches = chunk(ids, BATCH_SIZE);
			let processed = 0;
			const allResults = [];

			progressWrap.style.display = 'block';
			progressErrors.style.display = 'none';
			progressErrors.textContent = '';
			progressBar.style.width = '0%';
			progressText.textContent = 'Starting... 0 of ' + ids.length + ' processed.';
			setControlsDisabled(true);

			function runBatch(index) {
				if (index >= batches.length) {
					// All batches complete - render final summary.
					const successCount = allResults.filter(function (r) { return r.status === 'success'; }).length;
					const errorResults = allResults.filter(function (r) { return r.status !== 'success'; });

					progressBar.style.width = '100%';
					progressText.textContent = 'Done. ' + successCount + ' of ' + ids.length + ' ticket(s) created successfully.' +
						(errorResults.length ? (' ' + errorResults.length + ' failed - see details below.') : '');

					if (errorResults.length) {
						progressErrors.style.display = 'block';
						progressErrors.textContent = errorResults.map(function (r) {
							return r.name + ': ' + r.message;
						}).join('\n');
					}

					setControlsDisabled(false);

					if (successCount > 0) {
						// Uncheck successfully processed applicants so a re-run only targets failures.
						const successIds = allResults.filter(function (r) { return r.status === 'success'; }).map(function (r) { return String(r.id); });
						document.querySelectorAll('.cia-applicant-checkbox').forEach(function (cb) {
							if (successIds.indexOf(cb.value) !== -1) {
								cb.checked = false;
							}
						});
						updateCount();
					}

					return;
				}

				const batchIds = batches[index];
				const body = new URLSearchParams();
				body.append('action', 'cia_process_ticket_batch');
				body.append('nonce', nonceInput.value);
				body.append('event_id', eventSelect.value);
				body.append('ticket_type_id', ticketSelect.value);
				batchIds.forEach(function (id) {
					body.append('applicant_ids[]', id);
				});

				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				})
				.then(function (res) { return res.json(); })
				.then(function (json) {
					if (json && json.success && json.data && Array.isArray(json.data.results)) {
						allResults.push.apply(allResults, json.data.results);
					} else {
						batchIds.forEach(function (id) {
							allResults.push({ id: id, name: '(ID ' + id + ')', status: 'error', message: (json && json.data && json.data.message) ? json.data.message : 'Batch request failed.' });
						});
					}
				})
				.catch(function () {
					batchIds.forEach(function (id) {
						allResults.push({ id: id, name: '(ID ' + id + ')', status: 'error', message: 'Network or server error processing this batch.' });
					});
				})
				.finally(function () {
					processed += batchIds.length;
					const pct = Math.round((processed / ids.length) * 100);
					progressBar.style.width = pct + '%';
					progressText.textContent = 'Processing... ' + processed + ' of ' + ids.length + ' submitted.';
					runBatch(index + 1);
				});
			}

			runBatch(0);
		});
	});
	</script>
	<?php

	return ob_get_clean();
}

/**
 * Create Tickera order + ticket instance
 */
function cia_phase1_create_tickera_order_and_ticket($args) {
	$first_name     = $args['first_name'];
	$last_name      = $args['last_name'];
	$email          = $args['email'];
	$applicant_id   = $args['applicant_id'];
	$event_id       = (int) $args['event_id'];
	$ticket_type_id = (int) $args['ticket_type_id'];

	$event_post = get_post($event_id);
	if (!$event_post || $event_post->post_type !== 'tc_events') {
		return new WP_Error('invalid_event', 'Invalid Tickera Event ID.');
	}

	$ticket_post = get_post($ticket_type_id);
	if (!$ticket_post || $ticket_post->post_type !== 'tc_tickets') {
		return new WP_Error('invalid_ticket_type', 'Invalid Tickera Ticket Type ID.');
	}

	$order_number = strtoupper('CIA-' . wp_generate_password(10, false, false));
	$ticket_code  = strtoupper('TC-' . wp_generate_password(12, false, false));
	$now_ts       = current_time('timestamp');
	$full_name    = trim($first_name . ' ' . $last_name);

	$ticket_price = get_post_meta($ticket_type_id, 'price_per_ticket', true);
	$ticket_price = ($ticket_price === '' || $ticket_price === null) ? '0' : (string) $ticket_price;

	$currency = get_option('tc_currency', 'USD');

	$cart_contents = array(
		$ticket_type_id => 1,
	);

	$cart_info = array(
		'total'      => $ticket_price,
		'currency'   => $currency,
		'buyer_data' => array(
			'first_name_post_meta' => $first_name,
			'last_name_post_meta'  => $last_name,
			'email_post_meta'      => $email,
		),
		'owner_data'  => array(
			array(
				'first_name_post_meta' => $first_name,
				'last_name_post_meta'  => $last_name,
				'email_post_meta'      => $email,
			),
		),
	);

	$payment_info = array(
		'total'          => $ticket_price,
		'currency'       => $currency,
		'payment_type'   => 'manual_cia_generator',
		'transaction_id' => 'CIA-MANUAL-' . strtoupper(wp_generate_password(8, false, false)),
		'status'         => 'order_paid',
	);

	$order_post_id = wp_insert_post(array(
		'post_type'    => 'tc_orders',
		'post_status'  => 'publish',
		'post_title'   => $order_number,
		'post_content' => '',
	), true);

	if (is_wp_error($order_post_id) || !$order_post_id) {
		return new WP_Error('order_create_failed', 'Could not create Tickera order.');
	}

	update_post_meta($order_post_id, 'tc_order_date', $now_ts);
	update_post_meta($order_post_id, '_tc_paid_date', $now_ts);
	update_post_meta($order_post_id, 'tc_cart_contents', $cart_contents);
	update_post_meta($order_post_id, 'tc_cart_info', $cart_info);
	update_post_meta($order_post_id, 'tc_payment_info', $payment_info);
	update_post_meta($order_post_id, 'tc_order_status', 'order_paid');
	update_post_meta($order_post_id, 'tc_transaction_id', $payment_info['transaction_id']);
	update_post_meta($order_post_id, 'cia_applicant_uuid', $applicant_id);
	update_post_meta($order_post_id, 'tickera_order_applicant_id_capture', $applicant_id);
	update_post_meta($order_post_id, 'cia_created_manually', '1');

	$ticket_instance_id = wp_insert_post(array(
		'post_type'    => 'tc_tickets_instances',
		'post_status'  => 'publish',
		'post_parent'  => $order_post_id,
		'post_title'   => $ticket_code,
		'post_content' => '',
	), true);

	if (is_wp_error($ticket_instance_id) || !$ticket_instance_id) {
		wp_delete_post($order_post_id, true);
		return new WP_Error('ticket_instance_create_failed', 'Could not create Tickera ticket instance.');
	}

	update_post_meta($ticket_instance_id, 'ticket_code', $ticket_code);
	update_post_meta($ticket_instance_id, 'ticket_type_id', $ticket_type_id);
	update_post_meta($ticket_instance_id, 'event_id', $event_id);
	update_post_meta($ticket_instance_id, 'order_id', $order_post_id);
	update_post_meta($ticket_instance_id, 'first_name', $first_name);
	update_post_meta($ticket_instance_id, 'last_name', $last_name);
	update_post_meta($ticket_instance_id, 'owner_name', $full_name);
	update_post_meta($ticket_instance_id, 'owner_email', $email);
	update_post_meta($ticket_instance_id, 'buyer_name', $full_name);
	update_post_meta($ticket_instance_id, 'buyer_email', $email);
	update_post_meta($ticket_instance_id, 'payment_status', 'order_paid');
	update_post_meta($ticket_instance_id, 'checked_in', '0');
	update_post_meta($ticket_instance_id, 'downloaded_times', '0');
	update_post_meta($ticket_instance_id, 'ticket_download_link_expired', '0');
	update_post_meta($ticket_instance_id, 'cia_applicant_uuid', $applicant_id);
	update_post_meta($ticket_instance_id, 'tickera_order_applicant_id_capture', $applicant_id);
	update_post_meta($ticket_instance_id, 'cia_created_manually', '1');
	update_post_meta($ticket_instance_id, 'event_name', get_the_title($event_id));
	update_post_meta($ticket_instance_id, 'ticket_type_name', get_the_title($ticket_type_id));

	return array(
		'order_post_id'      => (int) $order_post_id,
		'ticket_instance_id' => (int) $ticket_instance_id,
		'ticket_code'        => $ticket_code,
		'order_number'       => $order_number,
	);
}

/**
 * Update CIA applications table by applicant UUID
 */
function cia_phase1_update_application_tickera_state($applicant_id, $data) {
	global $wpdb;

	$table = $wpdb->prefix . 'ce_cia_applications';

	$current_attempts = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tickera_generation_attempts FROM {$table} WHERE applicant_id = %s LIMIT 1",
			$applicant_id
		)
	);

	if ($current_attempts === null) {
		return new WP_Error('application_not_found', 'No CIA application row found for that UUID.');
	}

	$update = array();
	$format = array();

	$allowed = array(
		'tickera_sync_status',
		'tickera_event_id',
		'tickera_ticket_type_id',
		'tickera_order_id',
		'tickera_order_item_id',
		'tickera_attendee_id',
		'tickera_ticket_code',
		'tickera_created_at',
		'tickera_created_by',
		'tickera_sync_message',
		'tickera_manual_hold_reason',
	);

	foreach ($allowed as $key) {
		if (array_key_exists($key, $data)) {
			$update[$key] = $data[$key];

			if (in_array($key, array(
				'tickera_event_id',
				'tickera_ticket_type_id',
				'tickera_order_id',
				'tickera_order_item_id',
				'tickera_attendee_id',
				'tickera_created_by',
			), true)) {
				$format[] = is_null($data[$key]) ? '%s' : '%d';
			} else {
				$format[] = '%s';
			}
		}
	}

	$attempt_increment = isset($data['tickera_generation_attempts']) ? (int) $data['tickera_generation_attempts'] : 0;
	$update['tickera_generation_attempts'] = (int) $current_attempts + $attempt_increment;
	$format[] = '%d';

	$result = $wpdb->update(
		$table,
		$update,
		array('applicant_id' => $applicant_id),
		$format,
		array('%s')
	);

	if ($result === false) {
		return new WP_Error('db_update_failed', 'Failed updating CIA applications table.');
	}

	return true;
}
