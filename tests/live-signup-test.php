<?php
/**
 * Sign-up limits with Sell Placement codes, tested on a live WordPress site.
 *
 * Covers:
 *  - Every Sell Placement status, and the ways a code is typed.
 *  - Invites: a code with N invites takes N sign-ups, then the form stops for it.
 *  - Invites counted from the database: pending holds one, declined and deleted give it back.
 *  - Approval by hand, one ticket per email, a code gate that is off, a code suspended
 *    after one sign-up, and two codes that do not share invites.
 *  - Three people at the same moment for one code.
 *
 * The public part goes over HTTP (the event page, the form, admin-post.php and the
 * redirects), the way a visitor's browser does. Counts are read from the site's
 * database through WordPress.
 *
 * Everything it creates is named "ZZ SNN Test" or uses the .invalid mail domain, and
 * is deleted afterwards, pass or fail. The sign-up throttle (10 per 10 minutes per
 * address) is cleared before each sign-up so the test can make more than ten.
 *
 * Run:  php tests/live-signup-test.php
 * Optional environment:
 *   SNN_LIVE_WP   WordPress root           (default D:/WWW/LOCAL_SITES/woo.local)
 *   SNN_LIVE_URL  site address             (default http://woo.local)
 *   SNN_LIVE_IP   where the site answers   (default 127.0.0.1)
 */

$wp_root = rtrim(getenv('SNN_LIVE_WP') ?: 'D:/WWW/LOCAL_SITES/woo.local', '/\\');
$site    = rtrim(getenv('SNN_LIVE_URL') ?: 'http://woo.local', '/');
$ip      = getenv('SNN_LIVE_IP') ?: '127.0.0.1';

if (!is_file($wp_root . '/wp-load.php')) {
    echo "WordPress not found at {$wp_root}\n";
    exit(2);
}

$site_host = parse_url($site, PHP_URL_HOST);
$_SERVER['HTTP_HOST']      = $site_host;
$_SERVER['SERVER_NAME']    = $site_host;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
define('WP_USE_THEMES', false);
require $wp_root . '/wp-load.php';

if (!class_exists('SNN_T_Forms') || !function_exists('spl_insert_placement') || !SNN_T_Placements::available()) {
    echo "Both plugins must be active on this site: SNN Tickets and Sell Placement.\n";
    exit(2);
}

add_filter('pre_wp_mail', '__return_false', 999);

$T     = ['pass' => 0, 'fail' => 0, 'fails' => []];
$made  = ['lists' => [], 'placements' => []];
$msg   = SNN_T_Forms::default_settings();
$two_auto = ['require_placement_code' => 1, 'invites_per_code' => 2, 'approval_mode' => 'auto'];

/* ------------------------------------------------------------------ output */

function t_check($cond, $label, $detail = '') {
    global $T;
    if ($cond) {
        $T['pass']++;
        echo "  ok    {$label}\n";
        return true;
    }
    $T['fail']++;
    $T['fails'][] = $label . ($detail !== '' ? " [{$detail}]" : '');
    echo "  FAIL  {$label}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
    return false;
}

function t_section($s) {
    echo "\n== {$s} ==\n";
}

/* ------------------------------------------------------------------ fixtures */

function test_email($tag) {
    return 'ticket-' . $tag . '-' . uniqid() . '@tickets-test.invalid';
}

function left_label($n) {
    return sprintf(_n('%d ticket left', '%d tickets left', $n, 'snn-tickets'), $n);
}

/** An event with its sign-up form, named so the cleanup can find it. */
function fixture_event($name, $settings) {
    global $made;
    $list_id = SNN_T_Events::create([
        'name'        => 'ZZ SNN Test ' . $name,
        'event_start' => '2027-05-01 19:00:00',
        'venue'       => 'Test hall',
    ]);
    $made['lists'][] = $list_id;
    SNN_T_Forms::save(0, [
        'name'     => 'ZZ form ' . $name,
        'list_id'  => $list_id,
        'fields'   => SNN_T_Forms::default_fields(),
        'settings' => array_merge(SNN_T_Forms::default_settings(), $settings),
    ]);
    return $list_id;
}

