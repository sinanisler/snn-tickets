<?php
/**
 * Logic tests for SNN Tickets that run without a WordPress install.
 *
 * WordPress functions are stubbed just far enough to exercise the parts that
 * carry real decisions: signed scan URLs, QR file caching, placeholder
 * rendering, field sanitising and the approval rules.
 *
 * Run:  php tests/logic-test.php
 */

require_once __DIR__ . '/bootstrap.php';


echo "SNN Tickets logic tests — PHP " . PHP_VERSION . "\n";

/* ================= 1. signatures ================= */
section('Ticket signatures');

$code = 'ABC12345';
$sig  = SNN_T_QR::sign($code);

check(strlen($sig) === 16, 'signature is 16 hex chars');
check(ctype_xdigit($sig), 'signature is hexadecimal');
check(SNN_T_QR::verify($code, $sig), 'a good signature verifies');
check(!SNN_T_QR::verify($code, ''), 'an empty signature is rejected');
check(!SNN_T_QR::verify($code, str_repeat('0', 16)), 'a wrong signature is rejected');
check(!SNN_T_QR::verify('ABC12346', $sig), 'a signature does not transfer to another code');
check(SNN_T_QR::sign($code) === $sig, 'signing is deterministic');
check(SNN_T_QR::sign('XYZ99999') !== $sig, 'different codes sign differently');

// A rotated secret must invalidate the old signature.
$old_secret = get_option(SNN_T_QR::SECRET_OPTION);
delete_option(SNN_T_QR::SECRET_OPTION);
SNN_T_QR::secret();
check(!SNN_T_QR::verify($code, $sig), 'rotating the secret invalidates old signatures');
update_option(SNN_T_QR::SECRET_OPTION, $old_secret);
check(SNN_T_QR::verify($code, $sig), 'restoring the secret revalidates them');

/* ================= 2. scan URLs ================= */
section('Scan URLs');

$url = SNN_T_QR::scan_url($code);
check(strpos($url, 'snn_ticket=') !== false, 'scan URL carries the ticket code');
check(strpos($url, 'snn_sig=' . $sig) !== false, 'scan URL carries the signature');
check(strpos($url, 'https://tickets.example') === 0, 'scan URL falls back to the site root');

update_option(SNN_T_QR::SCAN_URL_OPTION, 'https://tickets.example/scan/');
$url2 = SNN_T_QR::scan_url($code);
check(strpos($url2, '/scan/') !== false, 'configured scan page is used when set');

// what a scanner would parse back out
parse_str((string)parse_url($url2, PHP_URL_QUERY), $q);
check(($q['snn_ticket'] ?? '') === $code, 'code survives the URL round-trip');
check(SNN_T_QR::verify($q['snn_ticket'], $q['snn_sig']), 'parsed URL args verify');

/* ================= 3. QR files ================= */
section('QR file cache');

$path = SNN_T_QR::ensure($code);
check(!is_wp_error($path), 'QR generation succeeds' . (is_wp_error($path) ? ': ' . $path->get_error_message() : ''));

if (!is_wp_error($path)) {
    check(file_exists($path) && filesize($path) > 0, 'QR PNG is written to disk');
    check(substr(file_get_contents($path), 0, 8) === "\x89PNG\r\n\x1a\n", 'cached file is a real PNG');
    check(strpos(basename($path), $code) === false, 'filename does not leak the ticket code');
    check(strlen(basename($path)) === 3 + 32 + 4, 'filename is a fixed-length hash');

    $mtime = filemtime($path);
    clearstatcache();
    $again = SNN_T_QR::ensure($code);
    check($again === $path && filemtime($path) === $mtime, 'second call reuses the cached file');

    $other = SNN_T_QR::ensure('ZZZ00000');
    check(!is_wp_error($other) && $other !== $path, 'different codes get different files');

    check(strpos(SNN_T_QR::url($code), 'https://tickets.example/uploads/') === 0, 'public URL points into uploads');

    // The PNG must decode back to the exact signed URL.
    define('SNN_QRCODE_STANDALONE', 1);
    require_once __DIR__ . '/../qrcode.php';
    $qr = new SNN_QRCode(SNN_T_QR::scan_url($code));
    check($qr->getTypeNumber() >= 1 && $qr->getTypeNumber() <= 10,
          'a signed scan URL fits a small QR version (v' . $qr->getTypeNumber() . ')');

    SNN_T_QR::delete($code);
    check(!file_exists($path), 'delete() removes the cached PNG');
    SNN_T_QR::delete('ZZZ00000');
}

/* ================= 4. placeholders ================= */
section('Email placeholders');

$vars = SNN_T_Mailer::build_vars([
    'name'        => 'Ada Lovelace',
    'email'       => 'ada@example.com',
    'ticket_code' => $code,
    'list_name'   => 'Summer Meetup',
    'form_name'   => 'Public signup',
    'fields'      => ['company' => 'Analytical Engines', 'diet' => ['vegan', 'gluten-free']],
]);

check($vars['{name}'] === 'Ada Lovelace', '{name} resolves');
check($vars['{ticket}'] === $code, '{ticket} resolves');
check($vars['{list}'] === 'Summer Meetup', '{list} resolves');
check($vars['{site}'] === 'Example Events', '{site} resolves');
check($vars['{qr_inline}'] === 'cid:' . SNN_T_Mailer::QR_CID, '{qr_inline} becomes a CID reference');
check(strpos($vars['{qr}'], 'https://') === 0, '{qr} is a hosted URL');
check($vars['{scan_url}'] === SNN_T_QR::scan_url($code), '{scan_url} matches the QR payload');
check($vars['{field:company}'] === 'Analytical Engines', '{field:key} resolves');
check($vars['{field:diet}'] === 'vegan, gluten-free', 'multi-value fields are joined');

