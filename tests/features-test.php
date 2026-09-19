<?php
/**
 * Tests for events, designs, the PDF writer, wallet passes, calendar files,
 * placeholders, check-in rules and CSV import. No WordPress needed.
 *
 * Where the system has them, the output is checked with independent tools:
 * `pdftotext` reads the PDF back, and the `openssl` CLI verifies the Apple
 * Wallet signature. Checks that need a missing tool are reported as skipped.
 *
 * Run:  php tests/features-test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-snn-tickets-admin.php';

echo "SNN Tickets feature tests — PHP " . PHP_VERSION . "\n";

$SKIPPED = [];
function skip($label) { global $SKIPPED; $SKIPPED[] = $label; echo "  SKIP: $label\n"; }
function tool($name) {
    $out = []; $rc = 1;
    @exec((stripos(PHP_OS, 'WIN') === 0 ? 'where ' : 'command -v ') . escapeshellarg($name) . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null'), $out, $rc);
    return $rc === 0;
}
function tmpdir() {
    $d = sys_get_temp_dir() . '/snn-feat-' . getmypid() . '-' . mt_rand();
    mkdir($d, 0777, true);
    return $d;
}
function rrmdir($d) {
    foreach ((array)glob($d . '/{,.}[!.,!..]*', GLOB_BRACE) as $f) is_dir($f) ? rrmdir($f) : @unlink($f);
    @rmdir($d);
}

global $wpdb;

function event_obj($over = []) {
    return SNN_T_Events::normalise((object)array_merge([
        'id'          => 7,
        'name'        => 'Design Summit 2026',
        'event_start' => '2026-10-03 19:00:00',
        'event_end'   => '2026-10-03 23:30:00',
        'venue'       => 'Grand Hall',
        'address'     => 'İstiklal Cd. 12, Beyoğlu, İstanbul',
        'organizer'   => 'SNN Events',
        'description' => "Doors open at 18:30.\nBring a photo ID.",
        'design'      => '',
        'attachments' => 'pdf,ics',
    ], $over));
}

/* ================= events ================= */
section('Events');

check(SNN_T_Events::parse_attachments('pdf, ics,bogus,pdf') === ['pdf', 'ics'], 'attachments: unknown and duplicate entries are dropped');
check(SNN_T_Events::sanitize_datetime('2026-10-03T19:00') === '2026-10-03 19:00:00', 'datetime-local input is accepted');
check(SNN_T_Events::sanitize_datetime('') === null, 'blank date becomes NULL');
check(SNN_T_Events::sanitize_datetime('not a date') === null, 'garbage date becomes NULL');

$ev = event_obj();
check(SNN_T_Events::format_time($ev) === '19:00 – 23:30', 'same-day time range is compact (' . SNN_T_Events::format_time($ev) . ')');
check(strpos(SNN_T_Events::format_where($ev), 'Grand Hall, İstiklal') === 0, 'venue and address join');
check(SNN_T_Events::to_utc_timestamp('2026-10-03 19:00:00') === gmmktime(16, 0, 0, 10, 3, 2026), 'site-local time converts to UTC (Istanbul is UTC+3)');
$zero = SNN_T_Events::normalise((object)['id' => 1, 'name' => 'x', 'event_start' => '0000-00-00 00:00:00']);
check($zero->event_start === '' && SNN_T_Events::format_when($zero) === '', 'a zero date counts as no date');

/* ================= designs ================= */
section('Designs');

$presets = SNN_T_Design::presets();
check(count($presets) === 6, 'six ready-made designs');
$need = array_merge(array_keys(SNN_T_Design::color_keys()), ['label', 'layout', 'font', 'radius', 'header_bg2']);
foreach ($presets as $k => $p) {
    check(!array_diff($need, array_keys($p)), "preset $k defines every key");
    foreach (array_keys(SNN_T_Design::color_keys()) as $c) {
        if (SNN_T_Design::sanitize_hex($p[$c]) !== $p[$c]) { check(false, "preset $k colour $c is a clean hex"); break; }
    }
}
check(SNN_T_Design::sanitize_hex('#ABC') === '#aabbcc', 'short hex expands');
check(SNN_T_Design::sanitize_hex('javascript:x') === '', 'non-colours are rejected');
check(SNN_T_Design::mix('#000000', '#ffffff', 0.5) === '#808080', 'mix halfway between black and white');

