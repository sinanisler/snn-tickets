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
                'list_id'       => (int)$form->list_id,
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
                'list_id'       => (int)$form->list_id,
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
        if ($ticket->status !== 'active') return new WP_Error('snn_sub_revoked', __('That ticket is revoked, so it was not re-sent.', 'snn-tickets'));

        return SNN_T_Mailer::enqueue_from_template('ticket', $form ? $form->settings['template_ticket'] : '', [
            'name'          => $submission->name,
            'email'         => $submission->email,
            'ticket_code'   => $ticket->ticket_code,
            'list_name'     => $form ? SNN_T_Forms::list_name($form->list_id) : '',
            'list_id'       => (int)$ticket->list_id,
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
            'approve' => __('approved', 'snn-tickets'),
            'reject'  => __('rejected', 'snn-tickets'),
            'resend'  => __('queued for resend', 'snn-tickets'),
            'delete'  => __('deleted', 'snn-tickets'),
        ];
        $msg = sprintf(__('%1$d submission(s) %2$s.', 'snn-tickets'), $done, $labels[$do] ?? __('updated', 'snn-tickets'));
        if ($errors) $msg .= ' Problems: ' . implode(' ', array_slice($errors, 0, 3));

        // Deliver approvals promptly rather than waiting for the next tick.
        if (in_array($do, ['approve', 'reject', 'resend'], true) && $done) {
            SNN_T_Mailer::process_queue();
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=snn-tickets-submissions');
        // A deleted submission has no detail page to return to.
        if ($do === 'delete') $back = remove_query_arg('submission', $back);
        wp_safe_redirect(add_query_arg('snn_msg', rawurlencode($msg), remove_query_arg(['snn_msg', 'snn_type'], $back)));
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

        $label_map = [];
        foreach (self::field_labels(SNN_T_Forms::all()) as $fl) $label_map += $fl;

        $out = fopen('php://output', 'w');
        fwrite($out, "ï»¿"); // Excel needs the BOM to read UTF-8
        fputcsv($out, array_merge(
            ['ID', 'Form', 'Status', 'Name', 'Email', 'Ticket code', 'Submitted', 'Decided'],
            array_map(function ($k) use ($label_map) { return $label_map[$k] ?? $k; }, $keys)
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

    /** key => label for every field of every form, for readable answers. */
    public static function field_labels($forms) {
        $out = [];
        foreach ($forms as $f) {
            foreach ($f->fields as $field) {
                $out[(int)$f->id][$field['key']] = $field['label'];
            }
        }
        return $out;
    }

    private static function answer($v) {
        if (is_array($v)) return implode(', ', $v);
        if ($v === '1') return '✓';
        return (string)$v;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions', 'snn-tickets'));

        if (!empty($_GET['submission'])) {
            self::render_detail((int)$_GET['submission']);
            return;
        }

        global $wpdb;
        $table = SNN_T_DB::submissions();
        $forms = SNN_T_Forms::all();

        $status  = isset($_GET['status'])  ? sanitize_key(wp_unslash($_GET['status'])) : 'pending';
        $form_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;
        $search  = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged   = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
        $per     = 25;

        $where = ['1=1'];
        $args  = [];
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where[] = 's.status = %s';
            $args[]  = $status;
        }
        if ($form_id) {
            $where[] = 's.form_id = %d';
            $args[]  = $form_id;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(s.name LIKE %s OR s.email LIKE %s OR s.data LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} s WHERE {$where_sql}";
        $total = (int)$wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);

        $tickets_table = SNN_T_DB::tickets();
        $list_sql = "SELECT s.*, t.ticket_code, t.validate_count FROM {$table} s LEFT JOIN {$tickets_table} t ON t.id = s.ticket_id
                     WHERE {$where_sql} ORDER BY s.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($args, [$per, ($paged - 1) * $per])));

        $counts     = self::counts($form_id);
        $form_names = [];
        foreach ($forms as $f) $form_names[(int)$f->id] = $f->name;
        $labels = self::field_labels($forms);
        $base = admin_url('admin.php?page=snn-tickets-submissions');
        ?>
        <div class="wrap snn-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Submissions', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::notice(); ?>

            <ul class="subsubsub">
                <?php
                $tabs = [
                    'pending'  => sprintf(__('Pending (%d)', 'snn-tickets'), $counts['pending']),
                    'approved' => sprintf(__('Approved (%d)', 'snn-tickets'), $counts['approved']),
                    'rejected' => sprintf(__('Rejected (%d)', 'snn-tickets'), $counts['rejected']),
                    'all'      => __('All', 'snn-tickets'),
                ];
                $i = 0;
                foreach ($tabs as $key => $label):
                    $url = add_query_arg(['status' => $key, 'form_id' => $form_id, 's' => $search], $base); ?>
                    <li><a href="<?php echo esc_url($url); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html($label); ?></a><?php echo (++$i < count($tabs)) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <form method="get" class="snn-toolbar" style="clear:both;padding-top:10px">
                <input type="hidden" name="page" value="snn-tickets-submissions">
                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <select name="form_id" onchange="this.form.submit()">
                    <option value="0"><?php esc_html_e('All forms', 'snn-tickets'); ?></option>
                    <?php foreach ($forms as $f): ?>
                        <option value="<?php echo (int)$f->id; ?>" <?php selected($form_id, (int)$f->id); ?>><?php echo esc_html($f->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Name, email or answer', 'snn-tickets'); ?>">
                <button class="button"><?php esc_html_e('Search', 'snn-tickets'); ?></button>
                <span class="spacer"></span>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'snn_submissions_export', 'form_id' => $form_id], admin_url('admin-post.php')), 'snn_submissions_export')); ?>"><?php esc_html_e('Export CSV', 'snn-tickets'); ?></a>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-subs-form">
                <input type="hidden" name="action" value="snn_submission_action">
                <?php wp_nonce_field('snn_submission_action'); ?>
                <input type="hidden" name="submission_id" value="0">

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_do">
                            <option value=""><?php esc_html_e('Bulk actions', 'snn-tickets'); ?></option>
                            <option value="approve"><?php esc_html_e('Approve & send ticket', 'snn-tickets'); ?></option>
                            <option value="reject"><?php esc_html_e('Reject', 'snn-tickets'); ?></option>
                            <option value="resend"><?php esc_html_e('Resend ticket', 'snn-tickets'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete permanently', 'snn-tickets'); ?></option>
                        </select>
                        <button class="button" data-bulk><?php esc_html_e('Apply', 'snn-tickets'); ?></button>
                        <span class="snn-muted" data-selected></span>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html(_n('%s item', '%s items', $total, 'snn-tickets')), number_format_i18n($total)); ?></span>
                        <?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => max(1, (int)ceil($total / $per)), 'prev_text' => '‹', 'next_text' => '›']); ?>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column check-column"><input type="checkbox" data-all aria-label="<?php esc_attr_e('Select all', 'snn-tickets'); ?>"></td>
                            <th><?php esc_html_e('Person', 'snn-tickets'); ?></th>
                            <th style="width:160px;"><?php esc_html_e('Form', 'snn-tickets'); ?></th>
                            <th style="width:100px;"><?php esc_html_e('Status', 'snn-tickets'); ?></th>
                            <th style="width:140px;"><?php esc_html_e('Ticket', 'snn-tickets'); ?></th>
                            <th><?php esc_html_e('Answers', 'snn-tickets'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Submitted', 'snn-tickets'); ?></th>
                            <th style="width:170px;"><?php esc_html_e('Actions', 'snn-tickets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8"><?php echo $search !== '' ? esc_html__('No submissions match.', 'snn-tickets') : esc_html__('No submissions here yet.', 'snn-tickets'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r):
                        $data = json_decode((string)$r->data, true);
                        $data = is_array($data) ? $data : [];
                        $lab  = $labels[(int)$r->form_id] ?? [];
                        $detail = add_query_arg('submission', (int)$r->id, $base);
                        // Name and email already show in the first column.
                        $extra = array_filter($data, function ($v, $k) use ($r, $data) {
                            $s = is_array($v) ? implode(',', $v) : (string)$v;
                            return $s !== '' && $s !== $r->name && $s !== $r->email;
                        }, ARRAY_FILTER_USE_BOTH);
                        ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int)$r->id; ?>"></th>
                            <td>
                                <strong><a href="<?php echo esc_url($detail); ?>"><?php echo esc_html($r->name ?: '—'); ?></a></strong><br>
                                <span class="snn-muted"><?php echo esc_html($r->email ?: '—'); ?></span>
                            </td>
                            <td><?php echo esc_html($form_names[(int)$r->form_id] ?? ('#' . (int)$r->form_id)); ?></td>
                            <td><?php echo SNN_T_Admin::status_badge($r->status); ?></td>
                            <td>
                                <?php if ($r->ticket_code): ?>
                                    <code><?php echo esc_html($r->ticket_code); ?></code>
                                    <?php if ((int)$r->validate_count > 0): ?><br><span class="snn-badge ok"><?php esc_html_e('checked in', 'snn-tickets'); ?></span><?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td style="font-size:12px">
                                <?php $shown = 0; foreach ($extra as $k => $v): if ($shown++ >= 3) break; ?>
                                    <span class="snn-muted"><?php echo esc_html($lab[$k] ?? $k); ?>:</span> <?php echo esc_html(wp_trim_words(self::answer($v), 10)); ?><br>
                                <?php endforeach; ?>
                                <?php if (count($extra) > 3): ?><a href="<?php echo esc_url($detail); ?>"><?php printf(esc_html__('+%d more', 'snn-tickets'), count($extra) - 3); ?></a><?php endif; ?>
                                <?php if (!$extra): ?><span class="snn-muted">—</span><?php endif; ?>
                            </td>
                            <td style="font-size:12px"><?php echo SNN_T_Admin::when($r->created_at); ?></td>
                            <td>
                                <div class="snn-actions">
                                <?php if ($r->status !== 'approved'): ?>
                                    <button class="button button-primary button-small" name="do" value="approve" data-row="<?php echo (int)$r->id; ?>"><?php esc_html_e('Approve', 'snn-tickets'); ?></button>
                                <?php else: ?>
                                    <button class="button button-small" name="do" value="resend" data-row="<?php echo (int)$r->id; ?>"><?php esc_html_e('Resend', 'snn-tickets'); ?></button>
                                <?php endif; ?>
                                <?php if ($r->status !== 'rejected'): ?>
                                    <button class="button button-small" name="do" value="reject" data-row="<?php echo (int)$r->id; ?>"><?php esc_html_e('Reject', 'snn-tickets'); ?></button>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php self::actions_script(); ?>
        <?php
    }

    private static function actions_script() {
        ?>
        <script>
        (function(){
            var form = document.getElementById('snn-subs-form');
            if (!form) return;
            var T = <?php echo wp_json_encode([
                'selected' => __('%d selected', 'snn-tickets'),
                'pick'     => __('Pick a bulk action first.', 'snn-tickets'),
                'none'     => __('Select at least one submission.', 'snn-tickets'),
                'reject1'  => __('Reject this submission?', 'snn-tickets'),
                'delete1'  => __('Permanently delete this submission and its ticket? This cannot be undone.', 'snn-tickets'),
                'bulk'     => [
                    'approve' => __('Approve %d submission(s) and send their tickets?', 'snn-tickets'),
                    'reject'  => __('Reject %d submission(s)?', 'snn-tickets'),
                    'resend'  => __('Resend the ticket email for %d submission(s)?', 'snn-tickets'),
                    'delete'  => __('Permanently delete %d submission(s) and their tickets? This cannot be undone.', 'snn-tickets'),
                ],
            ]); ?>;
            var all = form.querySelector('[data-all]'), counter = form.querySelector('[data-selected]');
            function boxes(){ return form.querySelectorAll('tbody input[type=checkbox]'); }
            function update(){ if (!counter) return; var n = form.querySelectorAll('tbody input[type=checkbox]:checked').length; counter.textContent = n ? T.selected.replace('%d', n) : ''; }
            if (all) all.addEventListener('change', function(){ boxes().forEach(function(b){ b.checked = all.checked; }); update(); });
            form.addEventListener('change', function(e){ if (e.target.name === 'ids[]') update(); });
            form.addEventListener('click', function(e){
                var row = e.target.closest('[data-row]');
                if (row) {
                    form.elements.submission_id.value = row.getAttribute('data-row');
                    boxes().forEach(function(b){ b.checked = false; });
                    if (row.value === 'reject' && !confirm(T.reject1)) e.preventDefault();
                    if (row.value === 'delete' && !confirm(T.delete1)) e.preventDefault();
                    return;
                }
                if (e.target.closest('[data-bulk]')) {
                    form.elements.submission_id.value = 0;
                    var act = form.elements.bulk_do.value;
                    var n = form.querySelectorAll('tbody input[type=checkbox]:checked').length;
                    if (!act) { alert(T.pick); e.preventDefault(); return; }
                    if (!n) { alert(T.none); e.preventDefault(); return; }
                    if (!confirm(T.bulk[act].replace('%d', n))) e.preventDefault();
                }
            });
        })();
        </script>
        <?php
    }

    private static function render_detail($id) {
        global $wpdb;
        $s    = self::get($id);
        $back = admin_url('admin.php?page=snn-tickets-submissions&status=all');
        ?>
        <div class="wrap snn-wrap">
            <h1><a href="<?php echo esc_url($back); ?>" class="dashicons dashicons-arrow-left-alt" style="text-decoration:none" aria-label="<?php esc_attr_e('Back', 'snn-tickets'); ?>"></a>
                <?php echo $s ? esc_html($s->name ?: $s->email ?: sprintf(__('Submission #%d', 'snn-tickets'), $id)) : esc_html__('Submission', 'snn-tickets'); ?>
                <?php if ($s) echo SNN_T_Admin::status_badge($s->status); ?></h1>
            <?php SNN_T_Admin::notice(); ?>
            <?php if (!$s): ?>
                <p><?php esc_html_e('That submission no longer exists.', 'snn-tickets'); ?></p></div>
                <?php return; endif;

            $form   = SNN_T_Forms::get($s->form_id);
            $ticket = $s->ticket_id ? SNN_T_Tickets::get($s->ticket_id) : null;
            $labels = $form ? self::field_labels([$form])[(int)$form->id] : [];
            $mails  = $wpdb->get_results($wpdb->prepare(
                "SELECT id, role, status, subject, sent_at, scheduled_at, last_error FROM " . SNN_T_DB::queue() . " WHERE submission_id = %d ORDER BY id DESC", $id));
            $decider = $s->decided_by ? get_userdata((int)$s->decided_by) : null;
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-subs-form" class="snn-actions" style="margin:12px 0 18px">
                <input type="hidden" name="action" value="snn_submission_action">
                <input type="hidden" name="submission_id" value="<?php echo (int)$id; ?>">
                <?php wp_nonce_field('snn_submission_action'); ?>
                <?php if ($s->status !== 'approved'): ?>
                    <button class="button button-primary" name="do" value="approve" data-row="<?php echo (int)$id; ?>"><?php esc_html_e('Approve & send ticket', 'snn-tickets'); ?></button>
                <?php else: ?>
                    <button class="button" name="do" value="resend" data-row="<?php echo (int)$id; ?>"><?php esc_html_e('Resend ticket', 'snn-tickets'); ?></button>
                <?php endif; ?>
                <?php if ($s->status !== 'rejected'): ?>
                    <button class="button" name="do" value="reject" data-row="<?php echo (int)$id; ?>"><?php esc_html_e('Reject', 'snn-tickets'); ?></button>
                <?php endif; ?>
                <input type="text" name="reason" class="regular-text" placeholder="<?php esc_attr_e('Note (optional, kept with the decision)', 'snn-tickets'); ?>">
                <button class="button button-link-delete" name="do" value="delete" data-row="<?php echo (int)$id; ?>"><?php esc_html_e('Delete permanently', 'snn-tickets'); ?></button>
            </form>

            <div class="snn-grid snn-grid-side">
                <div>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Answers', 'snn-tickets'); ?></h2>
                        <dl class="snn-dl">
                            <?php foreach ($s->data as $k => $v): ?>
                                <dt><?php echo esc_html($labels[$k] ?? $k); ?></dt>
                                <dd><?php echo self::answer($v) !== '' ? nl2br(esc_html(self::answer($v))) : '<span class="snn-muted">—</span>'; ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Emails', 'snn-tickets'); ?></h2>
                        <?php if (!$mails): ?><p class="snn-muted"><?php esc_html_e('No emails for this submission.', 'snn-tickets'); ?></p><?php else: ?>
                        <table class="widefat striped">
                            <tbody>
                            <?php foreach ($mails as $m): ?>
                                <tr><td style="width:90px"><?php echo SNN_T_Admin::status_badge($m->status); ?></td>
                                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-queue&view=' . (int)$m->id)); ?>"><?php echo esc_html($m->subject); ?></a>
                                        <?php if ($m->last_error): ?><br><span style="color:#b3261e;font-size:12px"><?php echo esc_html($m->last_error); ?></span><?php endif; ?></td>
                                    <td style="width:130px;font-size:12px"><?php echo SNN_T_Admin::when($m->sent_at ?: $m->scheduled_at); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Ticket', 'snn-tickets'); ?></h2>
                        <?php if ($ticket): ?>
                            <p style="text-align:center"><img src="<?php echo esc_attr(SNN_T_QR::data_uri($ticket->ticket_code, 4, 2)); ?>" alt="" width="160" height="160"><br>
                                <code style="font-size:14px"><?php echo esc_html($ticket->ticket_code); ?></code></p>
                            <p><?php echo SNN_T_Admin::status_badge($ticket->status); ?>
                                <?php if ((int)$ticket->validate_count > 0): ?><span class="snn-badge ok"><?php printf(esc_html__('checked in %s', 'snn-tickets'), wp_strip_all_tags(SNN_T_Admin::when($ticket->last_validated))); ?></span>
                                <?php else: ?><span class="snn-badge"><?php esc_html_e('not checked in', 'snn-tickets'); ?></span><?php endif; ?></p>
                            <p class="snn-actions">
                                <a class="button button-small" target="_blank" href="<?php echo esc_url(SNN_T_Files::url('view', $ticket->ticket_code)); ?>"><?php esc_html_e('Ticket page', 'snn-tickets'); ?></a>
                                <a class="button button-small" target="_blank" href="<?php echo esc_url(SNN_T_Files::url('pdf', $ticket->ticket_code)); ?>">PDF</a>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-lists&list=' . (int)$ticket->list_id . '&s=' . rawurlencode($ticket->ticket_code))); ?>"><?php esc_html_e('In event list', 'snn-tickets'); ?></a>
                            </p>
                        <?php else: ?>
                            <p class="snn-muted"><?php esc_html_e('No ticket yet — it is created on approval.', 'snn-tickets'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Details', 'snn-tickets'); ?></h2>
                        <dl class="snn-dl" style="grid-template-columns:100px 1fr">
                            <dt><?php esc_html_e('Form', 'snn-tickets'); ?></dt><dd><?php echo $form ? '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-forms&action=edit&form=' . (int)$form->id)) . '">' . esc_html($form->name) . '</a>' : '—'; ?></dd>
                            <dt><?php esc_html_e('Submitted', 'snn-tickets'); ?></dt><dd><?php echo SNN_T_Admin::when($s->created_at); ?></dd>
                            <dt><?php esc_html_e('Decided', 'snn-tickets'); ?></dt><dd><?php echo SNN_T_Admin::when($s->decided_at); ?><?php if ($decider): ?><br><span class="snn-muted"><?php echo esc_html($decider->display_name); ?></span><?php endif; ?></dd>
                            <?php if ($s->decision_reason): ?><dt><?php esc_html_e('Note', 'snn-tickets'); ?></dt><dd><?php echo esc_html($s->decision_reason); ?></dd><?php endif; ?>
                            <dt>IP</dt><dd><?php echo esc_html($s->ip ?: '—'); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        <?php
        self::actions_script();
    }
}