$empty = SNN_T_Mailer::build_vars(['name' => '', 'email' => 'x@y.com']);
check($empty['{name}'] === 'Guest', 'a blank name falls back to Guest');
check($empty['{qr}'] === '' && $empty['{qr_inline}'] === '', 'no ticket means no QR placeholders');

$body = SNN_T_Mailer::render('Hi {name}, code {ticket} <img src="{qr_inline}">, {field:company}. {unknown}', $vars);
check(strpos($body, 'Hi Ada Lovelace') === 0, 'rendering substitutes the name');
check(strpos($body, 'cid:' . SNN_T_Mailer::QR_CID) !== false, 'rendering substitutes the inline QR');
check(strpos($body, '{unknown}') !== false, 'unknown placeholders are left untouched');

foreach (['ticket', 'confirmation', 'rejection'] as $role) {
    $tpl = SNN_T_Mailer::default_template($role);
    check(!empty($tpl['subject']) && !empty($tpl['body']), "default $role template has subject and body");
}
$ticket_tpl = SNN_T_Mailer::default_template('ticket');
check(strpos($ticket_tpl['body'], '{ticket_card}') !== false, 'default ticket template embeds the designed ticket (with its QR)');
check(strpos(SNN_T_Mailer::render($ticket_tpl['body'], $vars), '{') === false
      || strpos(SNN_T_Mailer::render($ticket_tpl['body'], $vars), '{qr') === false,
      'default ticket template renders with no QR placeholder left over');

SNN_T_QR::delete($code);

/* ================= 5. field sanitising ================= */
section('Field sanitising');

$fields = SNN_T_Forms::sanitize_fields([
    ['label' => 'Full name',   'type' => 'text',    'required' => 1, 'map_to' => 'name'],
    ['label' => 'Email',       'type' => 'email',   'required' => '1', 'map_to' => 'email'],
    ['label' => 'Full name',   'type' => 'text'],                       // duplicate label
    ['label' => '',            'type' => 'text'],                       // no label: dropped
    ['label' => 'T-shirt',     'type' => 'nonsense', 'options' => ['S', '', 'M']],
    ['label' => 'Notes',       'type' => 'textarea', 'map_to' => 'hacker'],
]);

check(count($fields) === 5, 'unlabelled fields are dropped (got ' . count($fields) . ')');
$keys = array_column($fields, 'key');
check(count($keys) === count(array_unique($keys)), 'field keys are unique');
check($keys[0] === 'full-name' || $keys[0] === 'full_name', 'keys derive from the label');
check($keys[2] !== $keys[0], 'a duplicate label gets a distinct key');
check($fields[3]['type'] === 'text', 'an unknown field type falls back to text');
check($fields[3]['options'] === ['S', 'M'], 'blank choices are stripped');
check($fields[4]['map_to'] === '', 'an invalid map_to is discarded');
check($fields[1]['required'] === 1, 'required is normalised to 1');

/* ================= 6. settings sanitising ================= */
section('Settings sanitising');

$settings = SNN_T_Forms::sanitize_settings([
    'approval_mode'  => 'nonsense',
    'rules_match'    => 'any',
    'rules_fallback' => 'reject',
    'rules'          => [
        ['field' => 'company', 'op' => 'contains', 'value' => 'Ltd'],
        ['field' => '',        'op' => 'equals',   'value' => 'x'],     // no field: dropped
        ['field' => 'diet',    'op' => 'made_up',  'value' => 'vegan'], // bad op: coerced
    ],
    'max_tickets'   => '-5',
    'one_per_email' => 'yes',
    'notify_email'  => 'not-an-email',
    'redirect_url'  => 'javascript:alert(1)',
    'submit_label'  => '',
]);

check($settings['approval_mode'] === 'auto', 'an unknown approval mode falls back to auto');
check($settings['rules_match'] === 'any', 'a valid rules_match is kept');
check(count($settings['rules']) === 2, 'rules with no field are dropped');
check($settings['rules'][1]['op'] === 'equals', 'an unknown operator falls back to equals');
check($settings['max_tickets'] === 0, 'a negative capacity clamps to 0');
check($settings['one_per_email'] === 1, 'truthy values normalise to 1');
check($settings['notify_email'] === '', 'an invalid notification address is dropped');
check($settings['redirect_url'] === '', 'a javascript: redirect is rejected');
check($settings['submit_label'] === 'Get my ticket', 'a blank message falls back to the default');

// Per-form email wording
$mail_settings = SNN_T_Forms::sanitize_settings([
    'confirmation_subject' => "  You're in  ",
    'confirmation_body'    => '<p>Hi {name}</p>',
    'ticket_subject'       => '',
    'ticket_body'          => "  <p>{qr_inline}</p>  ",
]);
check($mail_settings['confirmation_subject'] === "You're in", 'a confirmation subject survives sanitising');
check($mail_settings['confirmation_body'] === '<p>Hi {name}</p>', 'a confirmation body keeps its HTML');
check($mail_settings['ticket_body'] === '<p>{qr_inline}</p>', 'a ticket body is trimmed');
check($mail_settings['ticket_subject'] === '', 'a blank mail subject stays blank rather than defaulting');

$defaults = SNN_T_Forms::default_settings();
foreach (['confirmation_subject', 'confirmation_body', 'ticket_subject', 'ticket_body'] as $k) {
    check($defaults[$k] === '', "$k defaults to blank so the template is used");
}