update_option(SNN_T_Design::OPTION, ['preset' => 'midnight', 'colors' => ['accent' => '#ff0000'], 'logo_url' => '', 'footer' => 'Hi <b>there</b>', 'wallet_links' => 1]);
$d = SNN_T_Design::resolve('');
check($d['key'] === 'midnight' && $d['accent'] === '#ff0000', 'site default preset with its colour override');
$d2 = SNN_T_Design::resolve('classic');
check($d2['key'] === 'classic' && $d2['accent'] === $presets['classic']['accent'], 'an event that picks another preset is not affected by the site overrides');
check(SNN_T_Design::resolve('nope')['key'] === 'midnight', 'unknown preset falls back to the site default');
$san = SNN_T_Design::sanitize_settings(['preset' => 'evil', 'colors' => ['bg' => 'red', 'card' => '#123456', 'x' => '#000000'], 'logo_url' => 'javascript:alert(1)']);
check($san['preset'] === 'minimal' && $san['colors'] === ['card' => '#123456'] && $san['logo_url'] === '', 'design settings are sanitised');

$html = SNN_T_Design::email_html('<p>Body</p>', $d, ['title' => 'T', 'preheader' => 'Pre <x>']);
check(strpos($html, '<!doctype html>') === 0, 'email shell is a full document');
check(strpos($html, 'background:' . $d['bg']) !== false, 'email uses the design background');
check(strpos($html, 'Pre &lt;x&gt;') !== false, 'preheader is escaped');
check(strpos($html, 'Hi &lt;b&gt;there&lt;/b&gt;') !== false, 'footer text is escaped');

/* ================= ticket data, cards, placeholders ================= */
section('Ticket card and placeholders');

$wpdb->rows = [];
$t = SNN_T_Events::build_ticket_data(['code' => 'TK8Q2ZL4', 'name' => 'Şükrü Ağaoğlu <script>', 'email' => 'sukru@example.com'], event_obj());
check($t['when'] !== '' && $t['venue'] === 'Grand Hall', 'ticket data carries event details');
check($t['scan_url'] === SNN_T_QR::scan_url('TK8Q2ZL4'), 'ticket data carries the signed scan URL');

$card = SNN_T_Design::ticket_card_html($t, 'cid:x');
check(strpos($card, '<script>') === false && strpos($card, 'Şükrü Ağaoğlu &lt;script&gt;') !== false, 'ticket card escapes names');
check(strpos($card, 'src="cid:x"') !== false && strpos($card, 'TK8Q2ZL4') !== false, 'ticket card shows the QR and the code');
$t_stub = $t; $t_stub['design'] = SNN_T_Design::resolve('boarding');
check(strpos(SNN_T_Design::ticket_card_html($t_stub, 'cid:x'), 'border-left:2px dashed') !== false, 'boarding-pass layout has a perforated stub');

$vars = SNN_T_Mailer::build_vars(['name' => 'Ada', 'email' => 'ada@example.com', 'ticket_code' => 'TK8Q2ZL4', 'list_name' => 'Design Summit 2026', 'ticket_data' => $t]);
check(strpos($vars['{ticket_card}'], 'cid:' . SNN_T_Mailer::QR_CID) !== false, '{ticket_card} embeds the QR by CID for real mail');
check(strpos($vars['{wallet_buttons}'], 'snn_file=pdf') !== false && strpos($vars['{wallet_buttons}'], 'snn_file=ics') !== false, '{wallet_buttons} links the PDF and calendar');
check(strpos($vars['{wallet_buttons}'], 'pkpass') === false, 'no Apple Wallet button until Apple Wallet is set up');
check(strpos($vars['{pdf_url}'], 'snn_key=' . SNN_T_Files::key('TK8Q2ZL4')) !== false, '{pdf_url} is signed');
check(strpos($vars['{qr_block}'], '<img') !== false, '{qr_block} is an image');