/** A real Sell Placement record in the given status, with a generated code. */
function fixture_placement($status) {
    global $made;
    $id = spl_insert_placement([
        'status'      => $status,
        'buyer_name'  => 'ZZ SNN Test',
        'buyer_email' => 'buyer-' . uniqid() . '@tickets-test.invalid',
    ]);
    $made['placements'][] = $id;
    return spl_get_placement($id);
}

function cleanup_fixtures() {
    global $wpdb, $made;
    foreach ($made['lists'] as $list_id) {
        SNN_T_Events::delete($list_id);
    }
    foreach ($made['placements'] as $id) {
        $wpdb->delete(spl_table(), ['id' => (int)$id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'spl_log', ['placement_id' => (int)$id], ['%d']);
    }
    clear_rate_limit();
}
register_shutdown_function('cleanup_fixtures');

function clear_rate_limit() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_snn\\_t\\_sub\\_%' OR option_name LIKE '\\_transient\\_timeout\\_snn\\_t\\_sub\\_%'");
}

/* ------------------------------------------------------------------ HTTP as a visitor */

/** Send several requests at once. Each entry is [method, url, post-array|null]. */
function http_many(array $requests) {
    global $site, $ip;
    $host = parse_url($site, PHP_URL_HOST);
    $port = parse_url($site, PHP_URL_PORT) ?: 80;

    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $req) {
        [$method, $url, $post] = $req;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
            CURLOPT_USERAGENT      => 'snn-tickets-live-test',
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        $raw  = (string)curl_multi_getcontent($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $size);
        preg_match('/^Location:\s*(.+?)\r?$/mi', $headers, $m);
        $out[$i] = [
            'status'   => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'location' => $m[1] ?? '',
            'body'     => (string)substr($raw, $size),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function http_get($url) {
    return http_many([['GET', $url, null]])[0];
}

/** The text of the first notice on the page, '' when there is none. */
function notice_text($html) {
    $xp = page_xpath($html);
    $n  = $xp ? $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " snn-form-notice ")]') : null;
    return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
}

function left_text($html) {
    $xp = page_xpath($html);
    $n  = $xp ? $xp->query('//p[contains(concat(" ", normalize-space(@class), " "), " snn-form-remaining ")]') : null;
    return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
}

function page_xpath($html) {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

/** The sign-up form's fields, or null when the page shows no form. */
function form_fields($html) {
    $xp = page_xpath($html);
    $forms = $xp->query('//form[@data-snn-form]');
    if ($forms->length === 0) return null;

    $out = ['fields' => [], 'email' => []];
    foreach ($xp->query('.//input', $forms->item(0)) as $input) {
        $name = $input->getAttribute('name');
        if ($name === '') continue;
        $type = strtolower($input->getAttribute('type') ?: 'text');
        if ($type === 'checkbox' || $type === 'radio') continue;
        $out['fields'][$name] = $input->getAttribute('value');
        if ($type === 'email') $out['email'][] = $name;
    }
    return $out;
}

/** Open the event page, with ?code= when a code is given. */
function open_form($url, $code) {
    $target = $code === null ? $url : add_query_arg('code', $code, $url);
    $res = http_get($target);
    return [
        'code'   => $code,
        'form'   => form_fields($res['body']),
        'notice' => notice_text($res['body']),
        'left'   => left_text($res['body']),
    ];
}

/**
 * The body a visitor's browser posts, from a form opened earlier. With $ajax the
 * form's script is used: it posts with snn_ajax=1 and reads a JSON reply.
 */
function signup_body($open, $email, $name = 'ZZ Test Person', $ajax = true) {
    $body = $open['form']['fields'];
    foreach ($body as $key => $value) {
        if (strpos($key, 'snn_field[') !== 0) continue;
        $body[$key] = in_array($key, $open['form']['email'], true) ? $email : $name;
    }
    $body['snn_website'] = '';
    $body['snn_t']       = (string)(time() - 10); // filled in over ten seconds ago
    if ($ajax) $body['snn_ajax'] = '1';
    return $body;
}

/** The outcome shown in the redirect: approved, pending, duplicate, code_used, ... */
function result_of($res) {
    $query = [];
    parse_str((string)parse_url($res['location'], PHP_URL_QUERY), $query);
    return $query['snn_result'] ?? ('http ' . $res['status']);
}

/**
 * The outcome in the JSON reply the form's script reads. A reply that is not JSON
 * (for example a database error printed before it) is returned as it arrived, so the
 * failure shows in the test output.
 */
function result_of_ajax($res) {
    $json = json_decode($res['body'], true);
    if (is_array($json) && isset($json['data']['result'])) return $json['data']['result'];
    return 'not json: ' . substr(trim(strip_tags($res['body'])), 0, 160);
}

/** One sign-up from a form opened earlier, sent the way the form's script sends it. */
function submit_form($open, $email, $name = 'ZZ Test Person') {
    clear_rate_limit();
    $res = http_many([['POST', admin_url('admin-post.php'), signup_body($open, $email, $name)]])[0];
    return result_of_ajax($res);
}

/* ------------------------------------------------------------------ database */

function sign_ups_with_code($code) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT id, form_id, status, email, placement_slot, ticket_id FROM ' . SNN_T_DB::submissions()
        . ' WHERE placement_code = %s ORDER BY id', SNN_T_Placements::normalise($code)));
}

