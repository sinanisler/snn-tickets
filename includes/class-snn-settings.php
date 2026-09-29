<?php
/**
 * Two screens that apply to every event:
 *
 *   Tickets → Emails    what was sent and what failed, saved templates,
 *                       the sender and a test
 *   Tickets → Settings  style, door & scanner, wallet passes, the wording
 *                       of public pages, and the advanced corner
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Settings {

    public static function init() {
        foreach (['general', 'look', 'door', 'advanced', 'wallet', 'template', 'template_delete', 'queue', 'wording'] as $a) {
            add_action('admin_post_snn_set_' . $a, [__CLASS__, 'save_' . $a]);
        }
        add_action('admin_init', [__CLASS__, 'moved_tabs']);
        add_action('admin_post_snn_wallet_test', [__CLASS__, 'wallet_test']);
        add_action('admin_post_snn_design_pdf', [__CLASS__, 'pdf_preview']);
        add_action('wp_ajax_snn_plain_test', [__CLASS__, 'ajax_plain_test']);
    }

    const EMAILS_PAGE   = 'snn-tickets-emails';
    const SETTINGS_PAGE = 'snn-tickets-settings';

    /** page => tab => label; the first tab is where a page opens. */
    private static function pages() {
        return [
            self::EMAILS_PAGE => [
                'log'       => __('Sent & waiting', 'snn-tickets'),
                'templates' => __('Templates', 'snn-tickets'),
                'general'   => __('Sender & test', 'snn-tickets'),
            ],
            self::SETTINGS_PAGE => [
                'look'     => __('Style', 'snn-tickets'),
                'door'     => __('Door & scanner', 'snn-tickets'),
                'wallet'   => __('Apple & Google Wallet', 'snn-tickets'),
                'wording'  => __('Wording', 'snn-tickets'),
                'advanced' => __('Advanced', 'snn-tickets'),
            ],
        ];
    }

    private static function page_of($tab) {
        foreach (self::pages() as $page => $tabs) if (isset($tabs[$tab])) return $page;
        return self::SETTINGS_PAGE;
    }

    public static function url($tab, $args = []) {
        return add_query_arg(array_merge(['page' => self::page_of($tab), 'tab' => $tab], $args), admin_url('admin.php'));
    }

    /** Email tabs used to live under Settings: old links land in the new place. */
    public static function moved_tabs() {
        if (($_GET['page'] ?? '') !== self::SETTINGS_PAGE) return;
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? ''));
        if ($tab === '' || self::page_of($tab) !== self::EMAILS_PAGE) return;
        $args = array_map(function ($v) { return is_string($v) ? sanitize_text_field(wp_unslash($v)) : ''; }, $_GET);
        unset($args['page'], $args['tab']);
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    private static function form_open($action, $multipart = false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ' class="snn-card" data-dirty>';
        echo '<input type="hidden" name="action" value="snn_set_' . esc_attr($action) . '">';
        wp_nonce_field('snn_set_' . $action);
    }

    private static function save_row($label) {
        echo '<div class="snn-row"><span class="snn-spacer"></span><span class="snn-muted snn-small" data-dirty-note hidden>' . esc_html__('Unsaved changes', 'snn-tickets') . '</span><button class="button button-primary">' . esc_html($label) . '</button></div>';
    }

    public static function render() {
        SNN_T_Admin::cap();
        $page = ($_GET['page'] ?? '') === self::EMAILS_PAGE ? self::EMAILS_PAGE : self::SETTINGS_PAGE;
        $tabs = self::pages()[$page];
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if (!isset($tabs[$tab])) $tab = array_key_first($tabs);
        $failed = SNN_T_Mailer::queue_counts()['failed'];
        ?>
        <div class="wrap snn-wrap">
            <h1><?php echo $page === self::EMAILS_PAGE ? esc_html__('Emails', 'snn-tickets') : esc_html__('Tickets Settings', 'snn-tickets'); ?></h1>
            <?php SNN_T_Admin::notice(); ?>
            <div class="snn-page">
                <nav class="snn-tabs">
                    <?php foreach ($tabs as $k => $l): ?>
                        <a class="<?php echo $tab === $k ? 'on' : ''; ?>" href="<?php echo esc_url(self::url($k)); ?>"><?php echo esc_html($l); ?><?php if ($k === 'log' && $failed): ?> <span class="cnt"><?php echo (int)$failed; ?></span><?php endif; ?></a>
                    <?php endforeach; ?>
                </nav>
                <?php call_user_func([__CLASS__, 'tab_' . $tab]); ?>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * General
     * ---------------------------------------------------------------- */

    private static function tab_general() {
        $d = SNN_T_Design::settings();
        self::form_open('general'); ?>
            <div class="snn-set"><div><h3><?php esc_html_e('Emails come from', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('What attendees see in their inbox.', 'snn-tickets'); ?></p></div><div class="body">
                <div class="snn-grid2">
                    <label class="snn-field"><span><?php esc_html_e('Name', 'snn-tickets'); ?></span><input type="text" name="from_name" value="<?php echo esc_attr(get_option(SNN_T_Mailer::FROM_NAME_OPTION, get_bloginfo('name'))); ?>"></label>
                    <label class="snn-field"><span><?php esc_html_e('Address', 'snn-tickets'); ?></span><input type="email" name="from_email" value="<?php echo esc_attr(get_option(SNN_T_Mailer::FROM_EMAIL_OPTION, '')); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"><small><?php esc_html_e('Leave empty to keep your site\'s usual sending address. The name above is used either way.', 'snn-tickets'); ?></small></label>
                </div>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Sending speed', 'snn-tickets'); ?></h3></div><div class="body">
                <label class="snn-field" style="max-width:220px"><span><?php esc_html_e('Emails per minute', 'snn-tickets'); ?></span><input type="number" name="batch_size" min="1" max="200" value="<?php echo esc_attr(SNN_T_Mailer::batch_size()); ?>"><small><?php esc_html_e('Lower it if your host or email provider limits sending.', 'snn-tickets'); ?></small></label>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Email footer', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Under every email.', 'snn-tickets'); ?></p></div><div class="body">
                <textarea name="footer" rows="2" placeholder="<?php esc_attr_e('Questions? Just reply to this email.', 'snn-tickets'); ?>"><?php echo esc_textarea($d['footer']); ?></textarea>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Check that email works', 'snn-tickets'); ?></h3></div><div class="body">
                <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Sends a short message right now. If it does not arrive, your site needs an SMTP plugin.', 'snn-tickets'); ?></p>
                <div class="snn-row"><input type="email" id="snn-plain-to" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" style="max-width:260px" aria-label="<?php esc_attr_e('Send to', 'snn-tickets'); ?>">
                    <button type="button" class="button" onclick="var b=this,r=document.getElementById('snn-plain-res');r.textContent='…';var f=new FormData();f.append('action','snn_plain_test');f.append('nonce',SNNT.nonce);f.append('to',document.getElementById('snn-plain-to').value);fetch(SNNT.ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(x){return x.json()}).then(function(j){r.textContent=(j&&j.data&&j.data.message)||'';r.style.color=j&&j.success?'#0a7d32':'#b3261e'})"><?php esc_html_e('Send a test email', 'snn-tickets'); ?></button>
                    <span id="snn-plain-res" class="snn-small"></span></div>
            </div></div>
            <?php self::save_row(__('Save settings', 'snn-tickets')); ?>
        </form>
        <?php
    }

    public static function save_general() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_general');
        $email = sanitize_email(wp_unslash($_POST['from_email'] ?? ''));
        update_option(SNN_T_Mailer::FROM_NAME_OPTION, sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')));
        update_option(SNN_T_Mailer::FROM_EMAIL_OPTION, ($email && is_email($email)) ? $email : '');
        if (isset($_POST['batch_size'])) update_option(SNN_T_Mailer::BATCH_SIZE_OPTION, max(1, min(200, (int)$_POST['batch_size'])));
        $d = SNN_T_Design::settings();
        $d['footer'] = sanitize_textarea_field(wp_unslash($_POST['footer'] ?? ''));
        update_option(SNN_T_Design::OPTION, SNN_T_Design::sanitize_settings($d));
        SNN_T_Admin::go(self::url('general'), __('Settings saved.', 'snn-tickets'));
    }

    public static function ajax_plain_test() {
        if (!current_user_can(SNN_T_Tickets::cap())) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('snn_email_tools', 'nonce');
        $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
        if (!$to || !is_email($to)) wp_send_json_error(['message' => __('That email address does not look right.', 'snn-tickets')]);
        $r = SNN_T_Mailer::send_now((object)[
            'to_email' => $to, 'subject' => sprintf(__('[%s] Test email', 'snn-tickets'), get_bloginfo('name')),
            'body' => '<p>' . esc_html__('It works. Ticket emails from this site will arrive like this one.', 'snn-tickets') . '</p>',
            'attach_qr' => 0, 'ticket_code' => '', 'attachments' => '',
        ]);
        if ($r === true) wp_send_json_success(['message' => sprintf(__('Sent to %s. Check the inbox (and spam folder).', 'snn-tickets'), $to)]);
        wp_send_json_error(['message' => sprintf(__('Sending failed: %s', 'snn-tickets'), $r)]);
    }

    /* ------------------------------------------------------------------
     * Look
     * ---------------------------------------------------------------- */

    private static function tab_look() {
        $s = SNN_T_Design::settings();
        $presets = SNN_T_Design::presets();
        $keys = SNN_T_Design::color_keys();
        $active = $presets[$s['preset']];
        $pcolors = [];
        foreach ($presets as $k => $p) $pcolors[$k] = array_intersect_key($p, $keys);
        self::form_open('look'); ?>
            <div data-look-editor data-presets="<?php echo esc_attr(wp_json_encode($pcolors)); ?>" class="snn-col" style="gap:0">
            <div class="snn-set"><div><h3><?php esc_html_e('Logo', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Email header and PDF. A wide PNG around 400×120 works best.', 'snn-tickets'); ?></p></div><div class="body">
                <div class="snn-row">
                    <img id="snn-logo-img" src="<?php echo esc_url($s['logo_url']); ?>" alt="" style="max-height:48px;max-width:220px;background:#f0f0f1;padding:6px;border-radius:4px" <?php echo $s['logo_url'] ? '' : 'hidden'; ?>>
                    <input type="hidden" id="snn-logo" name="design[logo_url]" value="<?php echo esc_attr($s['logo_url']); ?>">
                    <button type="button" class="button" data-media="<?php esc_attr_e('Choose a logo', 'snn-tickets'); ?>" data-target="snn-logo"><?php esc_html_e('Choose from Media Library', 'snn-tickets'); ?></button>
                    <?php if ($s['logo_url']): ?><label class="snn-check"><input type="checkbox" name="remove_logo" value="1"> <span><?php esc_html_e('Remove logo', 'snn-tickets'); ?></span></label><?php endif; ?>
                </div>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Default style', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Every event uses this unless it picks its own in Event settings.', 'snn-tickets'); ?></p></div><div class="body">
                <?php echo SNN_T_Admin::looks_picker('design[preset]', $s['preset']); // escaped inside ?>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Colours', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Fine-tune the default style. Events that pick a different style keep that style\'s own colours.', 'snn-tickets'); ?></p></div><div class="body">
                <div class="snn-colors">
                    <?php foreach ($keys as $k => $l): ?>
                        <label class="snn-color"><input type="color" name="design[colors][<?php echo esc_attr($k); ?>]" data-key="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($s['colors'][$k] ?? $active[$k]); ?>"><span class="snn-small"><?php echo esc_html($l); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div><button type="button" class="button button-small" data-colors-reset><?php esc_html_e("Reset to the style's colours", 'snn-tickets'); ?></button></div>
                <div data-mini></div>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Buttons in emails', 'snn-tickets'); ?></h3></div><div class="body">
                <label class="snn-check"><input type="checkbox" name="design[wallet_links]" value="1" <?php checked(!empty($s['wallet_links'])); ?>> <span><?php esc_html_e('Show Wallet, PDF and calendar buttons under the ticket', 'snn-tickets'); ?></span></label>
                <p style="margin:0"><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=snn_design_pdf'), 'snn_design_pdf')); ?>"><?php esc_html_e('Preview PDF ticket', 'snn-tickets'); ?> ↗</a>
                    <span class="snn-muted snn-small"><?php esc_html_e('Save first to see your changes. The ticket email preview is on each event\'s Emails tab.', 'snn-tickets'); ?></span></p>
            </div></div>
            </div>
            <?php self::save_row(__('Save style', 'snn-tickets')); ?>
        </form>
        <?php
    }

    public static function save_look() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_look');
        $in = (array)wp_unslash($_POST['design'] ?? []);
        $old = SNN_T_Design::settings();
        $in['footer'] = $old['footer'];
        $in['wallet_links'] = !empty($in['wallet_links']);
        if (!empty($_POST['remove_logo'])) $in['logo_url'] = '';
        $clean  = SNN_T_Design::sanitize_settings($in);
        $preset = SNN_T_Design::presets()[$clean['preset']];
        // Keep only colours that differ from the look, so switching looks
        // later is not blocked by stale overrides.
        foreach ($clean['colors'] as $k => $v) {
            if (strtolower($v) === strtolower($preset[$k])) unset($clean['colors'][$k]);
        }
        update_option(SNN_T_Design::OPTION, $clean);
        SNN_T_Admin::go(self::url('look'), __('Style saved.', 'snn-tickets'));
    }

    public static function pdf_preview() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_design_pdf');
        $t = SNN_T_Events::sample_ticket_data((int)($_GET['list_id'] ?? 0));
        $pdf = SNN_T_PDF::ticket($t);
        if (is_wp_error($pdf)) wp_die(esc_html($pdf->get_error_message()));
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="ticket-preview.pdf"');
        echo $pdf; // phpcs:ignore -- binary
        exit;
    }

    /* ------------------------------------------------------------------
     * Door & scanner
     * ---------------------------------------------------------------- */

    private static function tab_door() {
        $scan = (string)get_option(SNN_T_QR::SCAN_URL_OPTION, '');
        $conflict = SNN_T_Router::base_conflict();
        self::form_open('door'); ?>
            <div class="snn-set"><div><h3><?php esc_html_e('Door PIN', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Lets volunteers scan tickets without a WordPress account.', 'snn-tickets'); ?></p></div><div class="body">
                <div class="snn-row"><input type="password" name="pin" inputmode="numeric" autocomplete="new-password" style="max-width:180px" placeholder="<?php echo SNN_T_Scanner::pin_set() ? esc_attr__('Type a new PIN to change it', 'snn-tickets') : esc_attr__('For example 4821', 'snn-tickets'); ?>" aria-label="<?php esc_attr_e('Door PIN', 'snn-tickets'); ?>">
                    <?php echo SNN_T_Scanner::pin_set() ? SNN_T_Admin::chip(__('PIN is set', 'snn-tickets'), 'ok') : SNN_T_Admin::chip(__('No PIN yet', 'snn-tickets')); ?>
                    <?php if (SNN_T_Scanner::pin_set()): ?><label class="snn-check"><input type="checkbox" name="clear_pin" value="1"> <span><?php esc_html_e('Remove PIN', 'snn-tickets'); ?></span></label><?php endif; ?></div>
                <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Volunteers stay unlocked for 24 hours. Changing the PIN locks every phone. Without a PIN, only logged-in admins can scan.', 'snn-tickets'); ?></p>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Scanner', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('Staff open this on a phone. Each event also has its own scanner link on its Door tab.', 'snn-tickets'); ?></p></div><div class="body">
                <p class="snn-row" style="margin:0"><span class="snn-mono"><?php echo esc_html(SNN_T_Router::door_url()); ?></span> <?php echo SNN_T_Admin::copy_button(SNN_T_Router::door_url()); ?> <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url(SNN_T_Router::door_url()); ?>"><?php esc_html_e('Open', 'snn-tickets'); ?> ↗</a></p>
                <details class="snn-more" <?php echo $scan !== '' ? 'open' : ''; ?>><summary><?php esc_html_e('Use my own scanner page instead', 'snn-tickets'); ?></summary><div>
                    <label class="snn-field"><span><?php esc_html_e('Page address', 'snn-tickets'); ?></span><input type="url" name="scan_url" value="<?php echo esc_attr($scan); ?>" placeholder="<?php echo esc_attr(home_url('/check-in/')); ?>"><small><?php printf(esc_html__('A page of yours with the %s shortcode. Leave empty to use the built-in scanner.', 'snn-tickets'), '<code>[tickets_scan_page]</code>'); ?></small></label>
                </div></details>
            </div></div>
            <div class="snn-set"><div><h3><?php esc_html_e('Web address for events', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('The word before each event\'s name in its link.', 'snn-tickets'); ?></p></div><div class="body">
                <?php if (SNN_T_Router::pretty()): ?>
                    <label class="snn-field"><span class="snn-row" style="gap:4px;flex-wrap:nowrap;font-weight:400"><span class="snn-mono snn-muted"><?php echo esc_html(preg_replace('#^https?://#', '', home_url('/'))); ?></span><input type="text" name="base" value="<?php echo esc_attr(SNN_T_Router::base()); ?>" style="max-width:160px"><span class="snn-mono snn-muted">/summer-meetup/</span></span></label>
                    <?php if ($conflict): ?><div class="snn-hint warn"><?php echo esc_html(sprintf(__('You also have a page called "%s" at this address. Event links win over it; pick another word if you still need that page.', 'snn-tickets'), get_the_title($conflict))); ?></div><?php endif; ?>
                    <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Changing this changes every event link. Links already shared will stop working.', 'snn-tickets'); ?></p>
                <?php else: ?>
                    <div class="snn-hint warn"><?php printf(esc_html__('Your site uses plain permalinks, so event links look technical. For short links, pick any other option under %s.', 'snn-tickets'), '<a href="' . esc_url(admin_url('options-permalink.php')) . '">' . esc_html__('Settings → Permalinks', 'snn-tickets') . '</a>'); ?></div>
                <?php endif; ?>
            </div></div>
            <?php self::save_row(__('Save', 'snn-tickets')); ?>
        </form>
        <?php
    }

    public static function save_door() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_door');
        if (!empty($_POST['clear_pin'])) SNN_T_Scanner::set_pin('');
        elseif (trim(wp_unslash($_POST['pin'] ?? '')) !== '') SNN_T_Scanner::set_pin(wp_unslash($_POST['pin']));

        $msg = __('Saved.', 'snn-tickets');
        $old_scan = (string)get_option(SNN_T_QR::SCAN_URL_OPTION, '');
        $scan = esc_url_raw(wp_unslash($_POST['scan_url'] ?? ''));
        $old_base = SNN_T_Router::base();
        if (isset($_POST['base'])) {
            $base = sanitize_title(wp_unslash($_POST['base']));
            update_option(SNN_T_Router::BASE_OPTION, $base !== '' ? $base : 'events');
        }
        update_option(SNN_T_QR::SCAN_URL_OPTION, $scan);
        if ($old_scan !== $scan || $old_base !== SNN_T_Router::base()) {
            // Cached QR images point at the old address.
            $n = SNN_T_QR::flush_cache();
            $msg .= ' ' . sprintf(__('QR codes now point at the new address (%d cached images refreshed). Tickets already sent keep working.', 'snn-tickets'), $n);
        }
        SNN_T_Admin::go(self::url('door'), $msg);
    }

    /* ------------------------------------------------------------------
     * Email log
     * ---------------------------------------------------------------- */

    private static function tab_log() {
        global $wpdb;
        if (!empty($_GET['view'])) { self::log_message((int)$_GET['view']); return; }
        $table  = SNN_T_DB::queue();
        $counts = SNN_T_Mailer::queue_counts();
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $per    = 30;
        $where = ['1=1']; $args = [];
        if (in_array($status, ['pending', 'sending', 'sent', 'failed'], true)) { $where[] = 'status = %s'; $args[] = $status; }
        if ($search !== '') { $like = '%' . $wpdb->esc_like($search) . '%'; $where[] = '(to_email LIKE %s OR subject LIKE %s OR ticket_code LIKE %s)'; array_push($args, $like, $like, $like); }
        $w = implode(' AND ', $where);
        $total = (int)$wpdb->get_var($args ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$w}", $args) : "SELECT COUNT(*) FROM {$table} WHERE {$w}");
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT q.*, t.list_id FROM {$table} q LEFT JOIN " . SNN_T_DB::tickets() . " t ON t.id = q.ticket_id WHERE {$w} ORDER BY q.id DESC LIMIT %d OFFSET %d", array_merge($args, [$per, ($paged - 1) * $per])));
        $next  = wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);
        $roles = SNN_T_Mailer::roles();
        $pills = ['all' => __('All', 'snn-tickets'), 'pending' => __('Waiting', 'snn-tickets'), 'sent' => __('Sent', 'snn-tickets'), 'failed' => __('Failed', 'snn-tickets')];
        ?>
        <div class="snn-row">
            <div class="snn-pills"><?php foreach ($pills as $k => $l): $n = $k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0); ?>
                <a class="<?php echo $status === $k ? 'on' : ''; ?>" href="<?php echo esc_url(self::url('log', ['status' => $k])); ?>"><?php echo esc_html($l . ' (' . number_format_i18n($n) . ')'); ?></a><?php endforeach; ?></div>
            <span class="snn-spacer"></span>
            <form method="get" class="snn-row"><input type="hidden" name="page" value="snn-tickets-settings"><input type="hidden" name="tab" value="log"><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Email, subject or code', 'snn-tickets'); ?>"><button class="button"><?php esc_html_e('Search', 'snn-tickets'); ?></button></form>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="snn_set_queue"><?php wp_nonce_field('snn_set_queue'); ?>
            <div class="snn-hint"><?php printf(esc_html__('Emails are sent automatically in the background, %d per minute, so you can close this page.', 'snn-tickets'), (int)SNN_T_Mailer::batch_size()); ?>
                <?php printf(esc_html__('An email that fails is tried again after 5 minutes, 30 minutes and 2 hours before it counts as failed. Sent emails leave this log after %d days.', 'snn-tickets'), (int)SNN_T_Mailer::KEEP_SENT_DAYS); ?>
                <?php if ($next): ?><?php echo esc_html(sprintf(__('Next batch in %s.', 'snn-tickets'), human_time_diff($next, time()))); ?><?php endif; ?>
                <button class="snn-link" name="do" value="process"><?php esc_html_e('Send the next batch now', 'snn-tickets'); ?></button></div>
            <div class="snn-table-wrap" style="margin-top:12px"><table class="snn-table">
                <thead><tr><th><?php esc_html_e('Status', 'snn-tickets'); ?></th><th><?php esc_html_e('To', 'snn-tickets'); ?></th><th><?php esc_html_e('Email', 'snn-tickets'); ?></th><th><?php esc_html_e('When', 'snn-tickets'); ?></th><th></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="5" class="snn-empty"><?php esc_html_e('Nothing here.', 'snn-tickets'); ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr><td><?php echo SNN_T_Admin::status_badge($r->status); ?><?php if ((int)$r->attempts > 1): ?><br><span class="snn-muted snn-small"><?php echo esc_html(sprintf(__('%d tries', 'snn-tickets'), (int)$r->attempts)); ?></span><?php endif; ?>
                        <?php if ($r->status === 'pending' && (int)$r->attempts > 0): $at = strtotime($r->scheduled_at); ?><br><span class="snn-small" style="color:#8a5a00"><?php echo esc_html($at > current_time('timestamp')
                            ? sprintf(__('Trying again in %s', 'snn-tickets'), human_time_diff(current_time('timestamp'), $at))
                            : __('Trying again now', 'snn-tickets')); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($r->to_email); ?><?php if ($r->role === 'admin'): ?> <?php echo SNN_T_Admin::chip(__('to you', 'snn-tickets')); ?><?php endif; ?></td>
                        <td><?php echo esc_html($r->subject); ?><br><span class="snn-muted snn-small"><?php echo esc_html($roles[$r->role] ?? $r->role); ?><?php if ($r->attachments): ?> · 📎 <?php echo esc_html(strtoupper(str_replace(',', ', ', $r->attachments))); ?><?php endif; ?></span>
                            <?php if ($r->last_error): ?><br><span class="snn-small" style="color:#b3261e"><?php echo esc_html($r->last_error); ?></span><?php endif; ?></td>
                        <td class="snn-small snn-muted"><?php echo SNN_T_Admin::when($r->sent_at ?: $r->scheduled_at); ?></td>
                        <td class="act"><a class="button button-small" href="<?php echo esc_url(self::url('log', ['view' => (int)$r->id])); ?>"><?php esc_html_e('View', 'snn-tickets'); ?></a>
                            <?php if ($r->status === 'failed' && $r->ticket_id && $r->list_id): ?><a class="button button-small" href="<?php echo esc_url(SNN_T_Admin::event_admin_url((int)$r->list_id, ['person' => 't' . (int)$r->ticket_id])); ?>"><?php esc_html_e('Fix address', 'snn-tickets'); ?></a><?php endif; ?>
                            <?php if (in_array($r->status, ['failed', 'sending'], true)): ?><button class="button button-small" name="retry_one" value="<?php echo (int)$r->id; ?>"><?php esc_html_e('Retry', 'snn-tickets'); ?></button><?php endif; ?>
                            <button class="button button-small button-link-delete" name="delete_one" value="<?php echo (int)$r->id; ?>" data-confirm="<?php esc_attr_e('Remove this email from the log?', 'snn-tickets'); ?>">✕</button></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="snn-row" style="margin-top:10px">
                <?php if ($counts['failed']): ?><button class="button" name="do" value="retry"><?php esc_html_e('Retry all failed', 'snn-tickets'); ?></button><?php endif; ?>
                <button class="button" name="do" value="clear" data-confirm="<?php esc_attr_e('Remove every sent email from the log? This does not unsend them.', 'snn-tickets'); ?>"><?php esc_html_e('Clear sent emails from the log', 'snn-tickets'); ?></button>
                <span class="snn-spacer"></span><?php echo SNN_T_Admin::pager($total, $per, $paged); ?>
            </div>
        </form>
        <?php
    }

    private static function log_message($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . SNN_T_DB::queue() . " WHERE id = %d", $id));
        echo '<p><a href="' . esc_url(self::url('log')) . '">&larr; ' . esc_html__('Email log', 'snn-tickets') . '</a></p>';
        if (!$r) { echo '<p>' . esc_html__('That email is no longer in the log.', 'snn-tickets') . '</p>'; return; }
        $html = $r->body;
        if ($r->ticket_code !== '') $html = str_replace('cid:' . SNN_T_Mailer::QR_CID, SNN_T_QR::data_uri($r->ticket_code, 6, 2), $html);
        if (!SNN_T_Mailer::is_full_document($html)) $html = '<!doctype html><html><body style="font-family:sans-serif">' . $html . '</body></html>';
        $roles = SNN_T_Mailer::roles();
        ?>
        <div class="snn-card">
            <dl class="snn-dl">
                <dt><?php esc_html_e('Status', 'snn-tickets'); ?></dt><dd><?php echo SNN_T_Admin::status_badge($r->status); ?> <?php if ($r->last_error): ?><span style="color:#b3261e"><?php echo esc_html($r->last_error); ?></span><?php endif; ?></dd>
                <dt><?php esc_html_e('Email', 'snn-tickets'); ?></dt><dd><?php echo esc_html($roles[$r->role] ?? $r->role); ?></dd>
                <dt><?php esc_html_e('To', 'snn-tickets'); ?></dt><dd><?php echo esc_html(trim($r->to_name . ' <' . $r->to_email . '>')); ?></dd>
                <dt><?php esc_html_e('Subject', 'snn-tickets'); ?></dt><dd><?php echo esc_html($r->subject); ?></dd>
                <dt><?php esc_html_e('Attachments', 'snn-tickets'); ?></dt><dd><?php echo $r->attachments ? esc_html(strtoupper(str_replace(',', ', ', $r->attachments))) : '—'; ?></dd>
                <dt><?php esc_html_e('Queued', 'snn-tickets'); ?></dt><dd><?php echo SNN_T_Admin::when($r->created_at); ?></dd>
                <dt><?php esc_html_e('Sent', 'snn-tickets'); ?></dt><dd><?php echo SNN_T_Admin::when($r->sent_at); ?></dd>
            </dl>
        </div>
        <iframe title="<?php esc_attr_e('Email', 'snn-tickets'); ?>" style="width:100%;max-width:760px;height:900px;border:1px solid #c3c4c7;border-radius:6px;background:#fff" sandbox="" srcdoc="<?php echo esc_attr($html); ?>"></iframe>
        <?php
    }

    public static function save_queue() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_queue');
        $do = sanitize_key($_POST['do'] ?? '');
        if (!empty($_POST['retry_one']))  { SNN_T_Mailer::retry_failed((int)$_POST['retry_one']); $msg = __('Email queued again.', 'snn-tickets'); }
        elseif (!empty($_POST['delete_one'])) { SNN_T_Mailer::delete_row((int)$_POST['delete_one']); $msg = __('Removed from the log.', 'snn-tickets'); }
        elseif ($do === 'process') {
            $r = SNN_T_Mailer::process_queue();
            $msg = !empty($r['locked']) ? __('Another batch is being sent right now. Try again in a moment.', 'snn-tickets')
                : sprintf(__('Sent %1$d, failed %2$d.', 'snn-tickets'), $r['sent'], $r['failed']);
        }
        elseif ($do === 'retry') $msg = sprintf(__('%d emails queued again.', 'snn-tickets'), SNN_T_Mailer::retry_failed());
        elseif ($do === 'clear') $msg = sprintf(__('%d sent emails removed from the log.', 'snn-tickets'), SNN_T_Mailer::clear_sent());
        else $msg = __('Nothing to do.', 'snn-tickets');
        SNN_T_Admin::go(wp_get_referer() ?: self::url('log'), $msg);
    }

    /* ------------------------------------------------------------------
     * Email templates
     * ---------------------------------------------------------------- */

    private static function tab_templates() {
        $templates = SNN_T_Mailer::get_templates();
        $roles = SNN_T_Mailer::roles();
        $editing = isset($_GET['template']) ? sanitize_text_field(wp_unslash($_GET['template'])) : '';
        $new = isset($_GET['new']) ? sanitize_key(wp_unslash($_GET['new'])) : '';
        $cur = $editing !== '' ? ($templates[$editing] ?? null) : null;
        ?>
        <div class="snn-hint"><?php esc_html_e('Saved emails you can reuse in any event: on an event\'s Emails tab, pick "Start from a template". Each event keeps its own copy, so changing a template here does not change events that already used it.', 'snn-tickets'); ?></div>
        <?php if ($cur || isset($roles[$new])):
            $role = $cur['role'] ?? $new; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-card" data-dirty>
                <input type="hidden" name="action" value="snn_set_template"><input type="hidden" name="original" value="<?php echo esc_attr($editing); ?>"><input type="hidden" name="role" value="<?php echo esc_attr($role); ?>">
                <?php wp_nonce_field('snn_set_template'); ?>
                <p style="margin:0"><a href="<?php echo esc_url(self::url('templates')); ?>">&larr; <?php esc_html_e('All templates', 'snn-tickets'); ?></a></p>
                <div class="snn-grid2"><label class="snn-field"><span><?php esc_html_e('Template name', 'snn-tickets'); ?></span><input type="text" name="name" required value="<?php echo esc_attr($editing); ?>" placeholder="<?php esc_attr_e('Conference ticket', 'snn-tickets'); ?>"></label>
                    <div class="snn-field"><span><?php esc_html_e('Kind', 'snn-tickets'); ?></span><p style="margin:6px 0 0"><?php echo esc_html($roles[$role]); ?></p></div></div>
                <?php SNN_T_Admin::email_editor(['role' => $role, 'list' => 0, 'name' => 'mail', 'subject' => $cur['subject'] ?? '', 'body' => $cur['body'] ?? '', 'library' => false]); ?>
                <div class="snn-row"><?php if ($cur): ?><button class="button button-link-delete" formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>" name="action" value="snn_set_template_delete" formnovalidate data-confirm="<?php esc_attr_e('Delete this template? Events that already used it keep their copy.', 'snn-tickets'); ?>"><?php esc_html_e('Delete template', 'snn-tickets'); ?></button><?php endif; ?>
                    <span class="snn-spacer"></span><span class="snn-muted snn-small" data-dirty-note hidden><?php esc_html_e('Unsaved changes', 'snn-tickets'); ?></span><button class="button button-primary"><?php esc_html_e('Save template', 'snn-tickets'); ?></button></div>
            </form>
        <?php else: ?>
            <div class="snn-grid2">
                <?php foreach ($roles as $r => $label): $list = SNN_T_Mailer::templates_for_role($r); ?>
                    <div class="snn-card">
                        <div class="snn-card-h"><h2><?php echo esc_html($label); ?></h2><a class="button button-small" href="<?php echo esc_url(self::url('templates', ['new' => $r])); ?>">+ <?php esc_html_e('New', 'snn-tickets'); ?></a></div>
                        <?php if (!$list): ?><p class="snn-muted" style="margin:0"><?php esc_html_e('None saved yet.', 'snn-tickets'); ?></p><?php else: ?>
                            <ul style="margin:0"><?php foreach ($list as $n => $tp): ?><li><a href="<?php echo esc_url(self::url('templates', ['template' => $n])); ?>"><b><?php echo esc_html($n); ?></b></a> <span class="snn-muted snn-small">· <?php echo esc_html($tp['subject']); ?></span></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
    }

    public static function save_template() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_template');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $original = sanitize_text_field(wp_unslash($_POST['original'] ?? ''));
        $role = sanitize_key($_POST['role'] ?? 'ticket');
        if ($name === '') SNN_T_Admin::go(self::url('templates'), __('A template needs a name.', 'snn-tickets'), true);
        if (!isset(SNN_T_Mailer::roles()[$role])) $role = 'ticket';
        $mail = (array)wp_unslash($_POST['mail'] ?? []);
        $templates = SNN_T_Mailer::get_templates();
        if ($original !== '' && $original !== $name) unset($templates[$original]);
        $templates[$name] = [
            'role' => $role, 'subject' => sanitize_text_field($mail['subject'] ?? ''), 'body' => wp_kses_post($mail['body'] ?? ''),
            'created' => $templates[$name]['created'] ?? current_time('mysql'), 'updated' => current_time('mysql'),
        ];
        update_option(SNN_T_Mailer::TEMPLATES_OPTION, $templates);
        SNN_T_Admin::go(self::url('templates', ['template' => $name]), __('Template saved.', 'snn-tickets'));
    }

    public static function save_template_delete() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_template');
        $name = sanitize_text_field(wp_unslash($_POST['original'] ?? ''));
        $templates = SNN_T_Mailer::get_templates();
        unset($templates[$name]);
        update_option(SNN_T_Mailer::TEMPLATES_OPTION, $templates);
        SNN_T_Admin::go(self::url('templates'), __('Template deleted.', 'snn-tickets'));
    }

    /* ------------------------------------------------------------------
     * Wallet
     * ---------------------------------------------------------------- */

    private static function tab_wallet() {
        $a = SNN_T_Wallet::apple_settings();
        $g = SNN_T_Wallet::google_settings();
        $apple_ok = SNN_T_Wallet::apple_ready();
        $google_ok = SNN_T_Wallet::google_ready();
        $test = function ($which) { return wp_nonce_url(admin_url('admin-post.php?action=snn_wallet_test&which=' . $which), 'snn_wallet_test'); };
        ?>
        <div class="snn-hint"><?php esc_html_e('Optional. Adds "Add to Apple Wallet" and "Add to Google Wallet" buttons to ticket emails and ticket pages. Tickets work fine without it.', 'snn-tickets'); ?></div>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-col" style="gap:16px">
            <input type="hidden" name="action" value="snn_set_wallet"><?php wp_nonce_field('snn_set_wallet'); ?>
            <div class="snn-grid2" style="align-items:start">
                <div class="snn-card">
                    <div class="snn-card-h"><h2><?php esc_html_e('Apple Wallet', 'snn-tickets'); ?></h2><?php echo $apple_ok ? SNN_T_Admin::chip(__('Ready', 'snn-tickets'), 'ok') : SNN_T_Admin::chip(__('Not set up', 'snn-tickets')); ?></div>
                    <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Needs a paid Apple Developer account.', 'snn-tickets'); ?> <a href="https://developer.apple.com/documentation/walletpasses/building-a-pass" target="_blank" rel="noopener"><?php esc_html_e("Apple's guide", 'snn-tickets'); ?> ↗</a></p>
                    <ol class="snn-small" style="margin:0 0 0 18px"><li><?php esc_html_e('Create a Pass Type ID in your Apple Developer account.', 'snn-tickets'); ?></li><li><?php esc_html_e('Make its certificate and export it with its private key as a .p12 file.', 'snn-tickets'); ?></li><li><?php esc_html_e('Upload it below with its password, plus Apple\'s WWDR certificate.', 'snn-tickets'); ?></li></ol>
                    <label class="snn-field"><span><?php esc_html_e('Pass Type ID', 'snn-tickets'); ?></span><input type="text" name="apple[pass_type_id]" value="<?php echo esc_attr($a['pass_type_id']); ?>" placeholder="pass.com.example.tickets"></label>
                    <label class="snn-field"><span><?php esc_html_e('Team ID', 'snn-tickets'); ?></span><input type="text" name="apple[team_id]" value="<?php echo esc_attr($a['team_id']); ?>" placeholder="ABCDE12345"></label>
                    <label class="snn-field"><span><?php esc_html_e('Organisation name', 'snn-tickets'); ?></span><input type="text" name="apple[org_name]" value="<?php echo esc_attr($a['org_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></label>
                    <label class="snn-field"><span><?php esc_html_e('Certificate (.p12)', 'snn-tickets'); ?> <?php if ($a['p12']) echo SNN_T_Admin::chip(__('Uploaded', 'snn-tickets'), 'ok'); ?></span><input type="file" name="apple_p12" accept=".p12,.pfx"></label>
                    <label class="snn-field"><span><?php esc_html_e('Certificate password', 'snn-tickets'); ?></span><input type="password" name="apple[p12_password]" autocomplete="new-password" placeholder="<?php echo $a['p12_password'] !== '' ? esc_attr__('Saved – type to change', 'snn-tickets') : ''; ?>"></label>
                    <details class="snn-more"><summary><?php esc_html_e('My server refuses the .p12', 'snn-tickets'); ?></summary><div>
                        <label class="snn-field"><span><?php esc_html_e('Certificate (.pem)', 'snn-tickets'); ?></span><input type="file" name="apple_cert_pem" accept=".pem,.crt"></label>
                        <label class="snn-field"><span><?php esc_html_e('Private key (.pem)', 'snn-tickets'); ?></span><input type="file" name="apple_key_pem" accept=".pem,.key"></label>
                        <?php if ($a['cert_pem'] && $a['key_pem']) echo SNN_T_Admin::chip(__('PEM files uploaded', 'snn-tickets'), 'ok'); ?>
                    </div></details>
                    <label class="snn-field"><span><?php esc_html_e('Apple WWDR certificate', 'snn-tickets'); ?> <?php if ($a['wwdr_pem']) echo SNN_T_Admin::chip(__('Uploaded', 'snn-tickets'), 'ok'); ?></span><input type="file" name="apple_wwdr" accept=".cer,.pem,.crt">
                        <small><?php printf(esc_html__('Download %s from Apple.', 'snn-tickets'), '<a href="https://www.apple.com/certificateauthority/AppleWWDRCAG4.cer">AppleWWDRCAG4.cer</a>'); ?></small></label>
                    <div class="snn-row"><?php if ($apple_ok): ?><a class="button" href="<?php echo esc_url($test('apple')); ?>"><?php esc_html_e('Download a sample pass', 'snn-tickets'); ?></a><?php endif; ?>
                        <?php if ($a['p12'] || $a['cert_pem']): ?><label class="snn-check"><input type="checkbox" name="apple_clear" value="1"> <span><?php esc_html_e('Disconnect Apple Wallet', 'snn-tickets'); ?></span></label><?php endif; ?></div>
                </div>
                <div class="snn-card">
                    <div class="snn-card-h"><h2><?php esc_html_e('Google Wallet', 'snn-tickets'); ?></h2><?php echo $google_ok ? SNN_T_Admin::chip(__('Ready', 'snn-tickets'), 'ok') : SNN_T_Admin::chip(__('Not set up', 'snn-tickets')); ?></div>
                    <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Free.', 'snn-tickets'); ?> <a href="https://developers.google.com/wallet/tickets/events/web/prerequisites" target="_blank" rel="noopener"><?php esc_html_e("Google's guide", 'snn-tickets'); ?> ↗</a></p>
                    <ol class="snn-small" style="margin:0 0 0 18px"><li><?php esc_html_e('Create an issuer in the Google Pay & Wallet Console.', 'snn-tickets'); ?></li><li><?php esc_html_e('Create a Google Cloud service account with the Wallet API and add it to the issuer.', 'snn-tickets'); ?></li><li><?php esc_html_e('Upload its JSON key below.', 'snn-tickets'); ?></li></ol>
                    <label class="snn-field"><span><?php esc_html_e('Issuer ID', 'snn-tickets'); ?></span><input type="text" name="google[issuer_id]" value="<?php echo esc_attr($g['issuer_id']); ?>" placeholder="3388000000012345678"></label>
                    <label class="snn-field"><span><?php esc_html_e('Service account key (.json)', 'snn-tickets'); ?> <?php if ($g['client_email']) echo SNN_T_Admin::chip($g['client_email'], 'ok'); ?></span><input type="file" name="google_json" accept=".json"></label>
                    <div class="snn-hint warn snn-small"><?php esc_html_e('Until Google approves your issuer, only test users can save passes.', 'snn-tickets'); ?></div>
                    <div class="snn-row"><?php if ($google_ok): ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($test('google')); ?>"><?php esc_html_e('Try a sample pass', 'snn-tickets'); ?> ↗</a><?php endif; ?>
                        <?php if ($g['client_email']): ?><label class="snn-check"><input type="checkbox" name="google_clear" value="1"> <span><?php esc_html_e('Disconnect Google Wallet', 'snn-tickets'); ?></span></label><?php endif; ?></div>
                </div>
            </div>
            <div class="snn-row"><span class="snn-spacer"></span><button class="button button-primary"><?php esc_html_e('Save wallet settings', 'snn-tickets'); ?></button></div>
        </form>
        <?php
    }

    private static function upload($field) {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if (!is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
        $data = file_get_contents($_FILES[$field]['tmp_name']);
        return ($data === false || strlen($data) > 512 * 1024) ? null : $data;
    }

    public static function save_wallet() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_wallet');
        $notes = [];
        $a  = SNN_T_Wallet::apple_settings();
        $in = (array)($_POST['apple'] ?? []);
        if (!empty($_POST['apple_clear'])) $a = array_merge($a, ['p12' => '', 'p12_password' => '', 'cert_pem' => '', 'key_pem' => '']);
        $a['pass_type_id'] = sanitize_text_field(wp_unslash($in['pass_type_id'] ?? ''));
        $a['team_id']      = strtoupper(sanitize_text_field(wp_unslash($in['team_id'] ?? '')));
        $a['org_name']     = sanitize_text_field(wp_unslash($in['org_name'] ?? ''));
        if (isset($in['p12_password']) && $in['p12_password'] !== '') $a['p12_password'] = wp_unslash($in['p12_password']);
        if (null !== ($p12 = self::upload('apple_p12')))      $a['p12'] = base64_encode($p12);
        if (null !== ($pem = self::upload('apple_cert_pem'))) $a['cert_pem'] = $pem;
        if (null !== ($key = self::upload('apple_key_pem')))  $a['key_pem'] = $key;
        if (null !== ($w = self::upload('apple_wwdr')))       $a['wwdr_pem'] = SNN_T_Wallet::to_pem($w);
        if ($a['p12'] !== '' || ($a['cert_pem'] !== '' && $a['key_pem'] !== '')) {
            $creds = SNN_T_Wallet::apple_credentials($a);
            if (is_wp_error($creds)) $notes[] = $creds->get_error_message();
        }
        update_option(SNN_T_Wallet::APPLE_OPTION, $a, false);

        $g = SNN_T_Wallet::google_settings();
        if (!empty($_POST['google_clear'])) $g = array_merge($g, ['client_email' => '', 'private_key' => '']);
        $g['issuer_id'] = preg_replace('/[^0-9]/', '', (string)wp_unslash($_POST['google']['issuer_id'] ?? ''));
        if (null !== ($json = self::upload('google_json'))) {
            $sa = SNN_T_Wallet::parse_service_account($json);
            if (is_wp_error($sa)) $notes[] = $sa->get_error_message(); else $g = array_merge($g, $sa);
        }
        update_option(SNN_T_Wallet::GOOGLE_OPTION, $g, false);

        if ($notes) SNN_T_Admin::go(self::url('wallet'), __('Saved, with problems: ', 'snn-tickets') . implode(' ', $notes), true);
        SNN_T_Admin::go(self::url('wallet'), __('Wallet settings saved.', 'snn-tickets'));
    }

    public static function wallet_test() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_wallet_test');
        $t = SNN_T_Events::sample_ticket_data();
        if (($_GET['which'] ?? '') === 'google') {
            $url = SNN_T_Wallet::google_save_url($t);
            if (is_wp_error($url)) wp_die(esc_html($url->get_error_message()));
            wp_redirect($url);
            exit;
        }
        $pass = SNN_T_Wallet::apple_pkpass($t);
        if (is_wp_error($pass)) wp_die(esc_html($pass->get_error_message()));
        nocache_headers();
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="sample.pkpass"');
        echo $pass; // phpcs:ignore -- binary
        exit;
    }

    /* ------------------------------------------------------------------
     * Advanced
     * ---------------------------------------------------------------- */

    private static function tab_advanced() {
        $next = wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);
        $gd = function_exists('imagecreatetruecolor'); $zlib = function_exists('gzcompress');
        $rows = [
            [$gd || $zlib, __('QR codes', 'snn-tickets'), $gd ? __('Working (GD).', 'snn-tickets') : ($zlib ? __('Working (built-in encoder).', 'snn-tickets') : __('Neither GD nor zlib is available, so QR images cannot be made.', 'snn-tickets'))],
            [(bool)$next, __('Background sending', 'snn-tickets'), $next
                ? sprintf(__('Next run in %s.', 'snn-tickets'), human_time_diff($next, time())) . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? ' ' . __('DISABLE_WP_CRON is on: make sure a real cron job calls wp-cron.php.', 'snn-tickets') : '')
                : __('Not scheduled. Deactivate and reactivate the plugin to fix it.', 'snn-tickets')],
            [SNN_T_Router::pretty(), __('Short event links', 'snn-tickets'), SNN_T_Router::pretty() ? __('On.', 'snn-tickets') : __('Your site uses plain permalinks, so event links look technical.', 'snn-tickets')],
            [class_exists('ZipArchive'), __('Apple Wallet support', 'snn-tickets'), class_exists('ZipArchive') ? __('The zip extension is available.', 'snn-tickets') : __('The PHP zip extension is missing, so Apple Wallet passes cannot be made.', 'snn-tickets')],
            [function_exists('openssl_sign'), __('Wallet signing', 'snn-tickets'), function_exists('openssl_sign') ? __('OpenSSL is available.', 'snn-tickets') : __('OpenSSL is missing, so wallet passes cannot be signed.', 'snn-tickets')],
        ];
        self::form_open('advanced'); ?>
            <div class="snn-set"><div><h3><?php esc_html_e('System check', 'snn-tickets'); ?></h3></div><div class="body">
                <ul class="snn-syscheck"><?php foreach ($rows as $r): ?><li><span class="<?php echo $r[0] ? 'y' : 'n'; ?>"><?php echo $r[0] ? '✓' : '✕'; ?></span><span><b><?php echo esc_html($r[1]); ?></b><br><span class="snn-muted snn-small"><?php echo esc_html($r[2]); ?></span></span></li><?php endforeach; ?></ul>
            </div></div>
            <?php if (current_user_can('manage_options')): ?>
            <div class="snn-set"><div><h3 style="color:#b32d2e"><?php esc_html_e('Reset QR signature', 'snn-tickets'); ?></h3></div><div class="body">
                <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Every QR code carries a secret signature so it cannot be faked. Only reset it if you think the secret leaked: every ticket and download link already sent will stop working.', 'snn-tickets'); ?></p>
                <div><button class="button button-link-delete" name="reset_secret" value="1" data-confirm="<?php esc_attr_e('Reset the QR signature? Every ticket already sent will stop working at the door.', 'snn-tickets'); ?>"><?php esc_html_e('Reset signature…', 'snn-tickets'); ?></button></div>
            </div></div>
            <?php endif; ?>
            <?php self::save_row(__('Save', 'snn-tickets')); ?>
        </form>
        <?php
    }

    public static function save_advanced() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_advanced');
        if (!empty($_POST['reset_secret']) && current_user_can('manage_options')) {
            delete_option(SNN_T_QR::SECRET_OPTION);
            SNN_T_QR::secret();
            $n = SNN_T_QR::flush_cache();
            SNN_T_Admin::go(self::url('advanced'), sprintf(__('Signature reset; %d cached QR images cleared.', 'snn-tickets'), $n));
        }
        SNN_T_Admin::go(self::url('advanced'), __('Saved.', 'snn-tickets'));
    }

    /** The old name of the Settings screen callback. */
    /* ------------------------------------------------------------------
     * Wording
     * ---------------------------------------------------------------- */

    private static function tab_wording() {
        $saved = SNN_T_Texts::saved();
        self::form_open('wording'); ?>
            <div class="snn-hint"><?php esc_html_e('What guests and buyers read on the claim page, the ticket list and the shop pages. Leave a box empty to use the standard wording, which is translated into your site\'s language automatically. Emails are edited on each event\'s Emails tab.', 'snn-tickets'); ?></div>
            <?php foreach (SNN_T_Texts::groups() as $group): ?>
                <div class="snn-set"><div><h3><?php echo esc_html($group['title']); ?></h3><p class="snn-muted snn-small"><?php echo esc_html($group['intro']); ?></p></div><div class="body">
                    <?php foreach ($group['fields'] as $key => $f): ?>
                        <label class="snn-field"><span><?php echo esc_html($f[0]); ?><?php if ($f[2] !== ''): ?> <small class="snn-mono"><?php echo esc_html($f[2]); ?></small><?php endif; ?></span>
                            <input type="text" name="texts[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($saved[$key] ?? ''); ?>" placeholder="<?php echo esc_attr($f[1]); ?>"></label>
                    <?php endforeach; ?>
                </div></div>
            <?php endforeach; ?>
            <?php self::save_row(__('Save wording', 'snn-tickets')); ?>
        </form>
        <?php
    }

    public static function save_wording() {
        SNN_T_Admin::cap(); check_admin_referer('snn_set_wording');
        $n = count(SNN_T_Texts::save((array)wp_unslash($_POST['texts'] ?? [])));
        SNN_T_Admin::go(self::url('wording'), $n
            ? sprintf(_n('Saved. %d text uses your own words.', 'Saved. %d texts use your own words.', $n, 'snn-tickets'), $n)
            : __('Saved. Everything uses the standard wording.', 'snn-tickets'));
    }

    public static function render_settings_page() { self::render(); }
}