$preview = SNN_T_Mailer::build_vars(['ticket_code' => 'TK8Q2ZL4', 'ticket_data' => $t], 'preview');
check(strpos($preview['{ticket_card}'], 'data:image/png;base64,') !== false, 'previews inline the QR as a data URI');

update_option(SNN_T_Design::OPTION, ['preset' => 'minimal', 'wallet_links' => 0]);
$t_off = $t; $t_off['design'] = SNN_T_Design::resolve('');
$off = SNN_T_Mailer::build_vars(['ticket_code' => 'TK8Q2ZL4', 'ticket_data' => $t_off]);
check($off['{wallet_buttons}'] === '' && $off['{pdf_url}'] !== '', 'turning buttons off hides {wallet_buttons} but keeps {pdf_url}');

$text = SNN_T_Mailer::html_to_text('<html><head><style>p{}</style></head><body><h2>Hi</h2><p>See <a href="https://x.test/a?b=1&amp;c=2">your ticket</a></p><div style="display:none;max-height:0">pre</div></body></html>');
check(strpos($text, 'your ticket (https://x.test/a?b=1&c=2)') !== false, 'plain-text part keeps link targets');
check(strpos($text, 'p{}') === false && strpos($text, 'pre') === false, 'plain-text part drops styles and the hidden preheader');
check(SNN_T_Mailer::is_full_document("  <!DOCTYPE html><html>") && !SNN_T_Mailer::is_full_document('<p>x</p>'), 'full-document detection');

foreach (SNN_T_Mailer::tags() as $tag => $help) {
    if ($tag === '{field:key}') continue;
    if (!array_key_exists($tag, $vars)) { check(false, "documented tag $tag is produced by build_vars"); }
}
check(true, 'every documented tag is produced by build_vars');

/* ================= download links ================= */
section('Signed download links');

check(SNN_T_Files::key('ABC') === SNN_T_Files::key('ABC') && SNN_T_Files::key('ABC') !== SNN_T_Files::key('ABD'), 'file keys are per-ticket and stable');
check(SNN_T_Files::key('ABC') !== SNN_T_QR::sign('ABC'), 'file keys differ from the check-in signature');
$links = array_column(SNN_T_Files::links($t), 'key');
check($links === ['pdf', 'ics'], 'links: PDF and calendar without wallets (' . implode(',', $links) . ')');
$t_nodate = SNN_T_Events::build_ticket_data(['code' => 'X1'], event_obj(['event_start' => null]));
check(array_column(SNN_T_Files::links($t_nodate), 'key') === ['pdf'], 'no calendar link without a start date');

/* ================= check-in rules ================= */
section('Check-in rules');

$ticket_row = (object)['id' => 5, 'list_id' => 7, 'ticket_code' => 'TK8Q2ZL4', 'name' => 'Ada', 'email' => 'a@b.c', 'status' => 'active', 'validate_count' => 0, 'last_validated' => null];
$wpdb->rows = ['row' => $ticket_row, 'var' => 'Design Summit 2026'];
$wpdb->queries = [];
$r = SNN_T_Tickets::validate('TK8Q2ZL4', SNN_T_QR::sign('TK8Q2ZL4'), false);
check($r['valid'] && $r['signed'] && !$r['counted'], 'a signed QR opened by a non-staff visitor is valid but NOT counted');
check(!array_filter($wpdb->queries, function ($q) { return stripos($q, 'validate_count + 1') !== false; }), 'no check-in write for the attendee');

