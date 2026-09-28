<?php
/**
 * WooCommerce in the admin: the Ticket box and Tickets tab in the product
 * editor, the event's "Sell tickets" tab, and ticket codes on orders.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Woo_Admin {

    public static function init() {
        if (!SNN_T_Woo::active()) return;

        add_filter('product_type_options', [__CLASS__, 'type_option']);
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'product_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'product_panel']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_product']);
        add_action('woocommerce_after_order_itemmeta', [__CLASS__, 'order_item_tickets'], 10, 3);

        foreach (['tier_add', 'tier_save'] as $a) {
            add_action('admin_post_snn_' . $a, [__CLASS__, 'handle_' . $a]);
        }
    }

    /* ------------------------------------------------------------------
     * Product editor
     * ---------------------------------------------------------------- */

    /** "Ticket" next to Virtual and Downloadable; stored as _snn_ticket. */
    public static function type_option($options) {
        $options['snn_ticket'] = [
            'id'            => SNN_T_Woo::META_ON,
            'wrapper_class' => 'show_if_simple show_if_variable',
            'label'         => __('Ticket', 'snn-tickets'),
            'description'   => __('Buying this issues tickets for an event.', 'snn-tickets'),
            'default'       => 'no',
        ];
        return $options;
    }

    public static function product_tab($tabs) {
        $tabs['snn_tickets'] = [
            'label'    => __('Tickets', 'snn-tickets'),
            'target'   => 'snn_tickets_data',
            'class'    => ['snn_tickets_tab'],
            'priority' => 12,
        ];
        return $tabs;
    }

    public static function product_panel() {
        global $product_object;
        $p = $product_object instanceof WC_Product ? $product_object : null;
        $events = [0 => __('Choose an event…', 'snn-tickets')];
        foreach (SNN_T_Events::all() as $e) {
            $events[$e->id] = $e->name . ($e->event_start ? ' · ' . SNN_T_Events::format_date($e) : '');
        }
        $event_id = $p ? (int)$p->get_meta(SNN_T_Woo::META_EVENT) : 0;
        ?>
        <div id="snn_tickets_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_select([
                    'id'          => SNN_T_Woo::META_EVENT,
                    'label'       => __('Event', 'snn-tickets'),
                    'options'     => $events,
                    'value'       => $event_id,
                    'desc_tip'    => true,
                    'description' => __('Tickets bought here are for this event: its emails, ticket look and door scanner.', 'snn-tickets'),
                ]);
                woocommerce_wp_text_input([
                    'id'                => SNN_T_Woo::META_PER,
                    'label'             => __('Tickets per purchase', 'snn-tickets'),
                    'type'              => 'number',
                    'value'             => $p ? max(1, (int)$p->get_meta(SNN_T_Woo::META_PER)) : 1,
                    'custom_attributes' => ['min' => 1, 'max' => 50, 'step' => 1],
                    'desc_tip'          => true,
                    'description'       => __('How many people one purchase lets in. For example 2 for a couples ticket, 4 for a family pass.', 'snn-tickets'),
                ]);
                woocommerce_wp_checkbox([
                    'id'          => SNN_T_Woo::META_ASK,
                    'label'       => __('Attendee details', 'snn-tickets'),
                    'value'       => $p && $p->get_meta(SNN_T_Woo::META_ASK) === 'yes' ? 'yes' : 'no',
                    'description' => __("Ask for each attendee's details on the product page, using the event's sign-up questions. Each ticket is emailed to its attendee. Without this, every ticket goes to the buyer.", 'snn-tickets'),
                ]);
                ?>
            </div>
            <div class="options_group">
                <p class="form-field">
                    <?php if ($event_id && SNN_T_Events::get($event_id)): ?>
                        <a href="<?php echo esc_url(SNN_T_Admin::event_admin_url($event_id, ['tab' => 'sale'])); ?>"><?php esc_html_e('Open this event in Tickets', 'snn-tickets'); ?> →</a>
                    <?php elseif (count($events) === 1): ?>
                        <?php esc_html_e('There are no events yet.', 'snn-tickets'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-new')); ?>"><?php esc_html_e('Add an event', 'snn-tickets'); ?> →</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <script>
        jQuery(function($){
            var box = $('#<?php echo esc_js(SNN_T_Woo::META_ON); ?>');
            function sync(){
                var type = $('select#product-type').val();
                var on = box.is(':checked') && (type === 'simple' || type === 'variable');
                $('.snn_tickets_tab').toggle(on);
                if (!on && $('.snn_tickets_tab').hasClass('active')) $('.general_options > a').trigger('click');
            }
            box.on('change', sync);
            $(document.body).on('woocommerce-product-type-change', function(){ setTimeout(sync, 0); });
            sync();
        });
        </script>
        <?php
    }

    public static function save_product($product) {
        $on = isset($_POST[SNN_T_Woo::META_ON]) && $product->is_type(['simple', 'variable']);
        $product->update_meta_data(SNN_T_Woo::META_ON, $on ? 'yes' : 'no');
        if (isset($_POST[SNN_T_Woo::META_EVENT])) {
            $event = absint(wp_unslash($_POST[SNN_T_Woo::META_EVENT]));
            $product->update_meta_data(SNN_T_Woo::META_EVENT, SNN_T_Events::get($event) ? $event : 0);
        }
        if (isset($_POST[SNN_T_Woo::META_PER])) {
            $product->update_meta_data(SNN_T_Woo::META_PER, max(1, min(50, absint(wp_unslash($_POST[SNN_T_Woo::META_PER])))));
        }
        $product->update_meta_data(SNN_T_Woo::META_ASK, isset($_POST[SNN_T_Woo::META_ASK]) ? 'yes' : 'no');
        // Tickets never ship.
        if ($on && $product->is_type('simple')) $product->set_virtual(true);
    }

    /* ------------------------------------------------------------------
     * Orders
     * ---------------------------------------------------------------- */

    /** Ticket codes under each ticket line on the order screen. */
    public static function order_item_tickets($item_id, $item, $product) {
        if (!$item instanceof WC_Order_Item_Product) return;
        $tickets = SNN_T_Woo::item_tickets($item_id);
        list($list_id) = SNN_T_Woo::item_ticket_info($item);
        if (!$list_id) return;
        echo '<div class="snn-order-item-tickets" style="margin-top:6px">';
        if (!$tickets) {
            echo '<small style="color:#646970">' . esc_html__('Tickets are issued when the order is paid.', 'snn-tickets') . '</small>';
        }
        foreach ($tickets as $t) {
            $off = $t->status !== 'active';
            echo '<a href="' . esc_url(SNN_T_Admin::event_admin_url((int)$t->list_id, ['person' => 't' . (int)$t->id])) . '" style="display:inline-block;margin:0 6px 4px 0;padding:2px 8px;border-radius:10px;background:' . ($off ? '#f0f0f1' : '#edf7ed') . ';text-decoration:' . ($off ? 'line-through' : 'none') . ';font-family:monospace">'
                . esc_html($t->ticket_code) . '</a>';
        }
        echo '</div>';
    }

    /** "Order #123" link for the People list, or '' when there is no such order. */
    public static function order_link($order_id) {
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order) return '';
        return '<a href="' . esc_url($order->get_edit_order_url()) . '">' . esc_html(sprintf(__('Order #%s', 'snn-tickets'), $order->get_order_number())) . '</a>';
    }

    /* ------------------------------------------------------------------
     * Event page: Sell tickets
     * ---------------------------------------------------------------- */

    /** Active tickets sold per product for an event. */
    private static function sold_by_product($list_id) {
        global $wpdb;
        $out = [];
        foreach ((array)$wpdb->get_results($wpdb->prepare(
            "SELECT product_id, COUNT(*) AS n FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND source = 'order' AND status = 'active' GROUP BY product_id",
            (int)$list_id)) as $r) {
            $out[(int)$r->product_id] = (int)$r->n;
        }
        return $out;
    }

    public static function tab($event, $form) {
        $products = SNN_T_Woo::products_for($event->id);
        $sold     = self::sold_by_product($event->id);
        $max      = SNN_T_Events::spot_limit($event->id);
        $held     = SNN_T_Woo::held($event->id);
        $post     = esc_url(admin_url('admin-post.php'));
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];
        $cur      = get_woocommerce_currency_symbol();
        $form_tab = SNN_T_Admin::event_admin_url($event->id, ['tab' => 'form']);
        ?>
        <?php if (!$gateways): ?>
            <div class="snn-hint bad"><p><b><?php esc_html_e('No way to pay is switched on yet.', 'snn-tickets'); ?></b>
                <?php esc_html_e('People can add tickets to their cart but cannot check out.', 'snn-tickets'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')); ?>"><?php esc_html_e('Set up payments', 'snn-tickets'); ?> →</a></p></div>
        <?php endif; ?>

        <div class="snn-card">
            <h2><?php esc_html_e('Ticket types', 'snn-tickets'); ?></h2>
            <p class="snn-muted" style="margin:0"><?php esc_html_e('Each ticket type is a WooCommerce product. They are listed on the event page with a Buy button; once paid, the tickets appear under People and are emailed like any other ticket.', 'snn-tickets'); ?></p>
            <?php if (!$products): ?>
                <p class="snn-muted" style="margin:0"><?php esc_html_e('Nothing for sale yet. Add a ticket type below.', 'snn-tickets'); ?></p>
            <?php else: ?>
            <div class="snn-table-wrap"><table class="snn-table">
                <thead><tr><th><?php esc_html_e('Ticket type', 'snn-tickets'); ?></th><th><?php esc_html_e('Price', 'snn-tickets'); ?></th><th><?php esc_html_e('Quantity', 'snn-tickets'); ?></th><th><?php esc_html_e('Sold', 'snn-tickets'); ?></th><th><?php esc_html_e('Status', 'snn-tickets'); ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($products as $p):
                    $simple  = $p->is_type('simple');
                    $on_sale = $p->get_status() === 'publish';
                    $fid     = 'snn-tier-' . $p->get_id(); ?>
                    <tr>
                        <td><div class="who"><a href="<?php echo esc_url(get_edit_post_link($p->get_id())); ?>"><?php echo esc_html(SNN_T_Woo::tier_label($p, $event)); ?></a>
                            <span><?php echo esc_html(implode(' · ', array_filter([
                                $simple ? '' : __('Has options', 'snn-tickets'),
                                SNN_T_Woo::per_unit($p) > 1 ? sprintf(__('Admits %d', 'snn-tickets'), SNN_T_Woo::per_unit($p)) : '',
                                SNN_T_Woo::asks($p) ? __('Asks attendee details', 'snn-tickets') : '',
                            ]))); ?></span></div></td>
                        <?php if ($simple): ?>
                            <td><input form="<?php echo esc_attr($fid); ?>" type="text" inputmode="decimal" name="price" value="<?php echo esc_attr(wc_format_localized_price($p->get_regular_price())); ?>" style="width:90px" aria-label="<?php esc_attr_e('Price', 'snn-tickets'); ?>"> <span class="snn-muted"><?php echo esc_html(html_entity_decode($cur)); ?></span></td>
                            <td><input form="<?php echo esc_attr($fid); ?>" type="number" min="0" name="stock" value="<?php echo $p->managing_stock() ? (int)$p->get_stock_quantity() : ''; ?>" placeholder="∞" style="width:80px" aria-label="<?php esc_attr_e('Quantity left', 'snn-tickets'); ?>"></td>
                        <?php else: ?>
                            <td><?php echo wp_kses_post($p->get_price_html()); ?></td><td class="snn-muted">—</td>
                        <?php endif; ?>
                        <td class="snn-num"><?php echo (int)($sold[$p->get_id()] ?? 0); ?></td>
                        <td><?php echo SNN_T_Admin::chip($on_sale ? __('On sale', 'snn-tickets') : __('Not on sale', 'snn-tickets'), $on_sale ? 'ok' : ''); ?></td>
                        <td class="act">
                            <form id="<?php echo esc_attr($fid); ?>" method="post" action="<?php echo $post; ?>" style="display:inline">
                                <input type="hidden" name="action" value="snn_tier_save"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>"><input type="hidden" name="product" value="<?php echo (int)$p->get_id(); ?>">
                                <?php wp_nonce_field('snn_tier_save'); ?>
                                <?php if ($simple): ?><button class="button button-small" name="do" value="save"><?php esc_html_e('Save', 'snn-tickets'); ?></button><?php endif; ?>
                                <button class="button button-small" name="do" value="<?php echo $on_sale ? 'stop' : 'start'; ?>"><?php echo $on_sale ? esc_html__('Stop selling', 'snn-tickets') : esc_html__('Put on sale', 'snn-tickets'); ?></button>
                            </form>
                            <a class="button button-small" href="<?php echo esc_url(get_edit_post_link($p->get_id())); ?>"><?php esc_html_e('Edit', 'snn-tickets'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>

        <form method="post" action="<?php echo $post; ?>" class="snn-card">
            <input type="hidden" name="action" value="snn_tier_add"><input type="hidden" name="event" value="<?php echo (int)$event->id; ?>">
            <?php wp_nonce_field('snn_tier_add'); ?>
            <div class="snn-set"><div><h3><?php esc_html_e('Add a ticket type', 'snn-tickets'); ?></h3><p class="snn-muted snn-small"><?php esc_html_e('For example General admission, VIP or Student. For options such as sizes or dates, make a variable product in WooCommerce and tick "Ticket".', 'snn-tickets'); ?></p></div><div class="body">
                <label class="snn-field"><span><?php esc_html_e('Name', 'snn-tickets'); ?></span><input type="text" name="tier" required placeholder="<?php esc_attr_e('General admission', 'snn-tickets'); ?>"></label>
                <div class="snn-grid3">
                    <label class="snn-field"><span><?php echo esc_html(sprintf(__('Price (%s)', 'snn-tickets'), html_entity_decode($cur))); ?></span><input type="text" inputmode="decimal" name="price" required placeholder="0"></label>
                    <label class="snn-field"><span><?php esc_html_e('How many', 'snn-tickets'); ?> <small><?php esc_html_e('(optional)', 'snn-tickets'); ?></small></span><input type="number" min="0" name="stock" placeholder="∞"></label>
                    <label class="snn-field"><span><?php esc_html_e('Admits', 'snn-tickets'); ?></span><input type="number" min="1" max="50" name="per" value="1"></label>
                </div>
                <label class="snn-check"><input type="checkbox" name="ask" value="1"> <span><?php esc_html_e('Ask for each attendee\'s name and answers', 'snn-tickets'); ?> <span class="snn-muted snn-small"><?php esc_html_e('(uses the Sign-up form questions; otherwise every ticket goes to the buyer)', 'snn-tickets'); ?></span></span></label>
                <div><button class="button button-primary"><?php esc_html_e('Add and put on sale', 'snn-tickets'); ?></button></div>
            </div></div>
        </form>

        <div class="snn-card">
            <div class="snn-set"><div><h3><?php esc_html_e('Spots', 'snn-tickets'); ?></h3></div><div class="body">
                <p style="margin:0"><?php
                    echo $max
                        ? esc_html(sprintf(__('The whole event has %1$d spots, shared by the shop and the sign-up form. %2$d are taken.', 'snn-tickets'), $max, SNN_T_Events::spots_taken($event->id)))
                        : esc_html__('The event has no overall spot limit. Each ticket type can still have its own quantity.', 'snn-tickets'); ?>
                    <a href="<?php echo esc_url($form_tab); ?>"><?php esc_html_e('Change on the Sign-up form tab', 'snn-tickets'); ?></a></p>
                <?php if ($held): ?><p class="snn-muted snn-small" style="margin:0"><?php echo esc_html(sprintf(_n('%d spot is held by an order waiting for payment.', '%d spots are held by orders waiting for payment.', $held, 'snn-tickets'), $held)); ?></p><?php endif; ?>
                <?php if ($form && $form->status !== 'closed'): ?>
                    <p class="snn-muted snn-small" style="margin:0"><?php esc_html_e('Free sign-ups are open too, so people can also get a ticket without paying.', 'snn-tickets'); ?> <a href="<?php echo esc_url($form_tab); ?>"><?php esc_html_e('Close sign-ups', 'snn-tickets'); ?></a></p>
                <?php endif; ?>
            </div></div>
        </div>
        <?php
    }

    private static function guard($action) {
        SNN_T_Admin::cap();
        check_admin_referer($action);
        $id = (int)($_POST['event'] ?? 0);
        if (!SNN_T_Events::get($id)) wp_die(esc_html__('That event no longer exists.', 'snn-tickets'));
        return $id;
    }

    private static function back($id, $msg, $error = false) {
        SNN_T_Admin::go(SNN_T_Admin::event_admin_url($id, ['tab' => 'sale']), $msg, $error);
    }

    /** '' (no limit) or a whole number from a stock box. */
    private static function stock_in($raw) {
        $raw = trim((string)$raw);
        return $raw === '' ? null : max(0, (int)$raw);
    }

    public static function handle_tier_add() {
        $id    = self::guard('snn_tier_add');
        $event = SNN_T_Events::get($id);
        $in    = wp_unslash($_POST);
        $tier  = sanitize_text_field($in['tier'] ?? '');
        $price = wc_format_decimal($in['price'] ?? '');
        if ($tier === '' || $price === '') self::back($id, __('Give the ticket type a name and a price.', 'snn-tickets'), true);

        $p = new WC_Product_Simple();
        $p->set_name($event->name . ' – ' . $tier);
        $p->set_regular_price($price);
        $p->set_virtual(true);
        $p->set_status('publish');
        $p->set_menu_order(count(SNN_T_Woo::products_for($id)));
        $stock = self::stock_in($in['stock'] ?? '');
        if ($stock !== null) { $p->set_manage_stock(true); $p->set_stock_quantity($stock); }
        $p->update_meta_data(SNN_T_Woo::META_ON, 'yes');
        $p->update_meta_data(SNN_T_Woo::META_EVENT, $id);
        $p->update_meta_data(SNN_T_Woo::META_PER, max(1, min(50, (int)($in['per'] ?? 1))));
        $p->update_meta_data(SNN_T_Woo::META_ASK, !empty($in['ask']) ? 'yes' : 'no');
        $p->save();

        self::back($id, sprintf(__('"%s" is on sale.', 'snn-tickets'), $tier));
    }

    public static function handle_tier_save() {
        $id = self::guard('snn_tier_save');
        $in = wp_unslash($_POST);
        $p  = wc_get_product((int)($in['product'] ?? 0));
        if (!$p || SNN_T_Woo::event_id($p) !== $id) self::back($id, __('That ticket type no longer exists.', 'snn-tickets'), true);

        switch ($in['do'] ?? '') {
            case 'stop':
                $p->set_status('draft');
                $msg = __('Stopped selling. Tickets already sold still work.', 'snn-tickets');
                break;
            case 'start':
                $p->set_status('publish');
                $msg = __('Back on sale.', 'snn-tickets');
                break;
            default:
                if ($p->is_type('simple')) {
                    $price = wc_format_decimal($in['price'] ?? '');
                    if ($price !== '') $p->set_regular_price($price);
                    $stock = self::stock_in($in['stock'] ?? '');
                    $p->set_manage_stock($stock !== null);
                    if ($stock !== null) $p->set_stock_quantity($stock);
                }
                $msg = __('Saved.', 'snn-tickets');
        }
        $p->save();
        self::back($id, $msg);
    }
}
