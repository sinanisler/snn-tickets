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

    /** "Summer Gala – VIP" → "Summer Gala": the event a ticket type belongs to. */
    public static function event_name_from($title) {
        $title = trim((string)$title);
        foreach ([' – ', ' — ', ' - ', ' | ', ': '] as $sep) {
            $pos = strpos($title, $sep);
            if ($pos !== false && $pos > 0) return trim(substr($title, 0, $pos));
        }
        return $title;
    }

    /** Events for the picker: upcoming (soonest first), undated, past. */
    private static function event_groups() {
        $today = substr(current_time('mysql'), 0, 10);
        $groups = ['up' => [], 'none' => [], 'past' => []];
        foreach (SNN_T_Events::all() as $e) {
            if (!$e->event_start) $groups['none'][] = $e;
            elseif (substr($e->event_end ?: $e->event_start, 0, 10) >= $today) $groups['up'][] = $e;
            else $groups['past'][] = $e;
        }
        usort($groups['up'], function ($a, $b) { return strcmp($a->event_start, $b->event_start); });
        return $groups;
    }

    /** The values the tab's event fields show for one event. */
    private static function event_fields($e) {
        $start = $e ? $e->event_start : '';
        $end   = $e ? $e->event_end : '';
        $limit = $e ? SNN_T_Events::spot_limit($e->id) : 0;
        return [
            'name'     => $e ? $e->name : '',
            'date'     => $start ? substr($start, 0, 10) : '',
            'start'    => $start ? substr($start, 11, 5) : '',
            'end'      => $end ? substr($end, 11, 5) : '',
            // A multi-day event keeps its end date; the tab only shows times.
            'end_date' => $end && substr($end, 0, 10) !== substr($start, 0, 10) ? substr($end, 0, 10) : '',
            'venue'    => $e ? $e->venue : '',
            'spots'    => $limit ? (string)$limit : '',
            'url'      => $e ? SNN_T_Admin::event_admin_url($e->id, ['tab' => 'sale']) : '',
        ];
    }

    /**
     * The Tickets tab. It reads as one short form: which event (a new one by
     * default, named after the product), when and where, and how many
     * spots. Picking an existing event fills the fields with its details;
     * editing them updates that event.
     */
    public static function product_panel() {
        global $product_object;
        $p      = $product_object instanceof WC_Product ? $product_object : null;
        $linked = $p ? SNN_T_Events::get((int)$p->get_meta(SNN_T_Woo::META_EVENT)) : null;
        $groups = self::event_groups();
        $data   = [];
        foreach ($groups as $list) foreach ($list as $e) $data[$e->id] = self::event_fields($e);
        $f      = self::event_fields($linked);
        if (!$linked) $f['name'] = $p ? self::event_name_from($p->get_name()) : '';
        $pick   = $linked ? (string)$linked->id : 'new';
        $per    = $p ? max(1, (int)$p->get_meta(SNN_T_Woo::META_PER)) : 1;
        $ask    = $p && $p->get_meta(SNN_T_Woo::META_ASK) === 'yes';
        $labels = ['up' => __('Upcoming', 'snn-tickets'), 'none' => __('No date yet', 'snn-tickets'), 'past' => __('Past', 'snn-tickets')];
        ?>
        <div id="snn_tickets_data" class="panel woocommerce_options_panel hidden">
            <input type="hidden" name="snn_ev[for]" value="<?php echo esc_attr($pick); ?>" data-snn-for>
            <input type="hidden" name="snn_ev[end_date]" value="<?php echo esc_attr($f['end_date']); ?>" data-snn-f="end_date">
            <div class="options_group">
                <p class="form-field">
                    <label for="snn_ev_pick"><?php esc_html_e('Event', 'snn-tickets'); ?></label>
                    <select id="snn_ev_pick" name="<?php echo esc_attr(SNN_T_Woo::META_EVENT); ?>" class="select short">
                        <option value="new" <?php selected($pick, 'new'); ?>><?php esc_html_e('+ New event', 'snn-tickets'); ?></option>
                        <?php foreach ($groups as $g => $list): if (!$list) continue; ?>
                            <optgroup label="<?php echo esc_attr($labels[$g]); ?>">
                                <?php foreach ($list as $e): ?>
                                    <option value="<?php echo (int)$e->id; ?>" <?php selected($pick, (string)$e->id); ?>><?php echo esc_html($e->name . ($e->event_start ? ' · ' . SNN_T_Events::format_date($e) : '')); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <span class="description" data-snn-existing <?php echo $linked ? '' : 'hidden'; ?>><a href="<?php echo esc_url($f['url']); ?>" data-snn-link><?php esc_html_e('Open in Tickets', 'snn-tickets'); ?> →</a></span>
                </p>
                <p class="form-field" data-snn-new <?php echo $linked ? 'hidden' : ''; ?>>
                    <label for="snn_ev_name"><?php esc_html_e('Event name', 'snn-tickets'); ?></label>
                    <input type="text" class="short" id="snn_ev_name" name="snn_ev[name]" value="<?php echo esc_attr($f['name']); ?>" placeholder="<?php esc_attr_e('Taken from the product name', 'snn-tickets'); ?>" data-snn-f="name">
                </p>
                <p class="form-field">
                    <label for="snn_ev_date"><?php esc_html_e('Date', 'snn-tickets'); ?></label>
                    <input type="date" class="short" id="snn_ev_date" name="snn_ev[date]" value="<?php echo esc_attr($f['date']); ?>" data-snn-f="date">
                </p>
                <p class="form-field">
                    <label for="snn_ev_start"><?php esc_html_e('Time', 'snn-tickets'); ?></label>
                    <input type="time" id="snn_ev_start" name="snn_ev[start]" value="<?php echo esc_attr($f['start']); ?>" style="width:auto" data-snn-f="start" aria-label="<?php esc_attr_e('Starts', 'snn-tickets'); ?>">
                    <span style="float:left;margin:0 8px;line-height:30px">–</span>
                    <input type="time" name="snn_ev[end]" value="<?php echo esc_attr($f['end']); ?>" style="width:auto" data-snn-f="end" aria-label="<?php esc_attr_e('Ends', 'snn-tickets'); ?>">
                </p>
                <p class="form-field">
                    <label for="snn_ev_venue"><?php esc_html_e('Venue', 'snn-tickets'); ?></label>
                    <input type="text" class="short" id="snn_ev_venue" name="snn_ev[venue]" value="<?php echo esc_attr($f['venue']); ?>" placeholder="<?php esc_attr_e('Optional', 'snn-tickets'); ?>" data-snn-f="venue">
                </p>
                <p class="form-field">
                    <label for="snn_ev_spots"><?php esc_html_e('Spots', 'snn-tickets'); ?></label>
                    <input type="number" min="0" class="short" id="snn_ev_spots" name="snn_ev[spots]" value="<?php echo esc_attr($f['spots']); ?>" placeholder="<?php esc_attr_e('No limit', 'snn-tickets'); ?>" data-snn-f="spots">
                    <span class="description"><?php esc_html_e('For the whole event, every ticket type together.', 'snn-tickets'); ?></span>
                </p>
                <p class="form-field" data-snn-existing <?php echo $linked ? '' : 'hidden'; ?>>
                    <span class="description"><?php esc_html_e('Changes here update the event, for every ticket type it has.', 'snn-tickets'); ?></span>
                </p>
            </div>
            <div class="options_group">
                <details style="padding:0 12px 4px" <?php echo ($per > 1 || $ask) ? 'open' : ''; ?>>
                    <summary style="cursor:pointer;padding:10px 0;font-weight:600"><?php esc_html_e('More options', 'snn-tickets'); ?></summary>
                    <?php
                    woocommerce_wp_text_input([
                        'id'                => SNN_T_Woo::META_PER,
                        'label'             => __('People per purchase', 'snn-tickets'),
                        'type'              => 'number',
                        'value'             => $per,
                        'custom_attributes' => ['min' => 1, 'max' => 50, 'step' => 1],
                        'description'       => __('2 for a couples ticket, 4 for a family pass. Each person gets their own ticket.', 'snn-tickets'),
                    ]);
                    woocommerce_wp_checkbox([
                        'id'          => SNN_T_Woo::META_ASK,
                        'label'       => __('Guest details', 'snn-tickets'),
                        'value'       => $ask ? 'yes' : 'no',
                        'description' => __("Ask each guest's name and email (and the event's sign-up questions) when buying. Otherwise every ticket goes to the buyer.", 'snn-tickets'),
                    ]);
                    ?>
                </details>
            </div>
        </div>
        <script>
        jQuery(function($){
            var events = <?php echo wp_json_encode((object)$data); ?>;
            var panel = $('#snn_tickets_data'), box = $('#<?php echo esc_js(SNN_T_Woo::META_ON); ?>');
            var pick = $('#snn_ev_pick'), name = $('#snn_ev_name'), title = $('#title');
            function fromTitle(t){ t = String(t || '').trim(); var seps = [' – ', ' — ', ' - ', ' | ', ': ']; for (var i = 0; i < seps.length; i++) { var p = t.indexOf(seps[i]); if (p > 0) return t.slice(0, p).trim(); } return t; }
            // The name follows the product title until someone types their own.
            var named = name.val() !== '' && name.val() !== fromTitle(title.val());
            function sync(){
                var type = $('select#product-type').val();
                var on = box.is(':checked') && (type === 'simple' || type === 'variable');
                $('.snn_tickets_tab').toggle(on);
                if (!on && $('.snn_tickets_tab').hasClass('active')) $('.general_options > a').trigger('click');
            }
            function fill(){
                var id = pick.val(), e = events[id], isNew = !e;
                panel.find('[data-snn-new]').prop('hidden', !isNew);
                panel.find('[data-snn-existing]').prop('hidden', isNew);
                panel.find('[data-snn-for]').val(id);
                panel.find('[data-snn-f]').each(function(){
                    var k = $(this).data('snn-f');
                    if (k === 'name') { if (isNew && !named) $(this).val(fromTitle(title.val())); return; }
                    $(this).val(e ? (e[k] || '') : '');
                });
                if (e) panel.find('[data-snn-link]').attr('href', e.url);
            }
            box.on('change', function(){
                sync();
                // Straight to the tab that needs filling in.
                if (box.is(':checked')) $('.snn_tickets_tab a').trigger('click');
            });
            $(document.body).on('woocommerce-product-type-change', function(){ setTimeout(sync, 0); });
            pick.on('change', fill);
            name.on('input', function(){ named = name.val() !== ''; });
            title.on('input', function(){ if (pick.val() === 'new' && !named) name.val(fromTitle(title.val())); });
            sync();
        });
        </script>
        <?php
    }

    public static function save_product($product) {
        $on = isset($_POST[SNN_T_Woo::META_ON]) && $product->is_type(['simple', 'variable']);
        $product->update_meta_data(SNN_T_Woo::META_ON, $on ? 'yes' : 'no');

        $in = isset($_POST['snn_ev']) && is_array($_POST['snn_ev']) ? wp_unslash($_POST['snn_ev']) : null;
        if ($on && $in !== null) {
            $pick     = sanitize_key(wp_unslash($_POST[SNN_T_Woo::META_EVENT] ?? 'new'));
            $event_id = ($pick !== 'new' && SNN_T_Events::get((int)$pick)) ? (int)$pick : 0;
            $details  = array_merge(['venue' => $in['venue'] ?? ''], SNN_T_Events_Admin::datetimes($in));
            $spots    = max(0, (int)($in['spots'] ?? 0));
            // The fields belong to the event they were filled in for. Without
            // JavaScript, switching events leaves the old event's details in
            // them, and those must not overwrite the newly picked event.
            $fresh    = (string)($in['for'] ?? '') === ($event_id ? (string)$event_id : 'new');

            if (!$event_id) {
                $name = sanitize_text_field($in['name'] ?? '');
                if ($name === '') $name = self::event_name_from($product->get_name());
                $event_id = SNN_T_Events::create(array_merge(['name' => $name], $fresh ? $details : []));
                // Sign-ups start closed: the event page sells this product.
                if ($event_id) SNN_T_Forms::save(0, [
                    'name' => $name, 'list_id' => $event_id, 'status' => 'closed',
                    'fields' => SNN_T_Forms::default_fields(), 'settings' => ['max_tickets' => $fresh ? $spots : 0],
                ]);
            } elseif ($fresh) {
                SNN_T_Events::save($event_id, $details);
                self::save_spots($event_id, $spots);
            }
            if ($event_id) $product->update_meta_data(SNN_T_Woo::META_EVENT, $event_id);
        }

        if (isset($_POST[SNN_T_Woo::META_PER])) {
            $product->update_meta_data(SNN_T_Woo::META_PER, max(1, min(50, absint(wp_unslash($_POST[SNN_T_Woo::META_PER])))));
        }
        $product->update_meta_data(SNN_T_Woo::META_ASK, isset($_POST[SNN_T_Woo::META_ASK]) ? 'yes' : 'no');
        // Tickets never ship.
        if ($on && $product->is_type('simple')) $product->set_virtual(true);
    }

    /** Set the event-wide spot limit, which lives with its sign-up form. */
    public static function save_spots($event_id, $spots) {
        $form = SNN_T_Forms::for_list($event_id);
        if ($form && (int)$form->settings['max_tickets'] === (int)$spots) return;
        $event = SNN_T_Events::get($event_id);
        SNN_T_Forms::save($form ? $form->id : 0, [
            'name'     => $form ? $form->name : $event->name,
            'list_id'  => $event_id,
            'status'   => $form ? $form->status : 'closed',
            'fields'   => $form ? $form->fields : SNN_T_Forms::default_fields(),
            'settings' => array_merge($form ? $form->settings : [], ['max_tickets' => (int)$spots]),
        ]);
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