$form_with_override = (object)['settings' => SNN_T_Forms::sanitize_settings([
    'ticket_body' => '<p>Custom {ticket}</p>',
])];
$override = SNN_T_Forms::mail_override($form_with_override, 'ticket');
check(is_array($override) && $override['body'] === '<p>Custom {ticket}</p>', 'mail_override returns the form wording');
check($override['subject'] === '', 'mail_override leaves an unset subject blank');
check(SNN_T_Forms::mail_override($form_with_override, 'confirmation') === null,
      'mail_override is null when the role has no wording');
check(SNN_T_Forms::mail_override(null, 'ticket') === null, 'mail_override tolerates a missing form');
check(SNN_T_Forms::mail_override($form_with_override, 'rejection') === null,
      'mail_override ignores roles that have no per-form editor');

/* ================= 7. rules ================= */
section('Approval rules');

$data = [
    'company' => 'Acme Ltd',
    'email'   => 'someone@partner.com',
    'diet'    => ['vegan'],
    'consent' => '1',
    'notes'   => '',
    'tier'    => 'Gold',
];

$cases = [
    [['field' => 'company', 'op' => 'equals',       'value' => 'acme ltd'],  true,  'equals is case-insensitive'],
    [['field' => 'company', 'op' => 'equals',       'value' => 'Acme'],      false, 'equals is not a prefix match'],
    [['field' => 'company', 'op' => 'not_equals',   'value' => 'Other'],     true,  'not_equals'],
    [['field' => 'company', 'op' => 'contains',     'value' => 'cme'],       true,  'contains'],
    [['field' => 'company', 'op' => 'contains',     'value' => ''],          false, 'contains with an empty needle is false'],
    [['field' => 'company', 'op' => 'starts_with',  'value' => 'Acme'],      true,  'starts_with'],
    [['field' => 'company', 'op' => 'starts_with',  'value' => 'Ltd'],       false, 'starts_with only matches the start'],
    [['field' => 'notes',   'op' => 'is_empty',     'value' => ''],          true,  'is_empty'],
    [['field' => 'company', 'op' => 'not_empty',    'value' => ''],          true,  'not_empty'],
    [['field' => 'consent', 'op' => 'checked',      'value' => ''],          true,  'checked'],
    [['field' => 'notes',   'op' => 'not_checked',  'value' => ''],          true,  'not_checked'],
    [['field' => 'email',   'op' => 'email_domain', 'value' => 'partner.com'], true, 'email_domain'],
    [['field' => 'email',   'op' => 'email_domain', 'value' => 'PARTNER.COM'], true, 'email_domain is case-insensitive'],
    [['field' => 'email',   'op' => 'email_domain', 'value' => 'other.com'], false, 'email_domain rejects a mismatch'],
    [['field' => 'tier',    'op' => 'in_list',      'value' => 'Silver, Gold, Platinum'], true, 'in_list'],
    [['field' => 'tier',    'op' => 'in_list',      'value' => 'Silver,Bronze'], false, 'in_list rejects a miss'],
    [['field' => 'diet',    'op' => 'contains',     'value' => 'vegan'],     true,  'array values are joined before matching'],
    [['field' => 'missing', 'op' => 'is_empty',     'value' => ''],          true,  'a missing field counts as empty'],
    [['field' => 'missing', 'op' => 'equals',       'value' => 'x'],         false, 'a missing field does not equal a value'],
];

foreach ($cases as list($rule, $expect, $label)) {
    check(SNN_T_Forms::rule_matches($rule, $data) === $expect, $label);
}

/* ================= 8. decisions ================= */
section('Approval decisions');

function make_form($settings) {
    $f = new stdClass();
    $f->id = 1;
    $f->name = 'Test form';
    $f->list_id = 1;
    $f->status = 'active';
    $f->fields = [];
    $f->settings = SNN_T_Forms::sanitize_settings($settings);
    return $f;
}

check(SNN_T_Forms::decide(make_form(['approval_mode' => 'auto']), $data) === 'approved',
      'auto mode approves');
check(SNN_T_Forms::decide(make_form(['approval_mode' => 'manual']), $data) === 'pending',
      'manual mode holds for review');

$all_match = make_form([
    'approval_mode' => 'conditional',
    'rules_match'   => 'all',
    'rules'         => [
        ['field' => 'company', 'op' => 'contains', 'value' => 'Acme'],
        ['field' => 'tier',    'op' => 'equals',   'value' => 'Gold'],
    ],
]);
check(SNN_T_Forms::decide($all_match, $data) === 'approved', 'conditional/all approves when every rule matches');

$all_partial = make_form([
    'approval_mode' => 'conditional',
    'rules_match'   => 'all',
    'rules'         => [
        ['field' => 'company', 'op' => 'contains', 'value' => 'Acme'],
        ['field' => 'tier',    'op' => 'equals',   'value' => 'Bronze'],
    ],
]);
check(SNN_T_Forms::decide($all_partial, $data) === 'pending', 'conditional/all falls back when one rule fails');

$any_partial = make_form([
    'approval_mode' => 'conditional',
    'rules_match'   => 'any',
    'rules'         => [
        ['field' => 'company', 'op' => 'contains', 'value' => 'Nope'],
        ['field' => 'tier',    'op' => 'equals',   'value' => 'Gold'],
    ],
]);
check(SNN_T_Forms::decide($any_partial, $data) === 'approved', 'conditional/any approves on a single match');

$reject_fallback = make_form([
    'approval_mode'  => 'conditional',
    'rules_match'    => 'all',
    'rules_fallback' => 'reject',
    'rules'          => [['field' => 'tier', 'op' => 'equals', 'value' => 'Bronze']],
]);
check(SNN_T_Forms::decide($reject_fallback, $data) === 'rejected', 'conditional rejects when told to');

$no_rules = make_form(['approval_mode' => 'conditional', 'rules' => []]);
check(SNN_T_Forms::decide($no_rules, $data) === 'pending',
      'conditional with no rules holds rather than auto-approving');

