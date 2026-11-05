<?php
/**
 * Sell Placement codes as sign-up passes.
 *
 * A form can ask for the placement code from its link (?code=SP3K7Q9) when
 * the Sell Placement plugin is active. The code must exist there and must not
 * be cancelled or suspended. It gives a set number of invites (the form's
 * "Invites per code"). Each person signed up uses one, and the database refuses a
 * sign-up once every invite is taken.
 *
 * Without Sell Placement nothing can be checked, so a form that requires
 * codes lets nobody in. A deactivated plugin must not quietly open the form.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Placements {

    /** Sell Placement statuses that mean the code must not be honoured. */
    const CLOSED = ['cancelled', 'suspended'];

    public static function available() {
        return function_exists('spl_get_by_code');
    }

    /** Letters and digits in capitals, no more than the column holds. */
    public static function normalise($code) {
        return substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code)), 0, 32);
    }

    /**
     * @param int $invites How many sign-ups the code allows (the form's setting).
     * @return string 'ok', 'missing', 'invalid' or 'used' (no invites left)
     */
    public static function check($code, $invites) {
        $code = self::normalise($code);
        if ($code === '') return 'missing';
        if (!self::available()) return 'invalid';

        $placement = spl_get_by_code($code);
        if (!$placement || in_array((string)$placement->status, self::CLOSED, true)) return 'invalid';

        return self::left($code, $invites) > 0 ? 'ok' : 'used';
    }

    /**
     * The placement's page in Sell Placement, for the History panel. Sell Placement
     * checks its own permissions on that page. '' when there is no such placement.
     */
    public static function admin_url($code) {
        if (!self::available()) return '';
        $placement = spl_get_by_code(self::normalise($code));
        if (!$placement) return '';
        return admin_url('admin.php?page=spl-approvals&placement=' . (int)$placement->id);
    }

    /** Invites the code still has. */
    public static function left($code, $invites) {
        return max(0, (int)$invites - self::taken($code));
    }

    /** Invites held by sign-ups so far. A declined sign-up holds none. */
    public static function taken($code) {
        global $wpdb;
        $code = self::normalise($code);
        if ($code === '') return 0;
        $table = SNN_T_DB::submissions();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE placement_code = %s AND placement_slot IS NOT NULL", $code));
    }

    /**
     * The sign-ups that hold an invite for this code, oldest first, with the name and address
     * each was signed up with.
     */
    public static function held($code) {
        global $wpdb;
        $code = self::normalise($code);
        if ($code === '') return [];
        $table = SNN_T_DB::submissions();
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT name, email FROM {$table} WHERE placement_code = %s AND placement_slot IS NOT NULL ORDER BY id ASC", $code));
        return array_values((array)$rows);
    }

    /** Is invite $slot already held by a sign-up with this code? */
    public static function slot_taken($code, $slot) {
        global $wpdb;
        $code = self::normalise($code);
        if ($code === '') return false;
        $table = SNN_T_DB::submissions();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE placement_code = %s AND placement_slot = %d LIMIT 1", $code, (int)$slot));
    }
}