$wpdb->queries = [];
$r = SNN_T_Tickets::validate('TK8Q2ZL4', SNN_T_QR::sign('TK8Q2ZL4'), true);
check($r['valid'] && $r['counted'] && $r['validate_count'] === 1 && !$r['already_used'], 'staff scan checks the ticket in');
check((bool)array_filter($wpdb->queries, function ($q) { return stripos($q, 'validate_count = validate_count + 1') !== false; }), 'check-in is an atomic increment');

$ticket_row->validate_count = 1; $ticket_row->last_validated = date('Y-m-d H:i:s', time() - 120);
$r = SNN_T_Tickets::validate('TK8Q2ZL4', '', true);
check($r['valid'] && $r['already_used'] && $r['validate_count'] === 2 && $r['last_validated_human'] !== '', 'a second scan is flagged with when it was first used');

$r = SNN_T_Tickets::validate('TK8Q2ZL4', '', true, 99);
check(!$r['valid'] && $r['reason'] === 'other_list', 'a scanner locked to another event refuses the ticket');

$ticket_row->status = 'revoked';
$r = SNN_T_Tickets::validate('TK8Q2ZL4', '', true);
check(!$r['valid'] && $r['reason'] === 'revoked', 'revoked tickets are refused');

$wpdb->rows = ['row' => null];
$r = SNN_T_Tickets::validate('NOPE', '', true);
check(!$r['valid'] && $r['reason'] === 'not_found', 'unknown codes are refused');
check(SNN_T_Tickets::validate('', '', true)['valid'] === false, 'empty codes are refused');
$wpdb->rows = [];

/* ================= PDF ================= */
section('PDF ticket');

$enc = SNN_T_PDF_Doc::encode('Şükrü Ağaoğlu İzmir ıĞ Łódź – €');
check(strpos($enc, '?') === false, 'Turkish and Central European letters all map into the font encoding');
check(strlen($enc) === mb_strlen('Şükrü Ağaoğlu İzmir ıĞ Łódź – €'), 'one byte per character');
check(SNN_T_PDF_Doc::encode('日本') !== '', 'characters outside the encoding degrade instead of breaking');
check(abs(SNN_T_PDF_Doc::text_width('Hello', 'F1', 10) - 22.78) < 0.01, 'Helvetica metrics are right (Hello @10pt = 22.78pt)');
check(SNN_T_PDF_Doc::text_width('Ş', 'F2', 10) === SNN_T_PDF_Doc::text_width('S', 'F2', 10), 'accented letters measure like their base letter');
$wrapped = SNN_T_PDF_Doc::wrap(str_repeat('word ', 40), 'F1', 10, 120);
check(count($wrapped) > 5 && max(array_map(function ($l) { return SNN_T_PDF_Doc::text_width($l, 'F1', 10); }, $wrapped)) <= 120, 'wrapping keeps every line inside the box');
check(SNN_T_PDF_Doc::fit(str_repeat('x', 200), 'F1', 10, 50) !== str_repeat('x', 200), 'fit() shortens long text');

