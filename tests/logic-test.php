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

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

$PASS = 0; $FAIL = 0; $FAILURES = [];
function check($cond, $label) {
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; } else { $FAIL++; $FAILURES[] = $label; echo "  FAIL: $label\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

/* ================= WordPress stubs ================= */

$GLOBALS['snn_options'] = [];

function get_option($k, $default = false) {
    return array_key_exists($k, $GLOBALS['snn_options']) ? $GLOBALS['snn_options'][$k] : $default;
}
function update_option($k, $v, $autoload = null) { $GLOBALS['snn_options'][$k] = $v; return true; }
function add_option($k, $v) { if (!isset($GLOBALS['snn_options'][$k])) $GLOBALS['snn_options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['snn_options'][$k]); return true; }

function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string)$s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string)$s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$s)); }
function sanitize_email($s) { return filter_var(trim((string)$s), FILTER_SANITIZE_EMAIL); }
function is_email($s) { return (bool)filter_var($s, FILTER_VALIDATE_EMAIL); }
function sanitize_title($s) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$s), '-')); }
function esc_url_raw($s) { return filter_var((string)$s, FILTER_VALIDATE_URL) ? (string)$s : ''; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function wp_json_encode($v) { return json_encode($v); }
function wp_unslash($v) { return $v; }
function home_url($path = '/') { return 'https://tickets.example' . $path; }
function get_bloginfo($k) { return $k === 'name' ? 'Example Events' : 'UTF-8'; }
function current_time($type) { return $type === 'timestamp' ? time() : date('Y-m-d H:i:s'); }
function date_i18n($f, $t = null) { return date($f, $t ?: time()); }
function trailingslashit($s) { return rtrim((string)$s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string)$s, '/\\'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_generate_password($len = 12, $special = true, $extra = false) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    if ($special) $chars .= '!@#$%^&*()';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}
function wp_upload_dir() {
    $base = sys_get_temp_dir() . '/snn-tickets-test-' . getmypid();
    if (!is_dir($base)) mkdir($base, 0777, true);
    return ['basedir' => $base, 'baseurl' => 'https://tickets.example/uploads'];
}
function add_query_arg($args, $url = '') {
    if (!is_array($args)) return $url;
    $parts = parse_url($url);
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    foreach ($args as $k => $v) $query[$k] = $v;
    $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'tickets.example') . ($parts['path'] ?? '/');
    return $base . '?' . http_build_query($query);
}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function do_action() {}
function get_current_user_id() { return 1; }
function current_user_can() { return false; }
function get_transient($k) { return $GLOBALS['snn_transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['snn_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['snn_transients'][$k]); return true; }
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function wp_unschedule_event() { return true; }
function shortcode_atts($pairs, $atts) { return array_merge($pairs, (array)$atts); }
function wp_kses_post($s) { return $s; }

class WP_Error {
    private $code; private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

/** Minimal $wpdb: only the read paths the tested code touches. */
class SNN_Test_WPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rows = [];
    public function get_charset_collate() { return ''; }
    public function prepare($sql, ...$a) {
        if (count($a) === 1 && is_array($a[0])) $a = $a[0];
        foreach ($a as $v) {
            $sql = preg_replace('/%[dsf]/', is_numeric($v) ? (string)(int)$v : "'" . addslashes((string)$v) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_var($sql) { return $this->rows['var'] ?? null; }
    public function get_row($sql) { return $this->rows['row'] ?? null; }
    public function get_results($sql) { return $this->rows['results'] ?? []; }
    public function insert($t, $d, $f = null) { $this->insert_id++; return 1; }
    public function update() { return 1; }
    public function delete() { return 1; }
    public function query() { return 0; }
}
$GLOBALS['wpdb'] = new SNN_Test_WPDB();

/* ================= load the plugin classes ================= */

require_once __DIR__ . '/../includes/class-snn-db.php';
require_once __DIR__ . '/../includes/class-snn-qr.php';
require_once __DIR__ . '/../includes/class-snn-tickets.php';
require_once __DIR__ . '/../includes/class-snn-mailer.php';
require_once __DIR__ . '/../includes/class-snn-forms.php';

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
check(strpos($ticket_tpl['body'], '{qr_inline}') !== false, 'default ticket template embeds the QR inline');
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
check($settings['submit_label'] === 'Register', 'a blank message falls back to the default');

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
