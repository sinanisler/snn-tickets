<?php
/**
 * The dashboard: what needs attention, how the events are doing, and a
 * setup checklist until everything is in place.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Dashboard {

    public static function checklist() {
        global $wpdb;
        $scan  = trim((string)get_option(SNN_T_QR::SCAN_URL_OPTION, ''));
        $forms = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . SNN_T_DB::forms());
        $dated = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . SNN_T_DB::lists() . " WHERE event_start IS NOT NULL");
        $from  = (string)get_option(SNN_T_Mailer::FROM_EMAIL_OPTION, '');
        $cron  = (bool)wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);

        return [
            ['done' => $forms > 0, 'required' => true, 'label' => __('Create a registration form', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-forms&action=new')],
            ['done' => $dated > 0, 'required' => true, 'label' => __('Give your event a date and venue', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-lists')],
            ['done' => $scan !== '', 'required' => true, 'label' => __('Publish the scanner page and set its URL', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings')],
            ['done' => $from !== '', 'required' => false, 'label' => __('Set a From address for emails', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings')],
            ['done' => (bool)get_option(SNN_T_Design::OPTION), 'required' => false, 'label' => __('Pick a ticket design', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-design')],
            ['done' => SNN_T_Scanner::pin_set(), 'required' => false, 'label' => __('Set a door staff PIN for volunteers', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings')],
            ['done' => SNN_T_Wallet::apple_ready() || SNN_T_Wallet::google_ready(), 'required' => false, 'label' => __('Connect Apple or Google Wallet', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings&tab=wallet')],
            ['done' => $cron, 'required' => true, 'label' => __('Mail queue cron is scheduled', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings')],
        ];
    }

    public static function render() {
        SNN_T_Admin::cap();
        global $wpdb;

        $tickets = SNN_T_DB::tickets();
        $lists   = SNN_T_DB::lists();
        $today   = date('Y-m-d 00:00:00', current_time('timestamp'));

        $total    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tickets} WHERE status = 'active'");
        $inside   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tickets} WHERE status = 'active' AND validate_count > 0");
        $today_in = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tickets} WHERE last_validated >= %s", $today));
        $subs     = SNN_T_Submissions::counts();
        $queue    = SNN_T_Mailer::queue_counts();

        $events = $wpdb->get_results("
            SELECT l.*, COUNT(t.id) AS n,
                   SUM(CASE WHEN t.validate_count > 0 AND t.status = 'active' THEN 1 ELSE 0 END) AS inside,
                   SUM(CASE WHEN t.status = 'active' THEN 1 ELSE 0 END) AS active
            FROM {$lists} l LEFT JOIN {$tickets} t ON t.list_id = l.id
            GROUP BY l.id
            ORDER BY (l.event_start IS NULL), l.event_start ASC, l.id DESC
            LIMIT 8");

        $recent = $wpdb->get_results("
            SELECT t.name, t.ticket_code, t.last_validated, t.validate_count, l.name AS list_name
            FROM {$tickets} t LEFT JOIN {$lists} l ON l.id = t.list_id
            WHERE t.last_validated IS NOT NULL
            ORDER BY t.last_validated DESC LIMIT 8");

        $forms = SNN_T_Forms::all();
        $check = self::checklist();
        $todo  = array_filter($check, function ($c) { return !$c['done']; });
        ?>
        <div class="wrap snn-wrap">
            <h1><span class="dashicons dashicons-tickets-alt"></span> <?php esc_html_e('Tickets', 'snn-tickets'); ?></h1>

            <div class="snn-stats" style="margin-top:16px">
                <a class="snn-stat" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-lists')); ?>">
                    <div class="v"><?php echo number_format_i18n($total); ?></div><div class="l"><?php esc_html_e('Active tickets', 'snn-tickets'); ?></div></a>
                <div class="snn-stat ok">
                    <div class="v"><?php echo number_format_i18n($inside); ?></div><div class="l"><?php esc_html_e('Checked in', 'snn-tickets'); ?></div>
                    <div class="s"><?php printf(esc_html__('%s today', 'snn-tickets'), number_format_i18n($today_in)); ?></div></div>
                <a class="snn-stat <?php echo $subs['pending'] ? 'warn' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions&status=pending')); ?>">
                    <div class="v"><?php echo number_format_i18n($subs['pending']); ?></div><div class="l"><?php esc_html_e('Awaiting review', 'snn-tickets'); ?></div></a>
                <a class="snn-stat <?php echo $queue['failed'] ? 'bad' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-queue' . ($queue['failed'] ? '&status=failed' : ''))); ?>">
                    <div class="v"><?php echo number_format_i18n($queue['pending'] + $queue['sending']); ?></div><div class="l"><?php esc_html_e('Emails waiting', 'snn-tickets'); ?></div>
                    <div class="s"><?php printf(esc_html__('%1$s sent · %2$s failed', 'snn-tickets'), number_format_i18n($queue['sent']), number_format_i18n($queue['failed'])); ?></div></a>
            </div>

            <div class="snn-grid snn-grid-side">
                <div>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Events', 'snn-tickets'); ?></h2>
                        <?php if (!$events): ?>
                            <p class="snn-muted"><?php esc_html_e('No events yet. Build a registration form to create one.', 'snn-tickets'); ?></p>
                            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=new')); ?>"><?php esc_html_e('Build a registration form', 'snn-tickets'); ?></a></p>
                        <?php else: ?>
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Event', 'snn-tickets'); ?></th><th style="width:180px"><?php esc_html_e('When', 'snn-tickets'); ?></th><th style="width:200px"><?php esc_html_e('Checked in', 'snn-tickets'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($events as $e): $e = SNN_T_Events::normalise($e);
                                $pct = (int)$e->active ? round(100 * (int)$e->inside / (int)$e->active) : 0; ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-lists&list=' . (int)$e->id)); ?>"><strong><?php echo esc_html($e->name); ?></strong></a>
                                        <?php if ($e->venue): ?><br><span class="snn-muted" style="font-size:12px"><?php echo esc_html($e->venue); ?></span><?php endif; ?></td>
                                    <td style="font-size:12px"><?php echo $e->event_start ? esc_html(SNN_T_Events::format_date($e)) . '<br><span class="snn-muted">' . esc_html(SNN_T_Events::format_time($e)) . '</span>' : '<span class="snn-muted">—</span>'; ?></td>
                                    <td><div class="snn-progress"><i style="width:<?php echo (int)$pct; ?>%"></i></div>
                                        <span class="snn-muted" style="font-size:12px"><?php echo (int)$e->inside; ?> / <?php echo (int)$e->active; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>

                    <?php if ($forms): ?>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Registration forms', 'snn-tickets'); ?></h2>
                        <table class="widefat striped">
                            <thead><tr><th><?php esc_html_e('Form', 'snn-tickets'); ?></th><th style="width:200px"><?php esc_html_e('Capacity', 'snn-tickets'); ?></th><th style="width:120px"><?php esc_html_e('Pending', 'snn-tickets'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($forms, 0, 8) as $f):
                                $max = (int)$f->settings['max_tickets']; $taken = SNN_T_Forms::issued_count($f);
                                $c = SNN_T_Submissions::counts((int)$f->id); ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-forms&action=edit&form=' . (int)$f->id)); ?>"><?php echo esc_html($f->name); ?></a>
                                        <?php if ($f->status === 'closed'): ?> <span class="snn-badge"><?php esc_html_e('closed', 'snn-tickets'); ?></span><?php endif; ?></td>
                                    <td><?php if ($max): ?><div class="snn-progress"><i style="width:<?php echo (int)min(100, round(100 * $taken / $max)); ?>%;background:<?php echo $taken >= $max ? '#b3261e' : '#2271b1'; ?>"></i></div><?php endif; ?>
                                        <span class="snn-muted" style="font-size:12px"><?php echo esc_html($taken . ' / ' . ($max ?: '∞')); ?></span></td>
                                    <td><?php if ($c['pending']): ?><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions&status=pending&form_id=' . (int)$f->id)); ?>"><span class="snn-badge warn"><?php echo (int)$c['pending']; ?></span></a><?php else: ?><span class="snn-muted">0</span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <div>
                    <?php if ($todo): ?>
                    <div class="snn-card">
                        <h2><?php esc_html_e('Setup checklist', 'snn-tickets'); ?></h2>
                        <ul class="snn-check">
                            <?php foreach ($check as $c): ?>
                                <li><span class="dashicons <?php echo $c['done'] ? 'dashicons-yes-alt yes' : ($c['required'] ? 'dashicons-marker no' : 'dashicons-marker opt'); ?>"></span>
                                    <div><?php if ($c['done']): ?><span class="snn-muted"><?php echo esc_html($c['label']); ?></span>
                                    <?php else: ?><a href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['label']); ?></a><?php if (!$c['required']): ?> <span class="snn-muted" style="font-size:11px"><?php esc_html_e('optional', 'snn-tickets'); ?></span><?php endif; ?><?php endif; ?></div></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <div class="snn-card">
                        <h2><?php esc_html_e('Recent check-ins', 'snn-tickets'); ?></h2>
                        <?php if (!$recent): ?>
                            <p class="snn-muted"><?php esc_html_e('Nobody has been checked in yet.', 'snn-tickets'); ?></p>
                        <?php else: ?>
                            <ul class="snn-check">
                            <?php foreach ($recent as $r): ?>
                                <li><span class="dashicons dashicons-yes yes"></span>
                                    <div style="flex:1;min-width:0"><strong><?php echo esc_html($r->name ?: $r->ticket_code); ?></strong><?php if ((int)$r->validate_count > 1): ?> <span class="snn-badge warn"><?php echo (int)$r->validate_count; ?>×</span><?php endif; ?><br>
                                        <span class="snn-muted" style="font-size:12px"><?php echo esc_html($r->list_name); ?> · <?php echo SNN_T_Admin::when($r->last_validated); ?></span></div></li>
                            <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <div class="snn-card">
                        <h2><?php esc_html_e('Shortcodes', 'snn-tickets'); ?></h2>
                        <p><?php esc_html_e('Door scanner:', 'snn-tickets'); ?><br><?php echo SNN_T_Admin::copy_code('[tickets_scan_page]'); ?></p>
                        <?php foreach (array_slice($forms, 0, 3) as $f): ?>
                            <p><?php echo esc_html($f->name); ?>:<br><?php echo SNN_T_Admin::copy_code('[snn_ticket_form id="' . (int)$f->id . '"]'); ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        SNN_T_Admin::email_tools_script_only();
    }
}