$has_pdftotext = tool('pdftotext');
$dir = tmpdir();
foreach (array_keys($presets) as $key) {
    $tk = $t; $tk['name'] = 'Şükrü Ağaoğlu'; $tk['design'] = SNN_T_Design::resolve($key);
    $pdf = SNN_T_PDF::ticket($tk);
    $ok = is_string($pdf) && strpos($pdf, '%PDF-1.4') === 0 && substr(rtrim($pdf), -5) === '%%EOF';
    check($ok, "$key: produces a complete PDF (" . (is_string($pdf) ? strlen($pdf) : 0) . ' bytes)');
    if (!$ok) continue;

    // Every xref offset must land exactly on its object header.
    preg_match('/startxref\s+(\d+)/', $pdf, $m);
    $xref = (int)$m[1];
    $good = substr($pdf, $xref, 4) === 'xref';
    preg_match_all('/^(\d{10}) 00000 n $/m', substr($pdf, $xref), $mm);
    foreach ($mm[1] as $i => $off) {
        if (strpos(substr($pdf, (int)$off, 20), ($i + 1) . ' 0 obj') !== 0) { $good = false; break; }
    }
    check($good && count($mm[1]) > 8, "$key: cross-reference table is exact");

    if ($has_pdftotext) {
        $file = "$dir/$key.pdf";
        file_put_contents($file, $pdf);
        $txt = shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($file) . ' - 2>&1');
        check(strpos($txt, 'Şükrü Ağaoğlu') !== false, "$key: pdftotext reads the Turkish name back exactly");
        check(strpos($txt, 'Design Summit 2026') !== false && strpos($txt, 'TK8Q2ZL4') !== false, "$key: event name and ticket code are real text");
        check(strpos($txt, 'Grand Hall') !== false && strpos($txt, 'Beyoğlu') !== false, "$key: venue and address are printed");
    }
}
if (!$has_pdftotext) skip('pdftotext not installed — PDF text round-trip not checked');
if ($has_pdftotext && tool('pdftoppm')) {
    $pdf = SNN_T_PDF::ticket($t);
    file_put_contents("$dir/render.pdf", $pdf);
    shell_exec('pdftoppm -png -r 40 -singlefile ' . escapeshellarg("$dir/render.pdf") . ' ' . escapeshellarg("$dir/render") . ' 2>&1');
    check(is_file("$dir/render.png") && filesize("$dir/render.png") > 1000, 'poppler renders the PDF to an image');
}
$tr = $t; $tr['status'] = 'revoked';
$pdf = SNN_T_PDF::ticket($tr);
check(strpos(gzuncompress(substr($pdf, strpos($pdf, "stream\n") + 7, strpos($pdf, "\nendstream") - strpos($pdf, "stream\n") - 7)), 'REVOKED') !== false, 'revoked tickets are stamped REVOKED');

/* ================= Apple Wallet ================= */
section('Apple Wallet (.pkpass)');

$has_openssl_cli = tool('openssl');
$conf = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
if (stripos(PHP_OS, 'WIN') === 0 && !getenv('OPENSSL_CONF')) {
    // PHP on Windows needs a config file to make CSRs.
    $cnf = tmpdir() . '/openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:true\n[v3_leaf]\nbasicConstraints=CA:false\n");
    $conf['config'] = $cnf;
}
$ca_key = openssl_pkey_new($conf);
$ca_csr = openssl_csr_new(['commonName' => 'Test WWDR CA'], $ca_key, $conf);
$ca     = openssl_csr_sign($ca_csr, null, $ca_key, 30, $conf + ['x509_extensions' => 'v3_ca']);
$lf_key = openssl_pkey_new($conf);
$lf_csr = openssl_csr_new(['commonName' => 'Pass Type ID: pass.test.tickets', 'UID' => 'pass.test.tickets'], $lf_key, $conf);
$leaf   = openssl_csr_sign($lf_csr, $ca, $ca_key, 30, $conf + ['x509_extensions' => 'v3_leaf']);
openssl_x509_export($ca, $ca_pem);
openssl_x509_export($leaf, $leaf_pem);
openssl_pkey_export($lf_key, $leaf_key_pem, 'secret', $conf);
openssl_pkcs12_export($leaf, $p12, $lf_key, 'secret');
openssl_x509_export($ca, $ca_der_pem);
$ca_der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $ca_der_pem));

check(SNN_T_Wallet::to_pem($ca_der) === SNN_T_Wallet::to_pem($ca_pem) || strpos(SNN_T_Wallet::to_pem($ca_der), 'BEGIN CERTIFICATE') !== false, 'a DER .cer upload converts to PEM');

$apple = [
    'pass_type_id' => 'pass.test.tickets', 'team_id' => 'ABCDE12345', 'org_name' => 'SNN Events',
    'p12' => base64_encode($p12), 'p12_password' => 'secret', 'cert_pem' => '', 'key_pem' => '', 'wwdr_pem' => SNN_T_Wallet::to_pem($ca_der),
];
update_option(SNN_T_Wallet::APPLE_OPTION, $apple);
check(SNN_T_Wallet::apple_ready(), 'Apple Wallet counts as ready once credentials are saved');

