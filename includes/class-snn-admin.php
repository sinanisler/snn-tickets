<?php
/**
 * Shared admin pieces: assets, notices, small helpers, the email editor,
 * the share box, the look picker, and redirects from old screens.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Admin {

    /** Screens from before 0.26 and where they live now. */
    const LEGACY = [
        'snn-tickets-forms', 'snn-tickets-submissions', 'snn-tickets-lists', 'snn-tickets-generator',
        'snn-tickets-csv-import', 'snn-tickets-templates', 'snn-tickets-mailer', 'snn-tickets-queue', 'snn-tickets-design',
    ];

    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_init', [__CLASS__, 'legacy_redirect']);
        add_action('wp_ajax_snn_save_template', [__CLASS__, 'ajax_save_template']);
    }

    public static function cap() {
        if (!current_user_can(SNN_T_Tickets::cap())) wp_die(esc_html__('Insufficient permissions', 'snn-tickets'));
    }

    public static function page() {
        return isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    }

    public static function is_our_screen() {
        return strpos(self::page(), 'snn-tickets') === 0;
    }

    public static function assets() {
        if (!self::is_our_screen()) return;
        $ver = defined('SNN_TICKETS_VERSION') ? SNN_TICKETS_VERSION : '1';
        wp_enqueue_style('snn-tickets-admin', SNN_TICKETS_URL . 'assets/admin.css', [], $ver);
        wp_enqueue_script('snn-tickets-admin', SNN_TICKETS_URL . 'assets/admin.js', [], $ver, true);
        if (self::page() === 'snn-tickets-settings') wp_enqueue_media();
        wp_localize_script('snn-tickets-admin', 'SNNT', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snn_email_tools'),
            'i18n'  => self::js_strings(),
        ]);
    }

    private static function js_strings() {
        return [
            'copied' => __('Copied', 'snn-tickets'), 'copyThis' => __('Copy this:', 'snn-tickets'),
            'selected' => __('%d selected', 'snn-tickets'), 'error' => __('Something went wrong.', 'snn-tickets'),
            'visual' => __('Visual', 'snn-tickets'), 'linkUrl' => __('Link address', 'snn-tickets'),
            'resetAsk' => __('Replace the text with the default wording?', 'snn-tickets'),
            'tplName' => __('Name this template (for example "Conference ticket")', 'snn-tickets'),
            'replaceAsk' => __('Replace the current text with this template?', 'snn-tickets'),
            'sending' => __('Sending…', 'snn-tickets'), 'desktop' => __('Desktop', 'snn-tickets'), 'phone' => __('Phone', 'snn-tickets'),
            'subject' => __('Subject', 'snn-tickets'),
            'moveUp' => __('Move up', 'snn-tickets'), 'moveDown' => __('Move down', 'snn-tickets'),
            'untitled' => __('Untitled question', 'snn-tickets'), 'ticketName' => __('Name on the ticket', 'snn-tickets'),
            'ticketGoes' => __('Ticket goes here', 'snn-tickets'), 'question' => __('Question', 'snn-tickets'),
            'nameHelp' => __('Printed on the ticket and shown at the door. You can change the wording, but not remove it.', 'snn-tickets'),
            'emailHelp' => __('The ticket is sent to this address, so it is always required. You can change the wording, but not remove it.', 'snn-tickets'),
            'hint' => __('Hint inside the box', 'snn-tickets'), 'optional' => __('(optional)', 'snn-tickets'),
            'answerType' => __('Answer type', 'snn-tickets'), 'value' => __('Value', 'snn-tickets'),
            'hiddenHelp' => __('Not shown to people. Saved with every sign-up, for example to know which campaign they came from.', 'snn-tickets'),
            'choices' => __('Choices', 'snn-tickets'), 'onePerLine' => __('(one per line)', 'snn-tickets'),
            'moreOptions' => __('More options', 'snn-tickets'), 'prefilled' => __('Pre-filled answer', 'snn-tickets'),
            'chipHelp' => __('In emails, this answer is the chip', 'snn-tickets'), 'required' => __('Required', 'snn-tickets'),
            'duplicate' => __('Duplicate', 'snn-tickets'), 'remove' => __('Remove', 'snn-tickets'),
            'removeAsk' => __('Remove this question? Answers people already gave are kept.', 'snn-tickets'),
            'copySuffix' => __('(copy)', 'snn-tickets'), 'opt1' => __('Option 1', 'snn-tickets'), 'opt2' => __('Option 2', 'snn-tickets'),
            'add' => __('Add', 'snn-tickets'), 'approveWhen' => __('Give a ticket right away when', 'snn-tickets'),
            'all' => __('all', 'snn-tickets'), 'any' => __('any', 'snn-tickets'), 'ofThese' => __('of these are true:', 'snn-tickets'),
            'noValue' => __('no value needed', 'snn-tickets'), 'listPh' => __('a, b, c', 'snn-tickets'), 'valuePh' => __('value', 'snn-tickets'),
            'noRules' => __('No conditions yet. Add one below.', 'snn-tickets'), 'addCondition' => __('Add condition', 'snn-tickets'),
            'everyoneElse' => __('Everyone else:', 'snn-tickets'), 'waitsForMe' => __('waits for my approval', 'snn-tickets'),
            'declinedAuto' => __('is declined automatically', 'snn-tickets'), 'tryIt' => __('Try it:', 'snn-tickets'),
            'tryHelp' => __('type sample answers to see what would happen', 'snn-tickets'),
            'getsTicket' => __('gets a ticket right away', 'snn-tickets'), 'isDeclined' => __('is declined', 'snn-tickets'),
            'waits' => __('waits for you', 'snn-tickets'), 'spotsLeft' => __('%d spots left', 'snn-tickets'),
            'choose' => __('Choose…', 'snn-tickets'), 'getTicket' => __('Get my ticket', 'snn-tickets'),
            'noEmail' => __('No question collects the email address, so tickets cannot be emailed. Save anyway?', 'snn-tickets'),
            'yourEvent' => __('Your event', 'snn-tickets'), 'stepOf' => __('Step %1$d of %2$d', 'snn-tickets'),
            'nameFirst' => __('Give the event a name first.', 'snn-tickets'),
        ];
    }

    /* ------------------------------------------------------------------
     * Old screens
     * ---------------------------------------------------------------- */

    /** Send bookmarks of pre-0.26 screens to where things live now. */
    public static function legacy_redirect() {
        $page = self::page();
        if (!in_array($page, self::LEGACY, true) || !current_user_can(SNN_T_Tickets::cap())) return;

        $g = function ($k) { return isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : ''; };
        $to = admin_url('admin.php?page=snn-tickets-events');
        switch ($page) {
            case 'snn-tickets-forms':
                if ($g('action') === 'new') { $to = admin_url('admin.php?page=snn-tickets-new'); break; }
                if (($form = SNN_T_Forms::get((int)$g('form')))) $to = self::event_admin_url($form->list_id, ['tab' => 'form']);
                break;
            case 'snn-tickets-submissions':
                if (($s = SNN_T_Submissions::get((int)$g('submission'))) && ($form = SNN_T_Forms::get($s->form_id))) {
                    $to = self::event_admin_url($form->list_id, ['person' => 's' . (int)$s->id]);
                }
                break;
            case 'snn-tickets-lists':
                if ((int)$g('list')) $to = self::event_admin_url((int)$g('list'), ['tab' => $g('edit') ? 'settings' : 'people']);
                break;
            case 'snn-tickets-templates': $to = admin_url('admin.php?page=snn-tickets-emails&tab=templates'); break;
            case 'snn-tickets-queue':     $to = admin_url('admin.php?page=snn-tickets-emails&tab=log'); break;
            case 'snn-tickets-design':    $to = admin_url('admin.php?page=snn-tickets-settings&tab=look'); break;
            case 'snn-tickets-mailer':
            case 'snn-tickets-generator':
            case 'snn-tickets-csv-import':
                if ((int)$g('list_id')) $to = self::event_admin_url((int)$g('list_id'));
                break;
        }
        wp_safe_redirect($to);
        exit;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------- */

    public static function event_admin_url($list_id, $args = []) {
        return add_query_arg(array_merge(['page' => 'snn-tickets-events', 'event' => (int)$list_id], $args), admin_url('admin.php'));
    }

    public static function notice() {
        if (!isset($_GET['snn_msg'])) return;
        $msg  = sanitize_text_field(wp_unslash($_GET['snn_msg']));
        $type = isset($_GET['snn_type']) && $_GET['snn_type'] === 'error' ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    /** Redirect with a notice. */
    public static function go($url, $msg = '', $error = false) {
        $url = remove_query_arg(['snn_msg', 'snn_type'], $url);
        if ($msg !== '') {
            $url = add_query_arg('snn_msg', rawurlencode($msg), $url);
            if ($error) $url = add_query_arg('snn_type', 'error', $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    /** Kept for older callers: back to a plugin page with a notice. */
    public static function back($page, $msg, $extra = [], $error = false) {
        self::go(add_query_arg(array_merge(['page' => $page], $extra), admin_url('admin.php')), $msg, $error);
    }

    public static function chip($label, $class = '') {
        return '<span class="snn-chip ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    public static function state_chip($state) {
        $s = SNN_T_People::states()[$state] ?? [$state, ''];
        return self::chip($s[0], $s[1]);
    }

    /** Email queue states. */
    public static function status_badge($status) {
        $map = [
            'pending' => [__('Waiting', 'snn-tickets'), 'warn'], 'sending' => [__('Sending', 'snn-tickets'), 'info'],
            'sent' => [__('Sent', 'snn-tickets'), 'ok'], 'failed' => [__('Failed', 'snn-tickets'), 'bad'],
        ];
        $s = $map[$status] ?? [$status, ''];
        return self::chip($s[0], $s[1]);
    }

    /** "3 minutes ago" for recent, the date otherwise. */
    public static function when($mysql) {
        if (!$mysql || strpos((string)$mysql, '0000') === 0) return '—';
        $ts  = strtotime($mysql);
        $now = current_time('timestamp');
        $abs = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts);
        if ($ts <= $now && $now - $ts < DAY_IN_SECONDS) {
            return '<span title="' . esc_attr($abs) . '">' . esc_html(sprintf(__('%s ago', 'snn-tickets'), human_time_diff($ts, $now))) . '</span>';
        }
        return esc_html($abs);
    }

    public static function pager($total, $per, $paged) {
        $pages = max(1, (int)ceil($total / max(1, $per)));
        if ($pages < 2) return '';
        return '<div class="snn-pager"><span class="snn-muted">' . esc_html(sprintf(__('%1$s–%2$s of %3$s', 'snn-tickets'),
                number_format_i18n(($paged - 1) * $per + 1), number_format_i18n(min($total, $paged * $per)), number_format_i18n($total))) . '</span>'
            . paginate_links([
                'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages,
                'prev_text' => '‹', 'next_text' => '›', 'end_size' => 1, 'mid_size' => 2,
            ]) . '</div>';
    }

    public static function copy_button($text, $label = '') {
        return '<button type="button" class="button button-small" data-copy="' . esc_attr($text) . '">' . esc_html($label !== '' ? $label : __('Copy', 'snn-tickets')) . '</button>';
    }

    /** Inline QR image for any text (links to scanner pages). */
    public static function qr_img($text, $size = 140) {
        require_once SNN_TICKETS_DIR . 'qrcode.php';
        try {
            $qr = new SNN_QRCode($text, ['errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_M]);
            return '<img src="' . esc_attr($qr->toDataUri(4, 2)) . '" width="' . (int)$size . '" height="' . (int)$size . '" alt="">';
        } catch (Throwable $e) {
            return '';
        }
    }

    /* ------------------------------------------------------------------
     * Share & place
     * ---------------------------------------------------------------- */

    /** Every way to put an event on the site: links first, shortcodes second. */
    public static function share_rows($event) {
        $form = SNN_T_Forms::for_list($event->id);
        $rows = [];
        $rows[] = [__('Sign-up page', 'snn-tickets'), __('Share this link. People sign up here.', 'snn-tickets'),
                   SNN_T_Router::event_url($event), $form ? '[snn_ticket_form id="' . (int)$form->id . '"]' : ''];
        $rows[] = [__('Door scanner for this event', 'snn-tickets'), __('Open on a phone at the entrance. Refuses tickets for other events.', 'snn-tickets'),
                   SNN_T_Router::door_url($event), '[tickets_scan_page list="' . (int)$event->id . '"]'];
        $rows[] = [__('Door scanner for all events', 'snn-tickets'), __('One scanner for everything.', 'snn-tickets'),
                   SNN_T_Router::door_url(), '[tickets_scan_page]'];
        return $rows;
    }

    public static function share_dialog($event) {
        ?>
        <dialog class="snn-dialog" id="snn-share">
            <div class="snn-dialog-h"><h2><?php esc_html_e('Share & place', 'snn-tickets'); ?></h2><button type="button" class="snn-x" data-close aria-label="<?php esc_attr_e('Close', 'snn-tickets'); ?>">×</button></div>
            <div class="snn-dialog-b">
                <p class="snn-muted" style="margin:0"><?php esc_html_e('The links work right away, with no page to create. Use a shortcode instead if you want to place something on a page of your own.', 'snn-tickets'); ?></p>
                <div class="snn-table-wrap"><table class="snn-table snn-share-table" style="min-width:560px">
                    <thead><tr><th><?php esc_html_e('What', 'snn-tickets'); ?></th><th><?php esc_html_e('Link', 'snn-tickets'); ?></th><th><?php esc_html_e('Shortcode', 'snn-tickets'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach (self::share_rows($event) as $r): ?>
                        <tr>
                            <td><b><?php echo esc_html($r[0]); ?></b><br><span class="snn-muted snn-small"><?php echo esc_html($r[1]); ?></span></td>
                            <td><span class="snn-mono" style="overflow-wrap:anywhere"><?php echo esc_html($r[2]); ?></span><br>
                                <?php echo self::copy_button($r[2]); ?> <a class="button button-small" href="<?php echo esc_url($r[2]); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open', 'snn-tickets'); ?> ↗</a></td>
                            <td><?php if ($r[3] !== ''): ?><code><?php echo esc_html($r[3]); ?></code><br><?php echo self::copy_button($r[3]); ?><?php else: ?><span class="snn-muted">—</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if (!SNN_T_Router::pretty()): ?>
                    <div class="snn-hint warn"><?php printf(esc_html__('Your site uses plain permalinks, so these links look technical. For short links like yoursite.com/events/my-event, pick any other option under %s.', 'snn-tickets'), '<a href="' . esc_url(admin_url('options-permalink.php')) . '">' . esc_html__('Settings → Permalinks', 'snn-tickets') . '</a>'); ?></div>
                <?php endif; ?>
            </div>
        </dialog>
        <?php
    }

    /* ------------------------------------------------------------------
     * Look picker
     * ---------------------------------------------------------------- */

    public static function looks_picker($name, $selected, $with_site = false) {
        $out = '<div class="snn-looks" role="radiogroup">';
        if ($with_site) {
            $site = SNN_T_Design::presets()[SNN_T_Design::settings()['preset']];
            $out .= '<label class="snn-look"><input type="radio" name="' . esc_attr($name) . '" value=""' . checked($selected, '', false) . '>'
                  . SNN_T_Design::thumb(SNN_T_Design::resolve('')) . esc_html__('Site style', 'snn-tickets')
                  . '<small>' . esc_html(sprintf(__('%s with your colours', 'snn-tickets'), $site['label'])) . '</small></label>';
        }
        foreach (SNN_T_Design::presets() as $k => $p) {
            $out .= '<label class="snn-look"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($k) . '"' . checked($selected, $k, false) . '>'
                  . SNN_T_Design::thumb($p) . esc_html($p['label']) . '<small>' . esc_html($p['blurb']) . '</small></label>';
        }
        return $out . '</div>';
    }

    /* ------------------------------------------------------------------
     * Email editor
     * ---------------------------------------------------------------- */

    /**
     * An email editor: subject, chips for tags, a small toolbar, live
     * preview, test send, and the template library.
     *
     * $a keys: role, list (event id or 0), name (input name prefix),
     * subject, body, answers (tag => label), library (bool)
     */
    public static function email_editor($a) {
        $role     = $a['role'];
        $default  = SNN_T_Mailer::default_template($role);
        $tags     = [];
        foreach (SNN_T_Mailer::tag_catalog() as $tag => $d) {
            if ($d[3] !== null && !in_array($role, $d[3], true)) continue;
            $tags[] = [$tag, $d[0], $d[1], $d[2]];
        }
        $answers = [];
        foreach ((array)($a['answers'] ?? []) as $tag => $label) $answers[] = [$tag, $label];
        $templates = [];
        foreach (SNN_T_Mailer::templates_for_role($role) as $n => $tp) $templates[$n] = ['subject' => $tp['subject'], 'body' => $tp['body']];

        $cfg = ['role' => $role, 'list' => (int)($a['list'] ?? 0), 'tags' => $tags, 'answers' => $answers,
                'templates' => $templates, 'defaults' => $default];
        $name = $a['name'];
        $groups = [];
        foreach ($tags as $tg) $groups[$tg[2]][] = $tg;
        ?>
        <div class="snn-grid2" style="align-items:start" data-email-editor data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
            <div class="snn-col">
                <?php if (!empty($a['library'])): ?>
                <div class="snn-row">
                    <select data-start-template aria-label="<?php esc_attr_e('Start from a template', 'snn-tickets'); ?>">
                        <option value=""><?php esc_html_e('Start from a template…', 'snn-tickets'); ?></option>
                        <?php foreach ($templates as $n => $tp): ?><option value="<?php echo esc_attr($n); ?>"><?php echo esc_html($n); ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" class="button" data-save-template><?php esc_html_e('Save as template', 'snn-tickets'); ?></button>
                </div>
                <?php endif; ?>
                <label class="snn-field"><span><?php esc_html_e('Subject', 'snn-tickets'); ?></span>
                    <input type="text" name="<?php echo esc_attr($name); ?>[subject]" value="<?php echo esc_attr($a['subject'] !== '' ? $a['subject'] : $default['subject']); ?>" data-subject></label>
                <div class="snn-field"><span><?php esc_html_e('Message', 'snn-tickets'); ?></span>
                    <div class="snn-inserts"><span class="snn-muted snn-small"><?php esc_html_e('Insert:', 'snn-tickets'); ?></span>
                        <?php foreach ($groups as $g => $list): ?>
                            <span class="grp"><?php echo esc_html($g); ?></span>
                            <?php foreach ($list as $tg): ?><button type="button" data-ins="<?php echo esc_attr($tg[0]); ?>"><?php echo esc_html($tg[1]); ?></button><?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php if ($answers): ?><span class="grp"><?php esc_html_e('Answers', 'snn-tickets'); ?></span>
                            <?php foreach ($answers as $an): ?><button type="button" data-ins="<?php echo esc_attr($an[0]); ?>"><?php echo esc_html($an[1]); ?></button><?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="snn-rtebar">
                            <button type="button" data-cmd="bold" title="<?php esc_attr_e('Bold', 'snn-tickets'); ?>">B</button>
                            <button type="button" data-cmd="italic" title="<?php esc_attr_e('Italic', 'snn-tickets'); ?>" style="font-style:italic">I</button>
                            <button type="button" data-cmd="h2" title="<?php esc_attr_e('Heading', 'snn-tickets'); ?>">H</button>
                            <button type="button" data-cmd="p" title="<?php esc_attr_e('Paragraph', 'snn-tickets'); ?>">¶</button>
                            <button type="button" data-cmd="insertUnorderedList" title="<?php esc_attr_e('List', 'snn-tickets'); ?>">•</button>
                            <button type="button" data-cmd="createLink" title="<?php esc_attr_e('Link', 'snn-tickets'); ?>">🔗</button>
                            <span class="snn-spacer"></span>
                            <button type="button" data-cmd="html" title="<?php esc_attr_e('Edit the HTML', 'snn-tickets'); ?>">HTML</button>
                        </div>
                        <div class="snn-rte" contenteditable="true" data-rte aria-label="<?php esc_attr_e('Message', 'snn-tickets'); ?>"></div>
                        <textarea class="snn-html" data-html hidden aria-label="<?php esc_attr_e('Message HTML', 'snn-tickets'); ?>"></textarea>
                        <textarea name="<?php echo esc_attr($name); ?>[body]" data-body hidden><?php echo esc_textarea($a['body'] !== '' ? $a['body'] : $default['body']); ?></textarea>
                    </div>
                </div>
                <?php if (!empty($a['after'])) echo $a['after']; // trusted markup from caller ?>
                <div class="snn-row">
                    <input type="email" data-test-to value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" aria-label="<?php esc_attr_e('Send a test to', 'snn-tickets'); ?>" style="max-width:230px">
                    <button type="button" class="button" data-test><?php esc_html_e('Send me a test', 'snn-tickets'); ?></button>
                    <span data-test-res class="snn-small"></span>
                    <span class="snn-spacer"></span>
                    <button type="button" class="button-link" data-reset><?php esc_html_e('Reset to default text', 'snn-tickets'); ?></button>
                </div>
            </div>
            <div class="snn-col">
                <h3 style="margin:0"><?php esc_html_e('Preview', 'snn-tickets'); ?></h3>
                <div class="snn-mprev" data-preview><p class="snn-muted"><?php esc_html_e('Loading preview…', 'snn-tickets'); ?></p></div>
            </div>
        </div>
        <?php
    }

    /** Save a template from any email editor. */
    public static function ajax_save_template() {
        if (!current_user_can(SNN_T_Tickets::cap())) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('snn_email_tools', 'nonce');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $role = sanitize_key($_POST['role'] ?? 'ticket');
        if ($name === '') wp_send_json_error(['message' => __('A template needs a name.', 'snn-tickets')]);
        if (!isset(SNN_T_Mailer::roles()[$role])) $role = 'ticket';
        $templates = SNN_T_Mailer::get_templates();
        $existed = isset($templates[$name]);
        $templates[$name] = [
            'role'    => $role,
            'subject' => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
            'body'    => wp_kses_post(wp_unslash($_POST['body'] ?? '')),
            'created' => $templates[$name]['created'] ?? current_time('mysql'),
            'updated' => current_time('mysql'),
        ];
        update_option(SNN_T_Mailer::TEMPLATES_OPTION, $templates);
        wp_send_json_success(['message' => $existed
            ? sprintf(__('Template "%s" updated.', 'snn-tickets'), $name)
            : sprintf(__('Saved as template "%s". Use it in any event with "Start from a template".', 'snn-tickets'), $name)]);
    }
}
