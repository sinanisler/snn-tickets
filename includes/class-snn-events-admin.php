<?php
/**
 * Events list and the event page: People, Sign-up form, Emails, Door and
 * Event settings, with every action behind them.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Events_Admin {

    public static function init() {
        foreach (['event_settings', 'event_form', 'event_emails', 'person', 'people_bulk', 'people_add',
                  'people_import', 'people_send', 'people_export', 'event_duplicate', 'event_delete'] as $a) {
            add_action('admin_post_snn_' . $a, [__CLASS__, 'handle_' . $a]);
        }
    }

    private static function tabs() {
        return [
            'people'   => __('People', 'snn-tickets'),
            'form'     => __('Sign-up form', 'snn-tickets'),
            'emails'   => __('Emails', 'snn-tickets'),
            'door'     => __('Door', 'snn-tickets'),
            'settings' => __('Event settings', 'snn-tickets'),
        ];
    }

    public static function render_page() {
        SNN_T_Admin::cap();
        $id = isset($_GET['event']) ? (int)$_GET['event'] : 0;
        if ($id) { self::render_event($id); return; }
        self::render_list();
    }

    /* ==================================================================
     * Events list
     * ================================================================== */

    private static function render_list() {
        $show   = isset($_GET['show']) ? sanitize_key(wp_unslash($_GET['show'])) : 'upcoming';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $today  = substr(current_time('mysql'), 0, 10);
        $all    = SNN_T_Events::all();

        $groups = ['upcoming' => [], 'past' => [], 'nodate' => [], 'all' => $all];
        foreach ($all as $e) {
            if (!$e->event_start) $groups['nodate'][] = $e;
            elseif (substr($e->event_end ?: $e->event_start, 0, 10) >= $today) $groups['upcoming'][] = $e;
            else $groups['past'][] = $e;
        }
        usort($groups['upcoming'], function ($a, $b) { return strcmp($a->event_start, $b->event_start); });
        if (!isset($groups[$show])) $show = 'upcoming';
        if ($show === 'upcoming' && !$groups['upcoming'] && $all) $show = 'all';
        $list = $groups[$show];
        if ($search !== '') {
            $list = array_filter($list, function ($e) use ($search) { return stripos($e->name . ' ' . $e->venue, $search) !== false; });
        }
        $labels = ['upcoming' => __('Upcoming', 'snn-tickets'), 'past' => __('Past', 'snn-tickets'), 'nodate' => __('No date yet', 'snn-tickets'), 'all' => __('All', 'snn-tickets')];
        $base = admin_url('admin.php?page=snn-tickets-events');
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Events', 'snn-tickets'); ?> <a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-new')); ?>"><?php esc_html_e('Add New Event', 'snn-tickets'); ?></a></h1>
            <?php SNN_T_Admin::notice(); ?>
            <div class="snn-page">
            <?php if (!$all): ?>
                <div class="snn-card snn-empty">
                    <h2><?php esc_html_e('No events yet', 'snn-tickets'); ?></h2>
                    <p><?php esc_html_e('An event comes with its own sign-up page, emails and door scanner. It takes about a minute.', 'snn-tickets'); ?></p>
                    <p><a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-new')); ?>"><?php esc_html_e('Add your first event', 'snn-tickets'); ?></a></p>
                </div>
            <?php else: ?>
                <div class="snn-row">
                    <div class="snn-pills">
                        <?php foreach ($labels as $k => $l): ?>
                            <a class="<?php echo $show === $k ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg('show', $k, $base)); ?>"><?php echo esc_html($l . ' (' . count($groups[$k]) . ')'); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <span class="snn-spacer"></span>
                    <form method="get" class="snn-row">
                        <input type="hidden" name="page" value="snn-tickets-events"><input type="hidden" name="show" value="<?php echo esc_attr($show); ?>">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search events', 'snn-tickets'); ?>">
                        <button class="button"><?php esc_html_e('Search', 'snn-tickets'); ?></button>
                    </form>
                </div>
                <div class="snn-table-wrap">
                    <table class="snn-table">
                        <thead><tr><th><?php esc_html_e('Event', 'snn-tickets'); ?></th><th><?php esc_html_e('When', 'snn-tickets'); ?></th><th><?php esc_html_e('Spots', 'snn-tickets'); ?></th><th><?php esc_html_e('Checked in', 'snn-tickets'); ?></th><th><?php esc_html_e('Status', 'snn-tickets'); ?></th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$list): ?><tr><td colspan="6" class="snn-empty"><?php esc_html_e('No events here.', 'snn-tickets'); ?></td></tr><?php endif; ?>
                        <?php foreach ($list as $e):
                            $form = SNN_T_Forms::for_list($e->id);
                            $max = $form ? (int)$form->settings['max_tickets'] : 0;
                            $taken = SNN_T_Events::spots_taken($e->id);
                            $stats = SNN_T_Scanner::stats($e->id);
                            $waiting = SNN_T_Submissions::counts($e->id)['pending'];
                            list($label, $cls) = SNN_T_Dashboard::status($e, $form, $max, $taken);
                            $url = SNN_T_Admin::event_admin_url($e->id); ?>
                            <tr>
                                <td><div class="who"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($e->name); ?></a><span><?php echo esc_html($e->venue ?: '—'); ?></span></div></td>
                                <td class="snn-num"><?php if ($e->event_start): ?><?php echo esc_html(SNN_T_Events::format_date($e)); ?><br><span class="snn-muted snn-small"><?php echo esc_html(SNN_T_Events::format_time($e)); ?></span><?php else: ?><a href="<?php echo esc_url(SNN_T_Admin::event_admin_url($e->id, ['tab' => 'settings'])); ?>"><?php esc_html_e('Add a date', 'snn-tickets'); ?></a><?php endif; ?></td>
                                <td style="width:180px"><?php if ($max): ?><div class="snn-bar <?php echo $taken >= $max ? 'full' : ''; ?>"><i style="width:<?php echo (int)min(100, round(100 * $taken / $max)); ?>%"></i></div><?php endif; ?>
                                    <span class="snn-small snn-muted snn-num"><?php echo esc_html($taken . ' / ' . ($max ?: '∞')); ?></span>
                                    <?php if ($waiting): ?> · <a class="snn-small" style="color:#8a5a00;font-weight:600" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($e->id, ['filter' => 'waiting'])); ?>"><?php echo esc_html(sprintf(__('%d waiting', 'snn-tickets'), $waiting)); ?></a><?php endif; ?></td>
                                <td class="snn-num"><?php echo (int)$stats['checked_in'] . ' / ' . (int)$stats['total']; ?></td>
                                <td><?php echo SNN_T_Admin::chip($label, $cls); ?></td>
                                <td class="act"><a class="button button-small" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Open', 'snn-tickets'); ?></a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"><input type="hidden" name="action" value="snn_event_duplicate"><input type="hidden" name="event" value="<?php echo (int)$e->id; ?>"><?php wp_nonce_field('snn_event_duplicate'); ?><button class="button button-small"><?php esc_html_e('Duplicate', 'snn-tickets'); ?></button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ==================================================================
     * Event page
     * ================================================================== */

    private static function render_event($id) {
        $event = SNN_T_Events::get($id);
        if (!$event) {
            echo '<div class="wrap snn-wrap"><h1>' . esc_html__('Event not found', 'snn-tickets') . '</h1><p><a href="' . esc_url(admin_url('admin.php?page=snn-tickets-events')) . '">' . esc_html__('Back to all events', 'snn-tickets') . '</a></p></div>';
            return;
        }
        $tabs = self::tabs();
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'people';
        if (!isset($tabs[$tab])) $tab = 'people';
        if (!empty($_GET['filter']) || !empty($_GET['person'])) $tab = 'people';

        $form    = SNN_T_Forms::for_list($id);
        $max     = $form ? (int)$form->settings['max_tickets'] : 0;
        $taken   = SNN_T_Events::spots_taken($id);
        $counts  = SNN_T_People::counts($id);
        $stats   = SNN_T_Scanner::stats($id);
        $send    = SNN_T_People::send_counts($id);
        list($label, $cls) = SNN_T_Dashboard::status($event, $form, $max, $taken);
        $signup  = SNN_T_Router::event_url($event);
        $when    = SNN_T_Events::format_when($event);
        $where   = SNN_T_Events::format_where($event);
        ?>
        <div class="wrap snn-wrap">
            <p class="snn-crumb"><a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-events')); ?>"><?php esc_html_e('Events', 'snn-tickets'); ?></a> › <?php echo esc_html($event->name); ?></p>
            <h1><?php echo esc_html($event->name); ?> <?php echo SNN_T_Admin::chip($label, $cls); ?></h1>
            <p class="snn-sub"><?php echo esc_html(implode('  ·  ', array_filter([$when ?: __('No date yet', 'snn-tickets'), $where]))); ?></p>
            <?php SNN_T_Admin::notice(); ?>

            <div class="snn-page">
                <?php if (!empty($_GET['created'])): ?>
                    <div class="snn-hint" style="font-size:14px"><p><b><?php esc_html_e('Your event is live.', 'snn-tickets'); ?></b> <?php esc_html_e('Share this link and people can sign up:', 'snn-tickets'); ?></p>
                        <p class="snn-row"><span class="snn-mono" style="font-size:14px"><?php echo esc_html($signup); ?></span> <?php echo SNN_T_Admin::copy_button($signup, __('Copy link', 'snn-tickets')); ?> <a class="button button-small" href="<?php echo esc_url($signup); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open', 'snn-tickets'); ?> ↗</a></p></div>
                <?php endif; ?>

                <div class="snn-share">
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <span class="url"><?php echo esc_html($signup); ?></span>
                    <?php echo SNN_T_Admin::copy_button($signup, __('Copy sign-up link', 'snn-tickets')); ?>
                    <a class="button button-small" href="<?php echo esc_url($signup); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'snn-tickets'); ?> ↗</a>
                    <span class="snn-spacer"></span>
                    <button type="button" class="button button-small" data-open="snn-share"><?php esc_html_e('More ways to share & place', 'snn-tickets'); ?></button>
                </div>

                <div class="snn-kpis">
                    <div class="snn-kpi"><span class="l"><?php esc_html_e('Spots taken', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($taken); ?> <small><?php echo $max ? '/ ' . number_format_i18n($max) : esc_html__('no limit', 'snn-tickets'); ?></small></span><?php if ($max): ?><div class="snn-bar <?php echo $taken >= $max ? 'full' : ''; ?>"><i style="width:<?php echo (int)min(100, round(100 * $taken / $max)); ?>%"></i></div><?php endif; ?></div>
                    <a class="snn-kpi <?php echo $counts['waiting'] ? 'warn' : ''; ?>" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($id, ['filter' => 'waiting'])); ?>"><span class="l"><?php esc_html_e('Waiting for you', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($counts['waiting']); ?></span><?php if ($counts['waiting']): ?><span class="snn-small" style="color:#8a5a00;font-weight:600"><?php esc_html_e('Review →', 'snn-tickets'); ?></span><?php endif; ?></a>
                    <a class="snn-kpi <?php echo $counts['problems'] ? 'bad' : ''; ?>" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($id, ['filter' => $counts['problems'] ? 'problems' : 'ticket'])); ?>"><span class="l"><?php esc_html_e('Tickets emailed', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($send['all'] - $send['unsent']); ?> <small>/ <?php echo number_format_i18n($send['all']); ?></small></span><?php if ($counts['problems']): ?><span class="snn-small" style="color:#b3261e"><?php echo esc_html(sprintf(_n('%d failed', '%d failed', $counts['problems'], 'snn-tickets'), $counts['problems'])); ?></span><?php endif; ?></a>
                    <a class="snn-kpi" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($id, ['tab' => 'door'])); ?>"><span class="l"><?php esc_html_e('Checked in', 'snn-tickets'); ?></span><span class="v"><?php echo number_format_i18n($stats['checked_in']); ?> <small>/ <?php echo number_format_i18n($stats['total']); ?></small></span><div class="snn-bar ok"><i style="width:<?php echo $stats['total'] ? (int)round(100 * $stats['checked_in'] / $stats['total']) : 0; ?>%"></i></div></a>
                </div>

                <nav class="snn-tabs">
                    <?php foreach ($tabs as $k => $l): ?>
                        <a class="<?php echo $tab === $k ? 'on' : ''; ?>" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($id, ['tab' => $k])); ?>"><?php echo esc_html($l); ?><?php if ($k === 'people' && $counts['waiting']): ?> <span class="cnt"><?php echo (int)$counts['waiting']; ?></span><?php endif; ?></a>
                    <?php endforeach; ?>
                </nav>

                <?php
                switch ($tab) {
                    case 'form':     self::tab_form($event, $form); break;
                    case 'emails':   self::tab_emails($event, $form); break;
                    case 'door':     self::tab_door($event); break;
                    case 'settings': self::tab_settings($event); break;
                    default:         self::tab_people($event, $form, $counts, $send);
                }
                ?>
            </div>
            <?php SNN_T_Admin::share_dialog($event); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * People
     * ---------------------------------------------------------------- */

    private static function why_waiting($form) {
        if (!$form) return '';
        return $form->settings['approval_mode'] === 'conditional'
            ? __("Didn't match your rules", 'snn-tickets')
            : __('You approve everyone for this event', 'snn-tickets');
    }

    private static function tab_people($event, $form, $counts, $send) {
        $id     = $event->id;
        $filter = isset($_GET['filter']) ? sanitize_key(wp_unslash($_GET['filter'])) : 'all';
        if (!isset(SNN_T_People::filters()[$filter])) $filter = 'all';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        list($rows, $total) = SNN_T_People::query($id, $filter, $search, $paged);
        $why    = self::why_waiting($form);
        $post   = esc_url(admin_url('admin-post.php'));
        $base   = SNN_T_Admin::event_admin_url($id);
        ?>
        <div class="snn-row">
            <div class="snn-pills">
                <?php foreach (SNN_T_People::filters() as $k => $l):
                    if (in_array($k, ['problems', 'off'], true) && !$counts[$k] && $filter !== $k) continue; ?>
                    <a class="<?php echo $filter === $k ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg(['filter' => $k, 's' => $search], $base)); ?>"><?php echo esc_html($l . ' · ' . number_format_i18n($counts[$k])); ?></a>
                <?php endforeach; ?>
            </div>
            <span class="snn-spacer"></span>
            <form method="get" class="snn-row">
                <input type="hidden" name="page" value="snn-tickets-events"><input type="hidden" name="event" value="<?php echo (int)$id; ?>"><input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Name, email, code or answer', 'snn-tickets'); ?>">
                <button class="button"><?php esc_html_e('Search', 'snn-tickets'); ?></button>
            </form>
            <button type="button" class="button button-primary" data-open="snn-add"><?php esc_html_e('+ Add people', 'snn-tickets'); ?></button>
            <button type="button" class="button" data-open="snn-send"><?php esc_html_e('Email everyone', 'snn-tickets'); ?></button>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=snn_people_export&event=' . $id), 'snn_people_export')); ?>"><?php esc_html_e('Download list', 'snn-tickets'); ?></a>
        </div>

        <form method="post" action="<?php echo $post; ?>" data-bulk-form>
            <input type="hidden" name="action" value="snn_people_bulk"><input type="hidden" name="event" value="<?php echo (int)$id; ?>">
            <?php wp_nonce_field('snn_people_bulk'); ?>
            <div class="snn-bulk" data-bulk-bar hidden style="margin-bottom:10px">
                <b data-bulk-n></b><span class="snn-spacer"></span>
                <button class="button button-small" name="do" value="approve" data-bulk-do data-ask="<?php esc_attr_e('Approve %d and email their tickets?', 'snn-tickets'); ?>"><?php esc_html_e('Approve', 'snn-tickets'); ?></button>
                <button class="button button-small" name="do" value="resend" data-bulk-do data-ask="<?php esc_attr_e('Email the ticket to %d people?', 'snn-tickets'); ?>"><?php esc_html_e('Email ticket', 'snn-tickets'); ?></button>
                <button class="button button-small" name="do" value="undo" data-bulk-do data-ask="<?php esc_attr_e('Undo the check-in for %d people?', 'snn-tickets'); ?>"><?php esc_html_e('Undo check-in', 'snn-tickets'); ?></button>
                <button class="button button-small" name="do" value="cancel" data-bulk-do data-ask="<?php esc_attr_e('Cancel %d tickets? They will be refused at the door.', 'snn-tickets'); ?>"><?php esc_html_e('Cancel tickets', 'snn-tickets'); ?></button>
                <button class="button button-small" name="do" value="decline" data-bulk-do data-ask="<?php esc_attr_e('Decline %d people?', 'snn-tickets'); ?>"><?php esc_html_e('Decline', 'snn-tickets'); ?></button>
                <button class="button button-small" name="do" value="delete" data-bulk-do data-ask="<?php esc_attr_e('Permanently delete %d people and their tickets? This cannot be undone.', 'snn-tickets'); ?>"><?php esc_html_e('Delete', 'snn-tickets'); ?></button>
                <button class="button button-small" value="clear" data-bulk-do aria-label="<?php esc_attr_e('Clear selection', 'snn-tickets'); ?>">✕</button>
            </div>
            <div class="snn-table-wrap">
                <table class="snn-table">
                    <thead><tr><th class="cb"><input type="checkbox" data-bulk-all aria-label="<?php esc_attr_e('Select all', 'snn-tickets'); ?>"></th><th><?php esc_html_e('Person', 'snn-tickets'); ?></th><th><?php esc_html_e('Status', 'snn-tickets'); ?></th><th><?php esc_html_e('At the door', 'snn-tickets'); ?></th><th><?php esc_html_e('Signed up', 'snn-tickets'); ?></th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="snn-empty"><?php
                            if ($search !== '' || $filter !== 'all') esc_html_e('Nobody matches.', 'snn-tickets');
                            else esc_html_e('Nobody yet. Share the sign-up link, or add people yourself.', 'snn-tickets'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $p):
                        $open = add_query_arg(['person' => $p->key, 'filter' => $filter, 's' => $search, 'paged' => $paged], $base); ?>
                        <tr class="<?php echo $p->state === 'waiting' ? 'waiting' : ''; ?>">
                            <td class="cb"><input type="checkbox" name="ids[]" value="<?php echo esc_attr($p->key); ?>" aria-label="<?php esc_attr_e('Select', 'snn-tickets'); ?>"></td>
                            <td><div class="who"><a href="<?php echo esc_url($open); ?>"><?php echo esc_html($p->name !== '' ? $p->name : ($p->email !== '' ? $p->email : __('No name yet', 'snn-tickets'))); ?></a>
                                <span><?php echo esc_html($p->email !== '' ? $p->email : ($p->code !== '' ? $p->code : '')); ?></span>
                                <?php if ($p->state === 'waiting' && $why): ?><span style="color:#8a5a00"><?php echo esc_html(sprintf(__('Why waiting: %s', 'snn-tickets'), $why)); ?></span><?php endif; ?></div></td>
                            <td><?php echo SNN_T_Admin::state_chip($p->state); ?></td>
                            <td><?php if ($p->vc > 0): ?>
                                    <?php echo $p->vc > 1 ? SNN_T_Admin::chip(sprintf(__('In · scanned %d×', 'snn-tickets'), $p->vc), 'warn') : SNN_T_Admin::chip(sprintf(__('In · %s', 'snn-tickets'), date_i18n(get_option('time_format'), strtotime($p->lv))), 'ok'); ?>
                                <?php elseif ($p->kind === 't' && $p->st === 'active'): ?><span class="snn-muted snn-small"><?php esc_html_e('Not yet', 'snn-tickets'); ?></span>
                                <?php else: ?><span class="snn-muted">—</span><?php endif; ?></td>
                            <td class="snn-small snn-muted"><?php echo SNN_T_Admin::when($p->created); ?></td>
                            <td class="act">
                                <?php if ($p->state === 'waiting'): ?>
                                    <button class="button button-primary button-small" name="one" value="approve|<?php echo esc_attr($p->key); ?>"><?php esc_html_e('Approve', 'snn-tickets'); ?></button>
                                    <button class="button button-small" name="one" value="decline|<?php echo esc_attr($p->key); ?>"><?php esc_html_e('Decline', 'snn-tickets'); ?></button>
                                <?php elseif ($p->state === 'bounced'): ?>
                                    <a class="button button-small" href="<?php echo esc_url($open); ?>"><?php esc_html_e('Fix email', 'snn-tickets'); ?></a>
                                <?php elseif ($p->state === 'unsent' && $p->email !== ''): ?>
                                    <button class="button button-small" name="one" value="resend|<?php echo esc_attr($p->key); ?>"><?php esc_html_e('Email ticket', 'snn-tickets'); ?></button>
                                <?php else: ?>
                                    <a class="button button-small" href="<?php echo esc_url($open); ?>"><?php esc_html_e('Details', 'snn-tickets'); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?php echo SNN_T_Admin::pager($total, SNN_T_People::PER_PAGE, $paged); ?>

        <?php
        self::add_dialog($event);
        self::send_dialog($event, $send);
        self::import_dialog($event);
        if (!empty($_GET['person'])) self::person_panel($event, $form, sanitize_key(wp_unslash($_GET['person'])));
    }

    private static function add_dialog($event) {
        ?>
        <dialog class="snn-dialog" id="snn-add">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="snn_people_add"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>">
                <?php wp_nonce_field('snn_people_add'); ?>
                <div class="snn-dialog-h"><h2><?php esc_html_e('Add people', 'snn-tickets'); ?></h2><button type="button" class="snn-x" data-close aria-label="<?php esc_attr_e('Close', 'snn-tickets'); ?>">×</button></div>
                <div class="snn-dialog-b">
                    <div class="snn-pills"><a href="#" class="on" data-dtab="one"><?php esc_html_e('One person', 'snn-tickets'); ?></a><a href="#" data-dtab="csv"><?php esc_html_e('From a spreadsheet', 'snn-tickets'); ?></a><a href="#" data-dtab="blank"><?php esc_html_e('Blank tickets', 'snn-tickets'); ?></a></div>
                    <div class="snn-col" data-dpane="one">
                        <div class="snn-grid2">
                            <label class="snn-field"><span><?php esc_html_e('Name', 'snn-tickets'); ?></span><input type="text" name="name" placeholder="Ayşe Demir"></label>
                            <label class="snn-field"><span><?php esc_html_e('Email', 'snn-tickets'); ?></span><input type="email" name="email" placeholder="ayse@example.com"></label>
                        </div>
                        <label class="snn-check"><input type="checkbox" name="send" value="1" checked> <span><?php esc_html_e('Email their ticket now', 'snn-tickets'); ?></span></label>
                        <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('For guests, speakers and people who paid at the door. This skips the sign-up form and the spot limit.', 'snn-tickets'); ?></p>
                    </div>
                    <div class="snn-col" data-dpane="csv" hidden>
                        <label class="snn-field"><span><?php esc_html_e('Spreadsheet file (.csv)', 'snn-tickets'); ?></span><input type="file" name="csv" accept=".csv,text/csv"></label>
                        <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('In Excel or Google Sheets, use "Save as / Download as CSV". It needs a header row; next you will check which column holds the names and emails. Turkish headers and semicolons work too.', 'snn-tickets'); ?></p>
                    </div>
                    <div class="snn-col" data-dpane="blank" hidden>
                        <p style="margin:0"><?php esc_html_e('Tickets with no name, for walk-ins, door sales or printed ticket books. You can fill in names later.', 'snn-tickets'); ?></p>
                        <label class="snn-field" style="max-width:160px"><span><?php esc_html_e('How many?', 'snn-tickets'); ?></span><input type="number" name="count" value="50" min="1" max="5000"></label>
                        <details class="snn-more"><summary><?php esc_html_e('More options', 'snn-tickets'); ?></summary><div>
                            <label class="snn-field" style="max-width:160px"><span><?php esc_html_e('Code length', 'snn-tickets'); ?></span><input type="number" name="length" value="8" min="6" max="64"><small><?php esc_html_e('Letters and numbers. 8 is plenty.', 'snn-tickets'); ?></small></label>
                        </div></details>
                    </div>
                </div>
                <div class="snn-dialog-f">
                    <button type="button" class="button" data-close><?php esc_html_e('Cancel', 'snn-tickets'); ?></button>
                    <button class="button button-primary" name="mode" value="one" data-dtab-label="<?php echo esc_attr(wp_json_encode(['one' => __('Add & send ticket', 'snn-tickets'), 'csv' => __('Upload & check columns', 'snn-tickets'), 'blank' => __('Make blank tickets', 'snn-tickets')])); ?>"><?php esc_html_e('Add & send ticket', 'snn-tickets'); ?></button>
                </div>
            </form>
        </dialog>
        <?php
    }

    private static function send_dialog($event, $send) {
        $templates = SNN_T_Mailer::templates_for_role('ticket');
        ?>
        <dialog class="snn-dialog" id="snn-send">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="snn_people_send"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>">
                <?php wp_nonce_field('snn_people_send'); ?>
                <div class="snn-dialog-h"><h2><?php esc_html_e('Email everyone', 'snn-tickets'); ?></h2><button type="button" class="snn-x" data-close aria-label="<?php esc_attr_e('Close', 'snn-tickets'); ?>">×</button></div>
                <div class="snn-dialog-b">
                    <h3 style="margin:0"><?php esc_html_e('Who', 'snn-tickets'); ?></h3>
                    <div class="snn-choice">
                        <label class="snn-opt"><input type="radio" name="who" value="unsent" checked><span class="ic">✉</span><span><b><?php echo esc_html(sprintf(__("People who haven't got their ticket yet · %d", 'snn-tickets'), $send['unsent'])); ?></b><span class="snn-muted"><?php esc_html_e('Imported or added without email, or the email failed.', 'snn-tickets'); ?></span></span></label>
                        <label class="snn-opt"><input type="radio" name="who" value="all"><span class="ic">👥</span><span><b><?php echo esc_html(sprintf(__('Everyone with a ticket · %d', 'snn-tickets'), $send['all'])); ?></b><span class="snn-muted"><?php echo esc_html(sprintf(__('%d of them already have it and will get it again.', 'snn-tickets'), $send['all'] - $send['unsent'])); ?></span></span></label>
                    </div>
                    <label class="snn-field"><span><?php esc_html_e('Which email', 'snn-tickets'); ?></span>
                        <select name="template"><option value=""><?php esc_html_e("This event's ticket email", 'snn-tickets'); ?></option>
                            <?php foreach ($templates as $n => $t): ?><option value="<?php echo esc_attr($n); ?>"><?php echo esc_html(sprintf(__('Template: %s', 'snn-tickets'), $n)); ?></option><?php endforeach; ?>
                        </select></label>
                    <div class="snn-hint"><?php printf(esc_html__('Emails go out in the background, %d per minute. You can close this page.', 'snn-tickets'), (int)SNN_T_Mailer::batch_size()); ?> <a href="<?php echo esc_url(SNN_T_Admin::event_admin_url($event->id, ['tab' => 'emails'])); ?>"><?php esc_html_e('Preview the ticket email', 'snn-tickets'); ?></a></div>
                </div>
                <div class="snn-dialog-f">
                    <button type="button" class="button" data-close><?php esc_html_e('Cancel', 'snn-tickets'); ?></button>
                    <button class="button button-primary"><?php esc_html_e('Send', 'snn-tickets'); ?></button>
                </div>
            </form>
        </dialog>
        <?php
    }

    private static function import_dialog($event) {
        $token = isset($_GET['import']) ? sanitize_key(wp_unslash($_GET['import'])) : '';
        if ($token === '') return;
        $data = get_transient('snn_t_imp_' . $token);
        if (!is_array($data)) return;
        $guess = SNN_T_People::guess_columns($data['headers']);
        $opts = function ($sel) use ($data) {
            $o = '<option value="">' . esc_html__('— none —', 'snn-tickets') . '</option>';
            foreach ($data['headers'] as $i => $h) $o .= '<option value="' . (int)$i . '"' . selected($sel, $i, false) . '>' . esc_html($h !== '' ? $h : sprintf(__('Column %d', 'snn-tickets'), $i + 1)) . '</option>';
            return $o;
        };
        $dupes = 0;
        foreach (SNN_T_People::map_rows($data['rows'], $guess['name'], $guess['email']) as $r) {
            if ($r['email'] !== '' && SNN_T_Tickets::count_for_email($event->id, $r['email'])) $dupes++;
        }
        ?>
        <dialog class="snn-dialog" id="snn-import" data-autoopen>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="snn_people_import"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                <?php wp_nonce_field('snn_people_import'); ?>
                <div class="snn-dialog-h"><h2><?php echo esc_html(sprintf(__('Import %1$s · %2$d rows', 'snn-tickets'), $data['file'], count($data['rows']))); ?></h2><button type="button" class="snn-x" data-close aria-label="<?php esc_attr_e('Close', 'snn-tickets'); ?>">×</button></div>
                <div class="snn-dialog-b">
                    <p style="margin:0"><b><?php esc_html_e('Check the columns.', 'snn-tickets'); ?></b> <?php esc_html_e('We guessed; change them if they are wrong.', 'snn-tickets'); ?></p>
                    <div class="snn-grid2">
                        <label class="snn-field"><span><?php esc_html_e('Name is in', 'snn-tickets'); ?></span><select name="name_col"><?php echo $opts($guess['name']); // escaped ?></select></label>
                        <label class="snn-field"><span><?php esc_html_e('Email is in', 'snn-tickets'); ?></span><select name="email_col"><?php echo $opts($guess['email']); // escaped ?></select></label>
                    </div>
                    <div class="snn-table-wrap"><table class="snn-table" style="min-width:420px">
                        <thead><tr><?php foreach ($data['headers'] as $h): ?><th><?php echo esc_html($h); ?></th><?php endforeach; ?></tr></thead>
                        <tbody><?php foreach (array_slice($data['rows'], 0, 4) as $row): ?><tr><?php foreach ($data['headers'] as $i => $h): ?><td><?php echo esc_html($row[$i] ?? ''); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                    </table></div>
                    <label class="snn-check"><input type="checkbox" name="skip" value="1" checked> <span><?php esc_html_e('Skip people who already have a ticket', 'snn-tickets'); ?><?php if ($dupes): ?> <span class="snn-muted snn-small"><?php echo esc_html(sprintf(_n('(%d found)', '(%d found)', $dupes, 'snn-tickets'), $dupes)); ?></span><?php endif; ?></span></label>
                    <label class="snn-check"><input type="checkbox" name="send" value="1"> <span><?php esc_html_e('Email their tickets right away', 'snn-tickets'); ?></span></label>
                </div>
                <div class="snn-dialog-f"><button type="button" class="button" data-close><?php esc_html_e('Cancel', 'snn-tickets'); ?></button><button class="button button-primary"><?php esc_html_e('Import', 'snn-tickets'); ?></button></div>
            </form>
        </dialog>
        <?php
    }

    /** The side panel with everything about one person. */
    private static function person_panel($event, $form, $key) {
        $p = SNN_T_People::find($event->id, $key);
        $close = remove_query_arg(['person', 'snn_msg', 'snn_type']);
        if (!$p) return;
        $post  = esc_url(admin_url('admin-post.php'));
        $sub   = (int)$p->sid ? SNN_T_Submissions::get((int)$p->sid) : null;
        $labels = SNN_T_Submissions::field_labels($form);
        $ticket = $p->kind === 't' ? SNN_T_Tickets::get($p->id) : null;
        $emails = SNN_T_People::emails_for($p);
        $note   = $p->kind === 't' ? (string)($ticket->note ?? '') : (string)($sub->decision_reason ?? '');
        $decider = ($sub && $sub->decided_by) ? get_userdata((int)$sub->decided_by) : null;

        $action = function ($do, $label, $class = '', $ask = '') use ($post, $event, $p) {
            return '<form method="post" action="' . $post . '" style="display:inline">'
                . '<input type="hidden" name="action" value="snn_person"><input type="hidden" name="event" value="' . (int)$event->id . '">'
                . '<input type="hidden" name="person" value="' . esc_attr($p->key) . '"><input type="hidden" name="do" value="' . esc_attr($do) . '">'
                . wp_nonce_field('snn_person', '_wpnonce', true, false)
                . '<button class="button ' . esc_attr($class) . '"' . ($ask !== '' ? ' data-confirm="' . esc_attr($ask) . '"' : '') . '>' . esc_html($label) . '</button></form>';
        };
        ?>
        <div class="snn-scrim" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Person', 'snn-tickets'); ?>" onclick="if(event.target===this)location.href=<?php echo esc_attr(wp_json_encode($close)); ?>">
            <div class="snn-drawer">
                <div class="snn-drawer-h">
                    <div style="flex:1;min-width:0"><h2><?php echo esc_html($p->name !== '' ? $p->name : __('No name yet', 'snn-tickets')); ?></h2><span class="snn-muted snn-small"><?php echo esc_html($p->email); ?></span></div>
                    <?php echo SNN_T_Admin::state_chip($p->state); ?>
                    <a class="snn-x" href="<?php echo esc_url($close); ?>" aria-label="<?php esc_attr_e('Close', 'snn-tickets'); ?>">×</a>
                </div>
                <div class="snn-drawer-b">
                    <div class="snn-row">
                        <?php
                        if ($p->state === 'waiting') {
                            echo $action('approve', __('Approve & send ticket', 'snn-tickets'), 'button-primary');
                            echo $action('decline', __('Decline', 'snn-tickets'));
                        } elseif ($p->state === 'declined') {
                            echo $action('approve', __('Approve after all', 'snn-tickets'), 'button-primary');
                        } elseif ($p->state === 'cancelled') {
                            echo $action('restore', __('Restore ticket', 'snn-tickets'), 'button-primary');
                        } elseif ($p->kind === 't') {
                            if ($p->email !== '') echo $action('resend', $p->state === 'unsent' ? __('Email ticket', 'snn-tickets') : __('Email ticket again', 'snn-tickets'), $p->state === 'unsent' ? 'button-primary' : '');
                            if ($p->vc > 0) echo $action('undo', __('Undo check-in', 'snn-tickets'));
                            echo $action('cancel', __('Cancel ticket', 'snn-tickets'), 'button-link-delete', __('Cancel this ticket? It will be refused at the door.', 'snn-tickets'));
                        }
                        ?>
                    </div>

                    <?php if ($p->state === 'bounced'):
                        $last = $emails ? $emails[0] : null; ?>
                        <div class="snn-hint bad"><p><b><?php esc_html_e('The ticket email could not be delivered.', 'snn-tickets'); ?></b></p>
                            <?php if ($last && $last->last_error): ?><p class="snn-small"><?php echo esc_html($last->last_error); ?></p><?php endif; ?>
                            <p><?php esc_html_e('Correct the address below and press "Save & email ticket".', 'snn-tickets'); ?></p></div>
                    <?php endif; ?>

                    <?php if ($ticket): ?>
                        <div><h3><?php esc_html_e('Ticket', 'snn-tickets'); ?></h3>
                            <div class="snn-ticketbox">
                                <img src="<?php echo esc_attr(SNN_T_QR::data_uri($ticket->ticket_code, 4, 2)); ?>" alt="">
                                <div class="snn-col" style="gap:6px"><span class="snn-mono" style="font-size:15px;font-weight:700;letter-spacing:.08em"><?php echo esc_html($ticket->ticket_code); ?></span>
                                    <span><?php echo SNN_T_Admin::state_chip($p->state); ?></span>
                                    <span class="snn-row"><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(SNN_T_Files::url('view', $ticket->ticket_code)); ?>"><?php esc_html_e('Ticket page', 'snn-tickets'); ?> ↗</a>
                                        <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(SNN_T_Files::url('pdf', $ticket->ticket_code)); ?>">PDF ↗</a></span></div>
                            </div></div>
                    <?php else: ?>
                        <p class="snn-muted" style="margin:0"><?php esc_html_e("No ticket yet. It's created when you approve.", 'snn-tickets'); ?></p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo $post; ?>" class="snn-col">
                        <input type="hidden" name="action" value="snn_person"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><input type="hidden" name="person" value="<?php echo esc_attr($p->key); ?>">
                        <?php wp_nonce_field('snn_person'); ?>
                        <h3><?php esc_html_e('Contact', 'snn-tickets'); ?></h3>
                        <div class="snn-grid2">
                            <label class="snn-field"><span><?php esc_html_e('Name', 'snn-tickets'); ?></span><input type="text" name="name" value="<?php echo esc_attr($p->name); ?>"></label>
                            <label class="snn-field"><span><?php esc_html_e('Email', 'snn-tickets'); ?></span><input type="email" name="email" value="<?php echo esc_attr($p->email); ?>"></label>
                        </div>
                        <label class="snn-field"><span><?php esc_html_e('Private note', 'snn-tickets'); ?> <small><?php esc_html_e('(only admins see this)', 'snn-tickets'); ?></small></span><textarea name="note" rows="2"><?php echo esc_textarea($note); ?></textarea></label>
                        <div class="snn-row"><button class="button" name="do" value="save"><?php esc_html_e('Save', 'snn-tickets'); ?></button>
                            <?php if ($p->kind === 't' && $p->st === 'active'): ?><button class="button button-primary" name="do" value="save_send"><?php esc_html_e('Save & email ticket', 'snn-tickets'); ?></button><?php endif; ?></div>
                    </form>

                    <?php if ($p->answers): ?>
                        <div><h3><?php esc_html_e('Answers', 'snn-tickets'); ?></h3>
                            <dl class="snn-dl">
                                <?php foreach ($p->answers as $k => $v): ?>
                                    <dt><?php echo esc_html($labels[$k] ?? $k); ?></dt><dd><?php $a = SNN_T_Submissions::answer($v); echo $a !== '' ? nl2br(esc_html($a)) : '<span class="snn-muted">—</span>'; ?></dd>
                                <?php endforeach; ?>
                            </dl></div>
                    <?php endif; ?>

                    <div><h3><?php esc_html_e('History', 'snn-tickets'); ?></h3>
                        <ul class="snn-timeline">
                            <?php
                            $sources = ['form' => __('Signed up with the form', 'snn-tickets'), 'import' => __('Imported from a spreadsheet', 'snn-tickets'),
                                        'manual' => __('Added by hand', 'snn-tickets'), 'blank' => __('Made as a blank ticket', 'snn-tickets')];
                            $created = $sub ? $sub->created_at : $p->created; ?>
                            <li><b><?php echo esc_html($sources[$p->source] ?? __('Added', 'snn-tickets')); ?></b><time><?php echo SNN_T_Admin::when($created); ?></time></li>
                            <?php if ($sub && $sub->status === 'pending'): ?>
                                <li class="warn"><b><?php esc_html_e('Waiting for your approval', 'snn-tickets'); ?></b><?php $w = self::why_waiting($form); if ($w): ?> · <?php echo esc_html($w); ?><?php endif; ?></li>
                            <?php elseif ($sub && $sub->decided_at): ?>
                                <li class="<?php echo $sub->status === 'approved' ? 'ok' : 'bad'; ?>"><b><?php echo $sub->status === 'approved' ? esc_html__('Approved', 'snn-tickets') : esc_html__('Declined', 'snn-tickets'); ?></b>
                                    <?php echo $decider ? esc_html(sprintf(__('by %s', 'snn-tickets'), $decider->display_name)) : esc_html__('automatically', 'snn-tickets'); ?>
                                    <?php if ($sub->decision_reason && $p->kind === 't'): ?> · <?php echo esc_html($sub->decision_reason); ?><?php endif; ?>
                                    <time><?php echo SNN_T_Admin::when($sub->decided_at); ?></time></li>
                            <?php endif; ?>
                            <?php foreach (array_reverse($emails) as $m):
                                $roles = SNN_T_Mailer::roles(); ?>
                                <li class="<?php echo $m->status === 'failed' ? 'bad' : ''; ?>"><b><?php echo esc_html($roles[$m->role] ?? $m->role); ?></b> · <?php echo SNN_T_Admin::status_badge($m->status); ?>
                                    <?php if ($m->attachments): ?><span class="snn-muted snn-small">📎 <?php echo esc_html(strtoupper(str_replace(',', ', ', $m->attachments))); ?></span><?php endif; ?>
                                    <a class="snn-small" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=log&view=' . (int)$m->id)); ?>"><?php esc_html_e('View', 'snn-tickets'); ?></a>
                                    <time><?php echo esc_html($m->to_email); ?> · <?php echo SNN_T_Admin::when($m->sent_at ?: $m->created_at); ?></time></li>
                            <?php endforeach; ?>
                            <?php if ($p->vc > 0): ?>
                                <li class="<?php echo $p->vc > 1 ? 'warn' : 'ok'; ?>"><b><?php echo esc_html($p->vc > 1 ? sprintf(__('Checked in · scanned %d times', 'snn-tickets'), $p->vc) : __('Checked in', 'snn-tickets')); ?></b><time><?php echo esc_html(sprintf(__('Last scan %s', 'snn-tickets'), wp_strip_all_tags(SNN_T_Admin::when($p->lv)))); ?></time></li>
                            <?php endif; ?>
                            <?php if ($p->state === 'cancelled'): ?><li class="bad"><b><?php esc_html_e('Ticket cancelled', 'snn-tickets'); ?></b></li><?php endif; ?>
                        </ul>
                        <?php if ($sub && $sub->ip): ?><p class="snn-muted snn-small" style="margin:0"><?php echo esc_html(sprintf(__('Signed up from IP %s', 'snn-tickets'), $sub->ip)); ?></p><?php endif; ?>
                    </div>

                    <details class="snn-more"><summary style="color:#b32d2e"><?php esc_html_e('Delete this person', 'snn-tickets'); ?></summary><div>
                        <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Removes their sign-up, ticket and emails, for example after a privacy request. Their QR code stops working.', 'snn-tickets'); ?></p>
                        <div><?php echo $action('delete', __('Delete permanently', 'snn-tickets'), 'button-link-delete', __('Delete this person and their ticket? This cannot be undone.', 'snn-tickets')); ?></div>
                    </div></details>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Sign-up form
     * ---------------------------------------------------------------- */

    private static function tab_form($event, $form) {
        $fields   = $form ? $form->fields : SNN_T_Forms::default_fields();
        $settings = $form ? $form->settings : SNN_T_Forms::default_settings();
        $count    = count(SNN_T_Forms::all_for_list($event->id));
        $cfg = self::builder_cfg($fields, $settings, (bool)$form);
        $s = $settings;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-dirty>
            <input type="hidden" name="action" value="snn_event_form"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>">
            <input type="hidden" name="fields_json"><input type="hidden" name="settings_json">
            <?php wp_nonce_field('snn_event_form'); ?>
            <div class="snn-col" style="gap:18px">
                <?php if (!$form): ?><div class="snn-hint warn"><?php esc_html_e('This event has no sign-up form yet (its people were imported or added by hand). Save this tab to give it one.', 'snn-tickets'); ?></div><?php endif; ?>
                <?php if ($count > 1): ?><div class="snn-hint"><?php echo esc_html(sprintf(__('This event has %d forms from an older version. This tab edits the first one; the others keep working through their shortcodes.', 'snn-tickets'), $count)); ?></div><?php endif; ?>

                <div class="snn-side" data-builder data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>" data-head="<?php echo esc_attr('<p class="snn-muted snn-small" style="margin:0;font-weight:700;text-transform:uppercase;letter-spacing:.05em">' . esc_html(SNN_T_Events::format_when($event)) . '</p><h2>' . esc_html($event->name) . '</h2>'); ?>">
                    <div class="snn-col" style="gap:18px">
                        <div class="snn-card">
                            <div class="snn-card-h"><h2><?php esc_html_e('Questions', 'snn-tickets'); ?></h2><span class="snn-muted snn-small"><?php esc_html_e('Click one to change it. ▲▼ to reorder.', 'snn-tickets'); ?></span></div>
                            <div class="snn-qs" data-questions></div>
                            <div class="snn-addq" data-add-question></div>
                        </div>

                        <div class="snn-card">
                            <h2><?php esc_html_e('Who gets a ticket?', 'snn-tickets'); ?></h2>
                            <?php self::approval_choice('approval_mode', $s['approval_mode']); ?>
                            <div data-rules-box><div data-rules></div></div>
                        </div>

                        <div class="snn-card">
                            <h2><?php esc_html_e('Limits', 'snn-tickets'); ?></h2>
                            <div class="snn-row" style="align-items:flex-start">
                                <label class="snn-field" style="max-width:160px"><span><?php esc_html_e('Spots', 'snn-tickets'); ?></span><input type="number" min="0" value="<?php echo esc_attr($s['max_tickets'] ?: ''); ?>" placeholder="<?php esc_attr_e('No limit', 'snn-tickets'); ?>" data-setting="max_tickets"></label>
                                <p class="snn-muted snn-small" style="flex:1;min-width:220px;margin:24px 0 0"><?php esc_html_e('People waiting for your approval hold a spot, so you never approve more people than fit. Leave empty for no limit.', 'snn-tickets'); ?></p>
                            </div>
                            <label class="snn-check"><input type="checkbox" data-setting="one_per_email" <?php checked(!empty($s['one_per_email'])); ?>> <span><?php esc_html_e('One ticket per email address', 'snn-tickets'); ?></span></label>
                            <label class="snn-check"><input type="checkbox" data-setting="show_remaining" <?php checked(!empty($s['show_remaining'])); ?>> <span><?php esc_html_e('Show "spots left" above the form', 'snn-tickets'); ?></span></label>
                            <label class="snn-switch"><input type="checkbox" name="open" value="1" <?php checked(!$form || $form->status === 'active'); ?>><i></i><?php esc_html_e('Open for sign-ups', 'snn-tickets'); ?></label>
                            <p class="snn-muted snn-small" style="margin:-6px 0 0"><?php esc_html_e('Turn off to close the form without deleting anything. Visitors then see the "full or closed" message.', 'snn-tickets'); ?></p>
                        </div>

                        <div class="snn-card">
                            <details class="snn-more plain"><summary><?php esc_html_e('Messages people see', 'snn-tickets'); ?></summary><div>
                                <?php foreach ([
                                    'submit_label'      => __('Button text', 'snn-tickets'),
                                    'success_message'   => __('After signing up (ticket sent)', 'snn-tickets'),
                                    'pending_message'   => __('After signing up (waiting for approval)', 'snn-tickets'),
                                    'full_message'      => __('When the event is full or closed', 'snn-tickets'),
                                    'duplicate_message' => __('When the email is already signed up', 'snn-tickets'),
                                    'error_message'     => __('When something goes wrong', 'snn-tickets'),
                                ] as $k => $l): ?>
                                    <label class="snn-field"><span><?php echo esc_html($l); ?></span><input type="text" value="<?php echo esc_attr($s[$k]); ?>" data-setting="<?php echo esc_attr($k); ?>"></label>
                                <?php endforeach; ?>
                                <label class="snn-field"><span><?php esc_html_e('Send people to another page after signing up', 'snn-tickets'); ?> <small><?php esc_html_e('(optional)', 'snn-tickets'); ?></small></span><input type="url" value="<?php echo esc_attr($s['redirect_url']); ?>" placeholder="https://" data-setting="redirect_url"></label>
                            </div></details>
                            <details class="snn-more"><summary><?php esc_html_e('Form colour', 'snn-tickets'); ?></summary><div>
                                <div class="snn-row"><input type="color" value="<?php echo esc_attr($s['accent_color'] ?: '#111111'); ?>" data-setting="accent_color" style="width:44px;height:32px"><span class="snn-muted snn-small"><?php esc_html_e('For the button, focus rings and ticks. Everything else follows your theme.', 'snn-tickets'); ?></span></div>
                            </div></details>
                            <?php if ($form): ?>
                            <details class="snn-more"><summary><?php esc_html_e('Place the form on your own page', 'snn-tickets'); ?></summary><div>
                                <p class="snn-row" style="margin:0"><code>[snn_ticket_form id="<?php echo (int)$form->id; ?>"]</code> <?php echo SNN_T_Admin::copy_button('[snn_ticket_form id="' . (int)$form->id . '"]'); ?></p>
                                <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Not needed: the sign-up link already works. Use this if you want the form inside a page you designed.', 'snn-tickets'); ?></p>
                            </div></details>
                            <?php endif; ?>
                        </div>
                        <div class="snn-row"><span class="snn-spacer"></span><span class="snn-muted snn-small" data-dirty-note hidden><?php esc_html_e('Unsaved changes', 'snn-tickets'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save form', 'snn-tickets'); ?></button></div>
                    </div>
                    <aside class="snn-fpv"><div class="snn-fpv-h"><span><?php esc_html_e('Sign-up form', 'snn-tickets'); ?></span><span><?php esc_html_e('Live preview', 'snn-tickets'); ?></span></div><div class="snn-fpv-b" data-form-preview></div></aside>
                </div>
            </div>
        </form>
        <?php
    }

    /** Everything the question and rules builders need. */
    public static function builder_cfg($fields, $settings, $saved) {
        return [
            'fields'    => array_values($fields),
            'settings'  => $settings,
            'types'     => SNN_T_Forms::field_types(),
            'ops'       => SNN_T_Forms::operators(),
            'valueless' => SNN_T_Forms::valueless_operators(),
            'saved'     => $saved,
            'newLabels' => [
                'text' => __('New question', 'snn-tickets'), 'textarea' => __('Anything else we should know?', 'snn-tickets'),
                'select' => __('Which session?', 'snn-tickets'), 'radio' => __('Which day?', 'snn-tickets'),
                'checkbox' => __('Topics you like', 'snn-tickets'), 'consent' => __('I agree to the event rules', 'snn-tickets'),
                'email' => __('Work email', 'snn-tickets'), 'tel' => __('Phone number', 'snn-tickets'),
                'number' => __('How many guests?', 'snn-tickets'), 'date' => __('Date of birth', 'snn-tickets'),
                'hidden' => __('Came from', 'snn-tickets'),
            ],
        ];
    }

    /** The three "who gets a ticket" cards. */
    public static function approval_choice($name, $current) {
        $opts = [
            'auto'        => ['⚡', __('Everyone, right away', 'snn-tickets'), __('They sign up and get the ticket email within a minute.', 'snn-tickets'), true],
            'manual'      => ['✋', __('I approve each person', 'snn-tickets'), __('They get a "we got your request" email. You press Approve and the ticket goes out.', 'snn-tickets'), false],
            'conditional' => ['⚙', __('Automatically, if they match my rules', 'snn-tickets'), __('For example: company email addresses get a ticket, everyone else waits for you.', 'snn-tickets'), false],
        ];
        echo '<div class="snn-choice">';
        foreach ($opts as $k => $o) {
            echo '<label class="snn-opt"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($k) . '" data-setting="approval_mode"' . checked($current, $k, false) . '>'
               . '<span class="ic" aria-hidden="true">' . $o[0] . '</span><span><b>' . esc_html($o[1]) . '</b><span class="snn-muted">' . esc_html($o[2]) . '</span></span>'
               . ($o[3] ? SNN_T_Admin::chip(__('Most common', 'snn-tickets'), 'ok rec') : '') . '</label>';
        }
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     * Emails
     * ---------------------------------------------------------------- */

    private static function tab_emails($event, $form) {
        $roles  = SNN_T_Mailer::roles();
        $when   = SNN_T_Mailer::role_when();
        $emails = SNN_T_Events::emails($event);
        $icons  = ['ticket' => '🎟', 'confirmation' => '⏳', 'rejection' => '✉', 'admin' => '🔔'];
        $mail   = isset($_GET['mail']) ? sanitize_key(wp_unslash($_GET['mail'])) : 'ticket';
        if (!isset($roles[$mail])) $mail = 'ticket';
        $cur    = $emails[$mail];
        $mode   = $form ? $form->settings['approval_mode'] : 'auto';

        $answers = [];
        if ($form) foreach ($form->fields as $f) {
            if (in_array($f['map_to'], ['name', 'email'], true) || $f['type'] === 'consent') continue;
            $answers['{field:' . $f['key'] . '}'] = $f['label'];
        }
        $unused = ($mail === 'confirmation' && $mode === 'auto') || ($mail === 'rejection' && $mode === 'auto');
        ?>
        <div class="snn-side-l">
            <div class="snn-mails">
                <?php foreach ($roles as $r => $label):
                    $on = !empty($emails[$r]['on']); ?>
                    <a class="snn-ml <?php echo $r === $mail ? 'on' : ''; ?> <?php echo $on ? '' : 'off'; ?>" href="<?php echo esc_url(SNN_T_Admin::event_admin_url($event->id, ['tab' => 'emails', 'mail' => $r])); ?>">
                        <span class="ic" aria-hidden="true"><?php echo $icons[$r]; ?></span>
                        <span class="grow"><b><?php echo esc_html($label); ?></b><br><span class="snn-small snn-muted"><?php echo esc_html($when[$r]); ?></span></span>
                        <?php echo $on ? SNN_T_Admin::chip(__('On', 'snn-tickets'), 'ok') : SNN_T_Admin::chip(__('Off', 'snn-tickets')); ?>
                    </a>
                <?php endforeach; ?>
                <p class="snn-muted snn-small" style="margin:4px"><?php printf(esc_html__('Colours, logo and footer come from %s. Saved templates can be reused in any event.', 'snn-tickets'), '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=look')) . '">' . esc_html__('Settings → Look', 'snn-tickets') . '</a>'); ?></p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-card" data-dirty>
                <input type="hidden" name="action" value="snn_event_emails"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><input type="hidden" name="role" value="<?php echo esc_attr($mail); ?>">
                <?php wp_nonce_field('snn_event_emails'); ?>
                <div class="snn-card-h"><h2><?php echo esc_html($roles[$mail]); ?></h2>
                    <label class="snn-switch"><input type="checkbox" name="mail[on]" value="1" <?php checked(!empty($cur['on'])); ?>><i></i><?php esc_html_e('Send this email', 'snn-tickets'); ?></label></div>
                <p class="snn-muted" style="margin:-8px 0 0"><?php echo esc_html($when[$mail]); ?>
                    <?php if ($mail === 'ticket'): ?> <?php esc_html_e('Turned off, tickets are only sent when you press "Email ticket".', 'snn-tickets'); ?><?php endif; ?></p>
                <?php if ($unused): ?><div class="snn-hint warn"><?php esc_html_e('This event gives everyone a ticket right away, so this email is never sent. It is used when you approve people yourself or by rules.', 'snn-tickets'); ?></div><?php endif; ?>
                <?php if ($mail === 'admin'): ?>
                    <div class="snn-grid2">
                        <label class="snn-field"><span><?php esc_html_e('Send it when', 'snn-tickets'); ?></span><select name="mail[when]">
                            <option value="waiting" <?php selected($cur['when'], 'waiting'); ?>><?php esc_html_e('Someone is waiting for my approval', 'snn-tickets'); ?></option>
                            <option value="all" <?php selected($cur['when'], 'all'); ?>><?php esc_html_e('Anyone signs up', 'snn-tickets'); ?></option></select></label>
                        <label class="snn-field"><span><?php esc_html_e('Send it to', 'snn-tickets'); ?></span><input type="email" name="mail[to]" value="<?php echo esc_attr($cur['to']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"></label>
                    </div>
                <?php endif; ?>
                <?php
                $after = '';
                if ($mail === 'ticket') {
                    $types = SNN_T_Events::attachment_types();
                    $att = $event->attachment_list ? implode(', ', array_map(function ($a) use ($types) { return $types[$a]; }, $event->attachment_list)) : __('nothing', 'snn-tickets');
                    $after = '<p class="snn-muted snn-small" style="margin:0">📎 ' . esc_html(sprintf(__('Attached: %s', 'snn-tickets'), $att)) . ' · <a href="' . esc_url(SNN_T_Admin::event_admin_url($event->id, ['tab' => 'settings'])) . '">' . esc_html__('change', 'snn-tickets') . '</a></p>';
                }
                SNN_T_Admin::email_editor([
                    'role' => $mail, 'list' => $event->id, 'name' => 'mail',
                    'subject' => $cur['subject'], 'body' => $cur['body'], 'answers' => $answers, 'library' => true, 'after' => $after,
                ]);
                ?>
                <div class="snn-row"><span class="snn-spacer"></span><span class="snn-muted snn-small" data-dirty-note hidden><?php esc_html_e('Unsaved changes', 'snn-tickets'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save email', 'snn-tickets'); ?></button></div>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Door
     * ---------------------------------------------------------------- */

    private static function tab_door($event) {
        global $wpdb;
        $stats = SNN_T_Scanner::stats($event->id);
        $door  = SNN_T_Router::door_url($event);
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, ticket_code, validate_count, last_validated FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND last_validated IS NOT NULL ORDER BY last_validated DESC LIMIT 25", $event->id));
        $pct = $stats['total'] ? (int)round(100 * $stats['checked_in'] / $stats['total']) : 0;
        ?>
        <div class="snn-side">
            <div class="snn-col" style="gap:18px">
                <div class="snn-card">
                    <div class="snn-door-big">
                        <div><div class="v snn-num"><?php echo (int)$stats['checked_in']; ?><span class="snn-muted" style="font-size:22px"> / <?php echo (int)$stats['total']; ?></span></div><span class="snn-muted"><?php esc_html_e('checked in', 'snn-tickets'); ?></span></div>
                        <div class="grow"><div class="snn-bar ok" style="height:12px"><i style="width:<?php echo $pct; ?>%"></i></div>
                            <span class="snn-muted snn-small"><?php echo $event->event_start ? esc_html(sprintf(__('The event starts %s. Reload this page to see new check-ins.', 'snn-tickets'), SNN_T_Events::format_when($event))) : esc_html__('Reload this page to see new check-ins.', 'snn-tickets'); ?></span></div>
                    </div>
                </div>
                <div class="snn-card">
                    <h2><?php esc_html_e('Recent check-ins', 'snn-tickets'); ?></h2>
                    <?php if (!$recent): ?>
                        <p class="snn-muted" style="margin:0"><?php esc_html_e('Nobody has been checked in yet.', 'snn-tickets'); ?></p>
                    <?php else: ?>
                    <div class="snn-table-wrap" style="border:0"><table class="snn-table" style="min-width:440px"><tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr><td><a href="<?php echo esc_url(SNN_T_Admin::event_admin_url($event->id, ['person' => 't' . (int)$r->id])); ?>"><b><?php echo esc_html($r->name ?: __('No name', 'snn-tickets')); ?></b></a><br><span class="snn-mono snn-muted"><?php echo esc_html($r->ticket_code); ?></span></td>
                                <td><?php echo (int)$r->validate_count > 1
                                        ? SNN_T_Admin::chip(sprintf(__('Scanned %d× · last %s', 'snn-tickets'), (int)$r->validate_count, date_i18n(get_option('time_format'), strtotime($r->last_validated))), 'warn')
                                        : SNN_T_Admin::chip(sprintf(__('In · %s', 'snn-tickets'), date_i18n(get_option('time_format'), strtotime($r->last_validated))), 'ok'); ?></td>
                                <td class="act"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="snn_person"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><input type="hidden" name="person" value="t<?php echo (int)$r->id; ?>"><input type="hidden" name="do" value="undo"><input type="hidden" name="back" value="door">
                                    <?php wp_nonce_field('snn_person'); ?><button class="button button-small"><?php esc_html_e('Undo', 'snn-tickets'); ?></button></form></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="snn-col" style="gap:18px">
                <div class="snn-card" style="align-items:center;text-align:center">
                    <h2><?php esc_html_e('Open the scanner on a phone', 'snn-tickets'); ?></h2>
                    <?php echo SNN_T_Admin::qr_img($door, 160); ?>
                    <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Point a phone camera here. It opens the scanner for this event only: tickets for other events are refused.', 'snn-tickets'); ?></p>
                    <div class="snn-row" style="justify-content:center"><a class="button button-primary" href="<?php echo esc_url($door); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open scanner', 'snn-tickets'); ?> ↗</a><?php echo SNN_T_Admin::copy_button($door, __('Copy link', 'snn-tickets')); ?></div>
                </div>
                <div class="snn-card">
                    <h2><?php esc_html_e('Who can scan', 'snn-tickets'); ?></h2>
                    <p style="margin:0">✓ <?php esc_html_e('Admins who are logged in', 'snn-tickets'); ?><br>
                        <?php if (SNN_T_Scanner::pin_set()): ?>✓ <?php esc_html_e('Volunteers with the door PIN', 'snn-tickets'); ?> <?php echo SNN_T_Admin::chip(__('PIN set', 'snn-tickets'), 'ok'); ?>
                        <?php else: ?><span class="snn-muted">✕ <?php esc_html_e('Volunteers: no door PIN set yet', 'snn-tickets'); ?></span><?php endif; ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=door')); ?>"><?php echo SNN_T_Scanner::pin_set() ? esc_html__('Change the PIN', 'snn-tickets') : esc_html__('Set a door PIN', 'snn-tickets'); ?></a>
                    <div class="snn-hint snn-small"><?php esc_html_e('If a camera does not work, staff can type the code printed under the QR.', 'snn-tickets'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Event settings
     * ---------------------------------------------------------------- */

    private static function tab_settings($event) {
        $date  = $event->event_start ? substr($event->event_start, 0, 10) : '';
        $start = $event->event_start ? substr($event->event_start, 11, 5) : '';
        $end_d = $event->event_end ? substr($event->event_end, 0, 10) : '';
        $end_t = $event->event_end ? substr($event->event_end, 11, 5) : '';
        $multi = $end_d !== '' && $end_d !== $date;
        $base  = SNN_T_Router::pretty() ? home_url(user_trailingslashit(SNN_T_Router::base() . '/')) : '';
        $n     = SNN_T_Tickets::count_in_list($event->id);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-card" data-dirty>
            <input type="hidden" name="action" value="snn_event_settings"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>">
            <input type="hidden" name="attachments[]" value="">
            <?php wp_nonce_field('snn_event_settings'); ?>
            <div class="snn-set"><div><h3><?php esc_html_e('Details', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Printed on the ticket, in emails, in wallet passes and in the calendar invite.', 'snn-tickets'); ?></p></div><div class="body">
                <label class="snn-field"><span><?php esc_html_e('Event name', 'snn-tickets'); ?></span><input type="text" name="name" value="<?php echo esc_attr($event->name); ?>" required></label>
                <div class="snn-grid3">
                    <label class="snn-field"><span><?php esc_html_e('Date', 'snn-tickets'); ?></span><input type="date" name="date" value="<?php echo esc_attr($date); ?>"></label>
                    <label class="snn-field"><span><?php esc_html_e('Starts', 'snn-tickets'); ?></span><input type="time" name="start" value="<?php echo esc_attr($start); ?>"></label>
                    <label class="snn-field"><span><?php esc_html_e('Ends', 'snn-tickets'); ?></span><input type="time" name="end" value="<?php echo esc_attr($end_t); ?>"></label>
                </div>
                <details class="snn-more plain" <?php echo $multi ? 'open' : ''; ?>><summary><?php esc_html_e('Ends on a different day', 'snn-tickets'); ?></summary><div>
                    <label class="snn-field" style="max-width:220px"><span><?php esc_html_e('End date', 'snn-tickets'); ?></span><input type="date" name="end_date" value="<?php echo esc_attr($multi ? $end_d : ''); ?>"></label></div></details>
                <p class="snn-muted snn-small" style="margin:0"><?php echo esc_html(sprintf(__('Times use your site timezone (%s).', 'snn-tickets'), wp_timezone_string())); ?></p>
                <label class="snn-field"><span><?php esc_html_e('Venue', 'snn-tickets'); ?></span><input type="text" name="venue" value="<?php echo esc_attr($event->venue); ?>" placeholder="<?php esc_attr_e('Grand Hall', 'snn-tickets'); ?>"></label>
                <label class="snn-field"><span><?php esc_html_e('Address', 'snn-tickets'); ?></span><input type="text" name="address" value="<?php echo esc_attr($event->address); ?>"></label>
                <label class="snn-field"><span><?php esc_html_e('Organiser', 'snn-tickets'); ?></span><input type="text" name="organizer" value="<?php echo esc_attr($event->organizer); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></label>
                <label class="snn-field"><span><?php esc_html_e('Good to know', 'snn-tickets'); ?> <small><?php esc_html_e('(optional)', 'snn-tickets'); ?></small></span><textarea name="description" rows="3" placeholder="<?php esc_attr_e('Doors open at 18:30. Bring a photo ID.', 'snn-tickets'); ?>"><?php echo esc_textarea($event->description); ?></textarea><small><?php esc_html_e('Shown on the sign-up page, the PDF, the wallet pass and the ticket page.', 'snn-tickets'); ?></small></label>
            </div></div>

            <div class="snn-set"><div><h3><?php esc_html_e('Web address', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('The end of the sign-up link. If you change it, the old link keeps working.', 'snn-tickets'); ?></p></div><div class="body">
                <?php if ($base !== ''): ?>
                    <label class="snn-field"><span><?php esc_html_e('Link', 'snn-tickets'); ?></span><span class="snn-row" style="flex-wrap:nowrap;gap:4px"><span class="snn-mono snn-muted" style="white-space:nowrap"><?php echo esc_html(preg_replace('#^https?://#', '', $base)); ?></span><input type="text" name="slug" value="<?php echo esc_attr($event->slug); ?>"></span></label>
                <?php else: ?>
                    <p class="snn-muted" style="margin:0"><?php esc_html_e('Your site uses plain permalinks, so events use technical links. Choose another option under Settings → Permalinks for short links.', 'snn-tickets'); ?></p>
                <?php endif; ?>
            </div></div>

            <div class="snn-set"><div><h3><?php esc_html_e('Ticket look', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php printf(esc_html__('Colours and logo for every event are in %s.', 'snn-tickets'), '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=look')) . '">' . esc_html__('Settings → Look', 'snn-tickets') . '</a>'); ?></p></div><div class="body">
                <?php echo SNN_T_Admin::looks_picker('design', $event->design, true); // escaped inside ?>
                <p style="margin:0"><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=snn_design_pdf&list_id=' . $event->id), 'snn_design_pdf')); ?>"><?php esc_html_e('Preview PDF ticket', 'snn-tickets'); ?> ↗</a></p>
            </div></div>

            <div class="snn-set"><div><h3><?php esc_html_e('Attached to the ticket email', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Download buttons for these are in the email anyway; attaching also puts the file itself in the email.', 'snn-tickets'); ?></p></div><div class="body">
                <?php foreach (SNN_T_Events::attachment_types() as $k => $l):
                    $off = $k === 'pkpass' && !SNN_T_Wallet::apple_ready(); ?>
                    <label class="snn-check"><input type="checkbox" name="attachments[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $event->attachment_list, true)); ?> <?php disabled($off); ?>>
                        <span><?php echo esc_html($l); ?><?php if ($off): ?> · <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=wallet')); ?>"><?php esc_html_e('set up Apple Wallet first', 'snn-tickets'); ?></a><?php endif; ?>
                        <?php if ($k === 'ics'): ?><span class="snn-muted snn-small"> <?php esc_html_e('(needs a date)', 'snn-tickets'); ?></span><?php endif; ?></span></label>
                <?php endforeach; ?>
            </div></div>
            <div class="snn-row"><span class="snn-spacer"></span><span class="snn-muted snn-small" data-dirty-note hidden><?php esc_html_e('Unsaved changes', 'snn-tickets'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save event', 'snn-tickets'); ?></button></div>
        </form>

        <div class="snn-card">
            <div class="snn-set"><div><h3><?php esc_html_e('Copy this event', 'snn-tickets'); ?></h3></div><div class="body">
                <p class="snn-muted" style="margin:0"><?php esc_html_e('Makes a new event with the same questions, rules, emails and look. People and tickets are not copied, and the copy starts closed for sign-ups.', 'snn-tickets'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="snn_event_duplicate"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><?php wp_nonce_field('snn_event_duplicate'); ?><button class="button"><?php esc_html_e('Duplicate event', 'snn-tickets'); ?></button></form>
            </div></div>
            <div class="snn-set"><div><h3 style="color:#b32d2e"><?php esc_html_e('Delete event', 'snn-tickets'); ?></h3></div><div class="body">
                <p class="snn-muted" style="margin:0"><?php echo esc_html(sprintf(_n('Deletes the event, its sign-up form, %d ticket and all emails. QR codes stop working at the door. This cannot be undone.', 'Deletes the event, its sign-up form, %d tickets and all emails. QR codes stop working at the door. This cannot be undone.', $n, 'snn-tickets'), $n)); ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=snn_people_export&event=' . $event->id), 'snn_people_export')); ?>"><?php esc_html_e('Download the list first', 'snn-tickets'); ?></a></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-row">
                    <input type="hidden" name="action" value="snn_event_delete"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><?php wp_nonce_field('snn_event_delete'); ?>
                    <label><?php printf(esc_html__('Type %s to confirm:', 'snn-tickets'), '<b>DELETE</b>'); ?> <input type="text" name="confirm" style="width:120px" data-type-confirm="snn-del-go" data-word="DELETE" autocomplete="off"></label>
                    <button class="button button-link-delete" id="snn-del-go" disabled><?php esc_html_e('Delete forever', 'snn-tickets'); ?></button>
                </form>
            </div></div>
        </div>
        <?php
    }

    /* ==================================================================
     * Handlers
     * ================================================================== */

    private static function guard($action) {
        SNN_T_Admin::cap();
        check_admin_referer($action);
        $id = (int)($_POST['event'] ?? $_GET['event'] ?? 0);
        if (!SNN_T_Events::get($id)) wp_die(esc_html__('That event no longer exists.', 'snn-tickets'));
        return $id;
    }

    /** Build start/end datetimes from the date and time boxes. */
    public static function datetimes($in) {
        $date = trim((string)($in['date'] ?? ''));
        if ($date === '') return ['event_start' => '', 'event_end' => ''];
        $start = trim((string)($in['start'] ?? '')) ?: '00:00';
        $end   = trim((string)($in['end'] ?? ''));
        $end_d = trim((string)($in['end_date'] ?? '')) ?: $date;
        return ['event_start' => $date . ' ' . $start, 'event_end' => $end !== '' ? $end_d . ' ' . $end : ''];
    }

    public static function handle_event_settings() {
        $id = self::guard('snn_event_settings');
        $in = wp_unslash($_POST);
        SNN_T_Events::save($id, array_merge([
            'name' => $in['name'] ?? '', 'venue' => $in['venue'] ?? '', 'address' => $in['address'] ?? '',
            'organizer' => $in['organizer'] ?? '', 'description' => $in['description'] ?? '',
            'design' => $in['design'] ?? '', 'attachments' => array_filter((array)($in['attachments'] ?? [])),
        ], self::datetimes($in)));
        $msg = __('Event saved.', 'snn-tickets');
        if (isset($in['slug'])) {
            $before = SNN_T_Events::get($id)->slug;
            $after  = SNN_T_Events::set_slug($id, sanitize_title($in['slug']));
            if ($after !== $before) $msg .= ' ' . sprintf(__('The sign-up link is now %s; the old one still works.', 'snn-tickets'), SNN_T_Router::event_url($id));
            elseif (sanitize_title($in['slug']) !== '' && sanitize_title($in['slug']) !== $after) $msg .= ' ' . __('That web address was taken, so a number was added.', 'snn-tickets');
        }
        SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'settings']), $msg);
    }

    public static function handle_event_form() {
        $id = self::guard('snn_event_form');
        $event = SNN_T_Events::get($id);
        $form  = SNN_T_Forms::for_list($id);
        $fields   = json_decode(wp_unslash($_POST['fields_json'] ?? ''), true);
        $settings = json_decode(wp_unslash($_POST['settings_json'] ?? ''), true);
        if (!is_array($fields) || !$fields) SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'form']), __('The form needs at least one question.', 'snn-tickets'), true);
        $base = $form ? $form->settings : SNN_T_Forms::default_settings();
        SNN_T_Forms::save($form ? $form->id : 0, [
            'name'     => $event->name,
            'list_id'  => $id,
            'status'   => !empty($_POST['open']) ? 'active' : 'closed',
            'fields'   => $fields,
            'settings' => array_merge($base, is_array($settings) ? $settings : []),
        ]);
        SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'form']), __('Sign-up form saved.', 'snn-tickets'));
    }

    public static function handle_event_emails() {
        $id   = self::guard('snn_event_emails');
        $role = sanitize_key($_POST['role'] ?? '');
        if (!isset(SNN_T_Mailer::roles()[$role])) wp_die('Bad email');
        $in = (array)wp_unslash($_POST['mail'] ?? []);
        $emails = SNN_T_Events::emails(SNN_T_Events::get($id));
        $default = SNN_T_Mailer::default_template($role);
        $subject = sanitize_text_field($in['subject'] ?? '');
        $body    = trim(wp_kses_post((string)($in['body'] ?? '')));
        // Unchanged default wording is stored as "use the default", so a
        // better default in a later version reaches this event too.
        $emails[$role] = array_merge($emails[$role], [
            'on'      => !empty($in['on']) ? 1 : 0,
            'subject' => $subject === $default['subject'] ? '' : $subject,
            'body'    => self::same_html($body, $default['body']) ? '' : $body,
        ]);
        if ($role === 'admin') {
            $emails['admin']['when'] = $in['when'] ?? 'waiting';
            $emails['admin']['to']   = $in['to'] ?? '';
        }
        SNN_T_Events::save_emails($id, $emails);
        SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'emails', 'mail' => $role]), __('Email saved.', 'snn-tickets'));
    }

    private static function same_html($a, $b) {
        $n = function ($s) { return preg_replace('/\s+/', ' ', trim(str_replace(['<br>', '<br/>', '<br />'], '', (string)$s))); };
        return $n($a) === $n($b);
    }

    private static function back_to_people($id, $msg, $err = false, $extra = []) {
        $ref = wp_get_referer();
        $url = ($ref && strpos($ref, 'page=snn-tickets-events') !== false) ? $ref : SNN_T_Admin::event_admin_url($id);
        SNN_T_Admin::go(add_query_arg($extra, remove_query_arg(['import'], $url)), $msg, $err);
    }

    private static function done_msg($do, $n) {
        $m = [
            'approve' => _n('%d approved; their ticket is on its way.', '%d approved; their tickets are on their way.', $n, 'snn-tickets'),
            'decline' => _n('%d declined.', '%d declined.', $n, 'snn-tickets'),
            'resend'  => _n('%d ticket email queued.', '%d ticket emails queued.', $n, 'snn-tickets'),
            'cancel'  => _n('%d ticket cancelled. It will be refused at the door.', '%d tickets cancelled. They will be refused at the door.', $n, 'snn-tickets'),
            'restore' => _n('%d ticket restored.', '%d tickets restored.', $n, 'snn-tickets'),
            'undo'    => _n('Check-in undone for %d person.', 'Check-in undone for %d people.', $n, 'snn-tickets'),
            'delete'  => _n('%d person deleted.', '%d people deleted.', $n, 'snn-tickets'),
        ];
        return sprintf($m[$do] ?? __('%d updated.', 'snn-tickets'), $n);
    }

    public static function handle_person() {
        $id  = self::guard('snn_person');
        $key = sanitize_key($_POST['person'] ?? '');
        $do  = sanitize_key($_POST['do'] ?? '');

        if ($do === 'save' || $do === 'save_send') {
            $p = SNN_T_People::find($id, $key);
            if (!$p) self::back_to_people($id, __('That person is no longer on this event.', 'snn-tickets'), true);
            $name = wp_unslash($_POST['name'] ?? ''); $email = wp_unslash($_POST['email'] ?? ''); $note = wp_unslash($_POST['note'] ?? '');
            if ($p->kind === 't') {
                $r = SNN_T_Tickets::update_contact($p->id, $name, $email);
                SNN_T_Tickets::set_note($p->id, $note);
            } else {
                $r = SNN_T_Submissions::update_contact($p->id, $name, $email);
                SNN_T_Submissions::set_note($p->id, $note);
            }
            if (is_wp_error($r)) self::back_to_people($id, $r->get_error_message(), true);
            $msg = __('Saved.', 'snn-tickets');
            if ($do === 'save_send' && $p->kind === 't') {
                $q = SNN_T_Mailer::queue_ticket(SNN_T_Tickets::get($p->id));
                if (is_wp_error($q)) self::back_to_people($id, $q->get_error_message(), true);
                SNN_T_Mailer::process_queue();
                $msg = __('Saved, and the ticket email is on its way.', 'snn-tickets');
            }
            self::back_to_people($id, $msg);
        }

        $r = SNN_T_People::act($id, $key, $do, ['note' => '']);
        if (in_array($do, ['approve', 'decline', 'resend'], true) && !is_wp_error($r)) SNN_T_Mailer::process_queue();
        if (($_POST['back'] ?? '') === 'door') {
            SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'door']), is_wp_error($r) ? $r->get_error_message() : self::done_msg($do, 1), is_wp_error($r));
        }
        $extra = $do === 'delete' ? [] : [];
        if ($do === 'delete') {
            SNN_T_Admin::go(remove_query_arg('person', wp_get_referer() ?: SNN_T_Admin::event_admin_url($id)), is_wp_error($r) ? $r->get_error_message() : self::done_msg($do, 1), is_wp_error($r));
        }
        // An approved sign-up becomes a ticket: follow it.
        if ($do === 'approve' && !is_wp_error($r) && strpos($key, 's') === 0) {
            $sub = SNN_T_Submissions::get((int)substr($key, 1));
            if ($sub && $sub->ticket_id) {
                $ref = wp_get_referer();
                if ($ref && strpos($ref, 'person=') !== false) SNN_T_Admin::go(add_query_arg('person', 't' . (int)$sub->ticket_id, $ref), self::done_msg($do, 1));
            }
        }
        self::back_to_people($id, is_wp_error($r) ? $r->get_error_message() : self::done_msg($do, 1), is_wp_error($r), $extra);
    }

    public static function handle_people_bulk() {
        $id = self::guard('snn_people_bulk');
        if (!empty($_POST['one'])) {
            list($do, $key) = array_pad(explode('|', sanitize_text_field(wp_unslash($_POST['one'])), 2), 2, '');
            $keys = [sanitize_key($key)];
            $do = sanitize_key($do);
        } else {
            $do   = sanitize_key($_POST['do'] ?? '');
            $keys = array_map('sanitize_key', (array)($_POST['ids'] ?? []));
        }
        if (!$do || !$keys) self::back_to_people($id, __('Tick at least one person first.', 'snn-tickets'), true);

        $done = 0; $errors = [];
        foreach ($keys as $k) {
            $r = SNN_T_People::act($id, $k, $do);
            if (is_wp_error($r)) $errors[] = $r->get_error_message(); else $done++;
        }
        if (in_array($do, ['approve', 'decline', 'resend'], true) && $done) SNN_T_Mailer::process_queue();
        $msg = self::done_msg($do, $done);
        if ($errors) $msg .= ' ' . sprintf(__('%1$d skipped: %2$s', 'snn-tickets'), count($errors), implode(' ', array_unique(array_slice($errors, 0, 2))));
        self::back_to_people($id, $msg, !$done);
    }

    public static function handle_people_add() {
        $id   = self::guard('snn_people_add');
        $mode = sanitize_key($_POST['mode'] ?? 'one');

        if ($mode === 'blank') {
            $n = SNN_T_People::add_blank($id, (int)($_POST['count'] ?? 50), (int)($_POST['length'] ?? 8));
            self::back_to_people($id, sprintf(_n('Made %d blank ticket.', 'Made %d blank tickets.', $n, 'snn-tickets'), $n));
        }

        if ($mode === 'csv') {
            $f = $_FILES['csv'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                self::back_to_people($id, __('Pick a .csv file first.', 'snn-tickets'), true);
            }
            $csv = SNN_T_People::read_csv($f['tmp_name']);
            if (is_wp_error($csv)) self::back_to_people($id, $csv->get_error_message(), true);
            if (!$csv['rows']) self::back_to_people($id, __('The file has a header row but no people under it.', 'snn-tickets'), true);
            $token = strtolower(wp_generate_password(12, false, false));
            set_transient('snn_t_imp_' . $token, $csv + ['file' => sanitize_file_name($f['name'])], HOUR_IN_SECONDS);
            SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['import' => $token]));
        }

        $r = SNN_T_People::add_one($id, wp_unslash($_POST['name'] ?? ''), wp_unslash($_POST['email'] ?? ''), !empty($_POST['send']));
        if (is_wp_error($r)) self::back_to_people($id, $r->get_error_message(), true);
        if (!empty($_POST['send'])) SNN_T_Mailer::process_queue();
        self::back_to_people($id, !empty($_POST['send']) && !empty($_POST['email'])
            ? __('Added. Their ticket is on its way.', 'snn-tickets')
            : __('Added.', 'snn-tickets'));
    }

    public static function handle_people_import() {
        $id    = self::guard('snn_people_import');
        $token = sanitize_key($_POST['token'] ?? '');
        $data  = get_transient('snn_t_imp_' . $token);
        if (!is_array($data)) self::back_to_people($id, __('That upload has expired. Please upload the file again.', 'snn-tickets'), true);
        $name_col  = ($_POST['name_col'] ?? '') === '' ? null : (int)$_POST['name_col'];
        $email_col = ($_POST['email_col'] ?? '') === '' ? null : (int)$_POST['email_col'];
        if ($name_col === null && $email_col === null) self::back_to_people($id, __('Pick the column with names or emails.', 'snn-tickets'), true, ['import' => $token]);
        $people = SNN_T_People::map_rows($data['rows'], $name_col, $email_col);
        list($added, $skipped) = SNN_T_People::import($id, $people, !empty($_POST['skip']), !empty($_POST['send']));
        delete_transient('snn_t_imp_' . $token);
        if (!empty($_POST['send'])) SNN_T_Mailer::process_queue();
        $msg = sprintf(_n('Imported %d person.', 'Imported %d people.', $added, 'snn-tickets'), $added);
        if ($skipped) $msg .= ' ' . sprintf(_n('%d already had a ticket and was skipped.', '%d already had a ticket and were skipped.', $skipped, 'snn-tickets'), $skipped);
        if (empty($_POST['send']) && $added) $msg .= ' ' . __('Use "Email everyone" when you are ready to send their tickets.', 'snn-tickets');
        self::back_to_people($id, $msg);
    }

    public static function handle_people_send() {
        $id  = self::guard('snn_people_send');
        $who = ($_POST['who'] ?? '') === 'all' ? 'all' : 'unsent';
        $n   = SNN_T_People::email_everyone($id, $who, sanitize_text_field(wp_unslash($_POST['template'] ?? '')));
        if ($n) SNN_T_Mailer::process_queue();
        self::back_to_people($id, $n
            ? sprintf(_n('%d ticket email is being sent in the background.', '%d ticket emails are being sent in the background.', $n, 'snn-tickets'), $n)
            : __('Nobody to send to: everyone already has their ticket.', 'snn-tickets'), !$n);
    }

    public static function handle_people_export() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_people_export');
        $id = (int)($_GET['event'] ?? 0);
        $event = SNN_T_Events::get($id);
        if (!$event) wp_die(esc_html__('That event no longer exists.', 'snn-tickets'));
        list($rows) = SNN_T_People::query($id, 'all', '', 1, 100000);
        $labels = SNN_T_Submissions::field_labels(SNN_T_Forms::for_list($id));
        $keys = [];
        foreach ($rows as $r) foreach (array_keys($r->answers) as $k) $keys[$k] = true;
        $keys = array_keys($keys);
        $states = SNN_T_People::states();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . sanitize_file_name($event->slug . '-people-' . date('Ymd') . '.csv'));
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel needs the BOM to read UTF-8
        fputcsv($out, array_merge(
            [__('Name', 'snn-tickets'), __('Email', 'snn-tickets'), __('Status', 'snn-tickets'), __('Ticket code', 'snn-tickets'),
             __('Checked in', 'snn-tickets'), __('Scans', 'snn-tickets'), __('Last scan', 'snn-tickets'), __('Signed up', 'snn-tickets'), __('Ticket link', 'snn-tickets')],
            array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $keys)
        ), ',', '"', '\\');
        foreach ($rows as $r) {
            $line = [$r->name, $r->email, $states[$r->state][0] ?? $r->state, $r->code, $r->vc > 0 ? __('yes', 'snn-tickets') : __('no', 'snn-tickets'),
                     $r->vc, $r->lv ?: '', $r->created, $r->code !== '' ? SNN_T_Files::url('view', $r->code) : ''];
            foreach ($keys as $k) { $v = $r->answers[$k] ?? ''; $line[] = is_array($v) ? implode(' | ', $v) : $v; }
            fputcsv($out, $line, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    public static function handle_event_duplicate() {
        $id  = self::guard('snn_event_duplicate');
        $new = SNN_T_Events::duplicate($id);
        SNN_T_Admin::go(SNN_T_Admin::event_admin_url($new, ['tab' => 'settings']),
            __('Event copied. Change the name and date, then open it for sign-ups on the Sign-up form tab.', 'snn-tickets'));
    }

    public static function handle_event_delete() {
        $id = self::guard('snn_event_delete');
        if (strtoupper(trim(wp_unslash($_POST['confirm'] ?? ''))) !== 'DELETE') {
            SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'settings']), __('Type DELETE to confirm.', 'snn-tickets'), true);
        }
        $name = SNN_T_Events::get($id)->name;
        $n = SNN_T_Events::delete($id);
        SNN_T_Admin::go(admin_url('admin.php?page=snn-tickets-events'), sprintf(__('Deleted "%1$s" and %2$d tickets.', 'snn-tickets'), $name, $n));
    }
}
