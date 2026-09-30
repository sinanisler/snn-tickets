<?php
/**
 * WooCommerce integration tests: the real cart, checkout, order statuses,
 * refunds and admin edits, against a live site with WooCommerce active.
 *
 * Everything it makes (events, products, orders, users, tickets, queued
 * emails) is named "ZZ SNN Test" and deleted at the end, pass or fail.
 * No email leaves the site: wp_mail is short-circuited.
 *
 * Run from the site root:  studio wp eval-file wp-content/plugins/snn-tickets/tests/woo-integration-test.php
 */

if (!defined('ABSPATH')) { echo "Run with wp eval-file.\n"; return; }
if (!class_exists('SNN_T_Woo') || !SNN_T_Woo::active()) { echo "WooCommerce or SNN Tickets is not active.\n"; return; }

global $wpdb, $T;
$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'fails' => [], 'events' => [], 'products' => [], 'orders' => [], 'users' => []];

function t_check($cond, $label, $detail = '') {
    global $T;
    if ($cond) { $T['pass']++; return true; }
    $T['fail']++; $T['fails'][] = $label . ($detail !== '' ? " [$detail]" : '');
    echo "  FAIL: $label" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    return false;
}
function t_section($s) { echo "\n== $s ==\n"; }

add_filter('pre_wp_mail', '__return_false', 999);
remove_all_actions('snn_tickets_send_queue');

/* ------------------------------------------------------------------ helpers */

function t_event($name, $max = 0, $extra_fields = []) {
    global $T;
    $id = SNN_T_Events::create(['name' => 'ZZ SNN Test ' . $name, 'event_start' => '2027-05-01 19:00:00', 'venue' => 'Hall']);
    $fields = array_merge(SNN_T_Forms::default_fields(), $extra_fields);
    SNN_T_Forms::save(0, ['name' => 'ZZ form', 'list_id' => $id, 'fields' => $fields,
        'settings' => array_merge(SNN_T_Forms::default_settings(), ['max_tickets' => $max])]);
    $T['events'][] = $id;
    return $id;
}

function t_meta($p, $event, $opts) {
    $p->update_meta_data(SNN_T_Woo::META_ON, 'yes');
    $p->update_meta_data(SNN_T_Woo::META_EVENT, $event);
    $p->update_meta_data(SNN_T_Woo::META_PER, $opts['per'] ?? 1);
    $p->update_meta_data(SNN_T_Woo::META_ASK, !empty($opts['ask']) ? 'yes' : 'no');
    $p->update_meta_data(SNN_T_Woo::META_PASS, isset($opts['pass']) && !$opts['pass'] ? 'no' : 'yes');
}

function t_product($name, $event, $opts = []) {
    global $T;
    $p = new WC_Product_Simple();
    $p->set_name('ZZ SNN Test ' . $name);
    $p->set_regular_price((string)($opts['price'] ?? 10));
    $p->set_virtual(true);
    $p->set_status('publish');
    if (isset($opts['stock'])) { $p->set_manage_stock(true); $p->set_stock_quantity($opts['stock']); }
    t_meta($p, $event, $opts);
    $id = $p->save();
    $T['products'][] = $id;
    return $id;
}

/** @return array [parent id, [option => variation id]] */
function t_variable($name, $event, $options, $opts = []) {
    global $T;
    $attr = new WC_Product_Attribute();
    $attr->set_name('Tier');
    $attr->set_options($options);
    $attr->set_visible(true);
    $attr->set_variation(true);
    $p = new WC_Product_Variable();
    $p->set_name('ZZ SNN Test ' . $name);
    $p->set_attributes([$attr]);
    $p->set_status('publish');
    t_meta($p, $event, $opts);
    $pid = $p->save();
    $T['products'][] = $pid;
    $vars = [];
    foreach ($options as $i => $o) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($pid);
        $v->set_attributes(['tier' => $o]);
        $v->set_regular_price((string)(10 + 10 * $i));
        $v->set_virtual(true);
        $v->set_status('publish');
        $vars[$o] = $v->save();
        $T['products'][] = $vars[$o];
    }
    WC_Product_Variable::sync($pid);
    return [$pid, $vars];
}

function t_user($login) {
    global $T;
    $existing = get_user_by('login', $login);
    if ($existing) wp_delete_user($existing->ID);
    $id = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'customer',
        'first_name' => 'Zed', 'last_name' => 'Tester']);
    $T['users'][] = $id;
    return $id;
}

function t_fresh_cart($user_id = 0) {
    wp_set_current_user($user_id);
    if (!WC()->session) WC()->initialize_session();
    if (!WC()->cart) wc_load_cart();
    WC()->session->set('order_awaiting_payment', null);
    WC()->session->set('store_api_draft_order', null);
    WC()->cart->empty_cart();
    wc_clear_notices();
    $_POST = [];
}

/**
 * Add to cart as the product form does: the validation filter first, then
 * the cart. $post is what the form would post (attendees, gift choice).
 *
 * @return string|false cart item key
 */