function slots_of($rows) {
    $slots = array_map(fn($r) => $r->placement_slot === null ? null : (int)$r->placement_slot, $rows);
    sort($slots);
    return $slots;
}

function tickets_in_event($list_id) {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . SNN_T_DB::tickets() . " WHERE list_id = %d AND status = 'active'", (int)$list_id));
}

/* ================================================================== tests */

t_section('Placement codes: every Sell Placement status');

// Which statuses open the form. Only cancelled and suspended are refused today.
// "declined" means the artwork was refused: the buyer can upload again, so the code still counts.
$statuses = [
    'awaiting'  => true,   // bought, no artwork yet
    'review'    => true,   // artwork uploaded, waiting for a decision
    'approved'  => true,   // artwork approved
    'declined'  => true,   // artwork refused, the buyer can upload again
    'suspended' => false,  // suspended by the shop
    'cancelled' => false,  // cancelled
];
$codes = [];
foreach ($statuses as $status => $opens) {
    $row = fixture_placement($status);
    $codes[$status] = $row->code;
    $state = SNN_T_Placements::check($row->code, 2);
    t_check($opens ? $state === 'ok' : $state === 'invalid',
        "the {$status} code is " . ($opens ? 'accepted' : 'refused'), "got {$state}");
}

t_section('Placement codes: the ways a code is typed');
$code = $codes['approved'];
t_check(SNN_T_Placements::check(strtolower($code), 2) === 'ok', 'a code typed in lower case is accepted');
t_check(SNN_T_Placements::check(' ' . substr($code, 0, 3) . '-' . substr($code, 3) . ' ', 2) === 'ok',
    'a code with spaces and a dash is accepted');
t_check(SNN_T_Placements::normalise(strtolower($code)) === $code, 'a typed code is normalised to the stored code');
t_check(SNN_T_Placements::check('', 2) === 'missing', 'no code is "missing"');
t_check(SNN_T_Placements::check('ZZNOPE01', 2) === 'invalid', 'a code that does not exist is refused');
t_check(SNN_T_Placements::check('0' . substr($code, 1), 2) === 'invalid', 'a code with one character changed is refused');
t_check(SNN_T_Placements::check(str_repeat('A', 80), 2) === 'invalid', 'an absurdly long code is refused, not stored');

t_section('Invites counted from the database, many placements');

// One event and form for the sign-ups below. Each placement gets a different history.
$count_ev   = fixture_event('counting', $two_auto + ['approval_mode' => 'manual']);
$count_form = SNN_T_Forms::for_list($count_ev);

