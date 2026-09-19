<?php
/**
 * Ticket records: creation, lookup and validation.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Tickets {

    /** Max scan attempts per IP inside the rate-limit window. */
    const RATE_LIMIT_MAX    = 60;
    const RATE_LIMIT_WINDOW = 60; // seconds

    public static function generate_code($length = 8) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max   = strlen($chars) - 1;
        $code  = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }

    public static function unique_code($length = 8) {
        global $wpdb;
        $table = SNN_T_DB::tickets();
        do {
            $code   = self::generate_code($length);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE ticket_code = %s", $code));
        } while ($exists);
        return $code;
    }

    public static function create_list($name) {
        global $wpdb;
        $wpdb->insert(SNN_T_DB::lists(), [
            'name'       => $name,
            'created_at' => current_time('mysql'),
        ], ['%s', '%s']);
        return (int)$wpdb->insert_id;
    }

    /**
     * @return int new ticket id
     */
    public static function insert($list_id, $name, $email, $code = null, $submission_id = null) {
        global $wpdb;
        if (!$code) $code = self::unique_code(8);

        $wpdb->insert(SNN_T_DB::tickets(), [
            'list_id'        => (int)$list_id,
            'submission_id'  => $submission_id ? (int)$submission_id : null,
            'ticket_code'    => $code,
            'name'           => $name ?: '',
            'email'          => $email ?: '',
            'status'         => 'active',
            'validate_count' => 0,
            'last_validated' => null,
            'created_at'     => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        return (int)$wpdb->insert_id;
    }

    public static function get($id) {
        global $wpdb;
        $table = SNN_T_DB::tickets();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int)$id));
    }

    public static function get_by_code($code) {
        global $wpdb;
        $table = SNN_T_DB::tickets();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE ticket_code = %s", $code));
    }

    /**
     * Tickets already issued in a list for a given email address.
     */
    public static function count_for_email($list_id, $email) {
        global $wpdb;
        $table = SNN_T_DB::tickets();
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE list_id = %d AND email = %s",
            (int)$list_id, $email
        ));
    }

    public static function count_in_list($list_id) {
        global $wpdb;
        $table = SNN_T_DB::tickets();
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE list_id = %d", (int)$list_id
        ));
    }

    /* ------------------------------------------------------------------
     * Validation
     * ---------------------------------------------------------------- */

    /**
     * Look a ticket up and, when the caller is staff, check it in.
     *
     * Only staff scans count. A signed QR proves the ticket came from this
     * site, but anyone holding the email can open that link -- including
     * the attendee checking their own ticket -- so a signature alone must
     * never mark a ticket as used.
     *
     * @param string $code
     * @param string $sig         signature from the QR, may be empty
     * @param bool   $is_operator caller is door staff
     * @param int    $list_id     scanner limited to one event, 0 = any
     * @return array
     */
    public static function validate($code, $sig = '', $is_operator = false, $list_id = 0) {
        $code = trim((string)$code);
        if ($code === '') {
            return ['valid' => false, 'reason' => 'empty', 'message' => __('No ticket code supplied.', 'snn-tickets')];
        }

        $signed = SNN_T_QR::verify($code, $sig);

        $ticket = self::get_by_code($code);
        if (!$ticket && !$signed) {
            // Hand-typed codes are forgiving about case.
            $ticket = self::get_by_code(strtoupper($code));
        }
        if (!$ticket) {
            return ['valid' => false, 'reason' => 'not_found', 'message' => __('This ticket does not exist.', 'snn-tickets')];
        }

        global $wpdb;
        $lists     = SNN_T_DB::lists();
        $list_name = (string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$lists} WHERE id = %d", $ticket->list_id));

        if ($ticket->status === 'revoked') {
            return [
                'valid'       => false,
                'reason'      => 'revoked',
                'message'     => __('This ticket has been cancelled.', 'snn-tickets'),
                'ticket_code' => $ticket->ticket_code,
                'name'        => $ticket->name,
                'list_name'   => $list_name,
            ];
        }

        if ($list_id && (int)$ticket->list_id !== (int)$list_id) {
            return [
                'valid'       => false,
                'reason'      => 'other_list',
                'message'     => sprintf(__('This ticket is for %s.', 'snn-tickets'), $list_name),
                'ticket_code' => $ticket->ticket_code,
                'name'        => $ticket->name,
                'list_name'   => $list_name,
            ];
        }

        $already = ((int)$ticket->validate_count) > 0;
        $count   = (int)$ticket->validate_count;
        $last    = $ticket->last_validated;

        if ($is_operator) {
            // Atomic increment, so two phones at the same door cannot both
            // see a fresh ticket.
            $wpdb->query($wpdb->prepare(
                "UPDATE " . SNN_T_DB::tickets() . " SET validate_count = validate_count + 1, last_validated = %s WHERE id = %d",
                current_time('mysql'), (int)$ticket->id
            ));
            $count++;
        }

        return [
            'valid'                => true,
            'counted'              => (bool)$is_operator,
            'already_used'         => $already,
            'signed'               => $signed,
            'ticket_code'          => $ticket->ticket_code,
            'name'                 => $ticket->name,
            'email'                => $ticket->email,
            'list_name'            => $list_name,
            'validate_count'       => $count,
            'last_validated'       => $last,
            'last_validated_human' => $last ? self::human_time($last) : '',
            'message'              => $already
                ? sprintf(__('Valid, but already scanned %d time(s).', 'snn-tickets'), $count)
                : __('Welcome. This ticket is valid.', 'snn-tickets'),
        ];
    }

    public static function human_time($mysql) {
        $ts = strtotime($mysql);
        if (!$ts) return '';
        $now = current_time('timestamp');
        if (function_exists('human_time_diff') && $now - $ts < DAY_IN_SECONDS) {
            return sprintf(__('%s ago', 'snn-tickets'), human_time_diff($ts, $now));
        }
        return date_i18n(get_option('date_format', 'M j') . ' ' . get_option('time_format', 'H:i'), $ts);
    }

    /* ------------------------------------------------------------------
     * Admin actions on single tickets
     * ---------------------------------------------------------------- */

    public static function set_status($id, $status) {
        global $wpdb;
        if (!in_array($status, ['active', 'revoked'], true)) return false;
        return false !== $wpdb->update(SNN_T_DB::tickets(), ['status' => $status], ['id' => (int)$id], ['%s'], ['%d']);
    }

    public static function undo_checkin($id) {
        global $wpdb;
        return false !== $wpdb->update(SNN_T_DB::tickets(), ['validate_count' => 0, 'last_validated' => null], ['id' => (int)$id], ['%d', '%s'], ['%d']);
    }

    /**
     * Simple per-IP throttle for the public validation endpoint.
     *
     * @return bool true when the request is allowed through
     */
    public static function rate_limit_ok() {
        if (current_user_can('manage_options')) return true;

        $ip  = self::client_ip();
        $key = 'snn_t_rl_' . md5($ip);
        $hits = (int)get_transient($key);

        if ($hits >= self::RATE_LIMIT_MAX) {
            return false;
        }

        set_transient($key, $hits + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    public static function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return sanitize_text_field((string)$ip);
    }

    /**
     * Remove tickets and their cached QR images.
     */
    public static function delete_ticket($id) {
        $ticket = self::get($id);
        if (!$ticket) return false;

        SNN_T_QR::delete($ticket->ticket_code);

        global $wpdb;
        $wpdb->delete(SNN_T_DB::queue(), ['ticket_id' => (int)$id], ['%d']);
        return (bool)$wpdb->delete(SNN_T_DB::tickets(), ['id' => (int)$id], ['%d']);
    }
}
