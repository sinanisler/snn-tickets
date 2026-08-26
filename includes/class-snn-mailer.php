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

    /** Path of the QR to embed in the message currently being sent. */
    private static $inline_qr_path = null;

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
        add_action('phpmailer_init', [__CLASS__, 'attach_inline_qr']);

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
            'ticket'       => 'Ticket + QR code (sent once approved)',
            'confirmation' => 'Submission received (sent immediately)',
            'rejection'    => 'Rejected',
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
        $site = get_bloginfo('name');

        switch ($role) {
            case 'confirmation':
                return [
                    'subject' => 'We received your registration',
                    'body'    => "<p>Hi {name},</p>\n\n"
                        . "<p>Thanks for registering for <strong>{list}</strong>. "
                        . "We have your details and will review them shortly.</p>\n\n"
                        . "<p>You will get another email with your ticket once you are confirmed.</p>\n\n"
                        . "<p>— {site}</p>",
                ];

            case 'rejection':
                return [
                    'subject' => 'About your registration',
                    'body'    => "<p>Hi {name},</p>\n\n"
                        . "<p>Thanks for your interest in <strong>{list}</strong>. "
                        . "Unfortunately we are not able to confirm a place for you this time.</p>\n\n"
                        . "<p>— {site}</p>",
                ];

            case 'ticket':
            default:
                return [
                    'subject' => 'Your ticket for {list}',
                    'body'    => "<p>Hi {name},</p>\n\n"
                        . "<p>You are confirmed for <strong>{list}</strong>. "
                        . "Show the QR code below at the door.</p>\n\n"
                        . "<p style=\"text-align:center;\"><img alt=\"Your ticket QR code\" src=\"{qr_inline}\" width=\"240\" height=\"240\" style=\"display:block;margin:0 auto;\"></p>\n\n"
                        . "<p style=\"text-align:center;\">Ticket code: <strong>{ticket}</strong></p>\n\n"
                        . "<p>See you there!</p>\n\n"
                        . "<p>— {site}</p>",
                ];
        }
    }

    /* ------------------------------------------------------------------
     * Placeholders
     * ---------------------------------------------------------------- */

    /**
     * Build the replacement map for a ticket and/or submission.
     */
    public static function build_vars($args = []) {
        $defaults = [
            'name'        => '',
            'email'       => '',
            'ticket_code' => '',
            'list_name'   => '',
            'form_name'   => '',
            'fields'      => [],
        ];
        $a = array_merge($defaults, $args);

        $vars = [
            '{name}'      => $a['name'] !== '' ? $a['name'] : 'Guest',
            '{email}'     => $a['email'],
            '{ticket}'    => $a['ticket_code'],
            '{list}'      => $a['list_name'],
            '{form}'      => $a['form_name'],
            '{site}'      => get_bloginfo('name'),
            '{site_url}'  => home_url('/'),
            '{date}'      => date_i18n(get_option('date_format')),
        ];

        if ($a['ticket_code'] !== '') {
            $url = SNN_T_QR::ensure_url($a['ticket_code']);
            $vars['{qr}']        = is_wp_error($url) ? '' : $url;
            $vars['{qr_inline}'] = 'cid:' . self::QR_CID;
            $vars['{scan_url}']  = SNN_T_QR::scan_url($a['ticket_code']);
        } else {
            $vars['{qr}']        = '';
            $vars['{qr_inline}'] = '';
            $vars['{scan_url}']  = '';
        }

        foreach ((array)$a['fields'] as $key => $value) {
            $vars['{field:' . $key . '}'] = is_array($value) ? implode(', ', $value) : (string)$value;
        }

        return $vars;
    }

    public static function render($text, $vars) {
        return strtr((string)$text, $vars);
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
            'delay'         => 0,
        ];
        $a = array_merge($defaults, $args);

        $email = sanitize_email($a['to_email']);
        if (!$email || !is_email($email)) {
            return new WP_Error('snn_queue_email', 'A valid recipient address is required.');
        }
        if ($a['subject'] === '' || $a['body'] === '') {
            return new WP_Error('snn_queue_content', 'Subject and body are required.');
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
            'ticket_code'   => sanitize_text_field($a['ticket_code']),
            'status'        => 'pending',
            'attempts'      => 0,
            'scheduled_at'  => date('Y-m-d H:i:s', $now + (int)$a['delay']),
            'created_at'    => date('Y-m-d H:i:s', $now),
        ], ['%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%d','%s','%s']);

        if (!$ok) {
            return new WP_Error('snn_queue_insert', 'Could not write to the mail queue.');
        }

        return (int)$wpdb->insert_id;
    }

    /**
     * Queue a message built from a stored template (or its built-in default).
     */
    public static function enqueue_from_template($role, $template_name, $args) {
        $tpl = $template_name ? self::get_template($template_name) : null;
        if (!$tpl) {
            $tpl = self::default_template($role);
        }

        $vars = self::build_vars($args);
        $body = self::render($tpl['body'] ?? '', $vars);

        return self::enqueue([
            'to_email'      => $args['email'] ?? '',
            'to_name'       => $args['name'] ?? '',
            'subject'       => self::render($tpl['subject'] ?? '', $vars),
            'body'          => $body,
            'role'          => $role,
            'ticket_id'     => $args['ticket_id'] ?? null,
            'submission_id' => $args['submission_id'] ?? null,
            'ticket_code'   => $args['ticket_code'] ?? '',
            'attach_qr'     => strpos($body, 'cid:' . self::QR_CID) !== false,
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
     * Actually hand one row to wp_mail().
     *
     * @return true|string true, or an error message
     */
    private static function send_now($row) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $from_email = sanitize_email((string)get_option(self::FROM_EMAIL_OPTION, ''));
        $from_name  = sanitize_text_field((string)get_option(self::FROM_NAME_OPTION, ''));
        if ($from_email && is_email($from_email)) {
            $headers[] = 'From: ' . ($from_name ? sprintf('%s <%s>', $from_name, $from_email) : $from_email);
        }

        // Embed the QR as a CID attachment. Remote images are commonly
        // blocked, and a ticket whose QR does not render is useless.
        if ($row->attach_qr && $row->ticket_code !== '') {
            $path = SNN_T_QR::ensure($row->ticket_code);
            if (is_wp_error($path)) {
                return 'QR generation failed: ' . $path->get_error_message();
            }
            self::$inline_qr_path = $path;
        }

        $html = '<!doctype html><html><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;'
              . 'font-size:15px;line-height:1.6;color:#1a1a1a;">'
              . $row->body
              . '</body></html>';

        $error = '';
        $capture = function ($wp_error) use (&$error) {
            $error = $wp_error->get_error_message();
        };
        add_action('wp_mail_failed', $capture);

        $sent = wp_mail($row->to_email, $row->subject, $html, $headers);

        remove_action('wp_mail_failed', $capture);
        self::$inline_qr_path = null;

        if ($sent) return true;
        return $error !== '' ? $error : 'wp_mail() returned false';
    }

    /**
     * Hook into PHPMailer to embed the QR for the message being sent.
     */
    public static function attach_inline_qr($phpmailer) {
        if (!self::$inline_qr_path || !file_exists(self::$inline_qr_path)) return;
        try {
            $phpmailer->addEmbeddedImage(
                self::$inline_qr_path,
                self::QR_CID,
                'ticket-qr.png',
                'base64',
                'image/png'
            );
        } catch (Throwable $e) {
            // A failed embed should not abort the send; the plain code is
            // still in the body.
        }
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

    public static function retry_failed() {
        global $wpdb;
        $queue = SNN_T_DB::queue();
        return (int)$wpdb->query(
            "UPDATE {$queue} SET status = 'pending', attempts = 0, last_error = NULL WHERE status IN ('failed','sending')"
        );
    }

    public static function clear_sent() {
        global $wpdb;
        $queue = SNN_T_DB::queue();
        return (int)$wpdb->query("DELETE FROM {$queue} WHERE status = 'sent'");
    }
}
