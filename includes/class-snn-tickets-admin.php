<?php
/**
 * Admin screens for events (ticket lists) and their tickets: overview,
 * per-list ticket table, event settings, generator, CSV import, CSV export
 * and emailing a whole list.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Tickets_Admin {

    const PER_PAGE = 50;

    public static function init() {
        add_action('admin_post_snn_generate_tickets', [__CLASS__, 'handle_generate']);
        add_action('admin_post_snn_import_csv',       [__CLASS__, 'handle_import_csv']);
        add_action('admin_post_snn_csv_template',     [__CLASS__, 'download_csv_template']);
        add_action('admin_post_snn_delete_list',      [__CLASS__, 'handle_delete_list']);
        add_action('admin_post_snn_save_event',       [__CLASS__, 'handle_save_event']);
        add_action('admin_post_snn_ticket_action',    [__CLASS__, 'handle_ticket_action']);
        add_action('admin_post_snn_export_list',      [__CLASS__, 'handle_export_list']);
        add_action('admin_post_snn_queue_list_emails',[__CLASS__, 'handle_queue_list_emails']);
        add_action('admin_post_snn_resend_ticket',    [__CLASS__, 'handle_resend_ticket']);
        add_action('admin_post_snn_ticket_qr',        [__CLASS__, 'handle_ticket_qr']);
        add_action('wp_ajax_snn_update_ticket_field', [__CLASS__, 'ajax_update_ticket_field']);
    }

    private static function url($args = []) {
        return add_query_arg(array_merge(['page' => 'snn-tickets-lists'], $args), admin_url('admin.php'));
    }

    private static function redirect($url, $msg, $error = false) {
        $args = ['snn_msg' => rawurlencode($msg)];
        if ($error) $args['snn_type'] = 'error';
        wp_safe_redirect(add_query_arg($args, remove_query_arg(['snn_msg', 'snn_type'], $url)));
        exit;
    }

    /* ==================================================================
     * Overview
     * ================================================================== */

    public static function render_lists() {
        SNN_T_Admin::cap();

        $list_id = isset($_GET['list']) ? (int)$_GET['list'] : 0;
        if ($list_id && !empty($_GET['edit'])) { self::render_event_form($list_id); return; }
        if ($list_id)                           { self::render_list($list_id); return; }

        global $wpdb;
        $lists = $wpdb->get_results("
            SELECT l.*,
                   COUNT(t.id) AS total_tickets,
                   SUM(CASE WHEN t.validate_count > 0 THEN 1 ELSE 0 END) AS checked_in,
                   SUM(CASE WHEN t.status = 'revoked' THEN 1 ELSE 0 END) AS revoked
            FROM " . SNN_T_DB::lists() . " l
            LEFT JOIN " . SNN_T_DB::tickets() . " t ON t.list_id = l.id
            GROUP BY l.id
            ORDER BY l.id DESC
        ");

        $forms_by_list = [];
        foreach (SNN_T_Forms::all() as $f) $forms_by_list[(int)$f->list_id][] = $f;
        $types = SNN_T_Events::attachment_types();
        ?>
        <div class="wrap snn-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Events & Tickets', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::tabs('tickets', 'snn-tickets-lists'); ?>
            <?php SNN_T_Admin::notice(); ?>

            <?php if (!$lists): ?>
                <div class="snn-card snn-empty-state">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <h2><?php esc_html_e('No events yet', 'snn-tickets'); ?></h2>
                    <p class="snn-muted"><?php esc_html_e('Each event has its own ticket list. Build a registration form and one is created for you, or add tickets directly.', 'snn-tickets'); ?></p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=new')); ?>"><?php esc_html_e('Build a registration form', 'snn-tickets'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-generator')); ?>"><?php esc_html_e('Generate tickets', 'snn-tickets'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-csv-import')); ?>"><?php esc_html_e('Import from CSV', 'snn-tickets'); ?></a>
                    </p>
                </div>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Event', 'snn-tickets'); ?></th>
                    <th style="width:190px"><?php esc_html_e('When & where', 'snn-tickets'); ?></th>
                    <th style="width:90px"><?php esc_html_e('Tickets', 'snn-tickets'); ?></th>
                    <th style="width:170px"><?php esc_html_e('Checked in', 'snn-tickets'); ?></th>
                    <th style="width:150px"><?php esc_html_e('Sends with ticket', 'snn-tickets'); ?></th>
                    <th style="width:250px"><?php esc_html_e('Actions', 'snn-tickets'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($lists as $l):
                    $l = SNN_T_Events::normalise($l);
                    $active = (int)$l->total_tickets - (int)$l->revoked;
                    $pct = $active ? min(100, round(100 * (int)$l->checked_in / $active)) : 0;
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url(self::url(['list' => $l->id])); ?>"><?php echo esc_html($l->name); ?></a></strong>
                            <?php foreach ($forms_by_list[(int)$l->id] ?? [] as $f): ?>
                                <br><span class="snn-muted" style="font-size:12px"><?php esc_html_e('Form:', 'snn-tickets'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=edit&form=' . (int)$f->id)); ?>"><?php echo esc_html($f->name); ?></a></span>
                            <?php endforeach; ?>
                        </td>
                        <td style="font-size:12px">
                            <?php if ($l->event_start): ?><?php echo esc_html(SNN_T_Events::format_date($l)); ?><br><span class="snn-muted"><?php echo esc_html(SNN_T_Events::format_time($l)); ?></span>
                            <?php else: ?><a href="<?php echo esc_url(self::url(['list' => $l->id, 'edit' => 1])); ?>"><?php esc_html_e('Add date & venue', 'snn-tickets'); ?></a><?php endif; ?>
                            <?php if ($l->venue): ?><br><span class="snn-muted"><?php echo esc_html($l->venue); ?></span><?php endif; ?>
                        </td>
                        <td><?php echo number_format_i18n((int)$l->total_tickets); ?><?php if ($l->revoked): ?><br><span class="snn-muted" style="font-size:11px"><?php printf(esc_html__('%d revoked', 'snn-tickets'), (int)$l->revoked); ?></span><?php endif; ?></td>
                        <td>
                            <div class="snn-progress" title="<?php echo esc_attr($pct . '%'); ?>"><i style="width:<?php echo (int)$pct; ?>%"></i></div>
                            <span class="snn-muted" style="font-size:12px"><?php echo (int)$l->checked_in; ?> / <?php echo (int)$active; ?></span>
                        </td>
                        <td style="font-size:12px">
                            <?php echo $l->attachment_list
                                ? esc_html(implode(', ', array_map(function ($a) use ($types) { return $types[$a]; }, $l->attachment_list)))
                                : '<span class="snn-muted">' . esc_html__('Email only', 'snn-tickets') . '</span>'; ?>
                        </td>
                        <td>
                            <div class="snn-actions">
                                <a class="button button-small button-primary" href="<?php echo esc_url(self::url(['list' => $l->id])); ?>"><?php esc_html_e('Tickets', 'snn-tickets'); ?></a>
                                <a class="button button-small" href="<?php echo esc_url(self::url(['list' => $l->id, 'edit' => 1])); ?>"><?php esc_html_e('Event settings', 'snn-tickets'); ?></a>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-mailer&list_id=' . (int)$l->id)); ?>"><?php esc_html_e('Email', 'snn-tickets'); ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ==================================================================
     * One list
     * ================================================================== */

    private static function render_list($list_id) {
        global $wpdb;
        $event = SNN_T_Events::get($list_id);
        if (!$event) {
            echo '<div class="wrap"><p>' . esc_html__('That event no longer exists.', 'snn-tickets') . '</p></div>';
            return;
        }

        $table  = SNN_T_DB::tickets();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filter = isset($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : 'all';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));

        $where = ['list_id = %d'];
        $args  = [$list_id];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s OR ticket_code LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $filters = [
            'all'     => __('All', 'snn-tickets'),
            'in'      => __('Checked in', 'snn-tickets'),
            'out'     => __('Not checked in', 'snn-tickets'),
            'revoked' => __('Revoked', 'snn-tickets'),
        ];
        if ($filter === 'in')      $where[] = "validate_count > 0 AND status = 'active'";
        if ($filter === 'out')     $where[] = "validate_count = 0 AND status = 'active'";
        if ($filter === 'revoked') $where[] = "status = 'revoked'";
        $where = implode(' AND ', $where);

        $total   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $args));
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($args, [self::PER_PAGE, ($paged - 1) * self::PER_PAGE])
        ));

        $stats = SNN_T_Scanner::stats($list_id);
        $sent  = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT q.ticket_id) FROM " . SNN_T_DB::queue() . " q JOIN {$table} t ON t.id = q.ticket_id WHERE t.list_id = %d AND q.role = 'ticket' AND q.status = 'sent'", $list_id));

        $base = self::url(['list' => $list_id]);
        ?>
        <div class="wrap snn-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html($event->name); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(self::url(['list' => $list_id, 'edit' => 1])); ?>"><?php esc_html_e('Event settings', 'snn-tickets'); ?></a>
            <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-generator&list_id=' . $list_id)); ?>"><?php esc_html_e('Add tickets', 'snn-tickets'); ?></a>
            <p class="snn-muted" style="margin:4px 0 0">
                <a href="<?php echo esc_url(self::url()); ?>">&larr; <?php esc_html_e('All events', 'snn-tickets'); ?></a>
                <?php if ($when = SNN_T_Events::format_when($event)): ?> · <?php echo esc_html($when); ?><?php endif; ?>
                <?php if ($where_txt = SNN_T_Events::format_where($event)): ?> · <?php echo esc_html($where_txt); ?><?php endif; ?>
            </p>
            <?php SNN_T_Admin::notice(); ?>

            <div class="snn-stats" style="margin-top:16px">
                <div class="snn-stat"><div class="v"><?php echo number_format_i18n($stats['total']); ?></div><div class="l"><?php esc_html_e('Active tickets', 'snn-tickets'); ?></div></div>
                <div class="snn-stat ok"><div class="v"><?php echo number_format_i18n($stats['checked_in']); ?></div><div class="l"><?php esc_html_e('Checked in', 'snn-tickets'); ?></div>
                    <div class="snn-progress" style="margin-top:6px"><i style="width:<?php echo $stats['total'] ? (int)round(100 * $stats['checked_in'] / $stats['total']) : 0; ?>%"></i></div></div>
                <div class="snn-stat"><div class="v"><?php echo number_format_i18n($sent); ?></div><div class="l"><?php esc_html_e('Ticket emails sent', 'snn-tickets'); ?></div></div>
                <div class="snn-stat"><div class="l" style="margin:0 0 6px"><?php esc_html_e('Scanner for this event', 'snn-tickets'); ?></div><?php echo SNN_T_Admin::copy_code('[tickets_scan_page list="' . $list_id . '"]'); ?></div>
            </div>

            <div class="snn-toolbar">
                <ul class="subsubsub" style="margin:0">
                    <?php $i = 0; foreach ($filters as $k => $label): ?>
                        <li><a class="<?php echo $filter === $k ? 'current' : ''; ?>" href="<?php echo esc_url(add_query_arg(['filter' => $k, 's' => $search], $base)); ?>"><?php echo esc_html($label); ?></a><?php echo ++$i < count($filters) ? ' |' : ''; ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="spacer"></span>
                <form method="get" class="snn-actions">
                    <input type="hidden" name="page" value="snn-tickets-lists">
                    <input type="hidden" name="list" value="<?php echo (int)$list_id; ?>">
                    <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Name, email or code', 'snn-tickets'); ?>">
                    <button class="button"><?php esc_html_e('Search', 'snn-tickets'); ?></button>
                </form>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=snn_export_list&list_id=' . $list_id), 'snn_export_list')); ?>"><?php esc_html_e('Export CSV', 'snn-tickets'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-mailer&list_id=' . $list_id)); ?>"><?php esc_html_e('Email this list', 'snn-tickets'); ?></a>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-tickets-form">
                <input type="hidden" name="action" value="snn_ticket_action">
                <input type="hidden" name="list_id" value="<?php echo (int)$list_id; ?>">
                <input type="hidden" name="ticket_id" value="0">
                <?php wp_nonce_field('snn_ticket_action'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_do">
                            <option value=""><?php esc_html_e('Bulk actions', 'snn-tickets'); ?></option>
                            <option value="resend"><?php esc_html_e('Send ticket email', 'snn-tickets'); ?></option>
                            <option value="revoke"><?php esc_html_e('Revoke', 'snn-tickets'); ?></option>
                            <option value="restore"><?php esc_html_e('Restore', 'snn-tickets'); ?></option>
                            <option value="undo"><?php esc_html_e('Undo check-in', 'snn-tickets'); ?></option>
                            <option value="delete"><?php esc_html_e('Delete permanently', 'snn-tickets'); ?></option>
                        </select>
                        <button class="button" data-bulk><?php esc_html_e('Apply', 'snn-tickets'); ?></button>
                        <span class="snn-muted" data-selected></span>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html(_n('%s ticket', '%s tickets', $total, 'snn-tickets')), number_format_i18n($total)); ?></span>
                        <?php echo SNN_T_Admin::pager($total, self::PER_PAGE, $paged); ?>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped" id="snn-ticket-table"
                       data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>" data-update-nonce="<?php echo esc_attr(wp_create_nonce('snn_update_ticket')); ?>">
                    <thead><tr>
                        <td class="manage-column check-column"><input type="checkbox" data-all aria-label="<?php esc_attr_e('Select all', 'snn-tickets'); ?>"></td>
                        <th><?php esc_html_e('Name', 'snn-tickets'); ?></th>
                        <th><?php esc_html_e('Email', 'snn-tickets'); ?></th>
                        <th style="width:130px"><?php esc_html_e('Code', 'snn-tickets'); ?></th>
                        <th style="width:90px"><?php esc_html_e('Status', 'snn-tickets'); ?></th>
                        <th style="width:150px"><?php esc_html_e('Check-in', 'snn-tickets'); ?></th>
                        <th style="width:290px"><?php esc_html_e('Actions', 'snn-tickets'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$tickets): ?>
                        <tr><td colspan="7"><?php echo $search !== '' || $filter !== 'all' ? esc_html__('No tickets match.', 'snn-tickets') : esc_html__('No tickets in this event yet.', 'snn-tickets'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $t): ?>
                        <tr data-ticket-id="<?php echo (int)$t->id; ?>">
                            <th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int)$t->id; ?>"></th>
                            <td><span class="snn-inline-edit" contenteditable="true" data-field="name" data-original="<?php echo esc_attr($t->name); ?>" title="<?php esc_attr_e('Click to edit', 'snn-tickets'); ?>"><?php echo esc_html($t->name); ?></span></td>
                            <td><span class="snn-inline-edit" contenteditable="true" data-field="email" data-original="<?php echo esc_attr($t->email); ?>" title="<?php esc_attr_e('Click to edit', 'snn-tickets'); ?>"><?php echo esc_html($t->email); ?></span></td>
                            <td><code><?php echo esc_html($t->ticket_code); ?></code></td>
                            <td><?php echo SNN_T_Admin::status_badge($t->status); ?></td>
                            <td style="font-size:12px">
                                <?php if ((int)$t->validate_count > 0): ?>
                                    <span class="snn-badge ok">✓ <?php echo (int)$t->validate_count > 1 ? esc_html((int)$t->validate_count . '×') : ''; ?></span>
                                    <?php echo SNN_T_Admin::when($t->last_validated); ?>
                                <?php else: ?><span class="snn-muted">—</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="snn-actions">
                                    <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(SNN_T_Files::url('view', $t->ticket_code)); ?>"><?php esc_html_e('View', 'snn-tickets'); ?></a>
                                    <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(SNN_T_Files::url('pdf', $t->ticket_code)); ?>">PDF</a>
                                    <?php if ($t->email && $t->status === 'active'): ?>
                                        <button class="button button-small" name="do" value="resend" data-row="<?php echo (int)$t->id; ?>"><?php esc_html_e('Send', 'snn-tickets'); ?></button>
                                    <?php endif; ?>
                                    <?php if ((int)$t->validate_count > 0): ?>
                                        <button class="button button-small" name="do" value="undo" data-row="<?php echo (int)$t->id; ?>"><?php esc_html_e('Undo check-in', 'snn-tickets'); ?></button>
                                    <?php endif; ?>
                                    <?php if ($t->status === 'active'): ?>
                                        <button class="button button-small button-link-delete" name="do" value="revoke" data-row="<?php echo (int)$t->id; ?>"><?php esc_html_e('Revoke', 'snn-tickets'); ?></button>
                                    <?php else: ?>
                                        <button class="button button-small" name="do" value="restore" data-row="<?php echo (int)$t->id; ?>"><?php esc_html_e('Restore', 'snn-tickets'); ?></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total > self::PER_PAGE): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(esc_html(_n('%s ticket', '%s tickets', $total, 'snn-tickets')), number_format_i18n($total)); ?></span>
                        <?php echo SNN_T_Admin::pager($total, self::PER_PAGE, $paged); ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>

            <div class="snn-card" style="margin-top:24px;border-color:#f0c9c6">
                <h2 style="color:#b3261e"><?php esc_html_e('Delete this event', 'snn-tickets'); ?></h2>
                <p class="snn-muted"><?php esc_html_e('Removes the list, all its tickets and their queued emails. Forms that feed it are closed. This cannot be undone.', 'snn-tickets'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(sprintf(__('Delete "%s" and all %d tickets? This cannot be undone.', 'snn-tickets'), $event->name, SNN_T_Tickets::count_in_list($list_id)))); ?>);">
                    <input type="hidden" name="action" value="snn_delete_list">
                    <input type="hidden" name="list_id" value="<?php echo (int)$list_id; ?>">
                    <?php wp_nonce_field('snn_delete_list'); ?>
                    <button class="button button-link-delete"><?php esc_html_e('Delete event and tickets', 'snn-tickets'); ?></button>
                </form>
            </div>
        </div>

        <style>
        .snn-inline-edit{display:inline-block;min-width:120px;min-height:1.4em;padding:2px 4px;border-radius:3px;outline:none;border:1px dashed transparent}
        .snn-inline-edit:hover{border-color:#c3c4c7}
        .snn-inline-edit:focus{background:#f6f7f7;border-color:#2271b1}
        .snn-inline-edit.saving{opacity:.6}
        .snn-inline-edit.ok{background:#e7f5ea}
        .snn-inline-edit.err{background:#fbeaea}
        </style>
        <script>
        (function(){
            var form = document.getElementById('snn-tickets-form');
            var table = document.getElementById('snn-ticket-table');
            var all = form.querySelector('[data-all]');
            var counter = form.querySelector('[data-selected]');
            var T = <?php echo wp_json_encode([
                'selected' => __('%d selected', 'snn-tickets'),
                'pick'     => __('Pick a bulk action first.', 'snn-tickets'),
                'none'     => __('Select at least one ticket.', 'snn-tickets'),
                'confirm'  => [
                    'delete'  => __('Permanently delete %d ticket(s)? Their QR codes stop working. This cannot be undone.', 'snn-tickets'),
                    'revoke'  => __('Revoke %d ticket(s)? They will be refused at the door.', 'snn-tickets'),
                    'resend'  => __('Queue the ticket email for %d ticket(s)?', 'snn-tickets'),
                    'undo'    => __('Undo the check-in for %d ticket(s)?', 'snn-tickets'),
                    'restore' => __('Restore %d ticket(s)?', 'snn-tickets'),
                ],
                'invalid'  => __('Invalid email. Leave empty to clear.', 'snn-tickets'),
            ]); ?>;

            function boxes(){ return form.querySelectorAll('tbody input[type=checkbox]'); }
            function update(){
                var n = form.querySelectorAll('tbody input[type=checkbox]:checked').length;
                counter.textContent = n ? T.selected.replace('%d', n) : '';
            }
            all.addEventListener('change', function(){ boxes().forEach(function(b){ b.checked = all.checked; }); update(); });
            form.addEventListener('change', function(e){ if (e.target.name === 'ids[]') update(); });

            form.addEventListener('click', function(e){
                var row = e.target.closest('[data-row]');
                if (row) {
                    form.elements.ticket_id.value = row.getAttribute('data-row');
                    boxes().forEach(function(b){ b.checked = false; });
                    var msg = T.confirm[row.value];
                    if (row.value !== 'resend' && row.value !== 'undo' && row.value !== 'restore' && !confirm(msg.replace('%d', 1))) e.preventDefault();
                    return;
                }
                if (e.target.closest('[data-bulk]')) {
                    form.elements.ticket_id.value = 0;
                    var act = form.elements.bulk_do.value;
                    var n = form.querySelectorAll('tbody input[type=checkbox]:checked').length;
                    if (!act) { alert(T.pick); e.preventDefault(); return; }
                    if (!n) { alert(T.none); e.preventDefault(); return; }
                    if (!confirm(T.confirm[act].replace('%d', n))) e.preventDefault();
                }
            });

            // Inline edit of name and email.
            var ajax = table.getAttribute('data-ajax-url'), nonce = table.getAttribute('data-update-nonce');
            function clean(s){ return (s || '').replace(/[\r\n]+/g, ' ').trim(); }
            function save(el){
                var value = clean(el.textContent), original = el.getAttribute('data-original') || '';
                if (value === original) return;
                if (el.dataset.field === 'email' && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    el.classList.add('err'); el.title = T.invalid; el.textContent = original; return;
                }
                var fd = new FormData();
                fd.append('action', 'snn_update_ticket_field'); fd.append('nonce', nonce);
                fd.append('id', el.closest('tr').getAttribute('data-ticket-id'));
                fd.append('field', el.dataset.field); fd.append('value', value);
                el.classList.add('saving');
                fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
                    el.classList.remove('saving');
                    if (j && j.success) { el.textContent = j.data.value; el.setAttribute('data-original', j.data.value); el.classList.add('ok'); setTimeout(function(){ el.classList.remove('ok'); }, 1200); }
                    else { el.classList.add('err'); el.title = (j && j.data && j.data.message) || ''; el.textContent = original; }
                }).catch(function(){ el.classList.remove('saving'); el.classList.add('err'); el.textContent = original; });
            }
            table.addEventListener('keydown', function(e){
                var el = e.target.closest('.snn-inline-edit'); if (!el) return;
                if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
                if (e.key === 'Escape') { el.textContent = el.getAttribute('data-original') || ''; el.blur(); }
            });
            table.addEventListener('focusin', function(e){ var el = e.target.closest('.snn-inline-edit'); if (el) { el.classList.remove('err', 'ok'); el.title = ''; } });
            table.addEventListener('focusout', function(e){ var el = e.target.closest('.snn-inline-edit'); if (el) save(el); });
            table.addEventListener('paste', function(e){
                var el = e.target.closest('.snn-inline-edit'); if (!el) return;
                e.preventDefault();
                document.execCommand('insertText', false, clean((e.clipboardData || window.clipboardData).getData('text')));
            });
        })();
        </script>
        <?php
        SNN_T_Admin::email_tools_script_only();
    }

    /* ==================================================================
     * Event settings
     * ================================================================== */

    private static function render_event_form($list_id) {
        $event = SNN_T_Events::get($list_id);
        if (!$event) { echo '<div class="wrap"><p>' . esc_html__('That event no longer exists.', 'snn-tickets') . '</p></div>'; return; }

        $dt = function ($v) { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; };
        $presets = SNN_T_Design::presets();
        $site_preset = SNN_T_Design::settings()['preset'];
        ?>
        <div class="wrap snn-wrap">
            <h1><?php echo esc_html(sprintf(__('Event settings: %s', 'snn-tickets'), $event->name)); ?></h1>
            <p><a href="<?php echo esc_url(self::url(['list' => $list_id])); ?>">&larr; <?php esc_html_e('Back to tickets', 'snn-tickets'); ?></a></p>
            <?php SNN_T_Admin::notice(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="snn_save_event">
                <input type="hidden" name="list_id" value="<?php echo (int)$list_id; ?>">
                <?php wp_nonce_field('snn_save_event'); ?>

                <div class="snn-grid snn-grid-side">
                    <div class="snn-card">
                        <h2><?php esc_html_e('Details', 'snn-tickets'); ?></h2>
                        <p class="snn-muted"><?php esc_html_e('Shown on the ticket, in emails ({event_date}, {venue}…), in wallet passes and in the calendar invite.', 'snn-tickets'); ?></p>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="ev_name"><?php esc_html_e('Event name', 'snn-tickets'); ?></label></th>
                                <td><input id="ev_name" name="name" class="regular-text" required value="<?php echo esc_attr($event->name); ?>"></td></tr>
                            <tr><th><label for="ev_start"><?php esc_html_e('Starts', 'snn-tickets'); ?></label></th>
                                <td><input type="datetime-local" id="ev_start" name="event_start" value="<?php echo esc_attr($dt($event->event_start)); ?>"></td></tr>
                            <tr><th><label for="ev_end"><?php esc_html_e('Ends', 'snn-tickets'); ?></label></th>
                                <td><input type="datetime-local" id="ev_end" name="event_end" value="<?php echo esc_attr($dt($event->event_end)); ?>">
                                    <p class="description"><?php printf(esc_html__('Times are in the site timezone (%s).', 'snn-tickets'), esc_html(wp_timezone_string())); ?></p></td></tr>
                            <tr><th><label for="ev_venue"><?php esc_html_e('Venue', 'snn-tickets'); ?></label></th>
                                <td><input id="ev_venue" name="venue" class="regular-text" value="<?php echo esc_attr($event->venue); ?>" placeholder="<?php esc_attr_e('Grand Hall', 'snn-tickets'); ?>"></td></tr>
                            <tr><th><label for="ev_addr"><?php esc_html_e('Address', 'snn-tickets'); ?></label></th>
                                <td><input id="ev_addr" name="address" class="large-text" value="<?php echo esc_attr($event->address); ?>"></td></tr>
                            <tr><th><label for="ev_org"><?php esc_html_e('Organiser', 'snn-tickets'); ?></label></th>
                                <td><input id="ev_org" name="organizer" class="regular-text" value="<?php echo esc_attr($event->organizer); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
                            <tr><th><label for="ev_desc"><?php esc_html_e('Good to know', 'snn-tickets'); ?></label></th>
                                <td><textarea id="ev_desc" name="description" class="large-text" rows="4" placeholder="<?php esc_attr_e('Doors open at 18:30. Bring a photo ID.', 'snn-tickets'); ?>"><?php echo esc_textarea($event->description); ?></textarea>
                                    <p class="description"><?php esc_html_e('Printed on the PDF, the back of the wallet pass and the ticket page.', 'snn-tickets'); ?></p></td></tr>
                        </table>
                    </div>

                    <div>
                        <div class="snn-card">
                            <h2><?php esc_html_e('Attach to the ticket email', 'snn-tickets'); ?></h2>
                            <?php foreach (SNN_T_Events::attachment_types() as $k => $label):
                                $disabled = ($k === 'pkpass' && !SNN_T_Wallet::apple_ready()); ?>
                                <p><label><input type="checkbox" name="attachments[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $event->attachment_list, true)); ?> <?php disabled($disabled); ?>> <?php echo esc_html($label); ?></label>
                                <?php if ($disabled): ?><br><span class="snn-muted" style="font-size:12px"><?php printf(esc_html__('Set up Apple Wallet under %s first.', 'snn-tickets'), '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=wallet')) . '">' . esc_html__('Settings', 'snn-tickets') . '</a>'); ?></span><?php endif; ?>
                                <?php if ($k === 'ics'): ?><br><span class="snn-muted" style="font-size:12px"><?php esc_html_e('Needs a start date.', 'snn-tickets'); ?></span><?php endif; ?></p>
                            <?php endforeach; ?>
                            <p class="snn-muted" style="font-size:12px"><?php esc_html_e('Download links for these are always available through {wallet_buttons}, even when not attached.', 'snn-tickets'); ?></p>
                        </div>
                        <div class="snn-card">
                            <h2><?php esc_html_e('Design', 'snn-tickets'); ?></h2>
                            <select name="design" class="widefat">
                                <option value=""><?php echo esc_html(sprintf(__('Site default (%s)', 'snn-tickets'), $presets[$site_preset]['label'])); ?></option>
                                <?php foreach ($presets as $k => $p): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($event->design, $k); ?>><?php echo esc_html($p['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="snn-muted" style="font-size:12px"><?php printf(esc_html__('Colour tweaks and the logo live on the %s screen.', 'snn-tickets'), '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-design')) . '">' . esc_html__('Design', 'snn-tickets') . '</a>'); ?></p>
                        </div>
                    </div>
                </div>
                <p class="submit"><button class="button button-primary button-large"><?php esc_html_e('Save event', 'snn-tickets'); ?></button></p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_event() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_save_event');
        $list_id = (int)($_POST['list_id'] ?? 0);
        SNN_T_Events::save($list_id, wp_unslash($_POST));
        self::redirect(self::url(['list' => $list_id, 'edit' => 1]), __('Event saved.', 'snn-tickets'));
    }

    /* ==================================================================
     * Ticket actions
     * ================================================================== */

    public static function handle_ticket_action() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_ticket_action');

        $list_id = (int)($_POST['list_id'] ?? 0);
        $single  = (int)($_POST['ticket_id'] ?? 0);
        $do      = sanitize_key($_POST['do'] ?? '');

        if ($do && $single) {
            $ids = [$single];
        } else {
            $do  = sanitize_key($_POST['bulk_do'] ?? '');
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        }

        $back = wp_get_referer() ?: self::url(['list' => $list_id]);
        if (!$do || !$ids) self::redirect($back, __('Nothing to do.', 'snn-tickets'));

        $done = 0; $errors = [];
        foreach ($ids as $id) {
            switch ($do) {
                case 'revoke':  $ok = SNN_T_Tickets::set_status($id, 'revoked'); break;
                case 'restore': $ok = SNN_T_Tickets::set_status($id, 'active'); break;
                case 'undo':    $ok = SNN_T_Tickets::undo_checkin($id); break;
                case 'delete':  $ok = SNN_T_Tickets::delete_ticket($id); break;
                case 'resend':
                    $r  = self::queue_ticket_email(SNN_T_Tickets::get($id));
                    $ok = !is_wp_error($r);
                    if (!$ok) $errors[] = $r->get_error_message();
                    break;
                default: $ok = false;
            }
            if ($ok) $done++;
        }

        if ($do === 'resend' && $done) SNN_T_Mailer::process_queue();

        $labels = [
            'revoke'  => __('%d ticket(s) revoked.', 'snn-tickets'),
            'restore' => __('%d ticket(s) restored.', 'snn-tickets'),
            'undo'    => __('Check-in undone for %d ticket(s).', 'snn-tickets'),
            'delete'  => __('%d ticket(s) deleted.', 'snn-tickets'),
            'resend'  => __('%d ticket email(s) queued.', 'snn-tickets'),
        ];
        $msg = sprintf($labels[$do] ?? __('%d updated.', 'snn-tickets'), $done);
        if ($errors) $msg .= ' ' . implode(' ', array_unique(array_slice($errors, 0, 3)));
        self::redirect($back, $msg, (bool)$errors && !$done);
    }

    /** @return int|WP_Error */
    public static function queue_ticket_email($ticket, $template = '') {
        if (!$ticket) return new WP_Error('snn_t_missing', __('Ticket not found.', 'snn-tickets'));
        if (!$ticket->email) return new WP_Error('snn_t_noemail', __('That ticket has no email address.', 'snn-tickets'));
        if ($ticket->status !== 'active') return new WP_Error('snn_t_revoked', __('Revoked tickets are not emailed.', 'snn-tickets'));

        // A ticket that came from a form uses that form's wording.
        $override = null; $fields = []; $form_name = '';
        if ($ticket->submission_id) {
            $sub = SNN_T_Submissions::get((int)$ticket->submission_id);
            $form = $sub ? SNN_T_Forms::get($sub->form_id) : null;
            if ($form) {
                $override  = SNN_T_Forms::mail_override($form, 'ticket');
                $template  = $template ?: $form->settings['template_ticket'];
                $fields    = $sub->data;
                $form_name = $form->name;
            }
        }

        return SNN_T_Mailer::enqueue_from_template('ticket', $template, [
            'name'          => $ticket->name,
            'email'         => $ticket->email,
            'ticket_code'   => $ticket->ticket_code,
            'list_id'       => (int)$ticket->list_id,
            'ticket_id'     => (int)$ticket->id,
            'submission_id' => $ticket->submission_id ? (int)$ticket->submission_id : null,
            'form_name'     => $form_name,
            'fields'        => $fields,
        ], $override);
    }

    public static function handle_resend_ticket() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_resend_ticket');
        $r = self::queue_ticket_email(SNN_T_Tickets::get((int)($_POST['ticket'] ?? 0)));
        if (!is_wp_error($r)) SNN_T_Mailer::process_queue();
        self::redirect(wp_get_referer() ?: self::url(), is_wp_error($r) ? $r->get_error_message() : __('Ticket email queued.', 'snn-tickets'), is_wp_error($r));
    }

    public static function handle_ticket_qr() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_ticket_qr');
        $ticket = SNN_T_Tickets::get((int)($_GET['ticket'] ?? 0));
        if (!$ticket) wp_die(esc_html__('Ticket not found', 'snn-tickets'));
        $url = SNN_T_QR::ensure_url($ticket->ticket_code);
        if (is_wp_error($url)) wp_die(esc_html($url->get_error_message()));
        wp_redirect($url);
        exit;
    }

    public static function ajax_update_ticket_field() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'snn_update_ticket')) {
            wp_send_json_error(['message' => __('Invalid request.', 'snn-tickets')], 400);
        }

        $id    = (int)($_POST['id'] ?? 0);
        $field = sanitize_key($_POST['field'] ?? '');
        $value = wp_unslash($_POST['value'] ?? '');

        if ($id <= 0 || !in_array($field, ['name', 'email'], true)) {
            wp_send_json_error(['message' => __('Bad parameters.', 'snn-tickets')], 400);
        }

        if ($field === 'name') {
            $new_value = sanitize_text_field($value);
        } else {
            $raw = trim($value);
            $new_value = '';
            if ($raw !== '') {
                $san = sanitize_email($raw);
                if (!$san || !is_email($san)) wp_send_json_error(['message' => __('Invalid email address.', 'snn-tickets')], 400);
                $new_value = $san;
            }
        }

        global $wpdb;
        if (false === $wpdb->update(SNN_T_DB::tickets(), [$field => $new_value], ['id' => $id], ['%s'], ['%d'])) {
            wp_send_json_error(['message' => __('Database error.', 'snn-tickets')], 500);
        }
        wp_send_json_success(['id' => $id, 'field' => $field, 'value' => $new_value]);
    }

    public static function handle_export_list() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_export_list');
        global $wpdb;

        $list_id = (int)($_GET['list_id'] ?? 0);
        $event   = SNN_T_Events::get($list_id);
        if (!$event) wp_die(esc_html__('List not found', 'snn-tickets'));

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d ORDER BY id ASC", $list_id));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . sanitize_file_name($event->name . '-tickets-' . date('Ymd') . '.csv'));
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel needs the BOM to read UTF-8
        fputcsv($out, ['Name', 'Email', 'Ticket code', 'Status', 'Checked in', 'Scan count', 'Last scan', 'Created', 'Ticket link']);
        foreach ($rows as $t) {
            fputcsv($out, [
                $t->name, $t->email, $t->ticket_code, $t->status,
                (int)$t->validate_count > 0 ? 'yes' : 'no', (int)$t->validate_count,
                $t->last_validated ?: '', $t->created_at, SNN_T_Files::url('view', $t->ticket_code),
            ]);
        }
        fclose($out);
        exit;
    }

    /* ==================================================================
     * Delete a list
     * ================================================================== */

    public static function handle_delete_list() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_delete_list');

        $list_id = (int)($_POST['list_id'] ?? 0);
        $event   = $list_id ? SNN_T_Events::get($list_id) : null;
        if (!$event) wp_die(esc_html__('List not found', 'snn-tickets'));

        global $wpdb;
        $tickets = $wpdb->get_results($wpdb->prepare("SELECT id, ticket_code FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d", $list_id));
        foreach ($tickets as $ticket) {
            SNN_T_QR::delete($ticket->ticket_code);
            $wpdb->delete(SNN_T_DB::queue(), ['ticket_id' => (int)$ticket->id], ['%d']);
        }

        // Forms pointing at this list can no longer issue tickets, so close
        // them rather than leaving a form that silently fails.
        $wpdb->update(SNN_T_DB::forms(), ['status' => 'closed'], ['list_id' => $list_id], ['%s'], ['%d']);

        $n = (int)$wpdb->delete(SNN_T_DB::tickets(), ['list_id' => $list_id], ['%d']);
        $wpdb->delete(SNN_T_DB::lists(), ['id' => $list_id], ['%d']);

        self::redirect(self::url(), sprintf(__('Deleted "%1$s" and %2$d tickets.', 'snn-tickets'), $event->name, $n));
    }

    /* ==================================================================
     * Generator
     * ================================================================== */

    private static function list_options($selected = 0) {
        global $wpdb;
        $lists = $wpdb->get_results("SELECT id, name FROM " . SNN_T_DB::lists() . " ORDER BY id DESC");
        $out = '';
        foreach ($lists as $l) {
            $out .= '<option value="' . (int)$l->id . '"' . selected($selected, (int)$l->id, false) . '>' . esc_html($l->name) . '</option>';
        }
        return $out;
    }

    public static function render_generator() {
        SNN_T_Admin::cap();
        $now = date_i18n('Y-m-d H:i', current_time('timestamp'));
        $preselect = (int)($_GET['list_id'] ?? 0);
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Events & Tickets', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::tabs('tickets', 'snn-tickets-generator'); ?>
            <?php SNN_T_Admin::notice(); ?>

            <div class="snn-card" style="max-width:760px">
                <h2><?php esc_html_e('Generate blank tickets', 'snn-tickets'); ?></h2>
                <p class="snn-muted"><?php esc_html_e('Unnamed tickets with unique codes — handy for walk-ins, printed ticket books or door sales. You can name them later.', 'snn-tickets'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="snn_generate_tickets">
                    <?php wp_nonce_field('snn_generate_tickets'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="gen_list"><?php esc_html_e('Add to', 'snn-tickets'); ?></label></th>
                            <td><select id="gen_list" name="list_id" onchange="document.getElementById('gen_new').style.display=this.value==='0'?'':'none'">
                                    <option value="0"><?php esc_html_e('A new event', 'snn-tickets'); ?></option>
                                    <?php echo self::list_options($preselect); // escaped inside ?>
                                </select>
                                <div id="gen_new" style="margin-top:8px;<?php echo $preselect ? 'display:none' : ''; ?>">
                                    <input type="text" name="list_name" class="regular-text" placeholder="<?php echo esc_attr(sprintf(__('Generated %s', 'snn-tickets'), $now)); ?>">
                                </div></td></tr>
                        <tr><th><label for="gen_count"><?php esc_html_e('How many?', 'snn-tickets'); ?></label></th>
                            <td><input type="number" id="gen_count" name="count" value="10" min="1" max="5000" class="small-text"></td></tr>
                        <tr><th><label for="gen_len"><?php esc_html_e('Code length', 'snn-tickets'); ?></label></th>
                            <td><input type="number" id="gen_len" name="length" value="8" min="6" max="64" class="small-text">
                                <p class="description"><?php esc_html_e('Uppercase letters and digits.', 'snn-tickets'); ?></p></td></tr>
                    </table>
                    <p class="submit"><button class="button button-primary"><?php esc_html_e('Generate', 'snn-tickets'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function target_list($fallback_prefix) {
        $list_id = (int)($_POST['list_id'] ?? 0);
        if ($list_id && SNN_T_Events::get($list_id)) return $list_id;
        $name = trim(sanitize_text_field(wp_unslash($_POST['list_name'] ?? '')));
        if ($name === '') $name = $fallback_prefix . ' ' . date_i18n('Y-m-d H:i', current_time('timestamp'));
        return SNN_T_Tickets::create_list($name);
    }

    public static function handle_generate() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_generate_tickets');

        $count   = max(1, min(5000, (int)($_POST['count'] ?? 10)));
        $length  = max(6, min(64, (int)($_POST['length'] ?? 8)));
        $list_id = self::target_list(__('Generated', 'snn-tickets'));

        for ($i = 0; $i < $count; $i++) {
            SNN_T_Tickets::insert($list_id, '', '', SNN_T_Tickets::unique_code($length));
        }
        self::redirect(self::url(['list' => $list_id]), sprintf(__('Generated %d tickets.', 'snn-tickets'), $count));
    }

    /* ==================================================================
     * CSV import
     * ================================================================== */

    public static function render_csv_import() {
        SNN_T_Admin::cap();
        $now = date_i18n('Y-m-d H:i', current_time('timestamp'));
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Events & Tickets', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::tabs('tickets', 'snn-tickets-csv-import'); ?>
            <?php SNN_T_Admin::notice(); ?>

            <div class="snn-grid snn-grid-side">
                <div class="snn-card">
                    <h2><?php esc_html_e('Import attendees from CSV', 'snn-tickets'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="snn_import_csv">
                        <?php wp_nonce_field('snn_import_csv'); ?>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="csv_file"><?php esc_html_e('CSV file', 'snn-tickets'); ?></label></th>
                                <td><input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required></td></tr>
                            <tr><th><label for="csv_list"><?php esc_html_e('Add to', 'snn-tickets'); ?></label></th>
                                <td><select id="csv_list" name="list_id" onchange="document.getElementById('csv_new').style.display=this.value==='0'?'':'none'">
                                        <option value="0"><?php esc_html_e('A new event', 'snn-tickets'); ?></option>
                                        <?php echo self::list_options(); // escaped inside ?>
                                    </select>
                                    <div id="csv_new" style="margin-top:8px"><input type="text" name="list_name" class="regular-text" placeholder="<?php echo esc_attr(sprintf(__('Imported %s', 'snn-tickets'), $now)); ?>"></div></td></tr>
                            <tr><th><label for="csv_len"><?php esc_html_e('Code length', 'snn-tickets'); ?></label></th>
                                <td><input type="number" id="csv_len" name="length" value="10" min="6" max="64" class="small-text"></td></tr>
                            <tr><th><?php esc_html_e('Duplicates', 'snn-tickets'); ?></th>
                                <td><label><input type="checkbox" name="skip_dupes" value="1" checked> <?php esc_html_e('Skip emails that already have a ticket in this event', 'snn-tickets'); ?></label></td></tr>
                        </table>
                        <p class="submit"><button class="button button-primary"><?php esc_html_e('Import and create tickets', 'snn-tickets'); ?></button></p>
                    </form>
                </div>
                <div class="snn-card">
                    <h2><?php esc_html_e('Format', 'snn-tickets'); ?></h2>
                    <ul style="list-style:disc;padding-left:18px">
                        <li><?php esc_html_e('A header row with Name and Email columns (any order, any case).', 'snn-tickets'); ?></li>
                        <li><?php esc_html_e('Comma or semicolon separated; UTF-8, with or without BOM.', 'snn-tickets'); ?></li>
                        <li><?php esc_html_e('Every row gets a unique ticket code.', 'snn-tickets'); ?></li>
                    </ul>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=snn_csv_template')); ?>"><?php esc_html_e('Download a template', 'snn-tickets'); ?></a></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Parse an uploaded CSV into [['name' =>, 'email' =>], ...].
     *
     * @return array|WP_Error
     */
    public static function parse_csv($path) {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') return new WP_Error('snn_csv_empty', __('The CSV file is empty.', 'snn-tickets'));

        // Strip a UTF-8 BOM, which otherwise glues itself to the first header.
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1254,ISO-8859-1');

        $first = strtok($raw, "\n");
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);

        $header = fgetcsv($h, 0, $delim);
        $map = ['name' => null, 'email' => null];
        foreach ((array)$header as $i => $col) {
            $c = strtolower(trim((string)$col));
            if (in_array($c, ['name', 'full name', 'fullname', 'ad soyad', 'isim'], true)) $map['name'] = $i;
            if (in_array($c, ['email', 'e-mail', 'email address', 'e-posta', 'eposta'], true)) $map['email'] = $i;
        }
        if ($map['name'] === null && $map['email'] === null) {
            fclose($h);
            return new WP_Error('snn_csv_header', __('The CSV needs a header row with Name and Email columns.', 'snn-tickets'));
        }

        $rows = [];
        while (($row = fgetcsv($h, 0, $delim)) !== false) {
            if ($row === [null] || !array_filter($row, 'strlen')) continue;
            $name  = $map['name'] !== null ? sanitize_text_field($row[$map['name']] ?? '') : '';
            $email = $map['email'] !== null ? sanitize_email($row[$map['email']] ?? '') : '';
            $rows[] = ['name' => $name, 'email' => is_email($email) ? strtolower($email) : ''];
        }
        fclose($h);
        return $rows;
    }

    public static function handle_import_csv() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_import_csv');
        $back = admin_url('admin.php?page=snn-tickets-csv-import');

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            self::redirect($back, __('The upload failed. Pick a CSV file and try again.', 'snn-tickets'), true);
        }

        $rows = self::parse_csv($_FILES['csv_file']['tmp_name']);
        if (is_wp_error($rows)) self::redirect($back, $rows->get_error_message(), true);

        $length  = max(6, min(64, (int)($_POST['length'] ?? 10)));
        $skip    = !empty($_POST['skip_dupes']);
        $list_id = self::target_list(__('Imported', 'snn-tickets'));

        $added = 0; $skipped = 0;
        foreach ($rows as $r) {
            if ($skip && $r['email'] !== '' && SNN_T_Tickets::count_for_email($list_id, $r['email'])) { $skipped++; continue; }
            SNN_T_Tickets::insert($list_id, $r['name'], $r['email'], SNN_T_Tickets::unique_code($length));
            $added++;
        }

        $msg = sprintf(__('Imported %d attendees.', 'snn-tickets'), $added);
        if ($skipped) $msg .= ' ' . sprintf(__('%d already had a ticket and were skipped.', 'snn-tickets'), $skipped);
        self::redirect(self::url(['list' => $list_id]), $msg);
    }

    public static function download_csv_template() {
        SNN_T_Admin::cap();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=snn-tickets-template.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Name', 'Email']);
        fputcsv($out, ['Jane Doe', 'jane@example.com']);
        fputcsv($out, ['Şükrü Ağaoğlu', 'sukru@example.com']);
        fclose($out);
        exit;
    }

    /* ==================================================================
     * Email a whole list
     * ================================================================== */

    public static function render_mailer() {
        SNN_T_Admin::cap();
        global $wpdb;

        $tickets_t = SNN_T_DB::tickets();
        $queue_t   = SNN_T_DB::queue();
        $lists     = $wpdb->get_results("
            SELECT l.id, l.name,
                   SUM(CASE WHEN t.email <> '' AND t.status = 'active' THEN 1 ELSE 0 END) AS with_email,
                   SUM(CASE WHEN t.email <> '' AND t.status = 'active' AND EXISTS (
                       SELECT 1 FROM {$queue_t} q WHERE q.ticket_id = t.id AND q.role = 'ticket' AND q.status IN ('pending','sending','sent')
                   ) THEN 1 ELSE 0 END) AS handled
            FROM " . SNN_T_DB::lists() . " l LEFT JOIN {$tickets_t} t ON t.list_id = l.id
            GROUP BY l.id ORDER BY l.id DESC");
        $selected  = (int)($_GET['list_id'] ?? 0);
        if (!$selected && $lists) $selected = (int)$lists[0]->id;
        $templates = SNN_T_Mailer::templates_for_role('ticket');
        $rate      = (int)SNN_T_Mailer::batch_size();
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Emails', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::tabs('emails', 'snn-tickets-mailer'); ?>
            <?php SNN_T_Admin::notice(); ?>

            <?php if (!$lists): ?>
                <div class="snn-card snn-empty-state">
                    <span class="dashicons dashicons-email-alt"></span>
                    <p><?php esc_html_e('No events yet. Create an event with tickets first, then send them from here.', 'snn-tickets'); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-lists')); ?>"><?php esc_html_e('Go to Events & Tickets', 'snn-tickets'); ?></a></p>
                </div>
            </div>
            <?php return; endif; ?>

            <div class="snn-grid snn-grid-mail">
                <div class="snn-card">
                    <h2><?php esc_html_e('Send tickets to everyone in an event', 'snn-tickets'); ?></h2>
                    <p class="snn-muted"><?php printf(esc_html__('Each attendee with an email address gets their own ticket. Sending runs in the background at %d per minute — you can close this page.', 'snn-tickets'), $rate); ?></p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-mailer-form">
                        <input type="hidden" name="action" value="snn_queue_list_emails">
                        <?php wp_nonce_field('snn_queue_list_emails'); ?>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="snn_list_id"><?php esc_html_e('1. Event', 'snn-tickets'); ?></label></th>
                                <td><select id="snn_list_id" name="list_id" required style="max-width:100%">
                                    <?php foreach ($lists as $l): ?>
                                        <option value="<?php echo (int)$l->id; ?>" data-n="<?php echo (int)$l->with_email; ?>" data-done="<?php echo (int)$l->handled; ?>" <?php selected($selected, (int)$l->id); ?>><?php echo esc_html(sprintf(__('%1$s (%2$d with email)', 'snn-tickets'), $l->name, (int)$l->with_email)); ?></option>
                                    <?php endforeach; ?>
                                </select></td></tr>
                            <tr><th><label for="snn_template"><?php esc_html_e('2. Email', 'snn-tickets'); ?></label></th>
                                <td><select id="snn_template" name="template" style="max-width:100%">
                                        <option value=""><?php esc_html_e("Each form's own ticket email (or the built-in one)", 'snn-tickets'); ?></option>
                                        <?php foreach ($templates as $name => $t): ?><option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php printf(esc_html__('Pick a saved ticket template to use the same wording for everyone. Write new ones on the %s tab.', 'snn-tickets'), '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-templates&role=ticket')) . '">' . esc_html__('Templates', 'snn-tickets') . '</a>'); ?></p></td></tr>
                            <tr><th><?php esc_html_e('3. Who gets it', 'snn-tickets'); ?></th>
                                <td><label><input type="checkbox" id="snn_skip_sent" name="skip_sent" value="1" checked> <?php esc_html_e('Skip anyone whose ticket email is already queued or sent', 'snn-tickets'); ?></label>
                                    <div class="snn-hint" data-summary></div></td></tr>
                        </table>

                        <h3 style="margin:18px 0 6px;font-size:13px"><?php esc_html_e('Send yourself a test first', 'snn-tickets'); ?></h3>
                        <?php SNN_T_Admin::email_tools(['role' => 'ticket', 'template_el' => 'snn_template', 'list_el' => 'snn_list_id']); ?>

                        <p class="submit"><button class="button button-primary button-large" data-queue><?php esc_html_e('Queue emails', 'snn-tickets'); ?></button></p>
                    </form>
                </div>

                <div class="snn-sticky">
                    <div class="snn-card">
                        <div class="snn-card-head">
                            <h2><?php esc_html_e('Preview', 'snn-tickets'); ?></h2>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-design')); ?>"><?php esc_html_e('Change design', 'snn-tickets'); ?> &rarr;</a>
                        </div>
                        <p class="snn-muted" style="margin-top:0;font-size:12px"><?php esc_html_e('What an attendee of the chosen event will receive, filled in with sample details. Updates as you change the options.', 'snn-tickets'); ?></p>
                        <?php SNN_T_Admin::live_preview(['role' => 'ticket', 'template_el' => 'snn_template', 'list_el' => 'snn_list_id']); ?>
                    </div>
                </div>
            </div>
        </div>
        <style>.snn-grid-mail{grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:start}@media(max-width:1100px){.snn-grid-mail{grid-template-columns:1fr}.snn-grid-mail .snn-sticky{position:static}}</style>
        <script>
        (function(){
            var sel = document.getElementById('snn_list_id'), skip = document.getElementById('snn_skip_sent');
            var out = document.querySelector('[data-summary]'), btn = document.querySelector('[data-queue]');
            var T = <?php echo wp_json_encode([
                'will' => __('%1$s email(s) will be queued — about %2$s to send.', 'snn-tickets'),
                'skip' => __('%s already have their ticket and will be skipped.', 'snn-tickets'),
                'none' => __('Nobody left to send to on this event.', 'snn-tickets'),
                'dup'  => __('%s of them already got a ticket email and will get another one.', 'snn-tickets'),
                'min'  => __('%s min', 'snn-tickets'),
                'btn'  => __('Queue %s emails', 'snn-tickets'),
                'ask'  => __('Queue %s ticket emails now?', 'snn-tickets'),
                'rate' => max(1, $rate),
            ]); ?>;
            var count = 0;
            function f(s, a, b){ return s.replace('%1$s', a).replace('%2$s', b).replace('%s', a); }
            function update(){
                var o = sel.options[sel.selectedIndex], n = +o.getAttribute('data-n'), done = +o.getAttribute('data-done');
                count = skip.checked ? n - done : n;
                var html = '';
                out.classList.remove('warn');
                if (count <= 0) { html = '<p>' + T.none + '</p>'; out.classList.add('warn'); }
                else {
                    html = '<p><strong>' + f(T.will, count, f(T.min, Math.max(1, Math.ceil(count / T.rate)))) + '</strong></p>';
                    if (done && skip.checked) html += '<p>' + f(T.skip, done) + '</p>';
                    if (done && !skip.checked) { html += '<p>' + f(T.dup, done) + '</p>'; out.classList.add('warn'); }
                }
                out.innerHTML = html;
                btn.textContent = f(T.btn, Math.max(0, count));
                btn.disabled = count <= 0;
            }
            sel.addEventListener('change', update); skip.addEventListener('change', update); update();
            btn.addEventListener('click', function(e){ if (!confirm(f(T.ask, count))) e.preventDefault(); });
        })();
        </script>
        <?php
    }

    public static function handle_queue_list_emails() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_queue_list_emails');
        global $wpdb;

        $list_id   = (int)($_POST['list_id'] ?? 0);
        $template  = sanitize_text_field(wp_unslash($_POST['template'] ?? ''));
        $skip_sent = !empty($_POST['skip_sent']);

        if (!$list_id) self::redirect(admin_url('admin.php?page=snn-tickets-mailer'), __('Pick an event first.', 'snn-tickets'), true);

        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND email <> '' AND status = 'active'", $list_id));

        $queue = SNN_T_DB::queue();
        $queued = 0; $skipped = 0; $failed = 0;

        foreach ($tickets as $ticket) {
            if ($skip_sent) {
                $already = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue} WHERE ticket_id = %d AND role = 'ticket' AND status IN ('pending','sending','sent')", (int)$ticket->id));
                if ($already) { $skipped++; continue; }
            }
            $r = self::queue_ticket_email($ticket, $template);
            if (is_wp_error($r)) $failed++; else $queued++;
        }

        $msg = sprintf(__('Queued %d email(s).', 'snn-tickets'), $queued);
        if ($skipped) $msg .= ' ' . sprintf(__('Skipped %d already handled.', 'snn-tickets'), $skipped);
        if ($failed)  $msg .= ' ' . sprintf(__('%d could not be queued (bad address?).', 'snn-tickets'), $failed);
        if (!$queued && !$skipped) $msg .= ' ' . __('Nobody on this list has an email address.', 'snn-tickets');

        self::redirect(admin_url('admin.php?page=snn-tickets-queue'), $msg);
    }
}