function t_add($product_id, $qty = 1, $post = [], $variation_id = 0, $variation = []) {
    $_POST = $post;
    $ok = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $qty, $variation_id, $variation);
    $key = $ok ? WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation) : false;
    $_POST = [];
    return $key;
}

function t_errors() {
    $n = wc_get_notices('error');
    wc_clear_notices();
    return array_map(function ($e) { return is_array($e) ? $e['notice'] : $e; }, (array)$n);
}

function t_cart_errors() {
    wc_clear_notices();
    do_action('woocommerce_check_cart_items');
    return t_errors();
}

/** Place the order from the cart as the classic checkout does. */
function t_checkout($email = 'buyer@example.test', $first = 'Bea', $last = 'Buyer', $method = 'bacs') {
    global $T;
    WC()->cart->calculate_totals();
    $id = WC()->checkout()->create_order([
        'billing_first_name' => $first, 'billing_last_name' => $last, 'billing_email' => $email,
        'billing_country' => 'US', 'payment_method' => $method,
    ]);
    if (is_wp_error($id)) { t_check(false, 'checkout creates an order', $id->get_error_message()); return null; }
    $T['orders'][] = $id;
    $order = wc_get_order($id);
    $order->set_status('pending');
    $order->save();
    do_action('woocommerce_checkout_order_created', $order);
    WC()->cart->empty_cart();  // also forgets order_awaiting_payment
    WC()->session->set('order_awaiting_payment', $id);
    return wc_get_order($id);
}

function t_tickets($order_id, $status = null) {
    $all = SNN_T_Woo::order_tickets($order_id);
    return $status === null ? $all : array_values(array_filter($all, function ($t) use ($status) { return $t->status === $status; }));
}

function t_reload($order) { return wc_get_order(is_object($order) ? $order->get_id() : $order); }

function t_item($order, $product_id) {
    foreach (t_reload($order)->get_items() as $item) if ($item->get_product_id() === $product_id) return $item;
    return null;
}

function t_queue_for($ticket_id, $role = null) {
    global $wpdb;
    $sql = "SELECT * FROM " . SNN_T_DB::queue() . " WHERE ticket_id = %d" . ($role ? " AND role = %s" : '');
    return $wpdb->get_results($role ? $wpdb->prepare($sql, $ticket_id, $role) : $wpdb->prepare($sql, $ticket_id));
}

function t_queue_to($email, $role = null) {
    global $wpdb;
    $sql = "SELECT * FROM " . SNN_T_DB::queue() . " WHERE to_email = %s" . ($role ? " AND role = %s" : '');
    return $wpdb->get_results($role ? $wpdb->prepare($sql, $email, $role) : $wpdb->prepare($sql, $email));
}

function t_codes($tickets) { $c = array_map(function ($t) { return $t->ticket_code; }, $tickets); sort($c); return $c; }

/* ------------------------------------------------------------------ tests */

