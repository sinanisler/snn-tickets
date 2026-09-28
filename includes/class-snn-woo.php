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
    const ITEM_ISSUED    = '_snn_issued';        // tickets ever made for the line
    const ITEM_AUTO_OFF  = '_snn_auto_revoked';  // ticket ids the order itself cancelled

    /** Order meta: spots an unpaid order holds, per event. */
    const HOLD_PREFIX = '_snn_hold_';

    /** Order meta: the buyer's own ticket, and how many still wait for a name. */
    const ORDER_BUYER_TICKET = '_snn_buyer_ticket';
    const ORDER_UNNAMED      = '_snn_unnamed';

    const MANAGE_NONCE = 'snn_manage_';

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
            // The Ticket box and Tickets tab live in the classic product editor.
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', SNN_TICKETS_FILE, false);
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
        // Line items edited on a paid order in the admin.
        add_action('woocommerce_saved_order_items', [__CLASS__, 'sync_order']);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'sync_order'], 60);
        add_action('woocommerce_before_delete_order_item', [__CLASS__, 'item_deleted']);

        // Showing the tickets, and passing them on
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'order_tickets_html']);
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_tickets'], 10, 4);
        add_action('snn_tickets_route_tickets', [__CLASS__, 'manage_page']);
        add_filter('snn_tickets_claim_from', [__CLASS__, 'claim_from'], 10, 2);
        add_action('snn_tickets_claim_changed', [__CLASS__, 'claim_changed']);
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
     * first, and tickets already used at the door last. At most $max_new
     * tickets are made, so a line never issues more than it paid for.
     *
     * @param array $tickets [['id' =>, 'status' =>, 'vc' =>], ...] oldest first
     * @return array ['restore' => ids, 'revoke' => ids, 'create' => n]
     */
    public static function plan($tickets, $want, $max_new = PHP_INT_MAX) {
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
        $out['create']  = max(0, min($need - count($back), (int)$max_new));
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
            $made = [];
            foreach ($order->get_items() as $item_id => $item) {
                list($list_id, $per) = self::item_ticket_info($item);
                if (!$list_id) continue;
                $units = $paid ? max(0, (int)$item->get_quantity() + (int)$order->get_qty_refunded_for_item($item_id)) : 0;
                $made = array_merge($made, self::sync_item($order, $item, $list_id, $units * $per, (int)$item->get_quantity() * $per));
            }
            if ($made) self::send_order_emails($order, $made);
            self::refresh_unnamed($order);
            if (!$order->has_status(['pending', 'on-hold', 'checkout-draft'])) self::clear_holds($order);
        } finally {
            delete_option($lock);
        }
    }

    /**
     * @param int $want tickets the line should have now
     * @param int $paid tickets the line paid for, before refunds
     * @return int[] ids of the tickets made now
     */
    private static function sync_item($order, $item, $list_id, $want, $paid) {
        $tickets = self::item_tickets($item->get_id());
        $auto    = array_map('intval', (array)$item->get_meta(self::ITEM_AUTO_OFF));
        $issued  = max((int)$item->get_meta(self::ITEM_ISSUED), count($tickets));

        // A ticket cancelled by hand in People stays cancelled and still
        // uses up its place; only the order's own cancellations come back.
        // A deleted ticket is gone for good: $issued remembers it.
        $pool = []; $by_hand = 0;
        foreach ($tickets as $t) {
            if ($t->status !== 'active' && !in_array((int)$t->id, $auto, true)) { $by_hand++; continue; }
            $pool[] = ['id' => (int)$t->id, 'status' => $t->status, 'vc' => (int)$t->validate_count];
        }
        $plan = self::plan($pool, max(0, $want - $by_hand), max(0, $paid - $issued));

        foreach ($plan['revoke'] as $id) SNN_T_Tickets::set_status($id, 'revoked');
        foreach ($plan['restore'] as $id) SNN_T_Tickets::set_status($id, 'active');
        if ($plan['revoke'] || $plan['restore']) {
            $auto = array_values(array_diff(array_unique(array_merge($auto, $plan['revoke'])), $plan['restore']));
            $item->update_meta_data(self::ITEM_AUTO_OFF, $auto);
            $item->save_meta_data();
        }
        if (!$plan['create'] || !SNN_T_Events::get($list_id)) return [];

        $attendees = (array)$item->get_meta(self::ITEM_ATTENDEES);
        $buyer = [
            'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'email' => strtolower((string)$order->get_billing_email()),
        ];
        $form = SNN_T_Forms::for_list($list_id);
        $made = 0; $ids = [];

        for ($i = 0; $i < $plan['create']; $i++) {
            $a = $attendees[$issued + $i] ?? null;
            // Named at purchase; else the buyer's own (the order's first
            // ticket); else waiting for the buyer to pass it on.
            $open = false; $mine = false;
            if (is_array($a)) {
                $name  = ($a['name'] ?? '') !== '' ? $a['name'] : $buyer['name'];
                $email = ($a['email'] ?? '') !== '' ? $a['email'] : $buyer['email'];
            } elseif (!self::buyer_ticket($order)) {
                $name = $buyer['name']; $email = $buyer['email']; $mine = true;
            } else {
                $name = ''; $email = $buyer['email']; $open = true;
            }
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
            $made++;
            $ids[] = $ticket_id;
            if ($open) SNN_T_Claims::open($ticket_id);
            if ($mine) { $order->update_meta_data(self::ORDER_BUYER_TICKET, $ticket_id); $order->save_meta_data(); }
            if ($sid) {
                global $wpdb;
                $wpdb->update(SNN_T_DB::submissions(), [
                    'ticket_id' => $ticket_id, 'decided_at' => current_time('mysql'),
                    'decision_reason' => sprintf(__('Paid, order #%s', 'snn-tickets'), $order->get_order_number()),
                ], ['id' => $sid]);
            }
        }

        $item->update_meta_data(self::ITEM_ISSUED, $issued + $made);
        $item->save_meta_data();
        $order->add_order_note(sprintf(_n('%1$d ticket issued for %2$s.', '%1$d tickets issued for %2$s.', $made, 'snn-tickets'),
            $made, SNN_T_Events::get($list_id)->name));
        return $ids;
    }

    /** The buyer's own ticket, if the order already gave them one. */
    private static function buyer_ticket($order) {
        $id = (int)$order->get_meta(self::ORDER_BUYER_TICKET);
        return $id && SNN_T_Tickets::get($id) ? $id : 0;
    }

    public static function buyer_email($order) {
        return strtolower((string)$order->get_billing_email());
    }

    /* ------------------------------------------------------------------
     * Emails: one per person
     * ---------------------------------------------------------------- */

    /**
     * New tickets named for someone else get their own ticket email. The
     * buyer gets one email per event: their ticket alone, or the list of
     * all their tickets with links to pass them on.
     */
    public static function send_order_emails($order, $ids) {
        $buyer = self::buyer_email($order);
        $mine  = [];
        foreach ($ids as $id) {
            $t = SNN_T_Tickets::get($id);
            if (!$t || $t->status !== 'active') continue;
            if ($t->email !== '' && $t->email !== $buyer) {
                if (SNN_T_Mailer::event_template((int)$t->list_id, 'ticket')['on']) SNN_T_Mailer::queue_ticket($t);
                continue;
            }
            $mine[(int)$t->list_id][] = $t;
        }
        foreach ($mine as $list_id => $tickets) self::send_buyer_email($order, $list_id, $tickets);
    }

    /** @param object[] $new the tickets this email is about */
    public static function send_buyer_email($order, $list_id, $new) {
        $buyer = self::buyer_email($order);
        if ($buyer === '' || !is_email($buyer)) return false;
        $all  = array_values(array_filter(self::order_tickets($order->get_id()), function ($t) use ($list_id) {
            return $t->status === 'active' && (int)$t->list_id === (int)$list_id;
        }));
        $open = array_filter($all, ['SNN_T_Claims', 'is_open']);

        // One ticket, theirs, nothing to pass on: the plain ticket email.
        if (count($new) === 1 && !SNN_T_Claims::is_open($new[0]) && !$open) {
            return SNN_T_Mailer::event_template($list_id, 'ticket')['on'] ? SNN_T_Mailer::queue_ticket($new[0]) : false;
        }
        $own = null;
        foreach ($all as $t) if (!SNN_T_Claims::is_open($t) && $t->email === $buyer) { $own = $t; break; }

        if (!SNN_T_Mailer::event_template($list_id, 'order')['on']) {
            return $own && SNN_T_Mailer::event_template($list_id, 'ticket')['on'] ? SNN_T_Mailer::queue_ticket($own) : false;
        }
        $manage = self::manage_url($order);
        return SNN_T_Mailer::send_event_email('order', $list_id, [
            'name'        => $order->get_billing_first_name() ?: ($own ? $own->name : ''),
            'email'       => $buyer,
            'ticket_code' => $own ? $own->ticket_code : '',
            'ticket_id'   => $own ? (int)$own->id : null,
            'vars'        => [
                '{count}'         => (string)count($all),
                '{tickets_list}'  => SNN_T_Mailer::tickets_list_html(self::list_rows($all, $buyer)),
                '{manage_url}'    => $manage,
                '{manage_button}' => SNN_T_Mailer::button_html($manage, __('Manage your tickets', 'snn-tickets')),
            ],
        ]);
    }

    /** Rows for {tickets_list}: what the buyer should know about each ticket. */
    public static function list_rows($tickets, $buyer) {
        $rows = [];
        foreach ($tickets as $t) {
            if (($t->holder ?? '') === SNN_T_Claims::OPEN) {
                $rows[] = ['label' => __('Not named yet', 'snn-tickets'), 'note' => __('Send this link to your guest:', 'snn-tickets'), 'url' => SNN_T_Claims::url($t), 'link_label' => ''];
            } elseif (($t->holder ?? '') === SNN_T_Claims::SENT) {
                $rows[] = ['label' => sprintf(__('Sent to %s', 'snn-tickets'), $t->claim_email), 'note' => __('Waiting for them to fill in their name', 'snn-tickets'), 'url' => '', 'link_label' => ''];
            } elseif ($t->email === $buyer) {
                $rows[] = ['label' => $t->name !== '' ? $t->name : __('Your ticket', 'snn-tickets'), 'note' => __('Your ticket', 'snn-tickets'), 'url' => '', 'link_label' => ''];
            } else {
                $rows[] = ['label' => $t->name, 'note' => sprintf(__('Ticket emailed to %s', 'snn-tickets'), $t->email), 'url' => '', 'link_label' => ''];
            }
        }
        return $rows;
    }

    /* ------------------------------------------------------------------
     * Passing tickets on
     * ---------------------------------------------------------------- */

    public static function manage_url($order) {
        return SNN_T_Router::manage_url($order->get_id(), $order->get_order_key());
    }

    /** How many of an order's tickets still wait for a name, kept on the order for the admin list. */
    public static function refresh_unnamed($order) {
        $n = count(array_filter(self::order_tickets($order->get_id()), function ($t) {
            return $t->status === 'active' && SNN_T_Claims::is_open($t);
        }));
        if ($n) $order->update_meta_data(self::ORDER_UNNAMED, $n);
        else $order->delete_meta_data(self::ORDER_UNNAMED);
        $order->save_meta_data();
    }

    public static function claim_changed($ticket_id) {
        $t = SNN_T_Tickets::get($ticket_id);
        $order = ($t && $t->order_id) ? wc_get_order((int)$t->order_id) : null;
        if ($order) self::refresh_unnamed($order);
    }

    /** "Sinan got you a ticket": the buyer's first name on the claim page. */
    public static function claim_from($from, $ticket) {
        $order = !empty($ticket->order_id) ? wc_get_order((int)$ticket->order_id) : null;
        return $order ? ($order->get_billing_first_name() ?: $from) : $from;
    }

    /** Buyer's view of a ticket they may act on, or null. */
    private static function own_ticket($order, $id) {
        $t = SNN_T_Tickets::get((int)$id);
        return ($t && (int)$t->order_id === (int)$order->get_id() && $t->status === 'active') ? $t : null;
    }

    /**
     * /events/tickets/{order}/?key=... : the buyer's tickets, no account
     * needed; the order key in the link is the password. Posts from the
     * thank-you page and My Account land here too.
     */
    public static function manage_page($order_id) {
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || $order->get_type() !== 'shop_order') return;
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $ok  = ($key !== '' && hash_equals($order->get_order_key(), $key))
            || (get_current_user_id() && (int)$order->get_customer_id() === get_current_user_id())
            || current_user_can('manage_woocommerce');
        if (!$ok) return;

        $msg = ''; $bad = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!wp_verify_nonce(wp_unslash($_POST['_snn_manage'] ?? ''), self::MANAGE_NONCE . $order->get_id())) {
                $msg = __('That did not work. Please try again.', 'snn-tickets'); $bad = true;
            } else {
                $t  = self::own_ticket($order, $_POST['ticket'] ?? 0);
                $do = sanitize_key($_POST['do'] ?? '');
                $r  = $t ? true : new WP_Error('x', __('That ticket is not in this order.', 'snn-tickets'));
                if ($t && ($do === 'send' || $do === 'resend')) {
                    $to = $do === 'resend' ? $t->claim_email : wp_unslash($_POST['email'] ?? '');
                    $r  = SNN_T_Claims::send($t, $to, $order->get_billing_first_name());
                    if (!is_wp_error($r)) $msg = sprintf(__('Sent to %s. They fill in their name and get the ticket.', 'snn-tickets'), sanitize_email($to));
                } elseif ($t && $do === 'takeback') {
                    $r = SNN_T_Claims::take_back($t);
                    if (!is_wp_error($r)) $msg = __('Taken back. The link you sent no longer works.', 'snn-tickets');
                }
                if (is_wp_error($r)) { $msg = $r->get_error_message(); $bad = true; }
            }
            $back = add_query_arg(['snn_msg' => rawurlencode($msg), 'snn_bad' => $bad ? 1 : 0], self::manage_url($order));
            wp_safe_redirect($back . '#snn-tickets');
            exit;
        }

        ob_start();
        echo '<div class="snn-event-page snn-manage-page" style="max-width:720px;margin:0 auto;padding:32px 16px 48px">';
        echo '<h1>' . esc_html(sprintf(__('Your tickets · Order #%s', 'snn-tickets'), $order->get_order_number())) . '</h1>';
        if (!empty($_GET['snn_msg'])) {
            echo '<p class="snn-manage-msg" role="status" style="padding:12px 14px;border-radius:6px;background:' . (!empty($_GET['snn_bad']) ? '#fcf0f1' : '#edf7ed') . '">' . esc_html(sanitize_text_field(wp_unslash($_GET['snn_msg']))) . '</p>';
        }
        self::manage_html($order);
        echo '</div>';
        SNN_T_Router::render_in_theme(__('Your tickets', 'snn-tickets'), ob_get_clean(), true);
        exit;
    }

    /**
     * The buyer's ticket list with its actions: open your own ticket, copy
     * or email a link for the others, resend or take back a sent one.
     */
    public static function manage_html($order) {
        $tickets = array_values(array_filter(self::order_tickets($order->get_id()), function ($t) { return $t->status === 'active'; }));
        if (!$tickets) return;
        $buyer  = self::buyer_email($order);
        $action = esc_url(self::manage_url($order));
        $nonce  = wp_create_nonce(self::MANAGE_NONCE . $order->get_id());
        $groups = [];
        foreach ($tickets as $t) $groups[(int)$t->list_id][] = $t;
        $form = function ($t, $do, $label, $extra = '') use ($action, $nonce) {
            return '<form method="post" action="' . $action . '" class="snn-mt-form">'
                . '<input type="hidden" name="_snn_manage" value="' . esc_attr($nonce) . '"><input type="hidden" name="ticket" value="' . (int)$t->id . '"><input type="hidden" name="do" value="' . esc_attr($do) . '">'
                . $extra . '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
        };

        echo '<section id="snn-tickets" class="snn-order-tickets woocommerce-order-tickets">';
        foreach ($groups as $list_id => $list) {
            $event = SNN_T_Events::get($list_id);
            echo '<h2 class="woocommerce-column__title">' . esc_html(sprintf(__('Your tickets for %s', 'snn-tickets'), $event ? $event->name : '')) . '</h2>';
            if ($event && ($when = SNN_T_Events::format_when($event)) !== '') echo '<p class="snn-mt-when">' . esc_html($when) . '</p>';
            echo '<table class="woocommerce-table shop_table snn-mt"><tbody>';
            foreach ($list as $i => $t) {
                echo '<tr><td class="snn-mt-n">' . ((int)$i + 1) . '</td><td>';
                if (($t->holder ?? '') === SNN_T_Claims::OPEN) {
                    $url = SNN_T_Claims::url($t);
                    echo '<strong>' . esc_html__('Not named yet', 'snn-tickets') . '</strong><br><small>' . esc_html__('Send it on: your guest fills in their name and gets their own ticket.', 'snn-tickets') . '</small>';
                    echo '<div class="snn-mt-share"><input type="text" readonly value="' . esc_attr($url) . '" onclick="this.select()" aria-label="' . esc_attr__('Ticket link', 'snn-tickets') . '">'
                        . '<button type="button" class="button" onclick="var i=this.previousElementSibling;i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand(\'copy\')});this.textContent=' . esc_attr(wp_json_encode(__('Copied', 'snn-tickets'))) . '">' . esc_html__('Copy link', 'snn-tickets') . '</button></div>';
                    echo $form($t, 'send', __('Email it', 'snn-tickets'), '<input type="email" name="email" required placeholder="' . esc_attr__("Guest's email", 'snn-tickets') . '"> ');
                } elseif (($t->holder ?? '') === SNN_T_Claims::SENT) {
                    echo '<strong>' . esc_html(sprintf(__('Sent to %s', 'snn-tickets'), $t->claim_email)) . '</strong><br><small>' . esc_html__('Waiting for them to fill in their name.', 'snn-tickets') . '</small>';
                    echo '<div class="snn-mt-actions">' . $form($t, 'resend', __('Send again', 'snn-tickets')) . $form($t, 'takeback', __('Take back', 'snn-tickets')) . '</div>';
                } elseif ($t->email === $buyer) {
                    echo '<strong>' . esc_html($t->name !== '' ? $t->name : __('Your ticket', 'snn-tickets')) . '</strong> <small>' . esc_html__('(you)', 'snn-tickets') . '</small>';
                    echo '<div class="snn-mt-actions"><a class="button" href="' . esc_url(SNN_T_Router::ticket_url($t->ticket_code)) . '">' . esc_html__('Open ticket', 'snn-tickets') . '</a> <a href="' . esc_url(SNN_T_Files::url('pdf', $t->ticket_code)) . '">PDF</a></div>';
                } else {
                    echo '<strong>' . esc_html($t->name) . '</strong><br><small>' . esc_html(sprintf(__('Has their ticket (%s)', 'snn-tickets'), $t->email)) . '</small>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
        echo '<style>.snn-mt td{vertical-align:top}.snn-mt-n{width:28px;opacity:.6}.snn-mt-when{margin:-4px 0 10px;opacity:.75}.snn-mt-share{display:flex;gap:6px;margin:8px 0}.snn-mt-share input{flex:1;min-width:0;font-size:13px}.snn-mt-form{display:inline-flex;gap:6px;flex-wrap:wrap;margin:4px 6px 0 0}.snn-mt-form input[type=email]{min-width:200px}.snn-mt-actions{margin-top:6px}</style>';
    }

    /** A ticket line removed from an order in the admin takes its tickets with it. */
    public static function item_deleted($item_id) {
        foreach (self::item_tickets($item_id) as $t) {
            if ($t->status === 'active') SNN_T_Tickets::set_status((int)$t->id, 'revoked');
        }
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

    /** Thank-you page and My Account → Orders: the same list as the manage page. */
    public static function order_tickets_html($order) {
        if ($order instanceof WC_Order) self::manage_html($order);
    }

    /** The buyer's WooCommerce order emails list the tickets too, with the manage link. */
    public static function email_tickets($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin || !$order instanceof WC_Order) return;
        $tickets = self::display_tickets($order);
        if (!$tickets) return;
        $rows   = self::list_rows($tickets, self::buyer_email($order));
        $manage = self::manage_url($order);
        if ($plain_text) {
            echo "\n" . strtoupper(__('Your tickets', 'snn-tickets')) . "\n\n";
            foreach ($rows as $i => $r) echo ((int)$i + 1) . '. ' . $r['label'] . ($r['url'] !== '' ? ' ' . $r['url'] : '') . "\n";
            echo "\n" . __('Manage your tickets', 'snn-tickets') . ': ' . $manage . "\n\n";
            return;
        }
        echo '<h2>' . esc_html__('Your tickets', 'snn-tickets') . '</h2>';
        echo SNN_T_Mailer::tickets_list_html($rows); // escaped inside
        echo SNN_T_Mailer::button_html($manage, __('Manage your tickets', 'snn-tickets'));
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