$no_rules_reject = make_form(['approval_mode' => 'conditional', 'rules' => [], 'rules_fallback' => 'reject']);
check(SNN_T_Forms::decide($no_rules_reject, $data) === 'rejected',
      'conditional with no rules honours a reject fallback');

/* ================= 9. queue guards ================= */
section('Queue guards');

$bad = SNN_T_Mailer::enqueue(['to_email' => 'not-an-email', 'subject' => 's', 'body' => 'b']);
check(is_wp_error($bad), 'enqueue rejects an invalid address');

$empty_subject = SNN_T_Mailer::enqueue(['to_email' => 'a@b.com', 'subject' => '', 'body' => 'b']);
check(is_wp_error($empty_subject), 'enqueue rejects an empty subject');

$empty_body = SNN_T_Mailer::enqueue(['to_email' => 'a@b.com', 'subject' => 's', 'body' => '']);
check(is_wp_error($empty_body), 'enqueue rejects an empty body');

update_option(SNN_T_Mailer::BATCH_SIZE_OPTION, 5000);
check(SNN_T_Mailer::batch_size() === 200, 'batch size clamps to 200');
update_option(SNN_T_Mailer::BATCH_SIZE_OPTION, 0);
check(SNN_T_Mailer::batch_size() === 1, 'batch size clamps to at least 1');

/* ================= 10. ticket codes ================= */
section('Ticket codes');

$seen = [];
for ($i = 0; $i < 2000; $i++) {
    $c = SNN_T_Tickets::generate_code(8);
    $seen[$c] = true;
    if (strlen($c) !== 8 || !preg_match('/^[A-Z0-9]{8}$/', $c)) {
        check(false, 'generated code is 8 uppercase alphanumerics');
        break;
    }
}
check(count($seen) > 1990, '2000 generated codes are essentially all distinct (' . count($seen) . ')');
check(strlen(SNN_T_Tickets::generate_code(16)) === 16, 'code length is honoured');

/* ================= 11. shop orders ================= */
section('Shop order tickets');

require_once __DIR__ . '/../includes/class-snn-woo.php';
$t = function ($id, $status = 'active', $vc = 0) { return ['id' => $id, 'status' => $status, 'vc' => $vc]; };

check(SNN_T_Woo::plan([], 3) === ['restore' => [], 'revoke' => [], 'create' => 3], 'paid order with no tickets makes them all');
check(SNN_T_Woo::plan([$t(1), $t(2)], 2) === ['restore' => [], 'revoke' => [], 'create' => 0], 'running again changes nothing');
check(SNN_T_Woo::plan([$t(1), $t(2), $t(3)], 1)['revoke'] === [3, 2], 'refund cancels the newest tickets first');
check(SNN_T_Woo::plan([$t(1), $t(2, 'active', 1), $t(3, 'active', 2)], 1)['revoke'] === [1, 3], 'tickets used at the door are cancelled last');
check(SNN_T_Woo::plan([$t(1), $t(2)], 0)['revoke'] === [2, 1], 'cancelled order cancels every ticket');
check(SNN_T_Woo::plan([$t(1, 'revoked'), $t(2, 'revoked')], 3) === ['restore' => [1, 2], 'revoke' => [], 'create' => 1], 'reopened order restores old codes before making new ones');
check(SNN_T_Woo::plan([$t(1), $t(2, 'revoked')], 1) === ['restore' => [], 'revoke' => [], 'create' => 0], 'partly refunded order keeps its one ticket');
check(SNN_T_Woo::plan([$t(1)], -2)['revoke'] === [1], 'negative counts are treated as none');
check(SNN_T_Woo::plan([$t(1)], 3, 1) === ['restore' => [], 'revoke' => [], 'create' => 1], 'never makes more than the line paid for');
check(SNN_T_Woo::plan([$t(1, 'revoked')], 3, 0) === ['restore' => [1], 'revoke' => [], 'create' => 0], 'restores even when nothing new may be made');

/* ================= 12. email and wording ================= */
section('Email retries, recipients and wording');

check(SNN_T_Mailer::retry_delay(1) === 300 && SNN_T_Mailer::retry_delay(2) === 1800 && SNN_T_Mailer::retry_delay(3) === 7200, 'retries wait 5 min, 30 min, 2 h');
check(SNN_T_Mailer::retry_delay(9) === 7200 && SNN_T_Mailer::retry_delay(0) === 300, 'retry delay stays in range');
check(SNN_T_Mailer::MAX_ATTEMPTS === count(SNN_T_Mailer::RETRY_DELAYS) + 1, 'one delay between each pair of tries');
check(SNN_T_Events::parse_recipients('a@x.com, B@Y.com;bad  a@x.com') === ['a@x.com', 'b@y.com'], 'recipient list: valid, lower-case, no repeats');
check(SNN_T_Events::parse_recipients('') === [], 'empty recipient list');
check(SNN_T_Claims::mask_email('bob@example.com') === 'b***@example.com', 'email masked');
check(SNN_T_Claims::mask_email('nope') === '', 'not an email: nothing shown');
check(SNN_T_Texts::get('claim_title_from', ['buyer' => 'Sinan', 'event' => 'Gala']) === 'Sinan got you a ticket to Gala', 'wording default with placeholders');
update_option(SNN_T_Texts::OPTION, ['buy' => 'Get tickets']);
check(SNN_T_Texts::get('buy') === 'Get tickets', 'own wording wins');
update_option(SNN_T_Texts::OPTION, ['buy' => '   ']);
check(SNN_T_Texts::get('buy') === 'Buy', 'blank own wording falls back to the default');
$kept = SNN_T_Texts::save(['buy' => 'Buy', 'sold_out' => 'Gone!', 'nonsense' => 'x']);
check($kept === ['sold_out' => 'Gone!'], 'only changed, known texts are stored');
check(SNN_T_Texts::get('missing_key') === 'missing_key', 'unknown key shows itself');