echo "SNN Tickets WooCommerce integration — WC " . WC()->version . ", HPOS " .
    (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off') . "\n";

try {

$ev   = t_event('Gala', 0);
$uid  = t_user('zz_snn_test_buyer');
$user = get_userdata($uid);

/* ---------------------------------------------------------------- */
t_section('1. One ticket, logged-in buyer, bank transfer');
$p1 = t_product('Gala Standard', $ev);
t_fresh_cart($uid);
$key = t_add($p1, 1);
t_check((bool)$key, 'ticket product goes into the cart');
t_check(!t_cart_errors(), 'cart check has no errors');
$o = t_checkout($user->user_email, 'Zed', 'Tester');
t_check($o && $o->get_customer_id() === $uid, 'order belongs to the logged-in customer', $o ? 'customer ' . $o->get_customer_id() : '');
t_check(in_array($o->get_id(), wc_get_orders(['customer_id' => $uid, 'return' => 'ids', 'limit' => -1]), true), 'order shows under the customer\'s account orders');
$item = t_item($o, $p1);
t_check((int)$item->get_meta(SNN_T_Woo::ITEM_EVENT) === $ev, 'line item remembers the event');
t_check((int)$item->get_meta(SNN_T_Woo::ITEM_PER) === 1, 'line item remembers tickets per unit');
t_check((int)t_reload($o)->get_meta(SNN_T_Woo::HOLD_PREFIX . $ev) === 1, 'pending order holds 1 spot');
t_check(!t_tickets($o->get_id()), 'no ticket before payment');
$o = t_reload($o); $o->update_status('on-hold');
t_check(!t_tickets($o->get_id()), 'on-hold (awaiting transfer) still has no ticket');
t_check(SNN_T_Woo::held($ev) === 1, 'on-hold order keeps holding its spot');
$o = t_reload($o); $o->update_status('processing');
$tk = t_tickets($o->get_id());
t_check(count($tk) === 1, 'processing issues exactly 1 ticket', count($tk) . ' tickets');
$t0 = $tk[0] ?? null;
t_check($t0 && $t0->status === 'active' && $t0->source === 'order', 'ticket is active, source order');
t_check($t0 && $t0->email === strtolower($user->user_email) && $t0->name === 'Zed Tester', 'ticket is in the buyer\'s name', $t0 ? "$t0->name <$t0->email>" : '');
t_check($t0 && (int)$t0->order_item_id === $item->get_id() && (int)$t0->product_id === $p1, 'ticket links to the order line and product');
t_check($t0 && !SNN_T_Claims::is_open($t0), 'buyer\'s own ticket is not waiting for a name');
t_check((int)(t_reload($o)->get_meta(SNN_T_Woo::ORDER_BUYER_TICKET)[$ev] ?? 0) === (int)$t0->id, 'order remembers the buyer\'s ticket for the event');
t_check(t_reload($o)->get_meta(SNN_T_Woo::HOLD_PREFIX . $ev) === '', 'hold cleared once paid');
t_check(SNN_T_Events::spots_taken($ev) === 1, 'spot counted once (ticket, not ticket + hold)', 'taken ' . SNN_T_Events::spots_taken($ev));
t_check(count(t_queue_for($t0->id, 'ticket')) === 1, 'buyer gets one ticket email');
$o = t_reload($o); $o->update_status('completed');
SNN_T_Woo::sync_order($o->get_id());
t_check(count(t_tickets($o->get_id())) === 1, 'completing and re-syncing makes no extra tickets');
t_check(count(t_queue_for($t0->id, 'ticket')) === 1, 'no duplicate ticket email on completion');
ob_start(); SNN_T_Woo::order_tickets_html(t_reload($o)); $html = ob_get_clean();
t_check(strpos($html, SNN_T_Router::ticket_url($t0->ticket_code)) !== false, 'My Account / thank-you order view links the ticket');
ob_start(); SNN_T_Woo::email_tickets(t_reload($o), false, false); $mail = ob_get_clean();
t_check(strpos($mail, SNN_T_Router::ticket_url($t0->ticket_code)) !== false, 'WooCommerce customer email lists the ticket');
ob_start(); SNN_T_Woo::email_tickets(t_reload($o), true, false); $admin_mail = ob_get_clean();
t_check($admin_mail === '', 'admin order email does not get the buyer\'s ticket links');
$order1 = $o;

/* ---------------------------------------------------------------- */
t_section('2. Several tickets: one for the buyer, the rest to pass on');
$p2 = t_product('Gala Group', $ev);
t_fresh_cart();
t_add($p2, 3);
$o = t_checkout('group@example.test', 'Gus', 'Group');
t_check($o->get_customer_id() === 0, 'guest checkout has no customer');
$o->payment_complete();
$tk = t_tickets($o->get_id());
t_check(count($tk) === 3, '3 units = 3 tickets', count($tk));
$own = array_values(array_filter($tk, function ($t) { return !SNN_T_Claims::is_open($t); }));
$open = array_values(array_filter($tk, ['SNN_T_Claims', 'is_open']));
t_check(count($own) === 1 && $own[0]->name === 'Gus Group', 'exactly one named for the buyer');
t_check(count($open) === 2, 'two wait for a name, with a link');
t_check((int)t_reload($o)->get_meta(SNN_T_Woo::ORDER_UNNAMED) === 2, 'order shows 2 unnamed');
t_check(count(t_queue_to('group@example.test', 'order')) === 1, 'buyer gets one "your tickets" order email');
t_check(!t_queue_to('group@example.test', 'ticket'), 'and not a separate plain ticket email');
// pass one on, claim it, take one back
$r = SNN_T_Claims::send(SNN_T_Tickets::get($open[0]->id), 'friend@example.test', 'Gus');
t_check($r === true && SNN_T_Tickets::get($open[0]->id)->holder === SNN_T_Claims::SENT, 'sending a link marks the ticket sent');
t_check(count(t_queue_to('friend@example.test', 'gift')) === 1, 'friend gets the gift email');
$sent = SNN_T_Tickets::get($open[0]->id);
$old_code = $sent->ticket_code;
$claimed = SNN_T_Claims::claim($sent, 'Fran Friend', 'friend@example.test', ['name' => 'Fran Friend', 'email' => 'friend@example.test'], SNN_T_Forms::for_list($ev));
t_check(!is_wp_error($claimed) && $claimed->name === 'Fran Friend' && $claimed->ticket_code !== $old_code, 'claim names the ticket and changes its code');
t_check((int)t_reload($o)->get_meta(SNN_T_Woo::ORDER_UNNAMED) === 1, 'unnamed count drops to 1 after the claim');
t_check(is_wp_error(SNN_T_Claims::claim(SNN_T_Tickets::get($open[0]->id), 'X', 'x@example.test')), 'a claimed ticket cannot be claimed again');
SNN_T_Claims::send(SNN_T_Tickets::get($open[1]->id), 'wrong@example.test');
$k1 = SNN_T_Tickets::get($open[1]->id)->claim_key;
SNN_T_Claims::take_back(SNN_T_Tickets::get($open[1]->id));
t_check(SNN_T_Claims::by_key($k1) === null, 'taking a link back kills the old link');
// give own ticket away
t_check(SNN_T_Woo::can_give_away(t_reload($o), SNN_T_Tickets::get($own[0]->id)), 'buyer can give their own unused ticket away');
SNN_T_Woo::give_away(t_reload($o), SNN_T_Tickets::get($own[0]->id));
t_check(SNN_T_Claims::is_open(SNN_T_Tickets::get($own[0]->id)), 'given-away ticket now waits for a name');
t_check(!SNN_T_Woo::can_give_away(t_reload($o), SNN_T_Tickets::get($claimed->id)), 'a guest\'s ticket cannot be given away by the buyer');
SNN_T_Woo::sync_order($o->get_id());
t_check(count(t_tickets($o->get_id(), 'active')) === 3, 'resync after claims keeps 3 tickets');
$order2 = $o;

/* ---------------------------------------------------------------- */
t_section('3. Tickets that admit two (per unit = 2)');
$p3 = t_product('Gala Couples', $ev, ['per' => 2]);
t_fresh_cart();
t_add($p3, 2);
$o = t_checkout('couple@example.test', 'Cam', 'Couple');
t_check((int)t_reload($o)->get_meta(SNN_T_Woo::HOLD_PREFIX . $ev) === 4, 'hold counts 2 units × 2 = 4 spots');
$o->payment_complete();
t_check(count(t_tickets($o->get_id())) === 4, '2 units × 2 per unit = 4 tickets', count(t_tickets($o->get_id())));

/* ---------------------------------------------------------------- */
t_section('4. Variable product: tiers as variations');
list($pv, $vars) = t_variable('Gala Tiers', $ev, ['Standard', 'VIP']);
t_check(SNN_T_Woo::is_ticket(wc_get_product($vars['VIP'])), 'variation is a ticket through its parent');
t_fresh_cart();
t_add($pv, 1, [], $vars['Standard'], ['attribute_tier' => 'Standard']);
t_add($pv, 2, [], $vars['VIP'], ['attribute_tier' => 'VIP']);
t_check(count(WC()->cart->get_cart()) === 2, 'two tiers are two cart lines');
$o = t_checkout('tiers@example.test', 'Tia', 'Tiers');
$o->payment_complete();
$tk = t_tickets($o->get_id());
t_check(count($tk) === 3, '1 Standard + 2 VIP = 3 tickets', count($tk));
t_check(count(array_filter($tk, function ($t) use ($pv) { return (int)$t->product_id === $pv; })) === 3, 'tickets store the parent product id');
t_check(count(array_filter($tk, function ($t) { return !SNN_T_Claims::is_open($t); })) === 1, 'across two lines of one event the buyer still gets one ticket');

/* ---------------------------------------------------------------- */
t_section('5. Named attendees at purchase');
$ev_n = t_event('Workshop', 0, [['key' => 'diet', 'type' => 'select', 'label' => 'Diet', 'required' => 1, 'options' => ['Any', 'Vegan'], 'map_to' => '']]);
$pa = t_product('Workshop Seat', $ev_n, ['ask' => true]);
t_fresh_cart();
t_check(t_add($pa, 1) === false, 'attendee product rejected without the attendee form (e.g. shop loop / URL add)');
t_check((bool)t_errors(), 'with an error message');
t_check(t_add($pa, 2, ['snn_att_on' => 1, 'snn_att' => [['name' => 'Ann', 'email' => 'ann@example.test', 'diet' => 'Vegan']]]) === false, 'rejected when a second attendee is missing');
t_errors();
t_check(t_add($pa, 1, ['snn_att_on' => 1, 'snn_att' => [['name' => 'Ann', 'email' => 'ann@example.test', 'diet' => 'Meat']]]) === false, 'rejected when an answer is not an allowed choice');
t_errors();
$k = t_add($pa, 2, ['snn_att_on' => 1, 'snn_att' => [
    ['name' => 'Ann', 'email' => 'ANN@example.test', 'diet' => 'Vegan'],
    ['name' => 'Bob', 'email' => 'bob@example.test', 'diet' => 'Any'],
]]);
t_check((bool)$k, 'accepted with both attendees');
t_check(count(WC()->cart->get_cart_item($k)['snn_attendees'] ?? []) === 2, 'cart line carries both attendees');
$html = apply_filters('woocommerce_cart_item_quantity', '<input>', $k, WC()->cart->get_cart_item($k));
t_check(strpos($html, 'type="hidden"') !== false, 'quantity is locked in the cart');
WC()->cart->set_quantity($k, 3);
t_check((bool)t_cart_errors(), 'cart check catches a quantity changed past the names anyway');
WC()->cart->set_quantity($k, 2);
t_check(!t_cart_errors(), 'back to 2: cart is fine');
$o = t_checkout('payer@example.test', 'Pat', 'Payer');
$o->payment_complete();
$tk = t_tickets($o->get_id());
t_check(count($tk) === 2, '2 named tickets');
t_check(isset($tk[0], $tk[1]) && $tk[0]->name === 'Ann' && $tk[0]->email === 'ann@example.test' && $tk[1]->name === 'Bob', 'tickets carry the attendee names and lower-cased emails', isset($tk[0]) ? $tk[0]->email : '');
t_check(!array_filter($tk, ['SNN_T_Claims', 'is_open']), 'named tickets are not waiting for names');
$sub = isset($tk[0]) && $tk[0]->submission_id ? SNN_T_Submissions::get((int)$tk[0]->submission_id) : null;
t_check($sub && ($sub->data['diet'] ?? '') === 'Vegan', 'answers stored on a sign-up record');
t_check(count(t_queue_for($tk[0]->id, 'ticket')) === 1 && count(t_queue_for($tk[1]->id, 'ticket')) === 1, 'each attendee gets their own ticket email');
t_check(!t_queue_to('payer@example.test'), 'the payer (not attending) is not sent tickets of their own');

/* ---------------------------------------------------------------- */
t_section('6. Gifts');
$pg = t_product('Gala Gift', $ev);
t_fresh_cart();
t_check(t_add($pg, 1, ['snn_for' => 'gift', 'snn_gift_email' => 'not-an-email']) === false, 'gift with a bad email is rejected');
t_errors();
$kg = t_add($pg, 2, ['snn_for' => 'gift', 'snn_gift_email' => 'Gifted@Example.test']);
$km = t_add($pg, 1, ['snn_for' => 'me']);
t_check($kg && $km && $kg !== $km, 'gift and own tickets are separate cart lines');
t_check((WC()->cart->get_cart_item($kg)['snn_gift'] ?? '') === 'gifted@example.test', 'gift email kept, lower-cased');
$o = t_checkout('giver@example.test', 'Gil', 'Giver');
$o->payment_complete();
$gi = t_item($o, $pg);
$lines = array_values(t_reload($o)->get_items());
$gift_line = null; $me_line = null;
foreach ($lines as $l) { if ($l->get_meta(SNN_T_Woo::ITEM_GIFT) === 'yes') $gift_line = $l; else $me_line = $l; }
$gt = $gift_line ? SNN_T_Woo::item_tickets($gift_line->get_id()) : [];
$mt = $me_line ? SNN_T_Woo::item_tickets($me_line->get_id()) : [];
t_check(count($gt) === 2 && count(array_filter($gt, function ($t) { return $t->holder === SNN_T_Claims::SENT && $t->claim_email === 'gifted@example.test'; })) === 2, 'both gift tickets sent to the gift address');
t_check(count(t_queue_to('gifted@example.test', 'gift')) === 1, 'the gift address gets one email with both links');
t_check($gift_line && $gift_line->get_meta(SNN_T_Woo::ITEM_GIFT_SENT) === 'yes', 'gift marked sent');
t_check(count($mt) === 1 && !SNN_T_Claims::is_open($mt[0]) && $mt[0]->email === 'giver@example.test', 'the "for me" line still gives the buyer their ticket');
SNN_T_Woo::sync_order($o->get_id());
t_check(count(t_queue_to('gifted@example.test', 'gift')) === 1, 'resync does not resend the gift email');
t_fresh_cart();
t_add($pg, 1, ['snn_for' => 'gift', 'snn_gift_email' => '']);
$o = t_checkout('giver2@example.test', 'Gia', 'Giver');
$o->payment_complete();
$gt = t_tickets($o->get_id());
t_check(count($gt) === 1 && $gt[0]->holder === SNN_T_Claims::OPEN, 'gift without an address: one open link for the buyer to share');

/* ---------------------------------------------------------------- */
t_section('7. Tickets that cannot be passed on');
$pn = t_product('Gala Personal', $ev, ['pass' => false]);
t_fresh_cart();
t_add($pn, 1, ['snn_for' => 'gift', 'snn_gift_email' => 'x@example.test']);
t_check(empty(array_values(WC()->cart->get_cart())[0]['snn_gift']), 'gift choice ignored for a non-transferable ticket');
WC()->cart->empty_cart();
t_add($pn, 3);
$o = t_checkout('solo@example.test', 'Sol', 'Solo');
$o->payment_complete();
$tk = t_tickets($o->get_id());
t_check(count($tk) === 3 && !array_filter($tk, ['SNN_T_Claims', 'is_open']), 'all 3 are named for the buyer, none open');
t_check(!SNN_T_Woo::can_give_away(t_reload($o), $tk[0]), 'and cannot be given away');

/* ---------------------------------------------------------------- */
t_section('8. Two events in one order');
$ev2 = t_event('Concert', 0);
$pc = t_product('Concert Entry', $ev2);
t_fresh_cart();
t_add($p1, 1);
t_add($pc, 1);
$o = t_checkout('both@example.test', 'Bo', 'Both');
$o->payment_complete();
$tk = t_tickets($o->get_id());
t_check(count($tk) === 2, 'one ticket per event');
$by_ev = [];
foreach ($tk as $t) $by_ev[(int)$t->list_id] = $t;
t_check(isset($by_ev[$ev]) && !SNN_T_Claims::is_open($by_ev[$ev]), 'first event: ticket is the buyer\'s');
t_check(isset($by_ev[$ev2]) && !SNN_T_Claims::is_open($by_ev[$ev2]), 'second event: ticket is the buyer\'s too (not left waiting for a name)',
    isset($by_ev[$ev2]) ? 'holder=' . $by_ev[$ev2]->holder . ' name="' . $by_ev[$ev2]->name . '"' : '');

/* ---------------------------------------------------------------- */
t_section('9. Capacity, holds and hold expiry');
$ev_c = t_event('Small Room', 3);
$ps = t_product('Small Room Seat', $ev_c);
$pd = t_product('Small Room Duo', $ev_c, ['per' => 2]);
t_fresh_cart();
t_check((bool)t_add($ps, 2), '2 of 3 into the cart');
t_check(t_add($ps, 2) === false, 'adding 2 more (4 > 3) is refused');
t_errors();
t_check(t_add($pd, 1) === false, 'a duo (2 spots) is refused when only 1 is left');
t_errors();
$args = apply_filters('woocommerce_quantity_input_args', ['max_value' => -1], wc_get_product($ps));
t_check((int)$args['max_value'] === 3, 'quantity box stops at the spots left', 'max ' . $args['max_value']);
$oa = t_checkout('holder@example.test');
t_check(SNN_T_Woo::held($ev_c) === 2, 'pending order holds 2');
t_check(SNN_T_Woo::spots_left($ev_c) === 3, 'the buyer paying for it still sees their own spots');
t_fresh_cart();  // another visitor
t_check(SNN_T_Woo::spots_left($ev_c) === 1, 'another visitor sees 1 left');
t_check(t_add($ps, 2) === false, 'and cannot take 2');
t_errors();
t_check((bool)t_add($ps, 1), 'but can take the last one');
$ob = t_checkout('last@example.test');
t_fresh_cart();
t_check(SNN_T_Woo::spots_left($ev_c) === 0, 'event now full for everyone else');
t_check(t_add($ps, 1) === false, 'full event refuses more');
t_errors();
// the first pending order is abandoned past the hold window
$old = t_reload($oa); $old->set_date_created(time() - (SNN_T_Woo::hold_minutes() + 5) * 60); $old->save();
t_check(SNN_T_Woo::spots_left($ev_c) === 2, 'an abandoned pending order releases its spots after the hold time');
t_check((bool)t_add($ps, 2), 'so they can be sold again');
$oc = t_checkout('late@example.test');
$oc->payment_complete();
// the abandoned order is paid after all: oversold?
$old = t_reload($oa); $old->payment_complete();
$active = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND status = 'active'", $ev_c));
echo "  note: paying an expired hold late gives the event $active active tickets for 3 spots (WooCommerce stock behaves the same way)\n";
// cart check at checkout after spots disappear
t_fresh_cart();
$ev_r = t_event('Race', 1);
$pr = t_product('Race Seat', $ev_r);
t_add($pr, 1);
$orace = SNN_T_Tickets::insert($ev_r, 'Walk In', 'walkin@example.test');
t_check((bool)t_cart_errors(), 'checkout check catches a spot taken while the item sat in the cart');
WC()->cart->empty_cart();
// failed / cancelled pending orders
t_fresh_cart();
$ev_f = t_event('Failing', 2);
$pf = t_product('Failing Seat', $ev_f);
t_add($pf, 2);
$of = t_checkout('fail@example.test');
t_fresh_cart();
t_check(SNN_T_Woo::spots_left($ev_f) === 0, 'pending order holds both');
$of = t_reload($of); $of->update_status('failed');
t_check(SNN_T_Woo::spots_left($ev_f) === 2, 'failed payment frees the spots');
t_check(t_reload($of)->get_meta(SNN_T_Woo::HOLD_PREFIX . $ev_f) === '', 'failed order\'s hold meta cleared');

/* ---------------------------------------------------------------- */
t_section('10. Refunds, cancellation, re-payment');
t_fresh_cart();
$pp = t_product('Gala Refundable', $ev, ['price' => 20]);
t_add($pp, 3);
$o = t_checkout('refund@example.test', 'Rey', 'Refund');
$o->payment_complete();
$before = t_tickets($o->get_id());
t_check(count($before) === 3, '3 tickets issued');
// the oldest is used at the door
SNN_T_Tickets::validate($before[0]->ticket_code, '', true);
$ri = t_item($o, $pp);
$ref = wc_create_refund(['order_id' => $o->get_id(), 'amount' => 20, 'line_items' => [$ri->get_id() => ['qty' => 1, 'refund_total' => 20]]]);
t_check(!is_wp_error($ref), 'partial refund of 1 unit created', is_wp_error($ref) ? $ref->get_error_message() : '');
$after = t_tickets($o->get_id(), 'active');
t_check(count($after) === 2, 'refunding 1 unit cancels 1 ticket', count($after) . ' active');
t_check(in_array($before[0]->id, array_column($after, 'id')), 'the ticket already used at the door is kept');
t_check(!in_array($before[2]->id, array_column($after, 'id')), 'the newest unused ticket is the one cancelled');
t_check(t_reload($o)->has_status('processing'), 'order stays processing after a partial refund');
$ref2 = wc_create_refund(['order_id' => $o->get_id(), 'amount' => 40, 'line_items' => [$ri->get_id() => ['qty' => 2, 'refund_total' => 40]]]);
t_check(t_reload($o)->has_status('refunded'), 'order is fully refunded');
t_check(count(t_tickets($o->get_id(), 'active')) === 0, 'full refund cancels every ticket');
t_check(count(t_tickets($o->get_id())) === 3, 'no tickets created or deleted by refunds');
t_check(SNN_T_Tickets::validate($before[1]->ticket_code, '', true)['reason'] === 'revoked', 'a refunded ticket is refused at the door');

t_fresh_cart();
t_add($pp, 2);
$o = t_checkout('cancel@example.test', 'Cy', 'Cancel');
$o->payment_complete();
$codes = t_codes(t_tickets($o->get_id()));
$o = t_reload($o); $o->update_status('cancelled');
t_check(count(t_tickets($o->get_id(), 'active')) === 0, 'cancelling a paid order cancels its tickets');
$o = t_reload($o); $o->update_status('processing');
t_check(count(t_tickets($o->get_id(), 'active')) === 2, 're-opening it brings them back');
t_check(t_codes(t_tickets($o->get_id())) === $codes && count(t_tickets($o->get_id())) === 2, 'same codes come back, no new tickets');
$o = t_reload($o); $o->update_status('pending');
t_check(count(t_tickets($o->get_id(), 'active')) === 0, 'moving a paid order back to pending payment cancels tickets');
$o = t_reload($o); $o->update_status('completed');
t_check(count(t_tickets($o->get_id(), 'active')) === 2, 'completing it restores them');

/* ---------------------------------------------------------------- */
t_section('11. Staff changes and admin edits');
$tk = t_tickets($o->get_id());
SNN_T_Tickets::set_status($tk[0]->id, 'revoked');  // cancelled by hand in People
SNN_T_Woo::sync_order($o->get_id());
t_check(SNN_T_Tickets::get($tk[0]->id)->status === 'revoked', 'a ticket cancelled by hand stays cancelled on resync');
t_check(count(t_tickets($o->get_id())) === 2, 'and no replacement ticket is issued for it');
SNN_T_Tickets::delete_ticket($tk[1]->id);
SNN_T_Woo::sync_order($o->get_id());
t_check(count(t_tickets($o->get_id())) === 1, 'a deleted ticket is not re-issued');

t_fresh_cart();
t_add($pp, 1);
$o = t_checkout('edit@example.test', 'Ed', 'Edit');
$o->payment_complete();
$ei = t_item($o, $pp);
$ei->set_quantity(3); $ei->save();
do_action('woocommerce_saved_order_items', $o->get_id(), []);
t_check(count(t_tickets($o->get_id(), 'active')) === 3, 'raising a paid line\'s quantity in the admin issues the extra tickets');
$ei = t_item($o, $pp); $ei->set_quantity(1); $ei->save();
do_action('woocommerce_saved_order_items', $o->get_id(), []);
t_check(count(t_tickets($o->get_id(), 'active')) === 1, 'lowering it cancels the extras');
$o = t_reload($o);
$new_item = new WC_Order_Item_Product();
$new_item->set_product(wc_get_product($p2)); $new_item->set_quantity(2);
$o->add_item($new_item); $o->save();
do_action('woocommerce_saved_order_items', $o->get_id(), []);
t_check(count(t_tickets($o->get_id(), 'active')) === 3, 'a ticket line added in the admin (no checkout meta) issues tickets');
wc_delete_order_item($new_item->get_id());
t_check(count(t_tickets($o->get_id(), 'active')) === 1, 'removing a line in the admin cancels its tickets');

// order made by hand in the admin
$o = wc_create_order(['customer_id' => $uid]);
$T['orders'][] = $o->get_id();
$o->add_product(wc_get_product($p1), 2);
$o->set_billing_email('manual@example.test'); $o->set_billing_first_name('Man');
$o->calculate_totals(); $o->save();
$o->update_status('processing');
t_check(count(t_tickets($o->get_id())) === 2, 'an admin-made order issues tickets when set to processing');

// trash and restore
$ot = t_reload($order1);
wc_get_order($ot->get_id())->delete(false);
t_check(count(t_tickets($ot->get_id(), 'active')) === 0, 'trashing an order cancels its tickets');
if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
    wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class)->untrash_order(wc_get_order($ot->get_id()));
} else {
    wp_untrash_post($ot->get_id());
}
t_check(count(t_tickets($ot->get_id(), 'active')) === 1, 'restoring it from the trash brings the ticket back', 'status ' . wc_get_order($ot->get_id())->get_status());

