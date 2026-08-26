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
     * Look a ticket up and, when the caller is trusted, count the scan.
     *
     * A scan only counts when the request carries a valid signature or comes
     * from a logged-in operator. Unsigned public lookups are read-only, so a
     * scraper cannot burn through the ticket list by guessing codes.
     *
     * @param string $code
     * @param string $sig       signature from the QR, may be empty
     * @param bool   $is_operator caller holds the scan capability
     * @return array
     */
    public static function validate($code, $sig = '', $is_operator = false) {
        $code = trim((string)$code);
        if ($code === '') {
            return ['valid' => false, 'message' => 'No ticket code supplied.'];
        }

        $signed  = SNN_T_QR::verify($code, $sig);
        $trusted = $signed || $is_operator;

        $ticket = self::get_by_code($code);
        if (!$ticket) {
            return ['valid' => false, 'reason' => 'not_found', 'message' => 'This ticket does not exist.'];
        }

        if ($ticket->status === 'revoked') {
            return [
                'valid'       => false,
                'reason'      => 'revoked',
                'message'     => 'This ticket has been revoked.',
                'ticket_code' => $ticket->ticket_code,
                'name'        => $ticket->name,
            ];
        }

        global $wpdb;
        $lists     = SNN_T_DB::lists();
        $list_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$lists} WHERE id = %d", $ticket->list_id));

        $already = ((int)$ticket->validate_count) > 0;
        $count   = (int)$ticket->validate_count;

        if ($trusted) {
            $wpdb->update(SNN_T_DB::tickets(), [
                'validate_count' => $count + 1,
                'last_validated' => current_time('mysql'),
            ], ['id' => $ticket->id], ['%d', '%s'], ['%d']);
            $count++;
        }

        return [
            'valid'          => true,
            'counted'        => $trusted,
            'already_used'   => $already,
            'signed'         => $signed,
            'ticket_code'    => $ticket->ticket_code,
            'name'           => $ticket->name,
            'email'          => $ticket->email,
            'list_name'      => $list_name,
            'validate_count' => $count,
            'last_validated' => $ticket->last_validated,
            'message'        => $already
                ? 'Valid, but this ticket has already been scanned ' . $count . ' time(s).'
                : 'Welcome. This ticket is valid.',
        ];
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