$histories = [
    'no sign-ups'                 => [[], 2, 'ok'],
    'one approved'                => [['approved'], 1, 'ok'],
    'two approved'                => [['approved', 'approved'], 0, 'used'],
    'one approved, one pending'   => [['approved', 'pending'], 0, 'used'],
    'one approved, one declined'  => [['approved', 'declined'], 1, 'ok'],
    'one approved, one deleted'   => [['approved', 'deleted'], 1, 'ok'],
];
foreach ($histories as $label => [$steps, $left, $state]) {
    $p = fixture_placement('approved');
    foreach ($steps as $i => $step) {
        $slot = $i + 1;
        if ($step === 'deleted') {
            $sid = SNN_T_Submissions::create(['form_id' => $count_form->id, 'status' => 'approved', 'email' => test_email('x'), 'placement_code' => $p->code, 'placement_slot' => $slot]);
            SNN_T_Submissions::delete($sid);
            continue;
        }
        $sid = SNN_T_Submissions::create(['form_id' => $count_form->id, 'status' => $step === 'declined' ? 'approved' : $step, 'email' => test_email('x'), 'placement_code' => $p->code, 'placement_slot' => $slot]);
        if ($step === 'declined') SNN_T_Submissions::reject($sid, 1, 'test');
    }
    t_check(SNN_T_Placements::left($p->code, 2) === $left, "{$label}: {$left} invite(s) left", (string)SNN_T_Placements::left($p->code, 2));
    t_check(SNN_T_Placements::check($p->code, 2) === $state, "{$label}: the code is {$state}", SNN_T_Placements::check($p->code, 2));
}
$cancelled = fixture_placement('cancelled');
SNN_T_Submissions::create(['form_id' => $count_form->id, 'status' => 'approved', 'email' => test_email('x'), 'placement_code' => $cancelled->code, 'placement_slot' => 1]);
t_check(SNN_T_Placements::check($cancelled->code, 2) === 'invalid', 'a cancelled code stays refused even with invites left');
t_check(str_contains(SNN_T_Placements::admin_url($codes['approved']), 'placement=' . spl_get_by_code($codes['approved'])->id),
    'a code links to its own Sell Placement record');

t_section('Two invites, approved automatically (your test)');
$ev  = fixture_event('two invites', $two_auto);
$url = SNN_T_Router::event_url($ev);
$code = fixture_placement('awaiting')->code;

$no_code = open_form($url, null);
t_check($no_code['form'] === null, 'no code in the link: no form');
t_check($no_code['notice'] === $msg['code_invalid_message'], 'no code in the link: the invalid-link message shows', $no_code['notice']);

$first = open_form($url, $code);
t_check($first['form'] !== null, 'a valid code: the form shows');
t_check($first['left'] === left_label(2), 'a valid code: "2 tickets left" shows', $first['left']);
$old_page = $first; // a visitor who opened the page before the limit was reached

$r = submit_form($first, test_email('a'));
t_check($r === 'approved', 'sign-up 1 is approved', $r);

$mid = open_form($url, $code);
t_check($mid['left'] === left_label(1), 'after one sign-up: "1 ticket left" shows', $mid['left']);

$r = submit_form($mid, test_email('b'));
t_check($r === 'approved', 'sign-up 2 is approved', $r);

$after = open_form($url, $code);
t_check($after['form'] === null, 'after two sign-ups the form no longer shows for this code');
t_check($after['notice'] === $msg['invites_used_message'], 'after two sign-ups the page says all invites are used', $after['notice']);

$r = submit_form($old_page, test_email('c'));
t_check($r === 'code_used', 'a third sign-up from the older page is refused', $r);

$rows = sign_ups_with_code($code);
t_check(count($rows) === 2, 'the code has exactly two sign-ups in the database', (string)count($rows));
t_check(slots_of($rows) === [1, 2], 'the two sign-ups hold invites 1 and 2', implode(',', slots_of($rows)));
t_check(tickets_in_event($ev) === 2, 'exactly two tickets were issued for the event', (string)tickets_in_event($ev));
t_check(SNN_T_Placements::left($code, 2) === 0, 'the code has no invites left');