$bad = $apple; $bad['p12_password'] = 'wrong';
check(is_wp_error(SNN_T_Wallet::apple_credentials($bad)), 'a wrong .p12 password is reported, not ignored');

foreach (['p12' => $apple, 'pem' => array_merge($apple, ['p12' => '', 'cert_pem' => $leaf_pem, 'key_pem' => $leaf_key_pem])] as $mode => $settings) {
    $pass = SNN_T_Wallet::apple_pkpass($t, $settings);
    check(is_string($pass) && substr($pass, 0, 2) === 'PK', "$mode: builds a ZIP");
    if (!is_string($pass)) { if (is_wp_error($pass)) echo '    ' . $pass->get_error_message() . "\n"; continue; }

    $pdir = tmpdir();
    file_put_contents("$pdir/t.pkpass", $pass);
    $zip = new ZipArchive();
    $zip->open("$pdir/t.pkpass");
    $zip->extractTo("$pdir/x");
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
    $zip->close();
    sort($names);
    check($names === ['icon.png', 'icon@2x.png', 'icon@3x.png', 'manifest.json', 'pass.json', 'signature'], "$mode: contains exactly the required files");

    $manifest = json_decode(file_get_contents("$pdir/x/manifest.json"), true);
    $hashes_ok = count($manifest) === 4;
    foreach ($manifest as $f => $h) $hashes_ok = $hashes_ok && sha1_file("$pdir/x/$f") === $h;
    check($hashes_ok, "$mode: manifest SHA-1s match every file");

    $pj = json_decode(file_get_contents("$pdir/x/pass.json"), true);
    check($pj['serialNumber'] === 'TK8Q2ZL4' && $pj['passTypeIdentifier'] === 'pass.test.tickets' && $pj['teamIdentifier'] === 'ABCDE12345', "$mode: identifiers are set");
    check($pj['barcodes'][0]['message'] === $t['scan_url'] && $pj['barcodes'][0]['format'] === 'PKBarcodeFormatQR', "$mode: QR carries the signed scan URL");
    check(preg_match('/^rgb\(\d+, \d+, \d+\)$/', $pj['backgroundColor']) === 1, "$mode: colours use Wallet's rgb() format");
    check($pj['relevantDate'] === '2026-10-03T16:00:00Z', "$mode: relevantDate is the start time in UTC");
    check($pj['eventTicket']['secondaryFields'][0]['value'] === 'Şükrü Ağaoğlu <script>', "$mode: attendee name is stored as plain text");
    $png = getimagesize("$pdir/x/icon@2x.png");
    check($png && $png[0] === 58 && $png[1] === 58, "$mode: icon@2x is 58×58");

    if ($has_openssl_cli) {
        $cmd = 'openssl cms -verify -binary -inform DER -in ' . escapeshellarg("$pdir/x/signature")
             . ' -content ' . escapeshellarg("$pdir/x/manifest.json")
             . ' -CAfile ' . escapeshellarg(($f = "$pdir/ca.pem")) . ' -purpose any -out ' . escapeshellarg("$pdir/out") . ' 2>&1';
        file_put_contents($f, $ca_pem);
        $out = shell_exec($cmd);
        check(strpos((string)$out, 'Verification successful') !== false, "$mode: openssl verifies the detached signature over manifest.json");
        $certs = shell_exec('openssl pkcs7 -inform DER -in ' . escapeshellarg("$pdir/x/signature") . ' -print_certs -noout 2>&1');
        check(strpos($certs, 'Test WWDR CA') !== false && strpos($certs, 'pass.test.tickets') !== false, "$mode: signature embeds the pass certificate and the WWDR intermediate");
    }
    rrmdir($pdir);
}
if (!$has_openssl_cli) skip('openssl CLI not installed — pkpass signature not independently verified');

