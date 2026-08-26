<?php
/**
 * Form submissions: storage, approve/reject decisions, and the review screen.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Submissions {

    public static function init() {
        add_action('admin_post_snn_submission_action', [__CLASS__, 'handle_action']);
        add_action('admin_post_snn_submissions_export', [__CLASS__, 'handle_export']);
    }

    /* ------------------------------------------------------------------
     * Model
     * ---------------------------------------------------------------- */

    public static function create($args) {
        global $wpdb;

        $ok = $wpdb->insert(SNN_T_DB::submissions(), [
            'form_id'    => (int)$args['form_id'],
            'status'     => sanitize_key($args['status'] ?? 'pending'),
            'name'       => sanitize_text_field($args['name'] ?? ''),
            'email'      => sanitize_email($args['email'] ?? ''),
            'data'       => wp_json_encode($args['data'] ?? []),
            'ip'         => sanitize_text_field($args['ip'] ?? ''),
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s']);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public static function get($id) {
        global $wpdb;
        $table = SNN_T_DB::submissions();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$id));
        if ($row) {
            $decoded = json_decode((string)$row->data, true);
            $row->data = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /**
     * Turn a submission into a ticket and queue the ticket email.
     *
     * @return int|WP_Error ticket id
     */
    public static function approve($id, $user_id = 0, $reason = '') {
        global $wpdb;

        $submission = self::get($id);
        if (!$submission) return new WP_Error('snn_sub_missing', 'Submission not found.');

        $form = SNN_T_Forms::get($submission->form_id);
        if (!$form) return new WP_Error('snn_sub_form', 'The form for this submission no longer exists.');

        // Already approved with a ticket? Do not issue a second one.
        $ticket_id = (int)$submission->ticket_id;
        if (!$ticket_id) {
            $ticket_id = SNN_T_Tickets::insert(
                (int)$form->list_id,
                $submission->name,
                $submission->email,
                null,
                (int)$submission->id
            );
            if (!$ticket_id) {
                return new WP_Error('snn_sub_ticket', 'Could not create a ticket.');
            }
        }

        $wpdb->update(SNN_T_DB::submissions(), [
            'status'          => 'approved',
            'ticket_id'       => $ticket_id,
            'decided_at'      => current_time('mysql'),
            'decided_by'      => $user_id ?: get_current_user_id(),
            'decision_reason' => sanitize_text_field($reason),
        ], ['id' => (int)$id], ['%s', '%d', '%s', '%d', '%s'], ['%d']);

        $ticket = SNN_T_Tickets::get($ticket_id);

        if ($submission->email) {
            SNN_T_Mailer::enqueue_from_template('ticket', $form->settings['template_ticket'], [
                'name'          => $submission->name,
                'email'         => $submission->email,
                'ticket_code'   => $ticket ? $ticket->ticket_code : '',
                'list_name'     => SNN_T_Forms::list_name($form->list_id),
                'form_name'     => $form->name,
                'fields'        => $submission->data,
                'ticket_id'     => $ticket_id,
                'submission_id' => (int)$id,
            ], SNN_T_Forms::mail_override($form, 'ticket'));
        }

        return $ticket_id;
    }

    public static function reject($id, $user_id = 0, $reason = '') {
        global $wpdb;

        $submission = self::get($id);
        if (!$submission) return new WP_Error('snn_sub_missing', 'Submission not found.');

        $form = SNN_T_Forms::get($submission->form_id);

        $wpdb->update(SNN_T_DB::submissions(), [
            'status'          => 'rejected',
            'decided_at'      => current_time('mysql'),
            'decided_by'      => $user_id ?: get_current_user_id(),
            'decision_reason' => sanitize_text_field($reason),
        ], ['id' => (int)$id], ['%s', '%s', '%d', '%s'], ['%d']);

        if ($form && !empty($form->settings['send_rejection']) && $submission->email) {
            SNN_T_Mailer::enqueue_from_template('rejection', $form->settings['template_rejection'], [
                'name'          => $submission->name,
                'email'         => $submission->email,
                'list_name'     => SNN_T_Forms::list_name($form->list_id),
                'form_name'     => $form->name,
                'fields'        => $submission->data,
                'submission_id' => (int)$id,
            ]);
        }

        return true;
    }

    /**
     * Queue the ticket email again for an already-approved submission.
     */
    public static function resend($id) {
        $submission = self::get($id);
        if (!$submission)            return new WP_Error('snn_sub_missing', 'Submission not found.');
        if (!$submission->ticket_id) return new WP_Error('snn_sub_noticket', 'This submission has no ticket yet.');
        if (!$submission->email)     return new WP_Error('snn_sub_noemail', 'This submission has no email address.');

        $form   = SNN_T_Forms::get($submission->form_id);
        $ticket = SNN_T_Tickets::get($submission->ticket_id);
        if (!$ticket) return new WP_Error('snn_sub_noticket', 'The ticket no longer exists.');

        return SNN_T_Mailer::enqueue_from_template('ticket', $form ? $form->settings['template_ticket'] : '', [
            'name'          => $submission->name,
            'email'         => $submission->email,
            'ticket_code'   => $ticket->ticket_code,
            'list_name'     => $form ? SNN_T_Forms::list_name($form->list_id) : '',
            'form_name'     => $form ? $form->name : '',
            'fields'        => $submission->data,
            'ticket_id'     => (int)$submission->ticket_id,
            'submission_id' => (int)$id,
        ], SNN_T_Forms::mail_override($form, 'ticket'));
    }

    /**
     * Erase a submission and everything derived from it. Used for GDPR
     * erasure requests as well as ordinary cleanup.
     */
    public static function delete($id, $delete_ticket = true) {
        global $wpdb;

        $submission = self::get($id);
        if (!$submission) return false;

        if ($delete_ticket && $submission->ticket_id) {
            SNN_T_Tickets::delete_ticket((int)$submission->ticket_id);
        }

        $wpdb->delete(SNN_T_DB::queue(), ['submission_id' => (int)$id], ['%d']);
        return (bool)$wpdb->delete(SNN_T_DB::submissions(), ['id' => (int)$id], ['%d']);
    }

    public static function counts($form_id = 0) {
        global $wpdb;
        $table = SNN_T_DB::submissions();

        $sql = "SELECT status, COUNT(*) AS n FROM {$table}";
        if ($form_id) {
            $sql = $wpdb->prepare($sql . " WHERE form_id = %d", (int)$form_id);
        }
        $sql .= " GROUP BY status";

        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($wpdb->get_results($sql) as $r) {
            $out[$r->status] = (int)$r->n;
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Admin actions
     * ---------------------------------------------------------------- */

    public static function handle_action() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
        check_admin_referer('snn_submission_action');

        // A row button posts `do` + `submission_id`; the bulk bar posts
        // `bulk_do` + `ids[]`. Row actions win and act on that row alone.
        $single = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
        $do     = sanitize_key($_POST['do'] ?? '');

        if ($do && $single) {
            $ids = [$single];
        } else {
            $do  = sanitize_key($_POST['bulk_do'] ?? '');
            $ids = array_values(array_filter(array_unique(array_map('intval', (array)($_POST['ids'] ?? [])))));
        }

        if (!$do || !$ids) {
            $back = wp_get_referer() ?: admin_url('admin.php?page=snn-tickets-submissions');
            wp_safe_redirect(add_query_arg('snn_msg', rawurlencode('Nothing to do.'), remove_query_arg('snn_msg', $back)));
            exit;
        }

        $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
        $done   = 0;
        $errors = [];

        foreach ($ids as $id) {
            switch ($do) {
                case 'approve':
                    $r = self::approve($id, get_current_user_id(), $reason);
                    break;
                case 'reject':
                    $r = self::reject($id, get_current_user_id(), $reason);
                    break;
                case 'resend':
                    $r = self::resend($id);
                    break;
                case 'delete':
                    $r = self::delete($id) ? true : new WP_Error('snn_del', 'Could not delete submission ' . $id);
                    break;
                default:
                    $r = new WP_Error('snn_unknown', 'Unknown action');
            }

            if (is_wp_error($r)) {
                $errors[] = $r->get_error_message();
            } else {
                $done++;
            }
        }

        $labels = [
            'approve' => 'approved',
            'reject'  => 'rejected',
            'resend'  => 'queued for resend',
            'delete'  => 'deleted',
        ];
        $msg = sprintf('%d submission(s) %s.', $done, $labels[$do] ?? 'updated');
        if ($errors) $msg .= ' Problems: ' . implode(' ', array_slice($errors, 0, 3));

        // Deliver approvals promptly rather than waiting for the next tick.
        if (in_array($do, ['approve', 'reject', 'resend'], true) && $done) {
            SNN_T_Mailer::process_queue();
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=snn-tickets-submissions');
        wp_safe_redirect(add_query_arg('snn_msg', rawurlencode($msg), remove_query_arg('snn_msg', $back)));
        exit;
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
        check_admin_referer('snn_submissions_export');

        global $wpdb;
        $table   = SNN_T_DB::submissions();
        $form_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;

        $sql = "SELECT * FROM {$table}";
        if ($form_id) $sql = $wpdb->prepare($sql . " WHERE form_id = %d", $form_id);
        $sql .= " ORDER BY id ASC";

        $rows = $wpdb->get_results($sql);

        // Collect every custom field key across the export.
        $keys = [];
        foreach ($rows as $r) {
            $d = json_decode((string)$r->data, true);
            if (is_array($d)) {
                foreach (array_keys($d) as $k) $keys[$k] = true;
            }
        }
        $keys = array_keys($keys);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=snn-submissions-' . date('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, array_merge(
            ['ID', 'Form', 'Status', 'Name', 'Email', 'Ticket ID', 'Submitted', 'Decided'],
            $keys
        ));

        $tickets = SNN_T_DB::tickets();
        foreach ($rows as $r) {
            $d = json_decode((string)$r->data, true);
            $d = is_array($d) ? $d : [];

            $code = $r->ticket_id
                ? (string)$wpdb->get_var($wpdb->prepare("SELECT ticket_code FROM {$tickets} WHERE id = %d", (int)$r->ticket_id))
                : '';

            $line = [$r->id, $r->form_id, $r->status, $r->name, $r->email, $code, $r->created_at, $r->decided_at];
            foreach ($keys as $k) {
                $v = $d[$k] ?? '';
                $line[] = is_array($v) ? implode(' | ', $v) : $v;
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }

    /* ------------------------------------------------------------------
     * Review screen
     * ---------------------------------------------------------------- */

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

        global $wpdb;
        $table = SNN_T_DB::submissions();
        $forms = SNN_T_Forms::all();

        $status  = isset($_GET['status'])  ? sanitize_key(wp_unslash($_GET['status'])) : 'pending';
        $form_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;
        $paged   = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
        $per     = 25;

        $where = ['1=1'];
        $args  = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where[] = 'status = %s';
            $args[]  = $status;
        }
        if ($form_id) {
            $where[] = 'form_id = %d';
            $args[]  = $form_id;
        }
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int)$wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);

        $list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($args, [$per, ($paged - 1) * $per])));

        $counts     = self::counts($form_id);
        $form_names = [];
        foreach ($forms as $f) $form_names[(int)$f->id] = $f->name;

        $tickets_table = SNN_T_DB::tickets();
        ?>
        <div class="wrap">
            <h1>Submissions</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['snn_msg'])); ?></p></div>
            <?php endif; ?>

            <ul class="subsubsub">
                <?php
                $tabs = [
                    'pending'  => 'Pending (' . $counts['pending'] . ')',
                    'approved' => 'Approved (' . $counts['approved'] . ')',
                    'rejected' => 'Rejected (' . $counts['rejected'] . ')',
                    'all'      => 'All',
                ];
                $i = 0;
                foreach ($tabs as $key => $label):
                    $url = add_query_arg(['page' => 'snn-tickets-submissions', 'status' => $key, 'form_id' => $form_id], admin_url('admin.php'));
                    ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
                            <?php echo esc_html($label); ?>
                        </a><?php echo (++$i < count($tabs)) ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="page" value="snn-tickets-submissions">
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <select name="form_id">
                    <option value="0">All forms</option>
                    <?php foreach ($forms as $f): ?>
                        <option value="<?php echo (int)$f->id; ?>" <?php selected($form_id, (int)$f->id); ?>>
                            <?php echo esc_html($f->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Filter</button>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                        'action'  => 'snn_submissions_export',
                        'form_id' => $form_id,
                   ], admin_url('admin-post.php')), 'snn_submissions_export')); ?>">Export CSV</a>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="snn_submission_action">
                <?php wp_nonce_field('snn_submission_action'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_do">
                            <option value="">Bulk action</option>
                            <option value="approve">Approve &amp; send ticket</option>
                            <option value="reject">Reject</option>
                            <option value="resend">Resend ticket</option>
                            <option value="delete">Delete permanently</option>
                        </select>
                        <button class="button" onclick="return snnConfirmBulk(this.form)">Apply</button>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo (int)$total; ?> item(s)</span>
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => max(1, (int)ceil($total / $per)),
                            'prev_text' => '‹',
                            'next_text' => '›',
                        ]);
                        ?>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" onclick="snnToggleAll(this)"></td>
                            <th style="width:60px;">ID</th>
                            <th>Name / Email</th>
                            <th>Form</th>
                            <th style="width:90px;">Status</th>
                            <th>Ticket</th>
                            <th>Answers</th>
                            <th style="width:150px;">Submitted</th>
                            <th style="width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="9">No submissions here yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r):
                        $data = json_decode((string)$r->data, true);
                        $data = is_array($data) ? $data : [];
                        $code = $r->ticket_id
                            ? (string)$wpdb->get_var($wpdb->prepare("SELECT ticket_code FROM {$tickets_table} WHERE id = %d", (int)$r->ticket_id))
                            : '';
                        $badge = ['pending' => '#dba617', 'approved' => '#0a7d32', 'rejected' => '#b3261e'][$r->status] ?? '#666';
                        ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int)$r->id; ?>"></th>
                            <td><?php echo (int)$r->id; ?></td>
                            <td>
                                <strong><?php echo esc_html($r->name ?: '—'); ?></strong><br>
                                <a href="mailto:<?php echo esc_attr($r->email); ?>"><?php echo esc_html($r->email ?: '—'); ?></a>
                            </td>
                            <td><?php echo esc_html($form_names[(int)$r->form_id] ?? ('#' . (int)$r->form_id)); ?></td>
                            <td><span style="color:<?php echo esc_attr($badge); ?>;font-weight:600;"><?php echo esc_html($r->status); ?></span></td>
                            <td>
                                <?php if ($code): ?>
                                    <code><?php echo esc_html($code); ?></code><br>
                                    <a href="<?php echo esc_url(SNN_T_QR::url($code)); ?>" target="_blank" rel="noopener">QR</a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($data): ?>
                                    <details>
                                        <summary style="cursor:pointer;"><?php echo count($data); ?> field(s)</summary>
                                        <dl style="margin:6px 0 0;font-size:12px;">
                                        <?php foreach ($data as $k => $v): ?>
                                            <dt style="font-weight:600;"><?php echo esc_html($k); ?></dt>
                                            <dd style="margin:0 0 4px;"><?php echo esc_html(is_array($v) ? implode(', ', $v) : (string)$v); ?></dd>
                                        <?php endforeach; ?>
                                        </dl>
                                    </details>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?php echo esc_html($r->created_at); ?></td>
                            <td>
                                <?php if ($r->status !== 'approved'): ?>
                                    <button class="button button-primary button-small" name="do" value="approve"
                                            onclick="return snnRowAction(this, <?php echo (int)$r->id; ?>)">Approve</button>
                                <?php else: ?>
                                    <button class="button button-small" name="do" value="resend"
                                            onclick="return snnRowAction(this, <?php echo (int)$r->id; ?>)">Resend</button>
                                <?php endif; ?>
                                <?php if ($r->status !== 'rejected'): ?>
                                    <button class="button button-small" name="do" value="reject"
                                            onclick="return snnRowAction(this, <?php echo (int)$r->id; ?>)">Reject</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <input type="hidden" name="submission_id" value="0">
            </form>
        </div>

        <script>
        function snnToggleAll(box){
            box.closest('table').querySelectorAll('tbody input[type=checkbox]')
               .forEach(function(cb){ cb.checked = box.checked; });
        }
        function snnRowAction(btn, id){
            var form = btn.form;
            form.elements['submission_id'].value = id;
            // A row action applies to that row only.
            form.querySelectorAll('tbody input[type=checkbox]:checked')
                .forEach(function(cb){ cb.checked = false; });
            if (btn.value === 'reject') return confirm('Reject this submission?');
            return true;
        }
        function snnConfirmBulk(form){
            var action = form.querySelector('select[name=bulk_do]').value;
            if (!action) { alert('Pick a bulk action first.'); return false; }
            var n = form.querySelectorAll('tbody input[type=checkbox]:checked').length;
            if (!n) { alert('Select at least one submission.'); return false; }
            if (action === 'delete') {
                return confirm('Permanently delete ' + n + ' submission(s) and their tickets? This cannot be undone.');
            }
            return confirm(action + ' ' + n + ' submission(s)?');
        }
        </script>
        <?php
    }
}
