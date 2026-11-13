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

    /**
     * The codes a sign-up link gives access to.
     *
     * A plain link (?code=SP3K7Q9) gives that one code, as always.
     * A link from the buyer email (?code=A,B,C&placement_type=ticket,gift&orderid=123) gives
     * every normal (non-Gutschein) placement of that order, each with its own invites, so an
     * order with 2 tickets allows 2 × "Invites per code" sign-ups. One of the link's codes must
     * belong to the order, so an order ID cannot simply be guessed. Gutschein placements never
     * count here, they sign up with their own code link.
     *
     * @return string[]|null  null = no code in the link, [] = nothing usable
     */
    public static function pool($code_raw, $order_id = 0, $type_raw = '') {
        $codes = array_values(array_filter(array_map([__CLASS__, 'normalise'], explode(',', (string)$code_raw))));
        if (!$codes) return null;

        $types    = array_map('trim', explode(',', strtolower((string)$type_raw)));
        $order_id = (int)$order_id;
        if ($order_id <= 0 || !in_array('ticket', $types, true)) {
            return [$codes[0]];
        }
        if (!self::available() || !function_exists('spl_get_order_placements')) return [];

        $placements = spl_get_order_placements($order_id);
        $order_codes = array_map(function ($p) { return self::normalise($p->code); }, $placements);
        if (!array_intersect($codes, $order_codes)) return [];

        $pool = [];
        foreach ($placements as $p) {
            if (!empty($p->is_gift) || in_array((string)$p->status, self::CLOSED, true)) continue;
            $pool[] = self::normalise($p->code);
        }
        return $pool;
    }

    /** 'ok', 'missing', 'invalid' or 'used' for a pool from pool(). */
    public static function check_pool($pool, $invites) {
        if ($pool === null) return 'missing';
        if (!$pool) return 'invalid';
        if (count($pool) === 1) return self::check($pool[0], $invites);
        return self::left_pool($pool, $invites) > 0 ? 'ok' : 'used';
    }

    /** Invites left across every code of a pool. */
    public static function left_pool($pool, $invites) {
        $left = 0;
        foreach ((array)$pool as $code) $left += self::left($code, $invites);
        return $left;
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