$tr = $t; $tr['status'] = 'revoked';
check(!empty(SNN_T_Wallet::apple_pass_json($tr, $apple)['voided']), 'revoked tickets become voided passes');
$links = array_column(SNN_T_Files::links($t), 'key');
check($links[0] === 'pkpass', 'with Apple Wallet ready, its button comes first');

/* ================= Google Wallet ================= */
section('Google Wallet');

$gkey = openssl_pkey_new($conf);
openssl_pkey_export($gkey, $gpem, null, $conf);
$gpub = openssl_pkey_get_details($gkey)['key'];
$sa = SNN_T_Wallet::parse_service_account(json_encode(['type' => 'service_account', 'client_email' => 'wallet@proj.iam.gserviceaccount.com', 'private_key' => $gpem]));
check(!is_wp_error($sa) && $sa['client_email'] === 'wallet@proj.iam.gserviceaccount.com', 'service account JSON is parsed');
check(is_wp_error(SNN_T_Wallet::parse_service_account('{"nope":1}')), 'a wrong JSON file is rejected');

update_option(SNN_T_Wallet::GOOGLE_OPTION, ['issuer_id' => '3388000000012345678'] + $sa);
check(SNN_T_Wallet::google_ready(), 'Google Wallet counts as ready');

$url = SNN_T_Wallet::google_save_url($t);
check(strpos($url, 'https://pay.google.com/gp/v/save/') === 0, 'save link has the Google Wallet prefix');
list($h, $p, $sig) = explode('.', substr($url, strlen('https://pay.google.com/gp/v/save/')));
$b64 = function ($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); };
check(openssl_verify("$h.$p", $b64($sig), $gpub, OPENSSL_ALGO_SHA256) === 1, 'JWT signature verifies with the service account public key (RS256)');
$claims = json_decode($b64($p), true);
check($claims['aud'] === 'google' && $claims['typ'] === 'savetowallet' && $claims['iss'] === $sa['client_email'], 'JWT claims are what Google expects');
$obj = $claims['payload']['eventTicketObjects'][0];
$cls = $claims['payload']['eventTicketClasses'][0];
check($obj['classId'] === $cls['id'] && strpos($obj['id'], '3388000000012345678.') === 0, 'object belongs to the class under the issuer');
check(preg_match('/^\d+\.[A-Za-z0-9._-]+$/', $obj['id']) === 1 && preg_match('/^\d+\.[A-Za-z0-9._-]+$/', $cls['id']) === 1, 'IDs only use characters Google allows');
check($obj['barcode']['value'] === $t['scan_url'] && $obj['barcode']['type'] === 'QR_CODE', 'barcode carries the signed scan URL');
check($cls['dateTime']['start'] === '2026-10-03T16:00:00Z', 'class start time is UTC');
check(SNN_T_Wallet::google_payload($tr, get_option(SNN_T_Wallet::GOOGLE_OPTION))['payload']['eventTicketObjects'][0]['state'] === 'INACTIVE', 'revoked tickets are inactive');

/* ================= calendar ================= */
section('Calendar invite');

$ics = SNN_T_Wallet::ics($t);
check(strpos($ics, "BEGIN:VCALENDAR\r\n") === 0 && substr($ics, -15) === "END:VCALENDAR\r\n", 'CRLF line endings, wrapped in VCALENDAR');
check(strpos($ics, 'DTSTART:20261003T160000Z') !== false && strpos($ics, 'DTEND:20261003T203000Z') !== false, 'start and end are in UTC');
$longest = max(array_map('strlen', explode("\r\n", $ics)));
check($longest <= 75, "lines are folded to 75 octets (longest $longest)");
$unfolded = str_replace("\r\n ", '', $ics);
check(strpos($unfolded, 'LOCATION:Grand Hall\, İstiklal Cd. 12\, Beyoğlu\, İstanbul') !== false, 'commas are escaped and UTF-8 survives folding');
check(strpos($unfolded, 'Doors open at 18:30.\nBring a photo ID.') !== false, 'newlines in the description are escaped');
check(mb_check_encoding($ics, 'UTF-8'), 'folding never splits a UTF-8 character');
check(SNN_T_Wallet::ics($t_nodate) === '', 'no calendar file without a date');

