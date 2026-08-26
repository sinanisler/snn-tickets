<?php
/**
 * Server-side QR generation, signed scan URLs and the QR file cache.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_QR {

    const SECRET_OPTION   = 'snn_tickets_secret';
    const SCAN_URL_OPTION = 'snn_tickets_scan_url';
    const DIR             = 'snn-tickets-qr';

    /**
     * HMAC key for ticket signatures. Created once, then reused.
     */
    public static function secret() {
        $secret = get_option(self::SECRET_OPTION);
        if (!$secret) {
            $secret = wp_generate_password(64, true, true);
            update_option(self::SECRET_OPTION, $secret, false);
        }
        return $secret;
    }

    /**
     * Short signature proving a ticket code came from this site.
     */
    public static function sign($code) {
        return substr(hash_hmac('sha256', 'ticket:' . $code, self::secret()), 0, 16);
    }

    public static function verify($code, $sig) {
        if (!is_string($sig) || $sig === '') return false;
        return hash_equals(self::sign($code), $sig);
    }

    /**
     * The URL a scanned QR opens. Encoded into the QR itself, so it has to
     * work in any phone camera, not just our own scanner page.
     */
    public static function scan_url($code) {
        $base = trim((string)get_option(self::SCAN_URL_OPTION, ''));
        if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)) {
            $base = home_url('/');
        }
        return add_query_arg([
            'snn_ticket' => rawurlencode($code),
            'snn_sig'    => self::sign($code),
        ], $base);
    }

    /* ------------------------------------------------------------------
     * File cache
     * ---------------------------------------------------------------- */

    /**
     * Unguessable filename, so the QR directory cannot be enumerated.
     */
    private static function filename($code) {
        return 'qr-' . substr(hash_hmac('sha256', 'file:' . $code, self::secret()), 0, 32) . '.png';
    }

    private static function dir_info() {
        $uploads = wp_upload_dir();
        return [
            'path' => trailingslashit($uploads['basedir']) . self::DIR,
            'url'  => trailingslashit($uploads['baseurl']) . self::DIR,
        ];
    }

    public static function path($code) {
        $d = self::dir_info();
        return $d['path'] . '/' . self::filename($code);
    }

    public static function url($code) {
        $d = self::dir_info();
        return $d['url'] . '/' . self::filename($code);
    }

    /**
     * Generate the PNG if it is not cached yet.
     *
     * @return string|WP_Error absolute file path
     */
    public static function ensure($code, $scale = 8, $margin = 4) {
        $code = (string)$code;
        if ($code === '') {
            return new WP_Error('snn_qr_empty', 'Empty ticket code');
        }

        $path = self::path($code);
        if (file_exists($path) && filesize($path) > 0) {
            return $path;
        }

        $d = self::dir_info();
        if (!file_exists($d['path'])) {
            wp_mkdir_p($d['path']);
            // No directory listing, but the PNGs themselves stay fetchable so
            // they can be linked from an email.
            @file_put_contents($d['path'] . '/index.html', '');
        }

        require_once dirname(__DIR__) . '/qrcode.php';

        try {
            $qr = new SNN_QRCode(self::scan_url($code), [
                'errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_M,
            ]);
            if (!$qr->savePng($path, $scale, $margin)) {
                return new WP_Error('snn_qr_write', 'Could not write the QR image to ' . $path);
            }
        } catch (Throwable $e) {
            return new WP_Error('snn_qr_generate', 'QR generation failed: ' . $e->getMessage());
        }

        return $path;
    }

    /**
     * Public URL for a ticket's QR, generating it on first use.
     *
     * @return string|WP_Error
     */
    public static function ensure_url($code, $scale = 8, $margin = 4) {
        $path = self::ensure($code, $scale, $margin);
        if (is_wp_error($path)) return $path;
        return self::url($code);
    }

    /**
     * Inline data: URI, for admin previews only. Too large for most email
     * clients, which is why mail uses a CID attachment instead.
     */
    public static function data_uri($code, $scale = 6, $margin = 4) {
        require_once dirname(__DIR__) . '/qrcode.php';
        try {
            $qr = new SNN_QRCode(self::scan_url($code), [
                'errorCorrectLevel' => SNN_QRCode::ERROR_CORRECT_M,
            ]);
            return $qr->toDataUri($scale, $margin);
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function delete($code) {
        $path = self::path($code);
        if (file_exists($path)) @unlink($path);
    }

    /**
     * Drop every cached PNG — used after the signing secret changes, since
     * old images encode URLs that will no longer verify.
     */
    public static function flush_cache() {
        $d = self::dir_info();
        if (!is_dir($d['path'])) return 0;
        $n = 0;
        foreach ((array)glob($d['path'] . '/qr-*.png') as $file) {
            if (@unlink($file)) $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------
     * Scan entry point
     * ---------------------------------------------------------------- */

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_handle_scan']);
    }

    /**
     * A camera app opening the QR lands on the site root with our query args.
     * Send it to the configured scan page, or render a minimal result page.
     */
    public static function maybe_handle_scan() {
        if (empty($_GET['snn_ticket'])) return;

        $code = sanitize_text_field(wp_unslash($_GET['snn_ticket']));
        $sig  = sanitize_text_field(wp_unslash($_GET['snn_sig'] ?? ''));

        $configured = trim((string)get_option(self::SCAN_URL_OPTION, ''));

        if ($configured !== '') {
            $target_path  = untrailingslashit((string)parse_url($configured, PHP_URL_PATH));
            $current_path = untrailingslashit((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

            // Already on the scan page: let the shortcode handle it, and
            // never redirect to ourselves.
            if ($target_path !== $current_path) {
                wp_safe_redirect(add_query_arg([
                    'snn_ticket' => rawurlencode($code),
                    'snn_sig'    => $sig,
                ], $configured));
                exit;
            }
            return;
        }

        // No scan page configured: render a self-contained result page.
        $result = SNN_T_Tickets::validate($code, $sig, current_user_can('manage_options'));

        status_header(200);
        nocache_headers();
        self::render_standalone_result($result);
        exit;
    }

    private static function render_standalone_result($result) {
        $ok      = !empty($result['valid']);
        $color   = $ok ? '#0a7d32' : '#b3261e';
        $heading = $ok ? 'Valid ticket' : 'Not valid';
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($heading); ?></title>
        </head>
        <body style="font-family:system-ui,-apple-system,sans-serif;margin:0;padding:32px;background:#f6f7f7;">
            <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;">
                <h1 style="margin:0 0 12px;color:<?php echo esc_attr($color); ?>;"><?php echo esc_html($heading); ?></h1>
                <p style="margin:0 0 8px;"><?php echo esc_html($result['message'] ?? ''); ?></p>
                <?php if ($ok): ?>
                    <p style="margin:0;"><strong><?php echo esc_html($result['name'] ?: 'Guest'); ?></strong><br>
                    <?php echo esc_html($result['list_name'] ?? ''); ?><br>
                    <code><?php echo esc_html($result['ticket_code']); ?></code></p>
                <?php endif; ?>
            </div>
        </body>
        </html><?php
    }
}