t_section('Other invite counts: 1 and 3');
foreach ([1, 3] as $n) {
    $ev   = fixture_event("{$n} invite(s)", ['require_placement_code' => 1, 'invites_per_code' => $n, 'approval_mode' => 'auto']);
    $url  = SNN_T_Router::event_url($ev);
    $code = fixture_placement('approved')->code;

    $open = open_form($url, $code);
    t_check($open['left'] === left_label($n), "{$n} invite(s): the form starts with {$n} left", $open['left']);
    for ($i = 1; $i <= $n; $i++) {
        $r = submit_form(open_form($url, $code), test_email("n{$n}-{$i}"));
        t_check($r === 'approved', "{$n} invite(s): sign-up {$i} is approved", $r);
    }
    $after = open_form($url, $code);
    t_check($after['form'] === null && $after['notice'] === $msg['invites_used_message'],
        "{$n} invite(s): the form stops after {$n}", $after['notice']);
    t_check(count(sign_ups_with_code($code)) === $n, "{$n} invite(s): {$n} sign-ups stored for the code");
}

t_section('Same email twice');
$ev   = fixture_event('same email', $two_auto);
$url  = SNN_T_Router::event_url($ev);
$code = fixture_placement('awaiting')->code;
$open = open_form($url, $code);
$same = test_email('same');
t_check(submit_form($open, $same) === 'approved', 'the first sign-up with an email is approved');
t_check(submit_form($open, $same) === 'invalid', 'the same email again is refused under its own field');
t_check(SNN_T_Placements::left($code, 2) === 1, 'the refused duplicate does not use an invite', (string)SNN_T_Placements::left($code, 2));

t_section('Approval by hand: pending holds an invite, declined gives it back');
$ev   = fixture_event('manual', ['require_placement_code' => 1, 'invites_per_code' => 2, 'approval_mode' => 'manual']);
$url  = SNN_T_Router::event_url($ev);
$code = fixture_placement('awaiting')->code;
$open = open_form($url, $code);

$e1 = test_email('m1');
$e2 = test_email('m2');
t_check(submit_form($open, $e1) === 'pending', 'sign-up 1 waits for approval');
t_check(submit_form($open, $e2) === 'pending', 'sign-up 2 waits for approval');
t_check(open_form($url, $code)['notice'] === $msg['invites_used_message'], 'two pending sign-ups use both invites');
t_check(submit_form($open, test_email('m3')) === 'code_used', 'a third sign-up is refused while both are pending');

$first_sub = null;
foreach (sign_ups_with_code($code) as $row) {
    if ($row->email === $e1) $first_sub = (int)$row->id;
}
t_check($first_sub !== null, 'the first pending sign-up is stored');
SNN_T_Submissions::reject($first_sub, 1, 'test');

$freed = open_form($url, $code);
t_check($freed['left'] === left_label(1), 'after one is declined: "1 ticket left" shows', $freed['left']);
t_check(submit_form($freed, test_email('m4')) === 'pending', 'the freed invite takes a new sign-up');
$rows = sign_ups_with_code($code);
$held = array_values(array_filter(slots_of($rows), fn($s) => $s !== null));
t_check($held === [1, 2], 'the two live sign-ups hold invites 1 and 2 (the new one takes the freed invite)', implode(',', $held));
$declined_row = array_values(array_filter($rows, fn($r) => (int)$r->id === $first_sub))[0] ?? null;
t_check($declined_row && $declined_row->placement_slot === null, 'the declined sign-up holds no invite');

t_section('Code gate off: no code needed, no limit');
$ev   = fixture_event('gate off', ['require_placement_code' => 0, 'approval_mode' => 'auto']);
$url  = SNN_T_Router::event_url($ev);
$open = open_form($url, null);
t_check($open['form'] !== null, 'with the gate off the form shows without a code');
$all = true;
for ($i = 1; $i <= 3; $i++) {
    $all = $all && submit_form($open, test_email("open{$i}")) === 'approved';
}
t_check($all, 'three sign-ups without a code are all approved');
$stored_with_code = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . SNN_T_DB::submissions() . ' s JOIN ' . SNN_T_DB::forms() . " f ON f.id = s.form_id WHERE f.list_id = {$ev} AND s.placement_code IS NOT NULL");
t_check($stored_with_code === 0, 'none of them stores a placement code', (string)$stored_with_code);