/* ================= placement codes ================= */
section('Placement codes');

// Stand-in for Sell Placement's lookup: the codes it knows, with a status each.
$GLOBALS['spl_codes'] = ['SP3K7Q9' => 'awaiting', 'SPCANC1' => 'cancelled', 'SPHOLD2' => 'suspended'];
function spl_get_by_code($code) {
    return isset($GLOBALS['spl_codes'][$code]) ? (object)['id' => 7, 'code' => $code, 'status' => $GLOBALS['spl_codes'][$code]] : null;
}
function admin_url($path = '') { return 'https://tickets.example/wp-admin/' . $path; }

check(SNN_T_Placements::available(), 'the stand-in makes Sell Placement look installed');
check(SNN_T_Placements::normalise(' sp3k7q9 ') === 'SP3K7Q9', 'a typed code is trimmed and upper-cased');
check(SNN_T_Placements::normalise('SP-3K7 Q9!') === 'SP3K7Q9', 'stray punctuation and spaces are dropped');
check(SNN_T_Placements::normalise(str_repeat('A', 40)) === str_repeat('A', 32), 'a code is cut to the column width');

$GLOBALS['wpdb']->rows = [];
check(SNN_T_Placements::check('', 2) === 'missing', 'no code is missing');
check(SNN_T_Placements::check('NOPE123', 2) === 'invalid', 'an unknown code is invalid');
check(SNN_T_Placements::check('SPCANC1', 2) === 'invalid', 'a cancelled placement is invalid');
check(SNN_T_Placements::check('SPHOLD2', 2) === 'invalid', 'a suspended placement is invalid');
check(SNN_T_Placements::check('sp3k7q9', 2) === 'ok', 'a known code with no sign-ups is ok');
check(SNN_T_Placements::left('SP3K7Q9', 2) === 2, 'a fresh code has both invites left');
check(SNN_T_Placements::left('', 2) === 2 && SNN_T_Placements::taken('') === 0, 'an empty code holds nothing');

$GLOBALS['wpdb']->rows = ['var' => '1'];
check(SNN_T_Placements::check('SP3K7Q9', 2) === 'ok', 'a code with one invite used still has one');
check(SNN_T_Placements::left('SP3K7Q9', 2) === 1, 'one sign-up leaves one invite');
check(SNN_T_Placements::slot_taken('SP3K7Q9', 1) === true, 'a slot a sign-up holds is taken');
check(SNN_T_Placements::slot_taken('', 1) === false, 'an empty code holds no slot');

$GLOBALS['wpdb']->rows = ['var' => '2'];
check(SNN_T_Placements::check('SP3K7Q9', 2) === 'used', 'a code with both invites used is used');
check(SNN_T_Placements::check('SP3K7Q9', 3) === 'ok', 'raising the invites gives the code one back');
check(SNN_T_Placements::check('SPCANC1', 2) === 'invalid', 'a cancelled placement stays invalid, even with invites left');

$GLOBALS['wpdb']->rows = ['var' => '5'];
check(SNN_T_Placements::left('SP3K7Q9', 2) === 0, 'more sign-ups than invites never goes below zero');
$GLOBALS['wpdb']->rows = [];
check(SNN_T_Placements::admin_url('sp3k7q9') === 'https://tickets.example/wp-admin/admin.php?page=spl-approvals&placement=7',
      'a known code links to its Sell Placement page');
check(SNN_T_Placements::admin_url('NOPE123') === '', 'an unknown code has no link');
check(SNN_T_Placements::admin_url('') === '', 'an empty code has no link');
$GLOBALS['wpdb']->rows = [];

$gated = SNN_T_Forms::sanitize_settings(['require_placement_code' => 'yes', 'invites_used_message' => '  All used  ', 'invites_per_code' => '3']);
check($gated['require_placement_code'] === 1, 'the placement-code switch normalises to 1');
check($gated['invites_used_message'] === 'All used', 'the all-used message is kept, trimmed');
check($gated['invites_per_code'] === 3, 'the invites per code are kept as a number');
check(SNN_T_Forms::sanitize_settings(['invites_per_code' => '99'])['invites_per_code'] === 10, 'invites per code is capped at ten');
check(SNN_T_Forms::sanitize_settings(['invites_per_code' => '0'])['invites_per_code'] === 1, 'invites per code is at least one');
check(SNN_T_Forms::sanitize_settings([])['invites_per_code'] === 2, 'invites per code defaults to two');
check(SNN_T_Forms::sanitize_settings(['invites_per_code' => ''])['invites_per_code'] === 2, 'an empty invites field means the default');
check(SNN_T_Forms::default_settings()['require_placement_code'] === 0, 'placement codes are off by default');
check(SNN_T_Forms::default_settings()['invites_per_code'] === 2, 'a code gives two invites by default');
check(SNN_T_Forms::default_settings()['invites_used_message'] !== '', 'the all-used message has a default');

// A sign-up with a code holds its invite; one without holds none.
$GLOBALS['wpdb']->inserted = [];
SNN_T_Submissions::create(['form_id' => 1, 'placement_code' => 'SP3K7Q9', 'placement_slot' => 2]);
$held = end($GLOBALS['wpdb']->inserted)[1];
check($held['placement_code'] === 'SP3K7Q9' && $held['placement_slot'] === 2, 'a code sign-up keeps its invite slot');
SNN_T_Submissions::create(['form_id' => 1]);
$plain = end($GLOBALS['wpdb']->inserted)[1];
check($plain['placement_code'] === null && $plain['placement_slot'] === null, 'a sign-up without a code holds no slot');
check(SNN_T_Forms::default_settings()['code_invalid_message'] !== '', 'the invalid-code message has a default');

