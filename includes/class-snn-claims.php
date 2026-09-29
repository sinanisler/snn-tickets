<?php
/**
 * Passed-on tickets: a ticket that has no name yet, a private link that
 * lets its guest fill one in, and the page behind that link.
 *
 *   ''      a normal ticket with its holder's name on it
 *   'open'  not named yet; held by whoever bought it
 *   'sent'  its link was emailed to someone who has not filled it in yet
 *
 * The link's key is random and stored on the ticket, so taking a ticket
 * back is just a new key. Claiming gives the ticket a new code: the QR the
 * buyer may still have stops working, and only the guest holds the ticket.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Claims {

    const OPEN = 'open';
    const SENT = 'sent';

    const NONCE = 'snn_claim';

    public static function is_open($ticket) {
        return $ticket && in_array((string)($ticket->holder ?? ''), [self::OPEN, self::SENT], true);
    }

    public static function new_key() {
        return bin2hex(random_bytes(16));
    }

    public static function by_key($key) {
        global $wpdb;
        $key = preg_replace('/[^a-f0-9]/', '', strtolower((string)$key));
        if (strlen($key) !== 32) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . SNN_T_DB::tickets() . " WHERE claim_key = %s", $key));
    }

    /** The ticket a link used to open before its guest claimed it. */
    public static function by_used_key($key) {
        global $wpdb;
        $key = preg_replace('/[^a-f0-9]/', '', strtolower((string)$key));
        if (strlen($key) !== 32) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . SNN_T_DB::tickets() . " WHERE claimed_key = %s", $key));
    }

    /** "b***@example.com": enough to recognise, not enough to learn. */
    public static function mask_email($email) {
        $email = (string)$email;
        $at = strpos($email, '@');
        if ($at === false) return '';
        return substr($email, 0, 1) . str_repeat('*', max(3, min(8, $at - 1))) . substr($email, $at);
    }

    public static function url($ticket) {
        return ($ticket && $ticket->claim_key !== '') ? SNN_T_Router::claim_url($ticket->claim_key) : '';
    }

    private static function set($id, $row) {
        global $wpdb;
        $wpdb->update(SNN_T_DB::tickets(), $row, ['id' => (int)$id]);
    }

    /** Make a ticket wait for a name, with a fresh link. */
    public static function open($id) {
        self::set($id, ['holder' => self::OPEN, 'claim_key' => self::new_key(), 'claim_email' => '']);
    }

    /**
     * Email a ticket's link to someone. A new key each time, so an address
     * typed wrongly the first time cannot claim it later.
     *
     * @param string $from who is passing it on, for the email: "Sinan"
     * @return true|WP_Error
     */
    public static function send($ticket, $email, $from = '') {
        $email = strtolower(sanitize_email($email));
        if (!self::is_open($ticket) || $ticket->status !== 'active') {
            return new WP_Error('snn_claim_state', __('This ticket already has a name on it.', 'snn-tickets'));
        }
        if (!$email || !is_email($email)) {
            return new WP_Error('snn_claim_email', __('That email address does not look right.', 'snn-tickets'));
        }
        $key = self::new_key();
        self::set($ticket->id, ['holder' => self::SENT, 'claim_key' => $key, 'claim_email' => $email]);
        $url = SNN_T_Router::claim_url($key);

        SNN_T_Mailer::send_event_email('gift', (int)$ticket->list_id, [
            'name'      => '',
            'email'     => $email,
            'ticket_id' => (int)$ticket->id,
            'vars'      => [
                '{buyer}'        => $from !== '' ? $from : get_bloginfo('name'),
                '{claim_url}'    => $url,
                '{claim_button}' => SNN_T_Mailer::button_html($url, SNN_T_Texts::get('claim_button')),
            ],
        ]);
        do_action('snn_tickets_claim_changed', (int)$ticket->id);
        return true;
    }

    /** Take a sent link back: the old link stops working. */
    public static function take_back($ticket) {
        if (!self::is_open($ticket)) return new WP_Error('snn_claim_state', __('This ticket already has a name on it.', 'snn-tickets'));
        self::open($ticket->id);
        do_action('snn_tickets_claim_changed', (int)$ticket->id);
        return true;
    }

    /**
     * The guest fills in their details: the ticket becomes theirs, with a
     * new code, and their ticket email goes out.
     *
     * @return object|WP_Error the updated ticket
     */
    public static function claim($ticket, $name, $email, $answers = [], $form = null) {
        global $wpdb;
        if (!self::is_open($ticket) || $ticket->status !== 'active') {
            return new WP_Error('snn_claim_state', __('This ticket has already been claimed.', 'snn-tickets'));
        }

        $sid = 0;
        if ($form && !empty($form->id) && $answers) {
            $sid = SNN_T_Submissions::create([
                'form_id' => (int)$form->id, 'status' => 'approved', 'name' => $name, 'email' => $email,
                'data' => $answers, 'ip' => SNN_T_Tickets::client_ip(),
            ]);
        }

        $old  = $ticket->ticket_code;
        $code = SNN_T_Tickets::unique_code(max(8, strlen($old)));
        $row  = [
            'ticket_code' => $code, 'name' => sanitize_text_field($name), 'email' => strtolower(sanitize_email($email)),
            'holder' => '', 'claim_key' => '', 'claim_email' => '', 'claimed_key' => (string)$ticket->claim_key,
        ];
        // Keep the answers of an earlier sign-up record if there is no new one.
        if ($sid) $row['submission_id'] = $sid;
        self::set($ticket->id, $row);
        SNN_T_QR::delete($old);

        if ($sid) {
            $wpdb->update(SNN_T_DB::submissions(), [
                'ticket_id' => (int)$ticket->id, 'decided_at' => current_time('mysql'),
                'decision_reason' => __('Claimed a ticket passed on to them', 'snn-tickets'),
            ], ['id' => $sid]);
        }

        $fresh = SNN_T_Tickets::get($ticket->id);
        if ($fresh && $fresh->email !== '') SNN_T_Mailer::queue_ticket($fresh);
        if ($fresh) self::notify_admin($fresh, $sid);
        do_action('snn_tickets_claim_changed', (int)$ticket->id);
        do_action('snn_tickets_claimed', (int)$ticket->id, $old);
        return $fresh;
    }

    /** "Notice to you" when a guest claims a ticket, if the event wants it. */
    public static function notify_admin($ticket, $sid = 0) {
        $event = SNN_T_Events::get((int)$ticket->list_id);
        if (!$event) return;
        $cfg = SNN_T_Events::emails($event)['admin'];
        if (empty($cfg['on']) || empty($cfg['claims'])) return;
        foreach (SNN_T_Events::admin_recipients($event) as $to) {
            SNN_T_Mailer::send_event_email('admin', (int)$event->id, [
                'name'          => $ticket->name,
                'email'         => $ticket->email,
                'to_email'      => $to,
                'status'        => __('Claimed a ticket passed on to them', 'snn-tickets'),
                'submission_id' => $sid ?: null,
                'person'        => 't' . (int)$ticket->id,
            ]);
        }
    }

    /* ------------------------------------------------------------------
     * The claim page
     * ---------------------------------------------------------------- */

    /** The questions a guest answers: the event's sign-up form. */
    public static function form_for($list_id) {
        $form = SNN_T_Forms::for_list($list_id);
        return $form ?: (object)['id' => 0, 'fields' => SNN_T_Forms::default_fields()];
    }

    public static function route($key) {
        $ticket = self::by_key($key);
        $event  = $ticket ? SNN_T_Events::get((int)$ticket->list_id) : null;

        // A link that was used: say so, without showing the guest's ticket
        // to whoever else holds the link (usually the buyer).
        $used = !$ticket ? self::by_used_key($key) : null;
        if ($used && $used->status === 'active') {
            $ev = SNN_T_Events::get((int)$used->list_id);
            SNN_T_Router::render_in_theme(__('Ticket link', 'snn-tickets'), self::wrap(
                '<h1>' . esc_html(SNN_T_Texts::get('claim_used_title')) . '</h1>'
                . '<p>' . esc_html(SNN_T_Texts::get('claim_used_text', ['event' => $ev ? $ev->name : '', 'email' => self::mask_email($used->email)])) . '</p>'
                . '<p>' . esc_html(SNN_T_Texts::get('claim_used_help')) . '</p>'), true);
            return;
        }

        if (!$ticket || !$event || $ticket->status !== 'active' || !self::is_open($ticket)) {
            status_header(404);
            SNN_T_Router::render_in_theme(__('Ticket link', 'snn-tickets'), self::wrap(
                '<h1>' . esc_html(SNN_T_Texts::get('claim_dead_title')) . '</h1>'
                . '<p>' . esc_html(SNN_T_Texts::get('claim_dead_text')) . '</p>'), true);
            return;
        }

        $form   = self::form_for($event->id);
        $errors = []; $old = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['_snn_claim']) || !wp_verify_nonce(wp_unslash($_POST['_snn_claim']), self::NONCE . $key) || !empty($_POST['snn_website'])) {
                $errors['_'] = __('Something went wrong. Please try again.', 'snn-tickets');
            } else {
                $raw = isset($_POST['snn_field']) && is_array($_POST['snn_field']) ? wp_unslash($_POST['snn_field']) : [];
                list($data, $errors, $name, $email) = SNN_T_Forms::collect($form, $raw);
                $old = $data;
                if (!$errors && $email === '') $errors['_'] = __('A valid email address is required.', 'snn-tickets');
                if (!$errors) {
                    $r = self::claim($ticket, $name, $email, $data, $form);
                    if (!is_wp_error($r)) {
                        wp_safe_redirect(add_query_arg('claimed', 1, SNN_T_Router::ticket_url($r->ticket_code)));
                        exit;
                    }
                    $errors['_'] = $r->get_error_message();
                }
            }
        }

        $from  = (string)apply_filters('snn_tickets_claim_from', '', $ticket);
        $when  = SNN_T_Events::format_when($event);
        $where = SNN_T_Events::format_where($event);

        ob_start();
        SNN_T_Forms::render_styles();
        echo '<p class="snn-event-when">' . esc_html($when) . '</p>';
        echo '<h1 class="snn-event-title">' . esc_html($from !== ''
            ? SNN_T_Texts::get('claim_title_from', ['buyer' => $from, 'event' => $event->name])
            : SNN_T_Texts::get('claim_title', ['event' => $event->name])) . '</h1>';
        if ($where !== '') echo '<p class="snn-event-where">' . esc_html($where) . '</p>';
        echo '<p>' . esc_html(SNN_T_Texts::get('claim_intro')) . '</p>';

        echo '<div class="snn-ticket-form-wrap"><form method="post" class="snn-ticket-form" novalidate>';
        if (!empty($errors['_'])) echo '<div class="snn-form-notice snn-err" role="alert">' . esc_html($errors['_']) . '</div>';
        wp_nonce_field(self::NONCE . $key, '_snn_claim');
        echo '<div class="snn-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="snn_website" tabindex="-1" autocomplete="off"></label></div>';
        foreach ($form->fields as $field) {
            $value = $old[$field['key']] ?? null;
            if ($value === null && $ticket->claim_email !== '' && $field['map_to'] === 'email') $value = $ticket->claim_email;
            SNN_T_Forms::render_field($field, $value, $errors[$field['key']] ?? '');
        }
        echo '<p class="snn-form-submit"><button type="submit" class="snn-submit-button">' . esc_html(SNN_T_Texts::get('claim_submit')) . '</button></p>';
        echo '</form></div>';

        SNN_T_Router::render_in_theme($event->name, self::wrap(ob_get_clean()));
    }

    private static function wrap($html) {
        return '<div class="snn-event-page snn-claim-page">' . $html . '</div>'
            . '<style>.snn-event-page{max-width:640px;margin:0 auto;padding:32px 16px 48px}.snn-event-when{margin:0 0 6px;font-size:.85em;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.75}.snn-event-title{margin:0 0 6px}.snn-event-where{margin:0 0 20px;opacity:.8}</style>';
    }
}
