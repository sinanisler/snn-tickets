<?php
/**
 * WooCommerce: selling tickets in the shop.
 *
 * Any simple or variable product can be a ticket: the "Ticket" box next to
 * Virtual / Downloadable, and a Tickets tab that picks the event. A paid
 * order becomes tickets with source "order"; from there the emails, wallet
 * passes, People list and door work as they do for every other ticket.
 *
 *   in the cart       spots are checked against the event's limit
 *   order placed      its tickets hold spots while payment is pending
 *   paid              tickets are issued (processing / completed)
 *   refunded, etc.    tickets are cancelled, per item and per refunded unit
 *
 * Everything here only runs when WooCommerce is active.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Woo {

    /** Product meta. */
    const META_ON    = '_snn_ticket';     // 'yes' when the product issues tickets
    const META_EVENT = '_snn_event';      // event (list) id
    const META_PER   = '_snn_per_unit';   // tickets per unit bought
    const META_ASK   = '_snn_attendees';  // 'yes' to ask attendee details

    /** Order item meta. */
    const ITEM_ATTENDEES = '_snn_attendees';
    const ITEM_EVENT     = '_snn_event';
    const ITEM_PER       = '_snn_per_unit';

    /** Order meta: spots an unpaid order holds, per event. */
    const HOLD_PREFIX = '_snn_hold_';

    /** Attendee details checked in add-to-cart validation, used when the item is added. */
    private static $attendees = null;

    public static function boot() {
        add_action('before_woocommerce_init', [__CLASS__, 'declare_compat']);
        add_action('plugins_loaded', [__CLASS__, 'init'], 20);
    }

    public static function active() {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    public static function declare_compat() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SNN_TICKETS_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SNN_TICKETS_FILE, true);
        }
    }

    public static function init() {
        if (!self::active()) return;

        add_filter('snn_tickets_spots_taken', [__CLASS__, 'add_held'], 10, 2);
        add_filter('snn_tickets_event_shop', [__CLASS__, 'event_shop_html'], 10, 2);
        add_action('snn_tickets_event_deleted', [__CLASS__, 'event_deleted']);
        if (is_admin()) SNN_T_Woo_Admin::init();

        // Product page
        add_action('woocommerce_single_product_summary', [__CLASS__, 'product_event_info'], 25);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'attendee_fields']);
        add_filter('woocommerce_product_supports', [__CLASS__, 'no_ajax_add'], 10, 3);
        add_filter('woocommerce_product_add_to_cart_url', [__CLASS__, 'loop_url'], 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', [__CLASS__, 'loop_text'], 10, 2);

        // Cart
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'cart_item_data'], 10, 4);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'show_item_data'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'lock_quantity'], 10, 3);
        add_filter('woocommerce_store_api_product_quantity_editable', [__CLASS__, 'block_quantity_editable'], 10, 3);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'check_cart']);

        // Checkout
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'order_line_item'], 10, 4);
        add_action('woocommerce_checkout_order_created', [__CLASS__, 'set_holds']);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'set_holds']);

        // Order lifecycle. The status hooks run before WooCommerce sends its
        // own emails, so the tickets are there to be listed in them.
        add_action('woocommerce_order_status_processing', [__CLASS__, 'sync_order'], 5);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'sync_order'], 5);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'sync_order'], 5);
        add_action('woocommerce_order_refunded', [__CLASS__, 'sync_order'], 5);
        add_action('woocommerce_trash_order', [__CLASS__, 'cancel_order']);
        add_action('woocommerce_before_delete_order', [__CLASS__, 'cancel_order']);
        add_action('woocommerce_untrash_order', [__CLASS__, 'sync_order']);

        // Showing the tickets
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'order_tickets_html']);
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_tickets'], 10, 4);
    }

    /* ------------------------------------------------------------------
     * Products
     * ---------------------------------------------------------------- */

    /** The product that carries the ticket settings: a variation's parent. */
    public static function base_product($product) {
        if (!$product instanceof WC_Product) $product = $product ? wc_get_product($product) : null;
        if ($product && $product->is_type('variation')) $product = wc_get_product($product->get_parent_id());
        return $product ?: null;
    }

    public static function is_ticket($product) {
        $p = self::base_product($product);
        return $p && $p->get_meta(self::META_ON) === 'yes' && (int)$p->get_meta(self::META_EVENT) > 0;
    }

    public static function event_id($product) {
        $p = self::base_product($product);
        return $p ? (int)$p->get_meta(self::META_EVENT) : 0;
    }

    public static function per_unit($product) {
        $p = self::base_product($product);
        return $p ? max(1, min(50, (int)$p->get_meta(self::META_PER))) : 1;
    }

    public static function asks($product) {
        $p = self::base_product($product);
        return $p && $p->get_meta(self::META_ASK) === 'yes';
    }

    /** Ticket products of an event, drafts included. */
    public static function products_for($list_id, $published_only = false) {
        return wc_get_products([
            'limit'      => -1,
            'status'     => $published_only ? ['publish'] : ['publish', 'draft', 'pending', 'private'],
            'type'       => ['simple', 'variable'],
            'orderby'    => 'menu_order',
            'order'      => 'ASC',
            'meta_query' => [
                ['key' => self::META_EVENT, 'value' => (int)$list_id],
                ['key' => self::META_ON, 'value' => 'yes'],
            ],
        ]);
    }

    /** The questions asked for each attendee: the event's sign-up form. */
    public static function attendee_form($list_id) {
        $form = SNN_T_Forms::for_list($list_id);
        return $form ?: (object)['id' => 0, 'fields' => SNN_T_Forms::default_fields()];
    }

    /* ------------------------------------------------------------------
     * Spots
     * ---------------------------------------------------------------- */

    /** Minutes an unpaid order holds its spots, as WooCommerce holds stock. */
    public static function hold_minutes() {
        $m = (int)get_option('woocommerce_hold_stock_minutes', 60);
        return $m > 0 ? $m : 60;
    }

    /**
     * Spots held by orders placed but not paid yet: pending orders inside
     * the hold window, and on-hold orders (bank transfer, cheque).
     *
     * @param int[] $except order ids not to count
     */
    public static function held($list_id, $except = []) {
        $key = self::HOLD_PREFIX . (int)$list_id;
        $ids = wc_get_orders([
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => ['pending', 'on-hold'],
            'meta_query' => [['key' => $key, 'compare' => 'EXISTS']],
        ]);
        $cutoff = time() - self::hold_minutes() * MINUTE_IN_SECONDS;
        $n = 0;
        foreach ((array)$ids as $id) {
            if (in_array((int)$id, $except, true)) continue;
            $order = wc_get_order($id);
            if (!$order) continue;
            $created = $order->get_date_created();
            if ($order->has_status('pending') && $created && $created->getTimestamp() < $cutoff) continue;
            $n += (int)$order->get_meta($key);
        }
        return $n;
    }

    public static function add_held($taken, $list_id) {
        return $taken + self::held($list_id);
    }

    /** Orders this visitor is paying for right now; their holds are their own. */
    private static function own_orders() {
        if (!function_exists('WC') || !WC()->session) return [];
        return array_values(array_filter([
            (int)WC()->session->get('order_awaiting_payment'),
            (int)WC()->session->get('store_api_draft_order'),
        ]));
    }

    /**
     * Spots still free for this visitor, or null when the event has no
     * limit.
     */
    public static function spots_left($list_id) {
        $max = SNN_T_Events::spot_limit($list_id);
        if ($max <= 0) return null;
        $own = self::own_orders();
        $mine = 0;
        foreach ($own as $oid) {
            $o = wc_get_order($oid);
            if ($o && $o->has_status(['pending', 'on-hold'])) $mine += (int)$o->get_meta(self::HOLD_PREFIX . (int)$list_id);
        }
        return max(0, $max - SNN_T_Events::spots_taken($list_id) + $mine);
    }

    /** Tickets for an event already in the cart, leaving out one cart line. */
    public static function in_cart($list_id, $except_key = '') {
        if (!function_exists('WC') || !WC()->cart) return 0;
        $n = 0;
        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($key === $except_key || empty($item['data']) || !self::is_ticket($item['data'])) continue;
            if (self::event_id($item['data']) !== (int)$list_id) continue;
            $n += (int)$item['quantity'] * self::per_unit($item['data']);
        }
        return $n;
    }

    /* ------------------------------------------------------------------
     * Product page
     * ---------------------------------------------------------------- */

    public static function product_event_info() {
        global $product;
        if (!$product || !self::is_ticket($product)) return;
        $event = SNN_T_Events::get(self::event_id($product));
        if (!$event) return;
        $when  = SNN_T_Events::format_when($event);
        $where = SNN_T_Events::format_where($event);
        $left  = self::spots_left($event->id);
        $form  = SNN_T_Forms::for_list($event->id);

        echo '<div class="snn-product-event">';
        if ($when !== '')  echo '<p class="snn-product-when"><strong>' . esc_html__('When', 'snn-tickets') . ':</strong> ' . esc_html($when) . '</p>';
        if ($where !== '') echo '<p class="snn-product-where"><strong>' . esc_html__('Where', 'snn-tickets') . ':</strong> ' . esc_html($where) . '</p>';
        if ($left === 0) {
            echo '<p class="snn-product-full" style="font-weight:600">' . esc_html($form ? $form->settings['full_message'] : __('Sorry, this event is fully booked.', 'snn-tickets')) . '</p>';
        } elseif ($left !== null && $form && !empty($form->settings['show_remaining'])) {
            echo '<p class="snn-product-left" style="font-weight:600">' . esc_html(sprintf(_n('%d spot left', '%d spots left', $left, 'snn-tickets'), $left)) . '</p>';
        }
        echo '</div>';
    }

    /**
     * One block of questions per ticket. The blocks follow the quantity box;
     * without JavaScript the first block (or blocks, for a ticket that
     * admits several people) is shown and the server checks the count.
     */
    public static function attendee_fields() {
        global $product;
        if (!$product || !self::is_ticket($product) || !self::asks($product)) return;
        $form = self::attendee_form(self::event_id($product));
        $per  = self::per_unit($product);
        $old  = isset($_POST['snn_att']) && is_array($_POST['snn_att']) ? wp_unslash($_POST['snn_att']) : [];
        $count = max($per, count($old));

        SNN_T_Forms::render_styles();
        echo '<div class="snn-attendees snn-ticket-form" data-snn-attendees data-per="' . (int)$per . '">';
        echo '<input type="hidden" name="snn_att_on" value="1">';
        echo '<p class="snn-attendees-intro">' . esc_html__('Who is coming? Each ticket is emailed to the person named on it.', 'snn-tickets') . '</p>';
        echo '<div data-snn-att-list>';
        for ($i = 0; $i < $count; $i++) self::attendee_block($form, $i, $old[$i] ?? []);
        echo '</div><template data-snn-att-template>';
        self::attendee_block($form, '__i__', []);
        echo '</template></div>';
        echo '<style>.snn-attendees{margin:0 0 1.2em;max-width:none}.snn-attendee{border:1px solid rgba(0,0,0,.15);border-radius:8px;padding:14px 16px 2px;margin:0 0 12px}.snn-attendee legend{font-weight:700;padding:0 6px}.snn-attendees-intro{margin:0 0 10px}</style>';

        SNN_T_Forms::footer_script('snn-tickets-attendees', <<<'JS'
(function(){
    document.querySelectorAll('[data-snn-attendees]').forEach(function(box){
        var form = box.closest('form'); if (!form) return;
        var list = box.querySelector('[data-snn-att-list]');
        var tpl = box.querySelector('[data-snn-att-template]');
        var per = parseInt(box.getAttribute('data-per'), 10) || 1;
        function qty(){ var q = form.querySelector('input.qty, input[name=quantity]'); var n = q ? parseInt(q.value, 10) : 1; return isNaN(n) || n < 1 ? 1 : n; }
        function sync(){
            var want = qty() * per;
            while (list.children.length < want) {
                var i = list.children.length;
                var wrap = document.createElement('div');
                wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, i).replace(/__n__/g, i + 1);
                list.appendChild(wrap.firstElementChild);
            }
            while (list.children.length > want) list.removeChild(list.lastElementChild);
        }
        form.addEventListener('input', function(e){ if (e.target.matches('input.qty, input[name=quantity]')) sync(); });
        form.addEventListener('change', function(e){ if (e.target.matches('input.qty, input[name=quantity]')) sync(); });
        sync();
    });
})();
JS
        );
    }

    private static function attendee_block($form, $i, $old) {
        $n = $i === '__i__' ? '__n__' : (string)((int)$i + 1);
        echo '<fieldset class="snn-attendee"><legend>' . esc_html(sprintf(__('Ticket %s', 'snn-tickets'), $n)) . '</legend>';
        foreach ($form->fields as $field) {
            SNN_T_Forms::render_field($field, $old[$field['key']] ?? null, '', 'snn_att[' . $i . ']');
        }
        echo '</fieldset>';
    }

    /** Products that ask for attendees are bought from their own page. */
    public static function no_ajax_add($supports, $feature, $product) {
        if ($feature === 'ajax_add_to_cart' && self::is_ticket($product) && self::asks($product)) return false;
        return $supports;
    }

    public static function loop_url($url, $product) {
        return (self::is_ticket($product) && self::asks($product) && $product->is_type('simple')) ? $product->get_permalink() : $url;
    }

    public static function loop_text($text, $product) {
        return (self::is_ticket($product) && self::asks($product) && $product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock())
            ? __('Choose tickets', 'snn-tickets') : $text;
    }

    /* ------------------------------------------------------------------
     * Cart
     * ---------------------------------------------------------------- */

    /**
     * Read the attendee blocks posted from the product page.
     *
     * @return array [attendees, errors]
     */
    public static function read_attendees($form, $raw, $count) {
        $out = []; $errors = [];
        for ($i = 0; $i < $count; $i++) {
            $block = isset($raw[$i]) && is_array($raw[$i]) ? $raw[$i] : [];
            list($data, $errs, $name, $email) = SNN_T_Forms::collect($form, $block);
            foreach ($errs as $e) $errors[] = sprintf(__('Ticket %1$d: %2$s', 'snn-tickets'), $i + 1, $e);
            $out[] = ['name' => $name, 'email' => strtolower($email), 'data' => $data];
        }
        return [$out, $errors];
    }

    public static function validate_add($passed, $product_id, $qty, $variation_id = 0, $variations = []) {
        $product = wc_get_product($variation_id ?: $product_id);
        self::$attendees = null;
        if (!$passed || !$product || !self::is_ticket($product)) return $passed;

        $list_id = self::event_id($product);
        $event   = SNN_T_Events::get($list_id);
        if (!$event) {
            wc_add_notice(__('This event is no longer available.', 'snn-tickets'), 'error');
            return false;
        }

        $need = (int)$qty * self::per_unit($product);
        $left = self::spots_left($list_id);
        if ($left !== null && $need + self::in_cart($list_id) > $left) {
            wc_add_notice(self::left_message($event, max(0, $left - self::in_cart($list_id))), 'error');
            return false;
        }

        if (self::asks($product)) {
            if (empty($_POST['snn_att_on'])) {
                wc_add_notice(sprintf(__('Please add the attendee details for %s on its page.', 'snn-tickets'), $product->get_name()), 'error');
                return false;
            }
            $raw = isset($_POST['snn_att']) && is_array($_POST['snn_att']) ? wp_unslash($_POST['snn_att']) : [];
            list($attendees, $errors) = self::read_attendees(self::attendee_form($list_id), $raw, $need);
            if ($errors) {
                foreach (array_slice($errors, 0, 5) as $e) wc_add_notice($e, 'error');
                return false;
            }
            self::$attendees = $attendees;
        }
        return $passed;
    }

    private static function left_message($event, $left) {
        return $left > 0
            ? sprintf(_n('Only %1$d spot is left for %2$s.', 'Only %1$d spots are left for %2$s.', $left, 'snn-tickets'), $left, $event->name)
            : sprintf(__('%s is fully booked.', 'snn-tickets'), $event->name);
    }

    public static function cart_item_data($data, $product_id, $variation_id, $qty) {
        if (self::$attendees !== null) {
            $data['snn_attendees'] = self::$attendees;
            self::$attendees = null;
        }
        return $data;
    }

    public static function attendee_names($attendees) {
        return implode(', ', array_filter(array_map(function ($a) {
            return $a['name'] !== '' ? $a['name'] : $a['email'];
        }, (array)$attendees)));
    }

    public static function show_item_data($item_data, $cart_item) {
        if (!empty($cart_item['snn_attendees'])) {
            $item_data[] = ['key' => __('Attendees', 'snn-tickets'), 'value' => self::attendee_names($cart_item['snn_attendees'])];
        }
        return $item_data;
    }

    /** Named tickets cannot change quantity in the cart: the names would not match. */
    public static function lock_quantity($html, $key, $item = null) {
        if (!empty($item['snn_attendees'])) {
            return sprintf('%s <input type="hidden" name="cart[%s][qty]" value="%s" />', (int)$item['quantity'], esc_attr($key), (int)$item['quantity']);
        }
        return $html;
    }

    public static function block_quantity_editable($editable, $product, $cart_item) {
        return !empty($cart_item['snn_attendees']) ? false : $editable;
    }

    /** Runs for the cart page, the classic checkout and the block checkout. */
    public static function check_cart() {
        if (!WC()->cart) return;
        $events = [];
        foreach (WC()->cart->get_cart() as $item) {
            if (empty($item['data']) || !self::is_ticket($item['data'])) continue;
            $list_id = self::event_id($item['data']);
            $events[$list_id] = true;
            if (self::asks($item['data'])) {
                $need = (int)$item['quantity'] * self::per_unit($item['data']);
                if (count((array)($item['snn_attendees'] ?? [])) < $need) {
                    wc_add_notice(sprintf(__('%s needs a name for each ticket. Remove it and add it again from its page.', 'snn-tickets'), $item['data']->get_name()), 'error');
                }
            }
        }
        foreach (array_keys($events) as $list_id) {
            $event = SNN_T_Events::get($list_id);
            if (!$event) {
                wc_add_notice(__('An event in your cart is no longer available.', 'snn-tickets'), 'error');
                continue;
            }
            $left = self::spots_left($list_id);
            if ($left !== null && self::in_cart($list_id) > $left) {
                wc_add_notice(self::left_message($event, $left), 'error');
            }
        }
    }

    /* ------------------------------------------------------------------
     * Checkout
     * ---------------------------------------------------------------- */

    /** The line item remembers its event, so a later product change does not move tickets. */
    public static function order_line_item($item, $cart_item_key, $values, $order) {
        $product = $values['data'] ?? null;
        if (!$product || !self::is_ticket($product)) return;
        $item->add_meta_data(self::ITEM_EVENT, self::event_id($product), true);
        $item->add_meta_data(self::ITEM_PER, self::per_unit($product), true);
        if (!empty($values['snn_attendees'])) {
            $item->add_meta_data(self::ITEM_ATTENDEES, $values['snn_attendees'], true);
            $item->add_meta_data(__('Attendees', 'snn-tickets'), self::attendee_names($values['snn_attendees']), true);
        }
    }

    /** Event and tickets-per-unit of an order line, or [0, 0] when it is not a ticket. */
    public static function item_ticket_info($item) {
        $list_id = (int)$item->get_meta(self::ITEM_EVENT);
        $per     = (int)$item->get_meta(self::ITEM_PER);
        if (!$list_id) {
            // Orders made by hand in the admin skip the checkout hooks.
            $product = $item->get_product();
            if (!$product || !self::is_ticket($product)) return [0, 0];
            $list_id = self::event_id($product);
            $per     = self::per_unit($product);
        }
        return [$list_id, max(1, $per)];
    }

    public static function set_holds($order) {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order) return;
        $holds = [];
        foreach ($order->get_items() as $item) {
            list($list_id, $per) = self::item_ticket_info($item);
            if ($list_id) $holds[$list_id] = ($holds[$list_id] ?? 0) + (int)$item->get_quantity() * $per;
        }
        foreach ($order->get_meta_data() as $m) {
            if (strpos($m->key, self::HOLD_PREFIX) === 0) $order->delete_meta_data($m->key);
        }
        foreach ($holds as $list_id => $n) $order->update_meta_data(self::HOLD_PREFIX . $list_id, $n);
        $order->save_meta_data();
    }

    private static function clear_holds($order) {
        $had = false;
        foreach ($order->get_meta_data() as $m) {
            if (strpos($m->key, self::HOLD_PREFIX) === 0) { $order->delete_meta_data($m->key); $had = true; }
        }
        if ($had) $order->save_meta_data();
    }

    /* ------------------------------------------------------------------
     * Issuing and cancelling
     * ---------------------------------------------------------------- */

    /**
     * What to do so a line item has exactly $want active tickets.
     *
     * Cancelled tickets come back before new ones are made, so a code that
     * was already sent keeps working. Extra tickets are cancelled newest
     * first, and tickets already used at the door last.
     *
     * @param array $tickets [['id' =>, 'status' =>, 'vc' =>], ...] oldest first
     * @return array ['restore' => ids, 'revoke' => ids, 'create' => n]
     */
    public static function plan($tickets, $want) {
        $active  = array_values(array_filter($tickets, function ($t) { return $t['status'] === 'active'; }));
        $revoked = array_values(array_filter($tickets, function ($t) { return $t['status'] !== 'active'; }));
        $want    = max(0, (int)$want);
        $out     = ['restore' => [], 'revoke' => [], 'create' => 0];

        if (count($active) > $want) {
            usort($active, function ($a, $b) {
                $used = ((int)$a['vc'] > 0) <=> ((int)$b['vc'] > 0);
                return $used !== 0 ? $used : ((int)$b['id'] <=> (int)$a['id']);
            });
            $out['revoke'] = array_map('intval', array_column(array_slice($active, 0, count($active) - $want), 'id'));
            return $out;
        }

        $need = $want - count($active);
        $back = array_slice($revoked, 0, $need);
        $out['restore'] = array_map('intval', array_column($back, 'id'));
        $out['create']  = $need - count($back);
        return $out;
    }

    /** Tickets made for one order line, oldest first. */
    public static function item_tickets($item_id) {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . SNN_T_DB::tickets() . " WHERE order_item_id = %d ORDER BY id ASC", (int)$item_id));
    }

    public static function order_tickets($order_id) {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . SNN_T_DB::tickets() . " WHERE order_id = %d ORDER BY id ASC", (int)$order_id));
    }

    /**
     * Bring an order's tickets in line with its status and refunds. Safe to
     * call any number of times.
     */
    public static function sync_order($order_id) {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order || $order->get_type() !== 'shop_order') return;

        // Payment callbacks and the thank-you page can land at the same time.
        $lock = 'snn_tickets_order_lock_' . $order->get_id();
        if (!add_option($lock, time(), '', false)) {
            if ((int)get_option($lock) > time() - 60) return;
            update_option($lock, time(), false);
        }

        try {
            $paid = $order->has_status(wc_get_is_paid_statuses());
            foreach ($order->get_items() as $item_id => $item) {
                list($list_id, $per) = self::item_ticket_info($item);
                if (!$list_id) continue;
                $units = $paid ? max(0, (int)$item->get_quantity() + (int)$order->get_qty_refunded_for_item($item_id)) : 0;
                self::sync_item($order, $item, $list_id, $units * $per);
            }
            if (!$order->has_status(['pending', 'on-hold', 'checkout-draft'])) self::clear_holds($order);
        } finally {
            delete_option($lock);
        }
    }

    private static function sync_item($order, $item, $list_id, $want) {
        $tickets = self::item_tickets($item->get_id());
        $plan = self::plan(array_map(function ($t) {
            return ['id' => (int)$t->id, 'status' => $t->status, 'vc' => (int)$t->validate_count];
        }, $tickets), $want);

        foreach ($plan['revoke'] as $id) SNN_T_Tickets::set_status($id, 'revoked');
        foreach ($plan['restore'] as $id) SNN_T_Tickets::set_status($id, 'active');
        if (!$plan['create'] || !SNN_T_Events::get($list_id)) return;

        $attendees = (array)$item->get_meta(self::ITEM_ATTENDEES);
        $buyer = [
            'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email' => strtolower((string)$order->get_billing_email()),
        ];
        $form = SNN_T_Forms::for_list($list_id);
        $send = SNN_T_Mailer::event_template($list_id, 'ticket')['on'];
        $n = count($tickets);

        for ($i = 0; $i < $plan['create']; $i++) {
            $a = $attendees[$n + $i] ?? null;
            $name  = is_array($a) && ($a['name'] ?? '') !== '' ? $a['name'] : $buyer['name'];
            $email = is_array($a) && ($a['email'] ?? '') !== '' ? $a['email'] : $buyer['email'];
            $sid = 0;
            if (is_array($a) && $form && !empty($a['data'])) {
                // Answers live on a sign-up record, as they do for the form.
                $sid = SNN_T_Submissions::create([
                    'form_id' => (int)$form->id, 'status' => 'approved', 'name' => $name, 'email' => $email,
                    'data' => $a['data'], 'ip' => $order->get_customer_ip_address(),
                ]);
            }
            $ticket_id = SNN_T_Tickets::insert($list_id, $name, $email, null, $sid ?: null, 'order', [
                'order_id'      => $order->get_id(),
                'order_item_id' => $item->get_id(),
                'product_id'    => $item->get_product_id(),
            ]);
            if (!$ticket_id) continue;
            if ($sid) {
                global $wpdb;
                $wpdb->update(SNN_T_DB::submissions(), [
                    'ticket_id' => $ticket_id, 'decided_at' => current_time('mysql'),
                    'decision_reason' => sprintf(__('Paid, order #%s', 'snn-tickets'), $order->get_order_number()),
                ], ['id' => $sid]);
            }
            if ($send && $email !== '') SNN_T_Mailer::queue_ticket(SNN_T_Tickets::get($ticket_id));
        }

        $order->add_order_note(sprintf(_n('%1$d ticket issued for %2$s.', '%1$d tickets issued for %2$s.', $plan['create'], 'snn-tickets'),
            $plan['create'], SNN_T_Events::get($list_id)->name));
    }

    /** A trashed or deleted order's tickets stop working at the door. */
    public static function cancel_order($order_id) {
        foreach (self::order_tickets($order_id) as $t) {
            if ($t->status === 'active') SNN_T_Tickets::set_status((int)$t->id, 'revoked');
        }
    }

    /** Products of a deleted event stop selling. */
    public static function event_deleted($list_id) {
        foreach (self::products_for($list_id) as $p) {
            $p->set_status('draft');
            $p->update_meta_data(self::META_ON, 'no');
            $p->save();
        }
    }

    /* ------------------------------------------------------------------
     * Showing tickets to the buyer
     * ---------------------------------------------------------------- */

    /** The order's active tickets, grouped for display. */
    private static function display_tickets($order) {
        return array_values(array_filter(self::order_tickets($order->get_id()), function ($t) { return $t->status === 'active'; }));
    }

    /** Thank-you page and My Account → Orders. */
    public static function order_tickets_html($order) {
        $tickets = self::display_tickets($order);
        if (!$tickets) return;
        echo '<section class="snn-order-tickets woocommerce-order-tickets"><h2 class="woocommerce-column__title">' . esc_html__('Your tickets', 'snn-tickets') . '</h2>';
        echo '<table class="woocommerce-table shop_table"><tbody>';
        foreach ($tickets as $t) {
            $event = SNN_T_Events::get((int)$t->list_id);
            echo '<tr><td><strong>' . esc_html($t->name ?: $t->email) . '</strong><br><small>' . esc_html($event ? $event->name : '') . ' · <code>' . esc_html($t->ticket_code) . '</code></small></td>';
            echo '<td style="text-align:right"><a class="button" href="' . esc_url(SNN_T_Router::ticket_url($t->ticket_code)) . '">' . esc_html__('Open ticket', 'snn-tickets') . '</a> ';
            echo '<a href="' . esc_url(SNN_T_Files::url('pdf', $t->ticket_code)) . '">PDF</a></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><small>' . esc_html__('Each ticket has also been emailed to the person named on it.', 'snn-tickets') . '</small></p></section>';
    }

    public static function email_tickets($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin || !$order instanceof WC_Order) return;
        $tickets = self::display_tickets($order);
        if (!$tickets) return;
        if ($plain_text) {
            echo "\n" . strtoupper(__('Your tickets', 'snn-tickets')) . "\n\n";
            foreach ($tickets as $t) echo ($t->name ?: $t->email) . ' (' . $t->ticket_code . '): ' . SNN_T_Router::ticket_url($t->ticket_code) . "\n";
            echo "\n";
            return;
        }
        echo '<h2>' . esc_html__('Your tickets', 'snn-tickets') . '</h2><ul style="margin:0 0 24px;padding-left:18px">';
        foreach ($tickets as $t) {
            echo '<li style="margin:0 0 6px"><a href="' . esc_url(SNN_T_Router::ticket_url($t->ticket_code)) . '">' . esc_html($t->name ?: $t->email) . '</a> · ' . esc_html($t->ticket_code) . '</li>';
        }
        echo '</ul>';
    }

    /* ------------------------------------------------------------------
     * Event page
     * ---------------------------------------------------------------- */

    /** "Tickets" box on /events/{slug}/ listing what is for sale. */
    public static function event_shop_html($html, $event) {
        $products = self::products_for($event->id, true);
        if (!$products) return $html;
        $left = self::spots_left($event->id);
        $form = SNN_T_Forms::for_list($event->id);

        $out = '<section class="snn-event-shop"><h2 class="snn-event-shop-title">' . esc_html__('Tickets', 'snn-tickets') . '</h2>';
        if ($left === 0) {
            $out .= '<p class="snn-form-notice snn-warn">' . esc_html($form ? $form->settings['full_message'] : __('Sorry, this event is fully booked.', 'snn-tickets')) . '</p>';
        }
        $out .= '<ul class="snn-tiers">';
        foreach ($products as $p) {
            $buyable = $left !== 0 && $p->is_purchasable() && $p->is_in_stock();
            if (!$buyable) {
                $button = '<span class="snn-tier-out">' . esc_html__('Sold out', 'snn-tickets') . '</span>';
            } elseif ($p->is_type('simple') && !self::asks($p)) {
                $button = '<a class="snn-tier-buy wp-element-button" href="' . esc_url(add_query_arg('add-to-cart', $p->get_id(), wc_get_cart_url())) . '">' . esc_html__('Buy', 'snn-tickets') . '</a>';
            } else {
                $button = '<a class="snn-tier-buy wp-element-button" href="' . esc_url($p->get_permalink()) . '">' . esc_html__('Choose', 'snn-tickets') . '</a>';
            }
            $desc = wp_strip_all_tags((string)$p->get_short_description());
            $out .= '<li class="snn-tier"><div class="snn-tier-main"><span class="snn-tier-name">' . esc_html(self::tier_label($p, $event)) . '</span>'
                  . ($desc !== '' ? '<span class="snn-tier-desc">' . esc_html($desc) . '</span>' : '')
                  . '</div><span class="snn-tier-price">' . wp_kses_post($p->get_price_html()) . '</span>' . $button . '</li>';
        }
        $out .= '</ul></section>';
        $out .= '<style>.snn-event-shop{margin:0 0 32px}.snn-tiers{list-style:none;margin:0;padding:0}.snn-tier{display:flex;gap:14px;align-items:center;padding:14px 0;border-bottom:1px solid rgba(0,0,0,.12)}.snn-tier-main{flex:1;min-width:0;display:flex;flex-direction:column}.snn-tier-name{font-weight:600}.snn-tier-desc{font-size:.9em;opacity:.75}.snn-tier-price{white-space:nowrap}.snn-tier-buy{white-space:nowrap;text-decoration:none}.snn-tier-out{opacity:.6;font-weight:600}</style>';
        return $html . $out;
    }

    /** "VIP" rather than "Summer Gala – VIP" when the event name is already on the page. */
    public static function tier_label($product, $event) {
        $name = $product->get_name();
        foreach ([' – ', ' - ', ': '] as $sep) {
            if (strpos($name, $event->name . $sep) === 0) return substr($name, strlen($event->name . $sep));
        }
        return $name;
    }
}
