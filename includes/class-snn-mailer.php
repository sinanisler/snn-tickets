<?php
/**
 * Email templates, placeholder rendering, and the server-side send queue.
 *
 * Sending runs on WP-Cron rather than in the admin's browser, so a form
 * submission at 3am can still deliver its ticket.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Mailer {

    const TEMPLATES_OPTION  = 'snn_tickets_email_templates';
    const FROM_NAME_OPTION  = 'snn_tickets_from_name';
    const FROM_EMAIL_OPTION = 'snn_tickets_from_email';
    const BATCH_SIZE_OPTION = 'snn_tickets_mailer_batch_size';
    const CRON_HOOK         = 'snn_tickets_process_queue';
    const QR_CID            = 'snn-ticket-qr';
    const MAX_ATTEMPTS      = 3;

    /** State for the message currently being handed to PHPMailer. */
    private static $inline_qr_path = null;
    private static $attachments    = [];
    private static $alt_body       = '';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
        add_action('phpmailer_init', [__CLASS__, 'phpmailer_init']);
        add_action('wp_ajax_snn_email_preview', [__CLASS__, 'ajax_preview']);
        add_action('wp_ajax_snn_email_test',    [__CLASS__, 'ajax_test']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'snn_minute', self::CRON_HOOK);
        }
    }

    public static function cron_schedule($schedules) {
        if (!isset($schedules['snn_minute'])) {
            $schedules['snn_minute'] = [
                'interval' => 60,
                'display'  => 'Every minute (SNN Tickets)',
            ];
        }
        return $schedules;
    }

    public static function deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    /* ------------------------------------------------------------------
     * Templates
     * ---------------------------------------------------------------- */

    public static function roles() {
        return [
            'ticket'       => __('Ticket + QR code (sent once approved)', 'snn-tickets'),
            'confirmation' => __('Submission received (sent immediately)', 'snn-tickets'),
            'rejection'    => __('Rejected', 'snn-tickets'),
        ];
    }

    public static function get_templates() {
        $templates = get_option(self::TEMPLATES_OPTION, []);
        return is_array($templates) ? $templates : [];
    }

    public static function get_template($name) {
        $templates = self::get_templates();
        return $templates[$name] ?? null;
    }

    public static function templates_for_role($role) {
        $out = [];
        foreach (self::get_templates() as $name => $tpl) {
            if (($tpl['role'] ?? 'ticket') === $role) $out[$name] = $tpl;
        }
        return $out;
    }

    public static function default_template($role) {
        switch ($role) {
            case 'confirmation':
                return [
                    'subject' => __('We received your registration', 'snn-tickets'),
                    'body'    => '<h2 style="margin:0 0 12px;">' . __('Thanks, {name}!', 'snn-tickets') . "</h2>\n"
                        . '<p>' . __('We have your registration for <strong>{event}</strong> and will review it shortly.', 'snn-tickets') . "</p>\n"
                        . '<p>' . __('You will get another email with your ticket once you are confirmed.', 'snn-tickets') . "</p>\n"
                        . '<p>— {site}</p>',
                ];

            case 'rejection':
                return [
                    'subject' => __('About your registration', 'snn-tickets'),
                    'body'    => '<p>' . __('Hi {name},', 'snn-tickets') . "</p>\n"
                        . '<p>' . __('Thanks for your interest in <strong>{event}</strong>. Unfortunately we are not able to confirm a place for you this time.', 'snn-tickets') . "</p>\n"
                        . '<p>— {site}</p>',
                ];

            case 'ticket':
            default:
                return [
                    'subject' => __('Your ticket for {event}', 'snn-tickets'),
                    'body'    => '<h2 style="margin:0 0 12px;">' . __("You're in, {name}!", 'snn-tickets') . "</h2>\n"
                        . '<p>' . __('Here is your ticket. Show the QR code at the entrance — on your phone or printed.', 'snn-tickets') . "</p>\n"
                        . "{ticket_card}\n"
                        . "{wallet_buttons}\n"
                        . '<p>' . __('See you there!', 'snn-tickets') . "<br>— {site}</p>",
                ];
        }
    }

    /**
     * Tags an editor can insert, with a short description each.
     */
    public static function tags() {
        return [
            '{name}'           => __('Ticket holder name', 'snn-tickets'),
            '{email}'          => __('Their address', 'snn-tickets'),
            '{event}'          => __('Event name', 'snn-tickets'),
            '{event_date}'     => __('Event date', 'snn-tickets'),
            '{event_time}'     => __('Start – end time', 'snn-tickets'),
            '{venue}'          => __('Venue', 'snn-tickets'),
            '{address}'        => __('Address', 'snn-tickets'),
            '{ticket}'         => __('Ticket code', 'snn-tickets'),
            '{ticket_card}'    => __('The designed ticket with QR', 'snn-tickets'),
            '{qr_block}'       => __('Just the QR image', 'snn-tickets'),
            '{wallet_buttons}' => __('Wallet / PDF / calendar buttons', 'snn-tickets'),
            '{ticket_url}'     => __('Link to the ticket page', 'snn-tickets'),
            '{pdf_url}'        => __('PDF download link', 'snn-tickets'),
            '{ics_url}'        => __('Calendar file link', 'snn-tickets'),
            '{pkpass_url}'     => __('Apple Wallet link', 'snn-tickets'),
            '{gwallet_url}'    => __('Google Wallet link', 'snn-tickets'),
            '{form}'           => __('Form name', 'snn-tickets'),
            '{site}'           => __('Site name', 'snn-tickets'),
            '{date}'           => __("Today's date", 'snn-tickets'),
            '{field:key}'      => __('Any form field', 'snn-tickets'),
        ];
    }

    /* ------------------------------------------------------------------
     * Placeholders
     * ---------------------------------------------------------------- */

    /**
     * Build the replacement map for a ticket and/or submission.
     *
     * @param array  $args
     * @param string $mode 'cid' to embed the QR as an attachment (real
     *                     mail) or 'preview' to inline it as a data URI
     */
    public static function build_vars($args = [], $mode = 'cid') {
        $defaults = [
            'name'        => '',
            'email'       => '',
            'ticket_code' => '',
            'list_name'   => '',
            'list_id'     => 0,
            'ticket_id'   => 0,
            'form_name'   => '',
            'fields'      => [],
            'ticket_data' => null,
        ];
        $a = array_merge($defaults, $args);

        // Work out the event: explicit list, else the ticket's own list.
        $list_id = (int)$a['list_id'];
        if (!$list_id && $a['ticket_id'] && class_exists('SNN_T_Tickets')) {
            $tk = SNN_T_Tickets::get((int)$a['ticket_id']);
            if ($tk) $list_id = (int)$tk->list_id;
        }
        $event = ($list_id && class_exists('SNN_T_Events') && function_exists('get_option')) ? self::event($list_id) : null;
        if ($event && $a['list_name'] === '') $a['list_name'] = $event->name;

        $vars = [
            '{name}'       => $a['name'] !== '' ? $a['name'] : __('Guest', 'snn-tickets'),
            '{email}'      => $a['email'],
            '{ticket}'     => $a['ticket_code'],
            '{list}'       => $a['list_name'],
            '{event}'      => $a['list_name'],
            '{form}'       => $a['form_name'],
            '{site}'       => get_bloginfo('name'),
            '{site_url}'   => home_url('/'),
            '{date}'       => date_i18n(get_option('date_format')),
            '{event_date}' => $event ? SNN_T_Events::format_date($event) : '',
            '{event_time}' => $event ? SNN_T_Events::format_time($event) : '',
            '{venue}'      => $event ? $event->venue : '',
            '{address}'    => $event ? $event->address : '',
        ];

        foreach (['{qr}', '{qr_inline}', '{scan_url}', '{qr_block}', '{ticket_card}', '{wallet_buttons}',
                  '{ticket_url}', '{pdf_url}', '{ics_url}', '{pkpass_url}', '{gwallet_url}'] as $k) {
            $vars[$k] = '';
        }

        if ($a['ticket_code'] !== '') {
            $qr_src = $mode === 'preview' ? SNN_T_QR::data_uri($a['ticket_code'], 6, 2) : 'cid:' . self::QR_CID;

            $url = SNN_T_QR::ensure_url($a['ticket_code']);
            $vars['{qr}']        = is_wp_error($url) ? '' : $url;
            $vars['{qr_inline}'] = $qr_src;
            $vars['{scan_url}']  = SNN_T_QR::scan_url($a['ticket_code']);
            $vars['{qr_block}']  = '<p style="text-align:center;margin:18px 0;"><img src="' . esc_attr($qr_src) . '" width="220" height="220" alt="'
                                 . esc_attr__('Your ticket QR code', 'snn-tickets') . '" style="display:inline-block;width:220px;height:220px;border:0;"></p>';

            if (class_exists('SNN_T_Events')) {
                $t = $a['ticket_data'] ?: SNN_T_Events::build_ticket_data([
                    'code'  => $a['ticket_code'],
                    'name'  => $a['name'],
                    'email' => $a['email'],
                ], $event);

                $vars['{ticket_card}'] = SNN_T_Design::ticket_card_html($t, $qr_src);

                $links = SNN_T_Files::links($t);
                $vars['{ticket_url}'] = SNN_T_Files::url('view', $a['ticket_code']);
                foreach ($links as $l) $vars['{' . ($l['key'] === 'gwallet' ? 'gwallet' : $l['key']) . '_url}'] = $l['url'];
                $vars['{wallet_buttons}'] = !empty($t['design']['wallet_links']) ? SNN_T_Design::buttons_html($links, $t['design']) : '';
            }
        }

        foreach ((array)$a['fields'] as $key => $value) {
            $vars['{field:' . $key . '}'] = is_array($value) ? implode(', ', $value) : (string)$value;
        }

        return $vars;
    }

    private static function event($list_id) {
        static $cache = [];
        if (!array_key_exists($list_id, $cache)) $cache[$list_id] = SNN_T_Events::get($list_id);
        return $cache[$list_id];
    }

    public static function render($text, $vars) {
        return strtr((string)$text, $vars);
    }

    /**
     * Pick subject/body: per-form override, then a saved template, then the
     * built-in default.
     */
    public static function resolve_template($role, $template_name, $override = null) {
        $tpl = $template_name ? self::get_template($template_name) : null;
        if (!$tpl) $tpl = self::default_template($role);

        if (is_array($override)) {
            $subject = trim((string)($override['subject'] ?? ''));
            $body    = trim((string)($override['body'] ?? ''));
            if ($subject !== '') $tpl['subject'] = $subject;
            if ($body !== '')    $tpl['body']    = $body;
        }
        return $tpl;
    }

    /**
     * Render a full, designed message.
     *
     * @return array ['subject' =>, 'html' =>, 'attachments' => csv]
     */
    public static function compose($role, $tpl, $args, $mode = 'cid') {
        $vars    = self::build_vars($args, $mode);
        $subject = self::render($tpl['subject'] ?? '', $vars);
        // The visual editor wraps block tags in <p>; a table inside a
        // paragraph breaks in several mail clients.
        $body    = preg_replace('#<p[^>]*>\s*(\{(?:ticket_card|wallet_buttons|qr_block)\})\s*</p>#i', '$1', (string)($tpl['body'] ?? ''));
        $inner   = self::render($body, $vars);

        $list_id = (int)($args['list_id'] ?? 0);
        if (!$list_id && !empty($args['ticket_id'])) {
            $tk = SNN_T_Tickets::get((int)$args['ticket_id']);
            if ($tk) $list_id = (int)$tk->list_id;
        }
        $event  = $list_id ? self::event($list_id) : null;
        $design = SNN_T_Design::for_list($event);

        $html = self::is_full_document($inner)
            ? $inner
            : SNN_T_Design::email_html($inner, $design, [
                'title'     => $subject,
                'preheader' => $role === 'ticket' ? $vars['{event}'] . ($vars['{event_date}'] !== '' ? ' · ' . $vars['{event_date}'] : '') : '',
            ]);

        $attachments = '';
        if ($role === 'ticket' && !empty($args['ticket_code']) && $event) {
            $attachments = implode(',', $event->attachment_list);
        }

        return ['subject' => $subject, 'html' => $html, 'attachments' => $attachments];
    }

    public static function is_full_document($html) {
        return (bool)preg_match('/^\s*(<!doctype|<html)/i', (string)$html);
    }

    /* ------------------------------------------------------------------
     * Queue
     * ---------------------------------------------------------------- */

    /**
     * Put one message on the queue. Nothing is sent here.
     *
     * @return int|WP_Error queue row id
     */
    public static function enqueue($args) {
        global $wpdb;

        $defaults = [
            'to_email'      => '',
            'to_name'       => '',
            'subject'       => '',
            'body'          => '',
            'role'          => 'ticket',
            'ticket_id'     => null,
            'submission_id' => null,
            'ticket_code'   => '',
            'attach_qr'     => false,
            'attachments'   => '',
            'delay'         => 0,
        ];
        $a = array_merge($defaults, $args);

        $email = sanitize_email($a['to_email']);
        if (!$email || !is_email($email)) {
            return new WP_Error('snn_queue_email', __('A valid recipient address is required.', 'snn-tickets'));
        }
        if ($a['subject'] === '' || $a['body'] === '') {
            return new WP_Error('snn_queue_content', __('Subject and body are required.', 'snn-tickets'));
        }

        $now = current_time('timestamp');

        $ok = $wpdb->insert(SNN_T_DB::queue(), [
            'ticket_id'     => $a['ticket_id'] ? (int)$a['ticket_id'] : null,
            'submission_id' => $a['submission_id'] ? (int)$a['submission_id'] : null,
            'role'          => sanitize_key($a['role']),
            'to_email'      => $email,
            'to_name'       => sanitize_text_field($a['to_name']),
            'subject'       => $a['subject'],
            'body'          => $a['body'],
            'attach_qr'     => $a['attach_qr'] ? 1 : 0,
            'attachments'   => implode(',', SNN_T_Events::parse_attachments($a['attachments'])),
            'ticket_code'   => sanitize_text_field($a['ticket_code']),
            'status'        => 'pending',
            'attempts'      => 0,
            'scheduled_at'  => date('Y-m-d H:i:s', $now + (int)$a['delay']),
            'created_at'    => date('Y-m-d H:i:s', $now),
        ], ['%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%d','%s','%s']);

        if (!$ok) {
            return new WP_Error('snn_queue_insert', __('Could not write to the mail queue.', 'snn-tickets'));
        }

        return (int)$wpdb->insert_id;
    }

    /**
     * Queue a message built from a per-form override, a stored template, or
     * the built-in default -- in that order of precedence.
     *
     * @param array|null $override ['subject' => ?, 'body' => ?] straight from
     *                             the form builder. Either half may be blank,
     *                             in which case the template supplies it.
     */
    public static function enqueue_from_template($role, $template_name, $args, $override = null) {
        $tpl = self::resolve_template($role, $template_name, $override);
        $msg = self::compose($role, $tpl, $args);

        return self::enqueue([
            'to_email'      => $args['email'] ?? '',
            'to_name'       => $args['name'] ?? '',
            'subject'       => $msg['subject'],
            'body'          => $msg['html'],
            'role'          => $role,
            'ticket_id'     => $args['ticket_id'] ?? null,
            'submission_id' => $args['submission_id'] ?? null,
            'ticket_code'   => $args['ticket_code'] ?? '',
            'attach_qr'     => strpos($msg['html'], 'cid:' . self::QR_CID) !== false,
            'attachments'   => $msg['attachments'],
        ]);
    }

    public static function batch_size() {
        $size = (int)get_option(self::BATCH_SIZE_OPTION, 10);
        return max(1, min(200, $size));
    }

    /**
     * Cron worker. Claims a batch, sends it, records the outcome.
     *
     * @return array counts
     */
    public static function process_queue() {
        global $wpdb;

        // One worker at a time, so overlapping cron runs cannot double-send.
        if (get_transient('snn_t_queue_lock')) {
            return ['sent' => 0, 'failed' => 0, 'locked' => true];
        }
        set_transient('snn_t_queue_lock', 1, 120);

        $queue = SNN_T_DB::queue();
        $limit = self::batch_size();
        $now   = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue}
             WHERE status = 'pending' AND scheduled_at <= %s AND attempts < %d
             ORDER BY id ASC LIMIT %d",
            $now, self::MAX_ATTEMPTS, $limit
        ));

        $sent = 0; $failed = 0;

        foreach ($rows as $row) {
            // Claim it. If another worker got there first, skip.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$queue} SET status = 'sending', attempts = attempts + 1
                 WHERE id = %d AND status = 'pending'",
                $row->id
            ));
            if (!$claimed) continue;

            $result = self::send_now($row);

            if ($result === true) {
                $wpdb->update($queue, [
                    'status'     => 'sent',
                    'sent_at'    => current_time('mysql'),
                    'last_error' => null,
                ], ['id' => $row->id], ['%s', '%s', '%s'], ['%d']);
                $sent++;
            } else {
                $attempts = (int)$row->attempts + 1;
                $wpdb->update($queue, [
                    'status'     => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'last_error' => is_string($result) ? $result : 'Unknown send error',
                ], ['id' => $row->id], ['%s', '%s'], ['%d']);
                $failed++;
            }
        }

        delete_transient('snn_t_queue_lock');

        return ['sent' => $sent, 'failed' => $failed, 'locked' => false];
    }

    /**
     * Hand one message to wp_mail(). $row is a queue row, or any object
     * with the same fields (test sends).
     *
     * @return true|string true, or an error message
     */
    public static function send_now($row) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $from_email = sanitize_email((string)get_option(self::FROM_EMAIL_OPTION, ''));
        $from_name  = sanitize_text_field((string)get_option(self::FROM_NAME_OPTION, ''));
        if ($from_email && is_email($from_email)) {
            $headers[] = 'From: ' . ($from_name ? sprintf('%s <%s>', $from_name, $from_email) : $from_email);
        }

        // Embed the QR as a CID attachment. Remote images are commonly
        // blocked, and a ticket whose QR does not render is useless.
        if (!empty($row->attach_qr) && $row->ticket_code !== '') {
            $path = SNN_T_QR::ensure($row->ticket_code);
            if (is_wp_error($path)) {
                return 'QR generation failed: ' . $path->get_error_message();
            }
            self::$inline_qr_path = $path;
        }

        // Ticket files: built fresh at send time so they carry the ticket's
        // current name, status and design.
        self::$attachments = [];
        $types = SNN_T_Events::parse_attachments($row->attachments ?? '');
        if ($types && $row->ticket_code !== '') {
            $ticket = SNN_T_Tickets::get_by_code($row->ticket_code);
            $t = $ticket ? SNN_T_Events::ticket_data($ticket) : ($row->ticket_data ?? null);
            if ($t) {
                foreach ($types as $type) {
                    if ($type === 'pkpass' && !SNN_T_Wallet::apple_ready()) continue;
                    if ($type === 'ics' && !$t['start']) continue;
                    $file = SNN_T_Files::build($type, $t);
                    if (is_wp_error($file)) {
                        self::reset_message();
                        return sprintf('%s attachment failed: %s', strtoupper($type), $file->get_error_message());
                    }
                    self::$attachments[] = $file;
                }
            }
        }

        $html = self::is_full_document($row->body)
            ? $row->body
            : '<!doctype html><html><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;'
              . 'font-size:15px;line-height:1.6;color:#1a1a1a;">' . $row->body . '</body></html>';

        self::$alt_body = self::html_to_text($html);

        $error = '';
        $capture = function ($wp_error) use (&$error) {
            $error = $wp_error->get_error_message();
        };
        add_action('wp_mail_failed', $capture);

        $sent = wp_mail($row->to_email, $row->subject, $html, $headers);

        remove_action('wp_mail_failed', $capture);
        self::reset_message();

        if ($sent) return true;
        return $error !== '' ? $error : 'wp_mail() returned false';
    }

    private static function reset_message() {
        self::$inline_qr_path = null;
        self::$attachments    = [];
        self::$alt_body       = '';
    }

    /**
     * Embed the QR, attach ticket files and set the plain-text part for the
     * message being sent.
     */
    public static function phpmailer_init($phpmailer) {
        try {
            if (self::$inline_qr_path && file_exists(self::$inline_qr_path)) {
                $phpmailer->addEmbeddedImage(self::$inline_qr_path, self::QR_CID, 'ticket-qr.png', 'base64', 'image/png');
            }
            foreach (self::$attachments as $f) {
                $mime = strtok($f['mime'], ';');
                $phpmailer->addStringAttachment($f['bytes'], $f['name'], 'base64', $mime);
            }
            if (self::$alt_body !== '') {
                $phpmailer->AltBody = self::$alt_body;
            }
        } catch (Throwable $e) {
            // A failed embed should not abort the send; the plain code is
            // still in the body.
        }
    }

    /** Readable plain-text version of an HTML email. */
    public static function html_to_text($html) {
        $html = preg_replace('#<(head|style|script)[^>]*>.*?</\1>#is', '', (string)$html);
        $html = preg_replace('#<div style="display:none[^"]*">.*?</div>#is', '', $html);
        $html = preg_replace_callback('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[2]));
            $url = html_entity_decode($m[1]);
            return $label !== '' && $label !== $url ? $label . ' (' . $url . ')' : $url;
        }, $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|h[1-6]|tr|li|table)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /* ------------------------------------------------------------------
     * Previews and test sends
     * ---------------------------------------------------------------- */

    /** Sample arguments for a role, tied to a list when one is chosen. */
    public static function sample_args($role, $list_id = 0) {
        $t = SNN_T_Events::sample_ticket_data($list_id);
        return [
            'name'        => $t['name'],
            'email'       => $t['email'],
            'ticket_code' => $role === 'ticket' ? $t['code'] : '',
            'list_name'   => $t['event'],
            'list_id'     => $list_id,
            'form_name'   => __('Registration', 'snn-tickets'),
            'fields'      => ['company' => 'Analytical Engines Ltd'],
            'ticket_data' => $t,
        ];
    }

    private static function request_template() {
        $role    = sanitize_key($_POST['role'] ?? 'ticket');
        if (!isset(self::roles()[$role])) $role = 'ticket';
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $body    = wp_kses_post(wp_unslash($_POST['body'] ?? ''));
        $tpl_name = sanitize_text_field(wp_unslash($_POST['template'] ?? ''));
        $list_id = (int)($_POST['list_id'] ?? 0);

        // Design screen previews pass a preset to try before saving.
        $preset = sanitize_key($_POST['preset'] ?? '');
        if ($preset !== '' && !empty($_POST['design'])) {
            $draft = SNN_T_Design::sanitize_settings(json_decode(wp_unslash($_POST['design']), true));
            add_filter('pre_option_' . SNN_T_Design::OPTION, function () use ($draft) { return $draft; });
        }

        $tpl = self::resolve_template($role, $tpl_name, ['subject' => $subject, 'body' => $body]);
        return [$role, $tpl, $list_id];
    }

    public static function ajax_preview() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('snn_email_tools', 'nonce');

        list($role, $tpl, $list_id) = self::request_template();
        $msg = self::compose($role, $tpl, self::sample_args($role, $list_id), 'preview');

        wp_send_json_success(['subject' => $msg['subject'], 'html' => $msg['html'], 'attachments' => $msg['attachments']]);
    }

    public static function ajax_test() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden'], 403);
        check_ajax_referer('snn_email_tools', 'nonce');

        $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
        if (!$to || !is_email($to)) {
            $to = wp_get_current_user()->user_email;
        }

        list($role, $tpl, $list_id) = self::request_template();
        $args = self::sample_args($role, $list_id);
        $msg  = self::compose($role, $tpl, $args);

        $result = self::send_now((object)[
            'to_email'    => $to,
            'subject'     => '[' . __('Test', 'snn-tickets') . '] ' . $msg['subject'],
            'body'        => $msg['html'],
            'attach_qr'   => strpos($msg['html'], 'cid:' . self::QR_CID) !== false,
            'ticket_code' => $args['ticket_code'],
            'attachments' => $msg['attachments'],
            'ticket_data' => $args['ticket_data'],
        ]);

        if ($result === true) {
            wp_send_json_success(['message' => sprintf(__('Test email sent to %s.', 'snn-tickets'), $to)]);
        }
        wp_send_json_error(['message' => sprintf(__('Sending failed: %s', 'snn-tickets'), $result)]);
    }

    /* ------------------------------------------------------------------
     * Queue helpers for the admin screens
     * ---------------------------------------------------------------- */

    public static function queue_counts() {
        global $wpdb;
        $queue = SNN_T_DB::queue();
        $rows  = $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$queue} GROUP BY status");
        $out   = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $out[$r->status] = (int)$r->n;
        }
        return $out;
    }

    public static function retry_failed($id = 0) {
        global $wpdb;
        $queue = SNN_T_DB::queue();
        $sql = "UPDATE {$queue} SET status = 'pending', attempts = 0, last_error = NULL WHERE status IN ('failed','sending')";
        if ($id) $sql = $wpdb->prepare($sql . ' AND id = %d', (int)$id);
        return (int)$wpdb->query($sql);
    }

    public static function delete_row($id) {
        global $wpdb;
        return (int)$wpdb->delete(SNN_T_DB::queue(), ['id' => (int)$id], ['%d']);
    }

    public static function clear_sent() {
        global $wpdb;
        $queue = SNN_T_DB::queue();
        return (int)$wpdb->query("DELETE FROM {$queue} WHERE status = 'sent'");
    }
}
