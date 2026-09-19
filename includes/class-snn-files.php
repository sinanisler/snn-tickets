<?php
/**
 * Signed download links for a ticket's files, and the attendee ticket page.
 *
 * Links look like /?snn_file=pdf&snn_code=ABC&snn_key=<hmac>. The key is
 * an HMAC of the code, so links in an email work without a login while
 * nobody can fetch another person's ticket by guessing codes.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Files {

    public static function formats() {
        return ['pdf', 'pkpass', 'ics', 'gwallet', 'view'];
    }

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_serve'], 5);
    }

    public static function key($code) {
        return substr(hash_hmac('sha256', 'file:' . $code, SNN_T_QR::secret()), 0, 20);
    }

    public static function url($format, $code) {
        return add_query_arg([
            'snn_file' => $format,
            'snn_code' => rawurlencode($code),
            'snn_key'  => self::key($code),
        ], home_url('/'));
    }

    /**
     * Download links to show under a ticket, in the order they appear.
     *
     * @return array [['key' =>, 'label' =>, 'url' =>], ...]
     */
    public static function links($t) {
        if ($t['code'] === '') return [];
        $links = [];
        if (SNN_T_Wallet::apple_ready()) {
            $links[] = ['key' => 'pkpass', 'label' => __('Add to Apple Wallet', 'snn-tickets'), 'url' => self::url('pkpass', $t['code'])];
        }
        if (SNN_T_Wallet::google_ready()) {
            $links[] = ['key' => 'gwallet', 'label' => __('Add to Google Wallet', 'snn-tickets'), 'url' => self::url('gwallet', $t['code'])];
        }
        $links[] = ['key' => 'pdf', 'label' => __('Download PDF', 'snn-tickets'), 'url' => self::url('pdf', $t['code'])];
        if ($t['start']) {
            $links[] = ['key' => 'ics', 'label' => __('Add to calendar', 'snn-tickets'), 'url' => self::url('ics', $t['code'])];
        }
        return $links;
    }

    public static function maybe_serve() {
        if (empty($_GET['snn_file'])) return;

        $format = sanitize_key(wp_unslash($_GET['snn_file']));
        $code   = sanitize_text_field(wp_unslash($_GET['snn_code'] ?? ''));
        $key    = sanitize_text_field(wp_unslash($_GET['snn_key'] ?? ''));

        $ok = in_array($format, self::formats(), true)
           && $code !== ''
           && (hash_equals(self::key($code), $key) || current_user_can('manage_options'));

        $ticket = $ok ? SNN_T_Tickets::get_by_code($code) : null;
        if (!$ticket) {
            status_header(404);
            nocache_headers();
            wp_die(esc_html__('This ticket link is not valid.', 'snn-tickets'), esc_html__('Ticket not found', 'snn-tickets'), ['response' => 404]);
        }

        $t = SNN_T_Events::ticket_data($ticket);
        self::serve($format, $t);
    }

    /**
     * Produce a file for a ticket.
     *
     * @return array|WP_Error ['bytes' =>, 'mime' =>, 'name' =>]
     */
    public static function build($format, $t) {
        $base = sanitize_file_name(($t['event'] !== '' ? $t['event'] : 'ticket') . '-' . $t['code']);

        switch ($format) {
            case 'pdf':
                $bytes = SNN_T_PDF::ticket($t);
                if (is_wp_error($bytes)) return $bytes;
                return ['bytes' => $bytes, 'mime' => 'application/pdf', 'name' => $base . '.pdf'];

            case 'pkpass':
                if (!SNN_T_Wallet::apple_ready()) return new WP_Error('snn_apple_off', __('Apple Wallet is not set up.', 'snn-tickets'));
                $bytes = SNN_T_Wallet::apple_pkpass($t);
                if (is_wp_error($bytes)) return $bytes;
                return ['bytes' => $bytes, 'mime' => 'application/vnd.apple.pkpass', 'name' => $base . '.pkpass'];

            case 'ics':
                $bytes = SNN_T_Wallet::ics($t);
                if ($bytes === '') return new WP_Error('snn_ics_nodate', __('This event has no date yet.', 'snn-tickets'));
                return ['bytes' => $bytes, 'mime' => 'text/calendar; charset=utf-8', 'name' => $base . '.ics'];
        }

        return new WP_Error('snn_file_format', __('Unknown file type.', 'snn-tickets'));
    }

    private static function serve($format, $t) {
        nocache_headers();

        if ($format === 'view') {
            self::render_ticket_page($t);
            exit;
        }

        if ($format === 'gwallet') {
            if (!SNN_T_Wallet::google_ready()) wp_die(esc_html__('Google Wallet is not set up.', 'snn-tickets'));
            $url = SNN_T_Wallet::google_save_url($t);
            if (is_wp_error($url)) wp_die(esc_html($url->get_error_message()));
            // Not wp_safe_redirect: the destination is Google, on purpose.
            wp_redirect($url);
            exit;
        }

        $file = self::build($format, $t);
        if (is_wp_error($file)) {
            wp_die(esc_html($file->get_error_message()));
        }

        $disposition = $format === 'pdf' ? 'inline' : 'attachment';
        header('Content-Type: ' . $file['mime']);
        header('Content-Disposition: ' . $disposition . '; filename="' . $file['name'] . '"');
        header('Content-Length: ' . strlen($file['bytes']));
        header('X-Content-Type-Options: nosniff');
        echo $file['bytes']; // phpcs:ignore -- binary file
        exit;
    }

    /**
     * The attendee's own view of their ticket: what a phone shows when the
     * holder taps the QR link in their email. Read-only -- it never counts
     * as a check-in.
     */
    public static function render_ticket_page($t, $notice = '') {
        $d = $t['design'];
        $qr = SNN_T_QR::data_uri($t['code'], 8, 2);
        $card = SNN_T_Design::ticket_card_html($t, $qr);
        $links = SNN_T_Files::links($t);
        $buttons = SNN_T_Design::buttons_html($links, $d);
        $revoked = ($t['status'] ?? '') === 'revoked';

        status_header(200);
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(__('Your ticket – %s', 'snn-tickets'), $t['event'] !== '' ? $t['event'] : get_bloginfo('name'))); ?></title>
    <style>
        body{margin:0;background:<?php echo esc_attr($d['bg']); ?>;font-family:<?php echo esc_attr($d['font_stack']); ?>;color:<?php echo esc_attr($d['text']); ?>}
        .w{max-width:520px;margin:0 auto;padding:24px 16px 40px}
        .n{padding:12px 14px;border-radius:8px;margin:0 0 12px;font-size:14px;background:<?php echo esc_attr($d['card']); ?>;border-left:4px solid <?php echo esc_attr($d['accent']); ?>}
        .n.bad{border-color:#b3261e}
        .foot{font-size:12px;color:<?php echo esc_attr($d['muted']); ?>;text-align:center;margin-top:18px}
        .foot a{color:inherit}
    </style>
</head>
<body>
<div class="w">
    <?php if ($revoked): ?>
        <div class="n bad"><?php esc_html_e('This ticket has been cancelled and will not be accepted at the entrance.', 'snn-tickets'); ?></div>
    <?php elseif ($notice !== ''): ?>
        <div class="n"><?php echo esc_html($notice); ?></div>
    <?php endif; ?>
    <?php echo $card; // built from escaped parts ?>
    <?php if (!$revoked) echo $buttons; ?>
    <?php if ($t['description'] !== ''): ?>
        <p style="font-size:14px;line-height:1.6;"><?php echo nl2br(esc_html($t['description'])); ?></p>
    <?php endif; ?>
    <p class="foot"><a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(get_bloginfo('name')); ?></a></p>
</div>
</body>
</html><?php
    }
}