// Two sign-ups with one code: the second one's insert is refused by the unique key.
// With WP_DEBUG on, WordPress would print that refusal into the page; it must not.
section('Invite claims');
$GLOBALS['wpdb']->inserted = [];
$GLOBALS['wpdb']->show_errors = true;
ob_start();
$first_id = SNN_T_Submissions::create(['form_id' => 1, 'placement_code' => 'SPTEST1', 'placement_slot' => 1]);
$first_out = ob_get_clean();
check($first_id > 0 && $first_out === '', 'a first sign-up with a code saves quietly');

ob_start();
$refused_id = SNN_T_Submissions::create(['form_id' => 1, 'placement_code' => 'SPTEST1', 'placement_slot' => 1]);
$refused_out = ob_get_clean();
check($refused_id === 0, 'a sign-up for an invite already held is refused');
check($refused_out === '', 'the refused insert prints no database error into the page');
check($GLOBALS['wpdb']->suppress_errors === false, 'error display is restored after the insert');

ob_start();
$second_id = SNN_T_Submissions::create(['form_id' => 1, 'placement_code' => 'SPTEST1', 'placement_slot' => 2]);
$second_out = ob_get_clean();
check($second_id > 0 && $second_out === '', 'the next free invite still saves quietly');
$GLOBALS['wpdb']->show_errors = false;

/* ================= several tickets per sign-up ================= */
section('Several tickets per sign-up');

// The stand-in database also answers the invite queries from the rows it holds, and
// removes rows again, so an invite claim can be followed through a sign-up.
class SNN_Test_Slots_WPDB extends SNN_Test_WPDB {
    public function get_var($sql) {
        if (preg_match("/placement_code = '([^']*)' AND placement_slot = (\d+)/", $sql, $m)) {
            foreach ($this->inserted as [$t, $d]) {
                if (($d['placement_code'] ?? null) === $m[1] && (int)($d['placement_slot'] ?? 0) === (int)$m[2]) return '1';
            }
            return null;
        }
        if (preg_match("/placement_code = '([^']*)' AND placement_slot IS NOT NULL/", $sql, $m)) {
            $n = 0;
            foreach ($this->inserted as [$t, $d]) {
                if (($d['placement_code'] ?? null) === $m[1] && ($d['placement_slot'] ?? null) !== null) $n++;
            }
            return (string)$n;
        }
        return parent::get_var($sql);
    }
    public function insert($t, $d, $f = null) {
        $ok = parent::insert($t, $d, $f);
        if ($ok) $this->inserted[count($this->inserted) - 1][1]['_id'] = $this->insert_id;
        return $ok;
    }
    public function delete($t = null, $where = [], $f = null) {
        $this->inserted = array_values(array_filter($this->inserted, function ($row) use ($t, $where) {
            return !($row[0] === $t && isset($where['id']) && ($row[1]['_id'] ?? null) === (int)$where['id']);
        }));
        return 1;
    }
}

$saved_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new SNN_Test_Slots_WPDB();

$multi_form = (object)[
    'id' => 7, 'list_id' => 3, 'name' => 'Conference', 'status' => 'active',
    'fields' => SNN_T_Forms::sanitize_fields([
        ['key' => 'name',       'label' => 'Your name',   'type' => 'text',   'required' => 1, 'map_to' => 'name'],
        ['key' => 'email',      'label' => 'Your email',  'type' => 'email',  'required' => 1, 'map_to' => 'email'],
        ['key' => 'guest',      'label' => 'Guest name',  'type' => 'text',   'map_to' => 'name'],
        ['key' => 'guest_mail', 'label' => 'Guest email', 'type' => 'email',  'map_to' => 'email'],
        ['key' => 'diet',       'label' => 'Diet',        'type' => 'select', 'options' => ['Any', 'Vegan']],
    ]),
    'settings' => array_merge(SNN_T_Forms::default_settings(), ['invites_per_code' => 2, 'max_tickets' => 0]),
];

// Roles only make sense on the right kind of answer.
$roles = SNN_T_Forms::sanitize_fields([
    ['key' => 'a', 'label' => 'Name',  'type' => 'text',   'map_to' => 'name'],
    ['key' => 'b', 'label' => 'Size',  'type' => 'select', 'map_to' => 'name', 'options' => ['S']],
    ['key' => 'c', 'label' => 'Mail',  'type' => 'text',   'map_to' => 'email'],
    ['key' => 'd', 'label' => 'Guest', 'type' => 'text',   'map_to' => 'name'],
]);
check($roles[0]['map_to'] === 'name', 'a text answer can be the name on the ticket');
check($roles[1]['map_to'] === '', 'a dropdown cannot be the name on the ticket');
check($roles[2]['map_to'] === '', 'a text answer cannot be the ticket address');
check($roles[3]['map_to'] === 'name', 'a form can have more than one name on the ticket');

// Each name with a name in it is one ticket; the address beside it goes with it.
$data = ['name' => 'Ana', 'email' => 'ana@x.test', 'guest' => 'Ben', 'guest_mail' => 'ben@y.test', 'diet' => 'Any'];
$two  = SNN_T_Forms::holders($multi_form, $data, 'ana@x.test');
check(count($two) === 2, 'two names make two tickets');
check($two[0] === ['name' => 'Ana', 'email' => 'ana@x.test'], 'the first name gets the first address');
check($two[1] === ['name' => 'Ben', 'email' => 'ben@y.test'], 'the second name gets the second address');