t_section('Sell Placement suspends a code after one sign-up');
$ev   = fixture_event('suspend later', $two_auto);
$url  = SNN_T_Router::event_url($ev);
$p    = fixture_placement('approved');
$open = open_form($url, $p->code);
t_check(submit_form($open, test_email('s1')) === 'approved', 'sign-up 1 is approved');
$wpdb->update(spl_table(), ['status' => 'suspended'], ['id' => (int)$p->id], ['%s'], ['%d']);
$after = open_form($url, $p->code);
t_check($after['form'] === null, 'once suspended, the form no longer shows for the code');
t_check($after['notice'] === $msg['code_invalid_message'], 'once suspended, the page says the link is not valid', $after['notice']);
t_check(submit_form($open, test_email('s2')) === 'code_invalid', 'once suspended, an older page is refused');

t_section('Two codes do not share invites');
$ev  = fixture_event('two codes', $two_auto);
$url = SNN_T_Router::event_url($ev);
$pa  = fixture_placement('approved');
$pb  = fixture_placement('approved');
$oa  = open_form($url, $pa->code);
submit_form($oa, test_email('ca1'));
submit_form(open_form($url, $pa->code), test_email('ca2'));
t_check(open_form($url, $pa->code)['form'] === null, 'code A is used up after two sign-ups');
$ob = open_form($url, $pb->code);
t_check($ob['left'] === left_label(2), 'code B still has two left', $ob['left']);
t_check(submit_form($ob, test_email('cb1')) === 'approved', 'code B still takes a sign-up');

t_section('Three people press Get my ticket at the same moment');
$ev   = fixture_event('race', $two_auto);
$url  = SNN_T_Router::event_url($ev);
$code = fixture_placement('awaiting')->code;
$open = open_form($url, $code);
$emails = [test_email('r1'), test_email('r2'), test_email('r3')];
clear_rate_limit();
$results  = http_many(array_map(fn($e) => ['POST', admin_url('admin-post.php'), signup_body($open, $e, 'ZZ Race')], $emails));
$outcomes = array_map('result_of_ajax', $results);
sort($outcomes);
$approved = count(array_filter($outcomes, fn($o) => $o === 'approved'));
t_check($approved === 2, 'exactly two of the three are approved', implode(', ', $outcomes));
$others_ok = count(array_filter($outcomes, fn($o) => in_array($o, ['code_used', 'error'], true))) === 1;
t_check($others_ok, 'the third is refused as used (or as a clean error), not saved', implode(', ', $outcomes));
$rows = sign_ups_with_code($code);
t_check(count($rows) === 2, 'exactly two sign-ups are stored for the code', (string)count($rows));
t_check(slots_of($rows) === [1, 2], 'the two stored sign-ups hold different invites', implode(',', slots_of($rows)));
t_check(tickets_in_event($ev) === 2, 'exactly two tickets were issued', (string)tickets_in_event($ev));

t_section('Without JavaScript: the form posts normally and follows the redirect');
$ev   = fixture_event('no js', $two_auto);
$url  = SNN_T_Router::event_url($ev);
$code = fixture_placement('awaiting')->code;
$open = open_form($url, $code);
clear_rate_limit();
$plain = http_many([['POST', admin_url('admin-post.php'), signup_body($open, test_email('nojs1'), 'ZZ Test Person', false)]])[0];
t_check(result_of($plain) === 'approved', 'a sign-up sent without JavaScript is approved', result_of($plain));
t_check(result_of($plain) === 'approved' && count(sign_ups_with_code($code)) === 1, 'and it holds one invite');

/* ================================================================== results */

echo "\n" . str_repeat('=', 58) . "\n";
echo "PASS: {$T['pass']}   FAIL: {$T['fail']}\n";
if ($T['fail']) {
    echo "\nFailures:\n";
    foreach ($T['fails'] as $f) echo "  - {$f}\n";
    exit(1);
}
echo "ALL CHECKS PASSED\n";
exit(0);
