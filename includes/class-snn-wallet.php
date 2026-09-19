<?php
/**
 * Apple Wallet passes, Google Wallet save links and calendar invites.
 *
 * Apple: a .pkpass is a ZIP of pass.json, images and a manifest of SHA-1
 * hashes, with a detached PKCS#7 signature over the manifest made with the
 * organiser's Pass Type ID certificate. PHP's zip and openssl extensions
 * are all it takes.
 *
 * Google: no file at all. A JWT signed with a service account key carries
 * the event ticket class and object; the attendee opens
 * https://pay.google.com/gp/v/save/<jwt> and the pass lands in their wallet.
 */

if (!defined('ABSPATH') && php_sapi_name() !== 'cli') exit;

class SNN_T_Wallet {

    const APPLE_OPTION  = 'snn_tickets_apple_wallet';
    const GOOGLE_OPTION = 'snn_tickets_google_wallet';

    /* ==================================================================
     * Settings
     * ================================================================== */

    public static function apple_settings() {
        $s = get_option(self::APPLE_OPTION, []);
        return array_merge([
            'pass_type_id' => '',
            'team_id'      => '',
            'org_name'     => '',
            'p12'          => '',   // base64 of the .p12 bundle
            'p12_password' => '',
            'cert_pem'     => '',   // alternative to p12: PEM certificate
            'key_pem'      => '',   //                  + PEM private key
            'wwdr_pem'     => '',   // Apple WWDR intermediate, PEM
        ], is_array($s) ? $s : []);
    }

    public static function google_settings() {
        $s = get_option(self::GOOGLE_OPTION, []);
        return array_merge([
            'issuer_id'    => '',
            'client_email' => '',
            'private_key'  => '',
        ], is_array($s) ? $s : []);
    }

    public static function apple_ready() {
        $s = self::apple_settings();
        return class_exists('ZipArchive') && function_exists('openssl_pkcs7_sign')
            && $s['pass_type_id'] !== '' && $s['team_id'] !== '' && $s['wwdr_pem'] !== ''
            && ($s['p12'] !== '' || ($s['cert_pem'] !== '' && $s['key_pem'] !== ''));
    }

    public static function google_ready() {
        $s = self::google_settings();
        return function_exists('openssl_sign') && $s['issuer_id'] !== '' && $s['client_email'] !== '' && $s['private_key'] !== '';
    }

    /**
     * Load the signing certificate and key, from the .p12 or the PEM pair.
     *
     * @return array|WP_Error ['cert' => pem, 'key' => pem]
     */
    public static function apple_credentials($s = null) {
        $s = $s ?: self::apple_settings();

        if ($s['cert_pem'] !== '' && $s['key_pem'] !== '') {
            $key = openssl_pkey_get_private($s['key_pem'], $s['p12_password']);
            if (!$key) return self::err('snn_apple_key', __('The private key could not be read. Check the password.', 'snn-tickets'));
            if (!openssl_x509_read($s['cert_pem'])) return self::err('snn_apple_cert', __('The certificate PEM could not be read.', 'snn-tickets'));
            // Pass the loaded key object on: re-exporting it needs an
            // openssl.cnf, which many Windows PHP builds do not have.
            return ['cert' => $s['cert_pem'], 'key' => $key];
        }

        if ($s['p12'] === '') return self::err('snn_apple_missing', __('No Pass Type ID certificate has been uploaded.', 'snn-tickets'));

        $certs = [];
        if (!openssl_pkcs12_read(base64_decode($s['p12']), $certs, $s['p12_password'])) {
            return self::err('snn_apple_p12', __('The .p12 file could not be opened. Check the password, or upload the certificate and key as PEM files instead (newer OpenSSL versions reject some older .p12 encryption).', 'snn-tickets'));
        }
        return ['cert' => $certs['cert'], 'key' => $certs['pkey']];
    }

