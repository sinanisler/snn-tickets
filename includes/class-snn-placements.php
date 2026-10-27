<?php
/**
 * Sell Placement codes as sign-up passes.
 *
 * A form can ask for the placement code from its link (?code=SP3K7Q9) when
 * the Sell Placement plugin is active. The code must exist there, must not be
 * cancelled or suspended, and works for one sign-up only: the sign-up keeps
 * the code, and the database refuses a second sign-up with it.
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
     * @return string 'ok', 'missing', 'invalid' or 'used'
     */
    public static function check($code) {
        $code = self::normalise($code);
        if ($code === '') return 'missing';
        if (!self::available()) return 'invalid';

        $placement = spl_get_by_code($code);
        if (!$placement || in_array((string)$placement->status, self::CLOSED, true)) return 'invalid';

        return self::used($code) ? 'used' : 'ok';
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

    /** Has a sign-up already been made with this code? */
    public static function used($code) {
        global $wpdb;
        $code = self::normalise($code);
        if ($code === '') return false;
        $table = SNN_T_DB::submissions();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE placement_code = %s LIMIT 1", $code));
    }
}