/* ================= forms ================= */
section('Form validation');

$form = (object)['id' => 1, 'name' => 'F', 'list_id' => 7, 'status' => 'active', 'settings' => SNN_T_Forms::default_settings(), 'fields' => SNN_T_Forms::sanitize_fields([
    ['key' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => 1, 'map_to' => 'name'],
    ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => 1, 'map_to' => 'email'],
    ['key' => 'track', 'label' => 'Track', 'type' => 'select', 'options' => ['A', 'B']],
    ['key' => 'gdpr', 'label' => 'I agree', 'type' => 'consent', 'required' => 1],
])];
list(, $errors) = SNN_T_Forms::collect($form, ['name' => '', 'email' => 'bad', 'track' => 'Z']);
check(array_keys($errors) === ['name', 'email', 'track', 'gdpr'], 'errors are keyed by field so they can show under each field');
check($errors['gdpr'] === 'Please tick this box to continue.', 'consent boxes get a friendly message');
list($data, $errors, $name, $email) = SNN_T_Forms::collect($form, ['name' => 'Ada', 'email' => 'ada@example.com', 'track' => 'B', 'gdpr' => '1']);
check(!$errors && $name === 'Ada' && $email === 'ada@example.com', 'a valid submission passes');
$st = SNN_T_Forms::sanitize_settings(['accent_color' => '#AbCdEf', 'show_remaining' => '1']);
check($st['accent_color'] === '#abcdef' && $st['show_remaining'] === 1, 'form style settings are kept');
check(SNN_T_Forms::sanitize_settings(['accent_color' => 'red;}body{'])['accent_color'] === '', 'a malformed accent colour is dropped');
$saved = SNN_T_Forms::save(0, ['name' => 'x', 'list_id' => 1]);
check($saved > 0, 'save() works without an explicit status (used to write NULL)');

/* ================= CSV import ================= */
section('CSV import');

$csvdir = tmpdir();
file_put_contents("$csvdir/a.csv", "\xEF\xBB\xBFName,Email\nJane Doe,JANE@example.com\n,\nŞükrü Ağaoğlu,sukru@example.com\nNo Mail,not-an-email\n");
$rows = SNN_T_Tickets_Admin::parse_csv("$csvdir/a.csv");
check(!is_wp_error($rows) && count($rows) === 3, 'a BOM-prefixed file parses and blank rows are skipped');
check($rows[0]['email'] === 'jane@example.com' && $rows[1]['name'] === 'Şükrü Ağaoğlu' && $rows[2]['email'] === '', 'emails are normalised and bad ones cleared');
file_put_contents("$csvdir/b.csv", "Ad Soyad;E-posta\nAyşe Yılmaz;ayse@example.com\n");
$rows = SNN_T_Tickets_Admin::parse_csv("$csvdir/b.csv");
check(!is_wp_error($rows) && $rows[0]['name'] === 'Ayşe Yılmaz' && $rows[0]['email'] === 'ayse@example.com', 'semicolon CSVs with Turkish headers parse (Excel TR export)');
file_put_contents("$csvdir/c.csv", "Foo,Bar\n1,2\n");
check(is_wp_error(SNN_T_Tickets_Admin::parse_csv("$csvdir/c.csv")), 'a CSV without Name/Email columns is refused');
rrmdir($csvdir);
rrmdir($dir);

/* ================= results ================= */

$qdir = wp_upload_dir()['basedir'];
if (is_dir($qdir)) rrmdir($qdir);

echo "\n" . str_repeat('=', 58) . "\n";
echo "PASS: $PASS   FAIL: $FAIL" . ($SKIPPED ? '   SKIPPED: ' . count($SKIPPED) : '') . "\n";
if ($FAIL) {
    echo "\nFailures:\n";
    foreach ($FAILURES as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL CHECKS PASSED\n";
exit(0);