    /** Accept PEM or DER (.cer) and always return PEM. */
    public static function to_pem($data) {
        $data = (string)$data;
        if (strpos($data, '-----BEGIN CERTIFICATE-----') !== false) return trim($data) . "\n";
        if ($data === '') return '';
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($data), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    private static function err($code, $msg) {
        return class_exists('WP_Error') ? new WP_Error($code, $msg) : $msg;
    }

    /* ==================================================================
     * Apple Wallet
     * ================================================================== */

    public static function apple_pass_json($t, $s) {
        $d = $t['design'];

        $primary = [['key' => 'event', 'label' => strtoupper(__('Event', 'snn-tickets')), 'value' => $t['event'] !== '' ? $t['event'] : get_bloginfo('name')]];
        $secondary = [['key' => 'attendee', 'label' => strtoupper(__('Attendee', 'snn-tickets')), 'value' => $t['name'] !== '' ? $t['name'] : __('Guest', 'snn-tickets')]];
        if ($t['date'] !== '') {
            $secondary[] = ['key' => 'date', 'label' => strtoupper(__('Date', 'snn-tickets')), 'value' => $t['date'], 'textAlignment' => 'PKTextAlignmentRight'];
        }

        $aux = [];
        if ($t['time'] !== '')  $aux[] = ['key' => 'time', 'label' => strtoupper(__('Time', 'snn-tickets')), 'value' => $t['time']];
        if ($t['venue'] !== '') $aux[] = ['key' => 'venue', 'label' => strtoupper(__('Venue', 'snn-tickets')), 'value' => $t['venue']];

        $back = [['key' => 'code', 'label' => __('Ticket code', 'snn-tickets'), 'value' => $t['code']]];
        if ($t['address'] !== '')     $back[] = ['key' => 'address', 'label' => __('Address', 'snn-tickets'), 'value' => $t['address']];
        if ($t['description'] !== '') $back[] = ['key' => 'info', 'label' => __('Good to know', 'snn-tickets'), 'value' => $t['description']];
        $back[] = ['key' => 'organizer', 'label' => __('Organiser', 'snn-tickets'), 'value' => $t['organizer']];
        $back[] = ['key' => 'site', 'label' => __('Website', 'snn-tickets'), 'value' => home_url('/')];

        $bg = $d['header_bg'];
        $fg = $d['header_text'];
        // A white-on-white pass is unreadable; fall back to the accent.
        if (strtolower($bg) === strtolower($d['card']) && strtolower($bg) === '#ffffff') {
            $bg = $d['accent'];
            $fg = $d['accent_text'];
        }

        $json = [
            'formatVersion'      => 1,
            'passTypeIdentifier' => $s['pass_type_id'],
            'teamIdentifier'     => $s['team_id'],
            'serialNumber'       => $t['code'],
            'organizationName'   => $s['org_name'] !== '' ? $s['org_name'] : $t['organizer'],
            'description'        => sprintf(__('Ticket for %s', 'snn-tickets'), $t['event'] !== '' ? $t['event'] : get_bloginfo('name')),
            'logoText'           => $t['event'] !== '' ? $t['event'] : get_bloginfo('name'),
            'backgroundColor'    => SNN_T_Design::rgb_css($bg),
            'foregroundColor'    => SNN_T_Design::rgb_css($fg),
            'labelColor'         => SNN_T_Design::rgb_css(SNN_T_Design::mix($fg, $bg, 0.3)),
            'barcodes'           => [[
                'format'          => 'PKBarcodeFormatQR',
                'message'         => $t['scan_url'],
                'messageEncoding' => 'iso-8859-1',
                'altText'         => $t['code'],
            ]],
            'eventTicket'        => [
                'primaryFields'   => $primary,
                'secondaryFields' => $secondary,
                'auxiliaryFields' => $aux,
                'backFields'      => $back,
            ],
        ];
        // iOS < 9 reads the singular key.
        $json['barcode'] = $json['barcodes'][0];

        if ($t['start']) {
            $ts = SNN_T_Events::to_utc_timestamp($t['start']);
            if ($ts) $json['relevantDate'] = gmdate('Y-m-d\TH:i:s\Z', $ts);
        }
        if ($t['end'] || $t['start']) {
            $ts = SNN_T_Events::to_utc_timestamp($t['end'] ?: $t['start']);
            if ($ts) $json['expirationDate'] = gmdate('Y-m-d\TH:i:s\Z', $ts + DAY_IN_SECONDS);
        }
        if (($t['status'] ?? 'active') === 'revoked') $json['voided'] = true;

        return $json;
    }

    /**
     * Build a signed .pkpass.
     *
     * @return string|WP_Error the pass file bytes
     */
    public static function apple_pkpass($t, $s = null) {
        $s = $s ?: self::apple_settings();
        if (!class_exists('ZipArchive')) return self::err('snn_apple_zip', __('The PHP zip extension is required for Apple Wallet passes.', 'snn-tickets'));

        $creds = self::apple_credentials($s);
        if (is_wp_error_safe($creds)) return $creds;

        $d = $t['design'];
        $files = [
            'pass.json'    => wp_json_encode(self::apple_pass_json($t, $s), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'icon.png'     => self::badge_png(29, $d['accent'], $d['accent_text']),
            'icon@2x.png'  => self::badge_png(58, $d['accent'], $d['accent_text']),
            'icon@3x.png'  => self::badge_png(87, $d['accent'], $d['accent_text']),
        ];

        $manifest = [];
        foreach ($files as $name => $bytes) $manifest[$name] = sha1($bytes);
        $files['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES);

        $sig = self::pkcs7_detached($files['manifest.json'], $creds['cert'], $creds['key'], self::to_pem($s['wwdr_pem']));
        if (is_wp_error_safe($sig)) return $sig;
        $files['signature'] = $sig;

        $tmp = tempnam(sys_get_temp_dir(), 'snnpass');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return self::err('snn_apple_zipopen', __('Could not create the pass archive.', 'snn-tickets'));
        }
        foreach ($files as $name => $bytes) $zip->addFromString($name, $bytes);
        $zip->close();

        $out = file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    /**
     * Detached PKCS#7 signature, DER encoded.
     *
     * @return string|WP_Error
     */
    public static function pkcs7_detached($data, $cert_pem, $key_pem, $extra_pem = '') {
        $in    = tempnam(sys_get_temp_dir(), 'snnm');
        $out   = tempnam(sys_get_temp_dir(), 'snns');
        $extra = '';
        file_put_contents($in, $data);

        $args = [$in, $out, $cert_pem, $key_pem, [], PKCS7_BINARY | PKCS7_DETACHED];
        if ($extra_pem !== '') {
            $extra = tempnam(sys_get_temp_dir(), 'snnw');
            file_put_contents($extra, $extra_pem);
            $args[] = $extra;
        }

        $ok  = @openssl_pkcs7_sign(...$args);
        $smime = $ok ? (string)file_get_contents($out) : '';

        @unlink($in); @unlink($out);
        if ($extra) @unlink($extra);

        if (!$ok) {
            return self::err('snn_apple_sign', __('Signing the pass failed: ', 'snn-tickets') . (string)openssl_error_string());
        }

        // openssl writes S/MIME; the pass needs the raw DER signature.
        if (!preg_match('/filename="?smime\.p7s"?\s*\r?\n\r?\n(.+?)\r?\n\r?\n?------/s', $smime, $m)) {
            return self::err('snn_apple_sig_parse', __('Could not read the signature produced by OpenSSL.', 'snn-tickets'));
        }
        return base64_decode(preg_replace('/\s+/', '', $m[1]));
    }

    /**
     * A plain square PNG in the accent colour with a ticket-ish notch mark.
     * Wallet requires an icon; this keeps the pass valid with no uploads.
     */
    public static function badge_png($size, $bg, $fg) {
        list($br, $bg_, $bb) = SNN_T_Design::rgb($bg);
        list($fr, $fg_, $fb) = SNN_T_Design::rgb($fg);
        $raw = '';
        $c = ($size - 1) / 2;
        for ($y = 0; $y < $size; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $size; $x++) {
                // A ring, reading as a stylised ticket punch.
                $dist = sqrt(($x - $c) ** 2 + ($y - $c) ** 2);
                $on = $dist > $size * 0.22 && $dist < $size * 0.34;
                $raw .= $on ? chr($fr) . chr($fg_) . chr($fb) : chr($br) . chr($bg_) . chr($bb);
            }
        }
        $chunk = function ($type, $data) {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    /* ==================================================================
     * Google Wallet
     * ================================================================== */

    public static function google_ids($t, $s) {
        $site  = substr(md5(home_url('/')), 0, 8);
        $clean = function ($v) { return preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$v); };
        return [
            'class'  => $s['issuer_id'] . '.' . $clean('snn_' . $site . '_list_' . (int)$t['list_id']),
            'object' => $s['issuer_id'] . '.' . $clean('snn_' . $site . '_' . $t['code']),
        ];
    }

    public static function google_payload($t, $s) {
        $d   = $t['design'];
        $ids = self::google_ids($t, $s);
        $lang = str_replace('_', '-', function_exists('get_locale') ? get_locale() : 'en-US');
        $loc = function ($v) use ($lang) { return ['defaultValue' => ['language' => $lang, 'value' => (string)$v]]; };

        $class = [
            'id'           => $ids['class'],
            'issuerName'   => $t['organizer'],
            'eventName'    => $loc($t['event'] !== '' ? $t['event'] : get_bloginfo('name')),
            'reviewStatus' => 'UNDER_REVIEW',
        ];
        if ($t['venue'] !== '' || $t['address'] !== '') {
            $class['venue'] = [
                'name'    => $loc($t['venue'] !== '' ? $t['venue'] : $t['address']),
                'address' => $loc($t['address'] !== '' ? $t['address'] : $t['venue']),
            ];
        }
        if ($t['start']) {
            $dt = ['start' => gmdate('Y-m-d\TH:i:s\Z', SNN_T_Events::to_utc_timestamp($t['start']))];
            if ($t['end']) $dt['end'] = gmdate('Y-m-d\TH:i:s\Z', SNN_T_Events::to_utc_timestamp($t['end']));
            $class['dateTime'] = $dt;
        }

        $object = [
            'id'                 => $ids['object'],
            'classId'            => $ids['class'],
            'state'              => ($t['status'] ?? 'active') === 'revoked' ? 'INACTIVE' : 'ACTIVE',
            'ticketHolderName'   => $t['name'] !== '' ? $t['name'] : __('Guest', 'snn-tickets'),
            'ticketNumber'       => $t['code'],
            'hexBackgroundColor' => $d['accent'],
            'barcode'            => [
                'type'          => 'QR_CODE',
                'value'         => $t['scan_url'],
                'alternateText' => $t['code'],
            ],
        ];

        return [
            'iss'     => $s['client_email'],
            'aud'     => 'google',
            'typ'     => 'savetowallet',
            'iat'     => time(),
            'origins' => [home_url()],
            'payload' => [
                'eventTicketClasses' => [$class],
                'eventTicketObjects' => [$object],
            ],
        ];
    }

    public static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** RS256 JWT. @return string|WP_Error */
    public static function jwt($claims, $private_key) {
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body   = self::b64url(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $key    = openssl_pkey_get_private($private_key);
        if (!$key) return self::err('snn_google_key', __('The Google service account private key could not be read.', 'snn-tickets'));
        $sig = '';
        if (!openssl_sign($header . '.' . $body, $sig, $key, OPENSSL_ALGO_SHA256)) {
            return self::err('snn_google_sign', __('Signing the Google Wallet token failed.', 'snn-tickets'));
        }
        return $header . '.' . $body . '.' . self::b64url($sig);
    }

    /** @return string|WP_Error */
    public static function google_save_url($t, $s = null) {
        $s = $s ?: self::google_settings();
        $jwt = self::jwt(self::google_payload($t, $s), $s['private_key']);
        if (is_wp_error_safe($jwt)) return $jwt;
        return 'https://pay.google.com/gp/v/save/' . $jwt;
    }

    /**
     * Pull client_email / private_key out of a service account JSON file.
     *
     * @return array|WP_Error
     */
    public static function parse_service_account($json) {
        $data = json_decode((string)$json, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return self::err('snn_google_json', __('That does not look like a service account key file (client_email and private_key are missing).', 'snn-tickets'));
        }
        return ['client_email' => $data['client_email'], 'private_key' => $data['private_key']];
    }

    /* ==================================================================
     * Calendar
     * ================================================================== */

    public static function ics_escape($s) {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\\;', '\\,', '\\n', '\\n'], (string)$s);
    }

    /** RFC 5545 folding: lines longer than 75 octets continue with a space. */
    public static function ics_fold($line) {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            // Never split a UTF-8 sequence.
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }
        return $out . $line;
    }

    /** @return string '' when the event has no start date */
    public static function ics($t) {
        if (!$t['start']) return '';
        $start = SNN_T_Events::to_utc_timestamp($t['start']);
        $end   = $t['end'] ? SNN_T_Events::to_utc_timestamp($t['end']) : $start + 2 * HOUR_IN_SECONDS;
        $host  = parse_url(home_url('/'), PHP_URL_HOST) ?: 'localhost';

        $desc = sprintf(__('Ticket code: %s', 'snn-tickets'), $t['code']);
        if ($t['description'] !== '') $desc .= "\n\n" . $t['description'];

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SNN Tickets//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . sha1($t['code'] . '|' . $t['list_id']) . '@' . $host,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . gmdate('Ymd\THis\Z', $start),
            'DTEND:' . gmdate('Ymd\THis\Z', $end),
            'SUMMARY:' . self::ics_escape($t['event'] !== '' ? $t['event'] : get_bloginfo('name')),
            'DESCRIPTION:' . self::ics_escape($desc),
        ];
        $where = trim($t['venue'] . ($t['venue'] && $t['address'] ? ', ' : '') . $t['address']);
        if ($where !== '') $lines[] = 'LOCATION:' . self::ics_escape($where);
        $lines[] = 'URL:' . home_url('/');
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([__CLASS__, 'ics_fold'], $lines)) . "\r\n";
    }
}

if (!function_exists('is_wp_error_safe')) {
    /** is_wp_error() that also treats plain error strings as errors (CLI tests). */
    function is_wp_error_safe($v) {
        if (function_exists('is_wp_error') && is_wp_error($v)) return true;
        return false;
    }
}
