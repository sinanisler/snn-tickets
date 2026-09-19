<?php
/**
 * Shared WordPress stubs for the standalone test suites.
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
    public $queries = [];
    public function query($sql = "") { $this->queries[] = $sql; return 1; }
    public function esc_like($s) { return addcslashes($s, "_%\\"); }
}
$GLOBALS['wpdb'] = new SNN_Test_WPDB();


/* ---- added for the design / wallet / PDF features ---- */
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
function __($s, $d = null) { return $s; }
function _e($s, $d = null) { echo $s; }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_attr($s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function _n($one, $many, $n, $d = null) { return $n == 1 ? $one : $many; }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function get_locale() { return 'en_US'; }
function sanitize_file_name($s) { return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$s); }
function wp_timezone() { return new DateTimeZone('Europe/Istanbul'); }
function human_time_diff($a, $b = 0) { return abs(($b ?: time()) - $a) . ' secs'; }
function get_permalink($p = 0) { return 'https://tickets.example/scan/'; }
function wp_strip_all_tags($s) { return strip_tags((string)$s); }
function current_user_can_stub() { return false; }

/* ================= load the plugin classes ================= */

foreach (['db', 'qr', 'tickets', 'events', 'design', 'pdf', 'wallet', 'files', 'mailer', 'forms'] as $c) {
    require_once __DIR__ . '/../includes/class-snn-' . $c . '.php';
}
