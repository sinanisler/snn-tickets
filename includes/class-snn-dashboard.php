<?php
/**
 * Home: is there anything I need to do, how are the next events doing,
 * and a getting-started list until the basics are in place.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Dashboard {

    public static function checklist() {
        global $wpdb;
        $events = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . SNN_T_DB::lists());
        $dated  = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . SNN_T_DB::lists() . " WHERE event_start IS NOT NULL");
        $from   = (string)get_option(SNN_T_Mailer::FROM_EMAIL_OPTION, '');

        return [
            ['done' => $events > 0, 'optional' => false, 'label' => __('Create your first event', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-new')],
            ['done' => $dated > 0, 'optional' => false, 'label' => __('Give an event a date and venue', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-events')],
            // Optional: with no address, emails use the site's own sender.
            ['done' => $from !== '', 'optional' => true, 'label' => __('Set the sender name and address for emails', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings')],
            ['done' => (bool)SNN_T_Design::settings()['logo_url'], 'optional' => true, 'label' => __('Add your logo to tickets and emails', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings&tab=look')],
            ['done' => SNN_T_Scanner::pin_set(), 'optional' => true, 'label' => __('Set a door PIN for volunteers', 'snn-tickets'),
             'url' => admin_url('admin.php?page=snn-tickets-settings&tab=door')],
        ];
    }

    /** Things that need a decision or a fix, most urgent first. */
    public static function attention() {
        global $wpdb;
        $items = [];

        $waiting = $wpdb->get_results("
            SELECT f.list_id, COUNT(*) AS n, MIN(s.created_at) AS oldest
            FROM " . SNN_T_DB::submissions() . " s JOIN " . SNN_T_DB::forms() . " f ON f.id = s.form_id
            WHERE s.status = 'pending' AND (s.ticket_id IS NULL OR s.ticket_id = 0)
            GROUP BY f.list_id ORDER BY oldest ASC");
        foreach ((array)$waiting as $w) {
            $e = SNN_T_Events::get((int)$w->list_id);
            if (!$e) continue;
            $items[] = ['warn', (int)$w->n,
                sprintf(_n('%d person is waiting for your approval', '%d people are waiting for your approval', (int)$w->n, 'snn-tickets'), (int)$w->n),
                sprintf(__('%1$s · oldest %2$s', 'snn-tickets'), $e->name, wp_strip_all_tags(SNN_T_Admin::when($w->oldest))),
                __('Review', 'snn-tickets'), SNN_T_Admin::event_admin_url($e->id, ['filter' => 'waiting']), true];
        }

        $failed = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . SNN_T_DB::queue() . " WHERE status = 'failed'");
        if ($failed) {
            $items[] = ['bad', $failed,
                sprintf(_n('%d email could not be sent', '%d emails could not be sent', $failed, 'snn-tickets'), $failed),
                __('Usually a mistyped address. Fix it and send again.', 'snn-tickets'),
                __('See them', 'snn-tickets'), admin_url('admin.php?page=snn-tickets-settings&tab=log&status=failed'), false];
        }

        foreach (SNN_T_Events::all() as $e) {
            if ($e->event_start && strtotime($e->event_start) < current_time('timestamp')) continue;
            $form = SNN_T_Forms::for_list($e->id);
            if (!$form || $form->status !== 'active') continue;
            $max = (int)$form->settings['max_tickets'];
            if ($max && SNN_T_Events::spots_taken($e->id) >= $max) {
                $items[] = ['warn', '!', sprintf(__('%s is full', 'snn-tickets'), $e->name),
                    sprintf(__('All %d spots are taken. New visitors see "fully booked".', 'snn-tickets'), $max),
                    __('Add spots', 'snn-tickets'), SNN_T_Admin::event_admin_url($e->id, ['tab' => 'form']), false];
            }
        }

        if (!wp_next_scheduled(SNN_T_Mailer::CRON_HOOK)) {
            $items[] = ['bad', '!', __('Emails are not being sent automatically', 'snn-tickets'),
                __('The background sender is not scheduled. Deactivate and reactivate the plugin to fix it.', 'snn-tickets'),
                __('Details', 'snn-tickets'), admin_url('admin.php?page=snn-tickets-settings&tab=advanced'), false];
        }
        return $items;
    }

    public static function render() {
        SNN_T_Admin::cap();
        global $wpdb;

        $tickets  = SNN_T_DB::tickets();
        $today    = date('Y-m-d 00:00:00', current_time('timestamp'));
        $active   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tickets} WHERE status = 'active'");
        $today_in = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tickets} WHERE last_validated >= %s", $today));
        $waiting  = SNN_T_Submissions::counts()['pending'];
        $failed   = SNN_T_Mailer::queue_counts()['failed'];

        $now = current_time('mysql');
        $upcoming = array_values(array_filter(SNN_T_Events::all(), function ($e) use ($now) {
            return !$e->event_start || $e->event_start >= substr($now, 0, 10);
        }));
        usort($upcoming, function ($a, $b) {
            if (!$a->event_start) return 1;
            if (!$b->event_start) return -1;
            return strcmp($a->event_start, $b->event_start);
        });
        $upcoming = array_slice($upcoming, 0, 5);

        $recent = $wpdb->get_results("
            SELECT t.name, t.ticket_code, t.list_id, t.last_validated, t.validate_count, l.name AS list_name
            FROM {$tickets} t LEFT JOIN " . SNN_T_DB::lists() . " l ON l.id = t.list_id
            WHERE t.last_validated IS NOT NULL ORDER BY t.last_validated DESC LIMIT 8");

        $check = self::checklist();
        $todo  = array_filter($check, function ($c) { return !$c['done'] && !$c['optional']; });
        $done  = count(array_filter($check, function ($c) { return $c['done']; }));
        $attn  = self::attention();
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Tickets', 'snn-tickets'); ?> <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-new')); ?>"><?php esc_html_e('Add New Event', 'snn-tickets'); ?></a></h1>
            <?php SNN_T_Admin::notice(); ?>
            <div class="snn-page">
                <div class="snn-kpis">
                    <a class="snn-kpi <?php echo $waiting ? 'warn' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-events')); ?>"><span class="l"><?php esc_html_e('Waiting for your approval', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($waiting); ?></span></a>
                    <a class="snn-kpi <?php echo $failed ? 'bad' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=log' . ($failed ? '&status=failed' : ''))); ?>"><span class="l"><?php esc_html_e('Emails that failed', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($failed); ?></span></a>
                    <a class="snn-kpi" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-events')); ?>"><span class="l"><?php esc_html_e('Active tickets', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($active); ?></span></a>
                    <div class="snn-kpi"><span class="l"><?php esc_html_e('Checked in today', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($today_in); ?></span></div>
                </div>

                <div class="snn-side">
                    <div class="snn-col" style="gap:18px">
                        <div class="snn-card">
                            <h2><?php esc_html_e('Needs your attention', 'snn-tickets'); ?></h2>
                            <?php if (!$attn): ?>
                                <p class="snn-muted" style="margin:0">✓ <?php esc_html_e('Nothing right now. New sign-ups that need you will show up here.', 'snn-tickets'); ?></p>
                            <?php else: ?>
                                <ul class="snn-attn">
                                    <?php foreach ($attn as $a): ?>
                                        <li><span class="snn-dot <?php echo esc_attr($a[0]); ?>"><?php echo esc_html($a[1]); ?></span>
                                            <div style="flex:1;min-width:0"><b><?php echo esc_html($a[2]); ?></b><p class="snn-muted snn-small" style="margin:0"><?php echo esc_html($a[3]); ?></p></div>
                                            <a class="button <?php echo $a[6] ? 'button-primary' : ''; ?>" href="<?php echo esc_url($a[5]); ?>"><?php echo esc_html($a[4]); ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="snn-card-h"><h2><?php esc_html_e('Upcoming events', 'snn-tickets'); ?></h2><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-events')); ?>"><?php esc_html_e('All events', 'snn-tickets'); ?></a></div>
                        <div class="snn-evcards">
                            <?php foreach ($upcoming as $e):
                                $max = SNN_T_Events::spot_limit($e->id); $taken = SNN_T_Events::spots_taken($e->id);
                                $form = SNN_T_Forms::for_list($e->id);
                                list($label, $cls) = self::status($e, $form, $max, $taken); ?>
                                <a class="snn-evc" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($e->id)); ?>">
                                    <span class="when"><?php echo $e->event_start ? esc_html(SNN_T_Events::format_when($e)) : esc_html__('No date yet', 'snn-tickets'); ?></span>
                                    <h3><?php echo esc_html($e->name); ?></h3>
                                    <?php if ($e->venue): ?><span class="snn-muted snn-small"><?php echo esc_html($e->venue); ?></span><?php endif; ?>
                                    <?php if ($max): ?><div class="snn-bar <?php echo $taken >= $max ? 'full' : ''; ?>"><i style="width:<?php echo (int)min(100, round(100 * $taken / $max)); ?>%"></i></div><?php endif; ?>
                                    <div class="line snn-num"><span><?php echo $max ? esc_html(sprintf(__('%1$d of %2$d spots', 'snn-tickets'), $taken, $max)) : esc_html(sprintf(_n('%d person', '%d people', $taken, 'snn-tickets'), $taken)); ?></span><?php echo SNN_T_Admin::chip($label, $cls); ?></div>
                                </a>
                            <?php endforeach; ?>
                            <a class="snn-evc new" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-new')); ?>">+ <?php esc_html_e('Add New Event', 'snn-tickets'); ?></a>
                        </div>
                    </div>

                    <div class="snn-col" style="gap:18px">
                        <?php if ($todo): ?>
                        <div class="snn-card">
                            <div class="snn-card-h"><h2><?php esc_html_e('Getting started', 'snn-tickets'); ?></h2><span class="snn-muted snn-small"><?php echo esc_html(sprintf(__('%1$d of %2$d done', 'snn-tickets'), $done, count($check))); ?></span></div>
                            <div class="snn-bar ok"><i style="width:<?php echo (int)round(100 * $done / count($check)); ?>%"></i></div>
                            <ul class="snn-steps">
                                <?php foreach ($check as $c): ?>
                                    <li><span class="ck <?php echo $c['done'] ? 'y' : ''; ?>"><?php echo $c['done'] ? '✓' : ''; ?></span>
                                        <?php if ($c['done']): ?><span class="snn-muted"><?php echo esc_html($c['label']); ?></span>
                                        <?php else: ?><a href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['label']); ?></a><?php if ($c['optional']): ?> <?php echo SNN_T_Admin::chip(__('optional', 'snn-tickets')); ?><?php endif; ?><?php endif; ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('This box disappears once the basics are done.', 'snn-tickets'); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="snn-card">
                            <h2><?php esc_html_e('Last check-ins', 'snn-tickets'); ?></h2>
                            <?php if (!$recent): ?>
                                <p class="snn-muted" style="margin:0"><?php esc_html_e('Nobody has been checked in yet. At the door, open the scanner from the event\'s Door tab.', 'snn-tickets'); ?></p>
                            <?php else: ?>
                                <ul class="snn-attn">
                                    <?php foreach ($recent as $r): ?>
                                        <li><span class="snn-dot <?php echo (int)$r->validate_count > 1 ? 'warn' : 'ok'; ?>"><?php echo (int)$r->validate_count > 1 ? (int)$r->validate_count . '×' : '✓'; ?></span>
                                            <div style="flex:1;min-width:0"><b><?php echo esc_html($r->name ?: $r->ticket_code); ?></b><p class="snn-muted snn-small" style="margin:0"><?php echo esc_html($r->list_name); ?> · <?php echo SNN_T_Admin::when($r->last_validated); ?></p></div></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /** [label, chip class] for an event's sign-up state. */
    public static function status($event, $form, $max, $taken) {
        if (!$form) return [__('No sign-up form', 'snn-tickets'), ''];
        if ($event->event_start && strtotime($event->event_end ?: $event->event_start) < current_time('timestamp')) return [__('Past', 'snn-tickets'), ''];
        if ($form->status !== 'active') return [__('Closed', 'snn-tickets'), ''];
        if ($max && $taken >= $max) return [__('Full', 'snn-tickets'), 'warn'];
        return [__('Open', 'snn-tickets'), 'ok'];
    }
}