// product moved to another event after the sale
$pm = t_product('Gala Mover', $ev);
t_fresh_cart();
t_add($pm, 1);
$o = t_checkout('mover@example.test');
$prod = wc_get_product($pm); $prod->update_meta_data(SNN_T_Woo::META_EVENT, $ev2); $prod->save();
$o->payment_complete();
t_check((int)(t_tickets($o->get_id())[0]->list_id ?? 0) === $ev, 'moving the product to another event does not move a placed order');

/* ---------------------------------------------------------------- */
t_section('12. Event deleted');
$ev_d = t_event('Doomed', 0);
$pdm = t_product('Doomed Seat', $ev_d);
t_fresh_cart();
t_add($pdm, 1);
SNN_T_Events::delete($ev_d);
t_check(!wc_get_product($pdm)->is_purchasable(), 'a deleted event\'s product can no longer be bought (WooCommerce drops it from carts)');
t_check(wc_get_product($pdm)->get_status() === 'draft', 'the deleted event\'s products are unpublished');

/* ---------------------------------------------------------------- */
t_section('13. Buyer manage page access');
$o2 = t_reload($order2);
t_check(strpos(SNN_T_Woo::manage_url($o2), $o2->get_order_key()) !== false, 'manage link carries the order key');

/* ---------------------------------------------------------------- */
t_section('14. Block cart (Store API)');
add_filter('woocommerce_store_api_disable_nonce_check', '__return_true');
t_fresh_cart();
$req = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
$req->set_body_params(['id' => $pa, 'quantity' => 1]);
$res = rest_do_request($req);
t_check($res->is_error() || $res->get_status() >= 400, 'Store API refuses an attendee ticket without names', 'HTTP ' . $res->get_status());
$ev_s = t_event('Store API', 2);
$psa = t_product('Store API Seat', $ev_s);
$req = new WP_REST_Request('POST', '/wc/store/v1/cart/add-item');
$req->set_body_params(['id' => $psa, 'quantity' => 3]);
$res = rest_do_request($req);
t_check($res->is_error() || $res->get_status() >= 400, 'Store API refuses more than the spots left', 'HTTP ' . $res->get_status());
remove_filter('woocommerce_store_api_disable_nonce_check', '__return_true');

} catch (Throwable $e) {
    t_check(false, 'test run crashed', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    /* ------------------------------------------------------------ cleanup */
    WC()->cart && WC()->cart->empty_cart();
    foreach (array_unique($T['orders']) as $id) {
        foreach (SNN_T_Woo::order_tickets($id) as $t) SNN_T_Tickets::delete_ticket((int)$t->id);
        if ($o = wc_get_order($id)) $o->delete(true);
        delete_option('snn_tickets_order_lock_' . $id);
    }
    foreach (array_reverse(array_unique($T['products'])) as $id) if ($p = wc_get_product($id)) $p->delete(true);
    foreach (array_unique($T['events']) as $id) if (SNN_T_Events::get($id)) SNN_T_Events::delete($id);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($T['users'] as $id) wp_delete_user($id);
    $wpdb->query("DELETE FROM " . SNN_T_DB::queue() . " WHERE to_email LIKE '%@example.test'");
    // Sweep anything an interrupted earlier run left behind.
    foreach ((array)$wpdb->get_col("SELECT id FROM " . SNN_T_DB::lists() . " WHERE name LIKE 'ZZ SNN Test%'") as $id) {
        foreach ((array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT order_id FROM " . SNN_T_DB::tickets() . " WHERE list_id = %d AND order_id > 0", $id)) as $oid) {
            if ($o = wc_get_order($oid)) $o->delete(true);
        }
        SNN_T_Events::delete((int)$id);
    }
    foreach (wc_get_orders(['limit' => -1, 's' => '@example.test', 'return' => 'ids']) as $oid) {
        $o = wc_get_order($oid);
        if ($o && substr((string)$o->get_billing_email(), -13) === '@example.test') $o->delete(true);
    }
    foreach (wc_get_products(['limit' => -1, 'status' => 'any', 's' => 'ZZ SNN Test', 'type' => ['simple', 'variable']]) as $p) $p->delete(true);
    if ($u = get_user_by('login', 'zz_snn_test_buyer')) wp_delete_user($u->ID);
    $wpdb->query("DELETE FROM " . SNN_T_DB::tickets() . " WHERE email LIKE '%@example.test' AND source = 'order'");
    remove_action('shutdown', ['SNN_T_Mailer', 'spawn']);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Passed: {$T['pass']}   Failed: {$T['fail']}\n";
if ($T['fails']) { echo "\nFailures:\n"; foreach ($T['fails'] as $f) echo "  - $f\n"; }
