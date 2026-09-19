<?php
/**
 * Ticket and email designs.
 *
 * One design drives every surface a ticket appears on: the HTML email, the
 * PDF, the Apple Wallet pass and the Google Wallet object. A design is a
 * preset (layout + palette + type) plus the admin's overrides from the
 * Design screen. Lists can pick their own preset.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Design {

    const OPTION = 'snn_tickets_design';

    /** Colour keys the admin can override. */
    public static function color_keys() {
        return [
            'bg'          => __('Page background', 'snn-tickets'),
            'card'        => __('Card background', 'snn-tickets'),
            'text'        => __('Text', 'snn-tickets'),
            'muted'       => __('Muted text', 'snn-tickets'),
            'accent'      => __('Accent / buttons', 'snn-tickets'),
            'accent_text' => __('Button text', 'snn-tickets'),
            'header_bg'   => __('Header background', 'snn-tickets'),
            'header_text' => __('Header text', 'snn-tickets'),
        ];
    }

    public static function presets() {
        return [
            'minimal' => [
                'label'       => __('Minimal', 'snn-tickets'),
                'blurb'       => __('White card, black type, thin rules.', 'snn-tickets'),
                'layout'      => 'card',
                'font'        => 'sans',
                'radius'      => 8,
                'bg'          => '#f4f4f5',
                'card'        => '#ffffff',
                'text'        => '#18181b',
                'muted'       => '#71717a',
                'accent'      => '#18181b',
                'accent_text' => '#ffffff',
                'header_bg'   => '#ffffff',
                'header_text' => '#18181b',
                'header_bg2'  => '',
            ],
            'boarding' => [
                'label'       => __('Boarding pass', 'snn-tickets'),
                'blurb'       => __('Horizontal ticket with a perforated QR stub.', 'snn-tickets'),
                'layout'      => 'stub',
                'font'        => 'sans',
                'radius'      => 14,
                'bg'          => '#e8eef6',
                'card'        => '#ffffff',
                'text'        => '#0f172a',
                'muted'       => '#64748b',
                'accent'      => '#1d4ed8',
                'accent_text' => '#ffffff',
                'header_bg'   => '#1d4ed8',
                'header_text' => '#ffffff',
                'header_bg2'  => '',
            ],
            'midnight' => [
                'label'       => __('Midnight', 'snn-tickets'),
                'blurb'       => __('Dark card with a neon accent. Concerts and nightlife.', 'snn-tickets'),
                'layout'      => 'card',
                'font'        => 'sans',
                'radius'      => 16,
                'bg'          => '#09090b',
                'card'        => '#18181b',
                'text'        => '#fafafa',
                'muted'       => '#a1a1aa',
                'accent'      => '#a3e635',
                'accent_text' => '#09090b',
                'header_bg'   => '#18181b',
                'header_text' => '#a3e635',
                'header_bg2'  => '',
            ],
            'festival' => [
                'label'       => __('Festival', 'snn-tickets'),
                'blurb'       => __('Gradient header and bold display type.', 'snn-tickets'),
                'layout'      => 'stub',
                'font'        => 'sans',
                'radius'      => 20,
                'bg'          => '#fdf2f8',
                'card'        => '#ffffff',
                'text'        => '#1e1b4b',
                'muted'       => '#6b7280',
                'accent'      => '#db2777',
                'accent_text' => '#ffffff',
                'header_bg'   => '#7c3aed',
                'header_text' => '#ffffff',
                'header_bg2'  => '#db2777',
            ],
            'corporate' => [
                'label'       => __('Corporate', 'snn-tickets'),
                'blurb'       => __('Logo bar, grey panel, blue accent. Conferences.', 'snn-tickets'),
                'layout'      => 'card',
                'font'        => 'sans',
                'radius'      => 4,
                'bg'          => '#eef1f5',
                'card'        => '#ffffff',
                'text'        => '#1f2937',
                'muted'       => '#6b7280',
                'accent'      => '#0b63ce',
                'accent_text' => '#ffffff',
                'header_bg'   => '#1f2937',
                'header_text' => '#ffffff',
                'header_bg2'  => '',
            ],
            'classic' => [
                'label'       => __('Classic stub', 'snn-tickets'),
                'blurb'       => __('Cream paper and serif type. Theatre and galas.', 'snn-tickets'),
                'layout'      => 'stub',
                'font'        => 'serif',
                'radius'      => 2,
                'bg'          => '#efe8da',
                'card'        => '#fbf7ee',
                'text'        => '#3b2f22',
                'muted'       => '#8a7a64',
                'accent'      => '#8b1e2d',
                'accent_text' => '#fbf7ee',
                'header_bg'   => '#8b1e2d',
                'header_text' => '#fbf7ee',
                'header_bg2'  => '',
            ],
        ];
    }

    public static function defaults() {
        return [
            'preset'       => 'minimal',
            'colors'       => [],
            'logo_url'     => '',
            'footer'       => '',
            'wallet_links' => 1,
        ];
    }

    public static function settings() {
        $s = get_option(self::OPTION, []);
        $s = array_merge(self::defaults(), is_array($s) ? $s : []);
        if (!isset(self::presets()[$s['preset']])) $s['preset'] = 'minimal';
        $s['colors'] = is_array($s['colors']) ? $s['colors'] : [];
        return $s;
    }

    public static function sanitize_settings($in) {
        $out = self::defaults();
        $in  = is_array($in) ? $in : [];

        if (isset(self::presets()[$in['preset'] ?? ''])) $out['preset'] = $in['preset'];

        foreach (array_keys(self::color_keys()) as $k) {
            $c = self::sanitize_hex($in['colors'][$k] ?? '');
            if ($c !== '') $out['colors'][$k] = $c;
        }

        $out['logo_url']     = esc_url_raw($in['logo_url'] ?? '');
        $out['footer']       = sanitize_textarea_field($in['footer'] ?? '');
        $out['wallet_links'] = !empty($in['wallet_links']) ? 1 : 0;
        return $out;
    }

    public static function sanitize_hex($c) {
        $c = trim((string)$c);
        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $c, $m)) return '#' . strtolower($m[1]);
        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $c, $m)) {
            $h = strtolower($m[1]);
            return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return '';
    }

    /**
     * The resolved design for a preset key (blank = site default). Global
     * colour overrides apply only when the site-default preset is in use,
     * so a list that deliberately picks "Midnight" really gets Midnight.
     */
    public static function resolve($preset_key = '') {
        $settings = self::settings();
        $presets  = self::presets();

        $use_global = ($preset_key === '' || !isset($presets[$preset_key]) || $preset_key === $settings['preset']);
        $key        = $use_global ? $settings['preset'] : $preset_key;

        $d = $presets[$key];
        if ($use_global) {
            foreach ($settings['colors'] as $k => $v) {
                if (isset($d[$k]) && $v !== '') $d[$k] = $v;
            }
        }

        $d['key']          = $key;
        $d['logo_url']     = $settings['logo_url'];
        $d['footer']       = $settings['footer'];
        $d['wallet_links'] = (int)$settings['wallet_links'];
        $d['font_stack']   = $d['font'] === 'serif'
            ? "Georgia,'Times New Roman',Times,serif"
            : "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        return $d;
    }

    public static function for_list($event) {
        return self::resolve($event ? (string)$event->design : '');
    }

    /* ------------------------------------------------------------------
     * Colour helpers
     * ---------------------------------------------------------------- */

    /** @return int[] [r, g, b] 0-255 */
    public static function rgb($hex) {
        $hex = ltrim(self::sanitize_hex($hex) ?: '#000000', '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function rgb_css($hex) {
        list($r, $g, $b) = self::rgb($hex);
        return "rgb($r, $g, $b)";
    }

    /** Mix two colours; $t = 0 gives $a, 1 gives $b. */
    public static function mix($a, $b, $t) {
        $x = self::rgb($a); $y = self::rgb($b);
        $o = '#';
        for ($i = 0; $i < 3; $i++) {
            $o .= str_pad(dechex((int)round($x[$i] + ($y[$i] - $x[$i]) * $t)), 2, '0', STR_PAD_LEFT);
        }
        return $o;
    }

    /* ------------------------------------------------------------------
     * Email rendering
     * ---------------------------------------------------------------- */

    /**
     * Wrap a message body in the designed, table-based email shell. Tables
     * and inline styles only, so Outlook and Gmail render it the same.
     */
    public static function email_html($inner, $design, $args = []) {
        $d = $design;
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        $title     = $args['title'] ?? get_bloginfo('name');
        $preheader = $args['preheader'] ?? '';
        $site      = get_bloginfo('name');

        $header_bg = $d['header_bg2']
            ? "background:{$d['header_bg']};background-image:linear-gradient(120deg,{$d['header_bg']},{$d['header_bg2']});"
            : "background:{$d['header_bg']};";
        $border = $d['header_bg'] === $d['card'] ? 'border-bottom:1px solid ' . self::mix($d['card'], $d['text'], 0.1) . ';' : '';

        $brand = $d['logo_url']
            ? '<img src="' . $e($d['logo_url']) . '" alt="' . $e($site) . '" height="36" style="display:block;height:36px;max-width:200px;border:0;">'
            : '<span style="font-size:18px;font-weight:700;letter-spacing:.02em;color:' . $d['header_text'] . ';">' . $e($site) . '</span>';

        $footer = $d['footer'] !== '' ? nl2br($e($d['footer'])) . '<br>' : '';
        $footer .= '<a href="' . $e(home_url('/')) . '" style="color:' . $d['muted'] . ';">' . $e($site) . '</a>';

        $r = (int)$d['radius'];

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $d['bg'] . ';">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $d['bg'] . ';">'
            . '<tr><td align="center" style="padding:32px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:' . $d['card'] . ';border-radius:' . $r . 'px;overflow:hidden;">'
            . '<tr><td style="' . $header_bg . $border . 'padding:22px 32px;font-family:' . $d['font_stack'] . ';">' . $brand . '</td></tr>'
            . '<tr><td style="padding:28px 32px 32px;font-family:' . $d['font_stack'] . ';font-size:15px;line-height:1.6;color:' . $d['text'] . ';">'
            . $inner
            . '</td></tr></table>'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">'
            . '<tr><td align="center" style="padding:18px 24px;font-family:' . $d['font_stack'] . ';font-size:12px;line-height:1.6;color:' . $d['muted'] . ';">'
            . $footer
            . '</td></tr></table>'
            . '</td></tr></table></body></html>';
    }

    /**
     * The ticket itself, as email-safe HTML. $qr_src is either a cid: or
     * a data: URI (previews).
     */
    public static function ticket_card_html($t, $qr_src) {
        $d = $t['design'];
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        $line  = self::mix($d['card'], $d['text'], 0.12);
        $panel = self::mix($d['card'], $d['text'], 0.04);
        $r     = (int)$d['radius'];

        $row = function ($label, $value) use ($d, $e) {
            if ($value === '') return '';
            return '<tr><td style="padding:0 0 12px;">'
                 . '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:' . $d['muted'] . ';">' . $e($label) . '</div>'
                 . '<div style="font-size:15px;font-weight:600;color:' . $d['text'] . ';">' . $e($value) . '</div>'
                 . '</td></tr>';
        };

        $details = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . $row(__('Attendee', 'snn-tickets'), $t['name'] !== '' ? $t['name'] : __('Guest', 'snn-tickets'))
            . $row(__('Date', 'snn-tickets'), $t['date'])
            . $row(__('Time', 'snn-tickets'), $t['time'])
            . $row(__('Venue', 'snn-tickets'), trim($t['venue'] . ($t['address'] !== '' ? ' · ' . $t['address'] : ''), ' ·'))
            . '</table>';

        $head_bg = $d['header_bg2']
            ? "background:{$d['header_bg']};background-image:linear-gradient(120deg,{$d['header_bg']},{$d['header_bg2']});"
            : "background:{$d['accent']};";
        $head_color = $d['header_bg2'] ? $d['header_text'] : $d['accent_text'];

        $head = '<tr><td colspan="2" style="' . $head_bg . 'padding:16px 20px;color:' . $head_color . ';">'
              . '<div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;opacity:.85;">' . $e(__('Admit one', 'snn-tickets')) . '</div>'
              . '<div style="font-size:20px;font-weight:700;line-height:1.3;">' . $e($t['event'] !== '' ? $t['event'] : get_bloginfo('name')) . '</div>'
              . '</td></tr>';

        $qr = '<img src="' . $e($qr_src) . '" width="150" height="150" alt="' . $e(__('Ticket QR code', 'snn-tickets')) . '" style="display:block;margin:0 auto;width:150px;height:150px;border:0;background:#fff;padding:6px;border-radius:6px;">'
            . '<div style="margin-top:8px;text-align:center;font-family:Menlo,Consolas,monospace;font-size:13px;font-weight:700;letter-spacing:.08em;color:' . $d['text'] . ';">' . $e($t['code']) . '</div>';

        if ($d['layout'] === 'stub') {
            $body = '<tr>'
                  . '<td valign="top" style="padding:20px;">' . $details . '</td>'
                  . '<td valign="middle" width="190" style="width:190px;padding:20px 16px;background:' . $panel . ';border-left:2px dashed ' . $line . ';">' . $qr . '</td>'
                  . '</tr>';
        } else {
            $body = '<tr><td colspan="2" style="padding:20px 20px 4px;">' . $details . '</td></tr>'
                  . '<tr><td colspan="2" align="center" style="padding:16px 20px 20px;border-top:1px dashed ' . $line . ';background:' . $panel . ';">' . $qr . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
             . 'style="margin:18px 0;border:1px solid ' . $line . ';border-radius:' . $r . 'px;overflow:hidden;border-collapse:separate;background:' . $d['card'] . ';">'
             . $head . $body . '</table>';
    }

    /**
     * Row of link buttons: wallet passes, PDF and calendar.
     *
     * @param array $links [['label' => , 'url' => ], ...]
     */
    public static function buttons_html($links, $design) {
        $d = $design;
        if (!$links) return '';
        $out = '';
        foreach ($links as $i => $l) {
            $primary = $i === 0;
            $style = $primary
                ? "background:{$d['accent']};color:{$d['accent_text']};border:1px solid {$d['accent']};"
                : "background:transparent;color:{$d['text']};border:1px solid " . self::mix($d['card'], $d['text'], 0.25) . ';';
            $out .= '<a href="' . esc_url($l['url']) . '" style="display:inline-block;margin:0 6px 8px 0;padding:10px 16px;'
                  . 'border-radius:' . min(24, (int)$d['radius'] + 2) . 'px;font-size:14px;font-weight:600;text-decoration:none;' . $style . '">'
                  . htmlspecialchars($l['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        return '<div style="margin:8px 0 18px;">' . $out . '</div>';
    }
}