$no_addr = SNN_T_Forms::holders($multi_form, array_merge($data, ['guest_mail' => '']), 'ana@x.test');
check($no_addr[1] === ['name' => 'Ben', 'email' => 'ana@x.test'], 'a name without its own address goes to the buyer');

$blank_guest = SNN_T_Forms::holders($multi_form, array_merge($data, ['guest' => '  ']), 'ana@x.test');
check(count($blank_guest) === 1 && $blank_guest[0]['name'] === 'Ana', 'an empty optional name makes no ticket');

$no_names = SNN_T_Forms::holders((object)['fields' => [['key' => 'email', 'type' => 'email', 'map_to' => 'email']]], ['email' => 'ana@x.test'], 'ana@x.test');
check($no_names === [['name' => '', 'email' => 'ana@x.test']], 'a form with no names still gets one ticket, as before');

// Two people, two invites: two records, each with its own invite and its own address.
$GLOBALS['wpdb']->inserted = [];
$subs_rows = function () {
    return array_values(array_map(function ($r) { return $r[1]; },
        array_filter($GLOBALS['wpdb']->inserted, function ($r) { return $r[0] === SNN_T_DB::submissions(); })));
};
$ids = SNN_T_Forms::create_sign_ups($multi_form, 'approved', $two, $data, 'SPMULTI');
$rows = $subs_rows();
check(count($ids) === 2 && count($rows) === 2, 'a two-person sign-up saves two records');
check(($rows[0]['name'] ?? '') === 'Ana' && ($rows[1]['name'] ?? '') === 'Ben', 'each record carries its own name');
check(($rows[0]['email'] ?? '') === 'ana@x.test' && ($rows[1]['email'] ?? '') === 'ben@y.test', 'each record carries its own address');
check(($rows[0]['placement_slot'] ?? null) === 1 && ($rows[1]['placement_slot'] ?? null) === 2, 'each person takes its own invite');
check(($rows[1]['status'] ?? '') === 'approved', 'an approved sign-up approves each of its records');
check(SNN_T_Placements::left('SPMULTI', 2) === 0, 'both invites of the code are used up');

// Three people, two invites: nobody is saved, and the records already saved are removed.
$GLOBALS['wpdb']->inserted = [];
$three = [['name' => 'A', 'email' => 'a@x.test'], ['name' => 'B', 'email' => 'b@x.test'], ['name' => 'C', 'email' => 'c@x.test']];
check(SNN_T_Forms::create_sign_ups($multi_form, 'pending', $three, $data, 'SPROLL1') === [],
      'a sign-up with more people than invites saves nothing');
check(SNN_T_Placements::taken('SPROLL1') === 0, 'records saved before the refusal are removed again');
check(count($subs_rows()) === 0, 'no stray sign-up rows are left behind');

// A declined sign-up holds no invite, but keeps its code, and nothing is claimed.
$GLOBALS['wpdb']->inserted = [];
$declined = SNN_T_Forms::create_sign_ups($multi_form, 'rejected', $two, $data, 'SPNOPE1');
$rows = $subs_rows();
check(count($declined) === 2 && ($rows[0]['placement_slot'] ?? null) === null, 'a declined sign-up holds no invite');
check(SNN_T_Placements::taken('SPNOPE1') === 0, 'a declined sign-up does not count against the code');

// Without a code, each person's record is an ordinary sign-up.
$GLOBALS['wpdb']->inserted = [];
$plain = SNN_T_Forms::create_sign_ups($multi_form, 'pending', $two, $data, '');
$rows = $subs_rows();
check(count($plain) === 2 && ($rows[1]['status'] ?? '') === 'pending', 'without a code, each person is a pending sign-up');
check(($rows[1]['placement_code'] ?? null) === null && ($rows[1]['placement_slot'] ?? null) === null, 'without a code, no invite is claimed');
$GLOBALS['wpdb']->inserted = [];

// The second name and address do not offer the browser's autofill.
ob_start();
SNN_T_Forms::render_field(['key' => 'name', 'type' => 'text', 'label' => 'Name', 'placeholder' => '', 'required' => 1, 'options' => [], 'map_to' => 'name'], null, '');
$first_html = ob_get_clean();
ob_start();
SNN_T_Forms::render_field(['key' => 'guest', 'type' => 'text', 'label' => 'Guest', 'placeholder' => '', 'required' => 0, 'options' => [], 'map_to' => 'name', 'autofill' => false], null, '');
$guest_html = ob_get_clean();
check(strpos($first_html, 'autocomplete="name"') !== false, 'the buyer\'s name keeps browser autofill');
check(strpos($guest_html, 'autocomplete') === false, 'a second name does not get browser autofill');

check(SNN_T_Forms::messages($multi_form)['short'][1] === SNN_T_Forms::default_settings()['group_short_message'], 'a sign-up that does not fit has its own message');
check(SNN_T_Forms::default_settings()['group_short_message'] !== '', 'the not-enough-places message has a default');
check(SNN_T_Forms::sanitize_settings(['group_short_message' => '  Too many  '])['group_short_message'] === 'Too many', 'the not-enough-places message is kept, trimmed');

$GLOBALS['wpdb'] = $saved_wpdb;

/* ================= dividers, same addresses, optional people ================= */
section('Dividers, same addresses and optional people');

$div = SNN_T_Forms::sanitize_fields([
    ['type' => 'divider', 'color' => '#FF0000', 'height' => 4, 'style' => 'dashed', 'required' => 1, 'label' => ''],
    ['type' => 'divider', 'color' => 'red', 'height' => 99, 'style' => 'wiggly'],
    ['label' => '', 'type' => 'text'],
]);
check(count($div) === 2, 'a divider needs no label, and an unlabelled question is still dropped');
check($div[0]['type'] === 'divider' && $div[0]['key'] === 'divider', 'a divider gets the key "divider"');
check($div[0]['color'] === '#ff0000' && $div[0]['height'] === 4 && $div[0]['style'] === 'dashed', 'colour, height and style are kept, colour in lower case');
check($div[0]['required'] === 0, 'a divider is never required');
check($div[1]['key'] === 'divider_2', 'a second divider gets its own key');
check($div[1]['color'] === '#c3c4c7' && $div[1]['height'] === 20 && $div[1]['style'] === 'solid', 'a bad colour and style fall back, a height is capped at 20');

$addr = SNN_T_Forms::sanitize_fields([
    ['key' => 'mail',  'label' => 'Mail',   'type' => 'email', 'map_to' => 'email', 'required' => 0],
    ['key' => 'mail2', 'label' => 'Mail 2', 'type' => 'email', 'map_to' => 'email', 'required' => 0],
    ['key' => 'guest', 'label' => 'Guest',  'type' => 'text',  'map_to' => 'name',  'required' => 0],
]);
check($addr[0]['required'] === 1, 'the first address is always required, so tickets reach someone');
check($addr[1]['required'] === 0, 'a second address can be optional');
check($addr[2]['required'] === 0, 'a second name can be optional');

$people_form = (object)[
    'id' => 8, 'list_id' => 3, 'name' => 'Trip', 'status' => 'active',
    'fields' => SNN_T_Forms::sanitize_fields([
        ['key' => 'name',       'label' => 'Name',        'type' => 'text',  'required' => 1, 'map_to' => 'name'],
        ['key' => 'email',      'label' => 'Email',       'type' => 'email', 'required' => 1, 'map_to' => 'email'],
        ['key' => 'line',       'type' => 'divider'],
        ['key' => 'guest',      'label' => 'Guest',       'type' => 'text',  'map_to' => 'name'],
        ['key' => 'guest_mail', 'label' => 'Guest email', 'type' => 'email', 'map_to' => 'email'],
    ]),
    'settings' => array_merge(SNN_T_Forms::default_settings(), ['one_per_email' => 1]),
];

[$d1, $e1, , ] = SNN_T_Forms::collect($people_form, ['name' => 'Ana', 'email' => 'ana@x.test', 'guest' => 'Ben', 'guest_mail' => 'ANA@x.test']);
check(isset($e1['guest_mail']) && !isset($e1['email']), 'an address typed twice on one sign-up is refused under the second field');
check(!array_key_exists('line', $d1) && !isset($e1['line']), 'a divider has no answer and no error');

[$d2, $e2, , ] = SNN_T_Forms::collect($people_form, ['name' => 'Ana', 'email' => 'ana@x.test', 'guest' => 'Ben', 'guest_mail' => 'ben@y.test']);
check($e2 === [] && $d2['guest_mail'] === 'ben@y.test', 'two different addresses are fine');

[, $e3, , ] = SNN_T_Forms::collect($people_form, ['name' => 'Ana', 'email' => 'ana@x.test', 'guest' => 'Ben', 'guest_mail' => '']);
check($e3 === [], 'an empty second address is fine: its ticket goes to the first address');

// An address already signed up, or holding a ticket, is refused under its own field.
$GLOBALS['wpdb']->rows = ['var' => '1'];
$taken = SNN_T_Forms::taken_addresses($people_form, ['email' => 'ana@x.test', 'guest_mail' => '']);
check(isset($taken['email']) && !isset($taken['guest_mail']), 'an address already signed up is refused under its own field');
check($taken['email'] === SNN_T_Forms::default_settings()['duplicate_message'], 'the refusal uses the form\'s duplicate message');
$off = clone $people_form;
$off->settings['one_per_email'] = 0;
check(SNN_T_Forms::taken_addresses($off, ['email' => 'ana@x.test']) === [], 'with "one ticket per email" off, a known address is allowed');
$GLOBALS['wpdb']->rows = [];
check(SNN_T_Forms::taken_addresses($people_form, ['email' => 'new@x.test']) === [], 'a new address is not refused');

// A divider draws its line and asks nothing.
ob_start();
SNN_T_Forms::render_field(['key' => 'line', 'type' => 'divider', 'label' => '', 'placeholder' => '', 'required' => 0, 'options' => [], 'map_to' => '', 'color' => '#ff0000', 'height' => 4, 'style' => 'dashed'], null, '');
$line_html = ob_get_clean();
check(strpos($line_html, 'border-top:4px dashed #ff0000') !== false, 'a divider draws a line with its height, style and colour');
check(strpos($line_html, 'name=') === false, 'a divider has no answer field');
ob_start();
SNN_T_Forms::render_field(['key' => 'line', 'type' => 'divider', 'label' => '', 'placeholder' => '', 'required' => 0, 'options' => [], 'map_to' => '', 'color' => 'javascript:x', 'height' => 1, 'style' => 'solid'], null, '');
check(strpos(ob_get_clean(), 'border-top:1px solid #c3c4c7') !== false, 'a divider with a bad colour uses the default colour');

/* ================= results ================= */

// tidy the temp upload dir
$dir = wp_upload_dir()['basedir'] . '/' . SNN_T_QR::DIR;
foreach ((array)glob($dir . '/*') as $f) @unlink($f);
@rmdir($dir);
@rmdir(wp_upload_dir()['basedir']);

echo "\n" . str_repeat('=', 58) . "\n";
echo "PASS: $PASS   FAIL: $FAIL\n";
if ($FAIL) {
    echo "\nFailures:\n";
    foreach ($FAILURES as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL CHECKS PASSED\n";
exit(0);
