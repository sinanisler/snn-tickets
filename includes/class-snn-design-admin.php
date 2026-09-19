<?php
/**
 * The Design screen: pick a ready-made design, tune its colours, add a
 * logo, and preview the email and the PDF before saving.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Design_Admin {

    public static function init() {
        add_action('admin_post_snn_save_design', [__CLASS__, 'handle_save']);
        add_action('admin_post_snn_design_pdf',  [__CLASS__, 'handle_pdf_preview']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function assets($hook) {
        if (strpos((string)$hook, 'snn-tickets-design') === false) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();
    }

    /** A small CSS mock-up of a preset, for the picker. */
    public static function thumb($p) {
        $head = $p['header_bg2']
            ? "background:linear-gradient(120deg,{$p['header_bg']},{$p['header_bg2']});"
            : "background:{$p['header_bg']};" . ($p['header_bg'] === $p['card'] ? 'border-bottom:1px solid ' . SNN_T_Design::mix($p['card'], $p['text'], .12) . ';' : '');
        $font = $p['font'] === 'serif' ? 'Georgia,serif' : 'inherit';
        $line = SNN_T_Design::mix($p['card'], $p['text'], .15);
        $qr = '<span style="display:block;width:34px;height:34px;background:'
            . 'repeating-linear-gradient(90deg,' . $p['text'] . ' 0 4px,transparent 4px 7px),'
            . 'repeating-linear-gradient(0deg,' . $p['text'] . ' 0 4px,transparent 4px 7px);background-blend-mode:multiply;opacity:.85;border:3px solid #fff;outline:1px solid ' . $line . '"></span>';

        $details = '<span style="display:block;height:5px;width:60%;background:' . $p['muted'] . ';opacity:.5;border-radius:2px;margin:0 0 5px"></span>'
                 . '<span style="display:block;height:7px;width:80%;background:' . $p['text'] . ';border-radius:2px;margin:0 0 8px"></span>'
                 . '<span style="display:block;height:5px;width:45%;background:' . $p['muted'] . ';opacity:.5;border-radius:2px;margin:0 0 5px"></span>'
                 . '<span style="display:block;height:7px;width:55%;background:' . $p['text'] . ';border-radius:2px"></span>';

        $body = $p['layout'] === 'stub'
            ? '<span style="display:flex"><span style="flex:1;padding:10px">' . $details . '</span>'
              . '<span style="width:58px;display:flex;align-items:center;justify-content:center;border-left:2px dashed ' . $line . ';background:' . SNN_T_Design::mix($p['card'], $p['text'], .04) . '">' . $qr . '</span></span>'
            : '<span style="display:flex;align-items:center;gap:8px;padding:10px"><span style="flex:1">' . $details . '</span>' . $qr . '</span>';

        return '<span class="snn-thumb" style="background:' . $p['bg'] . ';font-family:' . $font . '">'
             . '<span style="display:block;background:' . $p['card'] . ';border-radius:' . min(10, $p['radius']) . 'px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.15)">'
             . '<span style="display:block;' . $head . 'padding:8px 10px;color:' . $p['header_text'] . ';font-weight:700;font-size:12px">Summit 2026</span>'
             . $body . '</span></span>';
    }

    public static function render() {
        SNN_T_Admin::cap();
        $s       = SNN_T_Design::settings();
        $presets = SNN_T_Design::presets();
        $colors  = SNN_T_Design::color_keys();
        $active  = $presets[$s['preset']];

        global $wpdb;
        $lists = $wpdb->get_results("SELECT id, name FROM " . SNN_T_DB::lists() . " ORDER BY id DESC LIMIT 50");
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Design', 'snn-tickets'); ?></h1>
            <p class="snn-muted"><?php esc_html_e('One design styles the ticket email, the PDF, the wallet passes and the attendee ticket page. Each event can also pick its own preset in its event settings.', 'snn-tickets'); ?>
                <?php printf(esc_html__('The design is the frame; the words inside each email come from %s.', 'snn-tickets'), '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-templates')) . '">' . esc_html__('Email templates', 'snn-tickets') . '</a>'); ?></p>
            <?php SNN_T_Admin::notice(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="snn-design-form">
                <input type="hidden" name="action" value="snn_save_design">
                <?php wp_nonce_field('snn_save_design'); ?>

                <div class="snn-card">
                    <h2><?php esc_html_e('Choose a design', 'snn-tickets'); ?></h2>
                    <div class="snn-presets">
                        <?php foreach ($presets as $key => $p): ?>
                            <label class="snn-preset">
                                <input type="radio" name="design[preset]" value="<?php echo esc_attr($key); ?>" <?php checked($s['preset'], $key); ?>
                                       data-colors="<?php echo esc_attr(wp_json_encode(array_intersect_key($p, $colors))); ?>">
                                <?php echo self::thumb($p); // built from sanitised preset values ?>
                                <strong><?php echo esc_html($p['label']); ?></strong>
                                <span class="snn-muted"><?php echo esc_html($p['blurb']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="snn-grid snn-grid-2">
                    <div class="snn-card">
                        <h2><?php esc_html_e('Colours', 'snn-tickets'); ?></h2>
                        <p class="snn-muted"><?php esc_html_e('Start from the preset and change what you like. "Reset" brings back the preset colour.', 'snn-tickets'); ?></p>
                        <table class="form-table" role="presentation">
                            <?php foreach ($colors as $k => $label):
                                $val = $s['colors'][$k] ?? $active[$k]; ?>
                                <tr><th><label for="c_<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></label></th>
                                    <td><input type="text" class="snn-color" id="c_<?php echo esc_attr($k); ?>" name="design[colors][<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($val); ?>" data-key="<?php echo esc_attr($k); ?>"></td></tr>
                            <?php endforeach; ?>
                        </table>
                        <p><button type="button" class="button" data-reset><?php esc_html_e('Reset all colours to the preset', 'snn-tickets'); ?></button></p>
                    </div>

                    <div class="snn-card">
                        <h2><?php esc_html_e('Branding', 'snn-tickets'); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="d_logo"><?php esc_html_e('Logo', 'snn-tickets'); ?></label></th>
                                <td>
                                    <div id="d_logo_prev" style="margin:0 0 8px;<?php echo $s['logo_url'] ? '' : 'display:none'; ?>"><img src="<?php echo esc_url($s['logo_url']); ?>" alt="" style="max-height:48px;max-width:220px;background:#f0f0f1;padding:6px;border-radius:4px"></div>
                                    <input type="url" id="d_logo" name="design[logo_url]" class="regular-text" value="<?php echo esc_attr($s['logo_url']); ?>">
                                    <button type="button" class="button" data-media><?php esc_html_e('Choose image', 'snn-tickets'); ?></button>
                                    <p class="description"><?php esc_html_e('Shown in the email header and on the PDF. A wide PNG or JPG around 400×120 works best.', 'snn-tickets'); ?></p>
                                </td></tr>
                            <tr><th><label for="d_footer"><?php esc_html_e('Email footer', 'snn-tickets'); ?></label></th>
                                <td><textarea id="d_footer" name="design[footer]" class="large-text" rows="3" placeholder="<?php esc_attr_e('Questions? Reply to this email.', 'snn-tickets'); ?>"><?php echo esc_textarea($s['footer']); ?></textarea></td></tr>
                            <tr><th><?php esc_html_e('Buttons', 'snn-tickets'); ?></th>
                                <td><label><input type="checkbox" name="design[wallet_links]" value="1" <?php checked($s['wallet_links']); ?>> <?php esc_html_e('Show Wallet / PDF / calendar buttons where {wallet_buttons} is used', 'snn-tickets'); ?></label></td></tr>
                        </table>

                        <h2><?php esc_html_e('Preview', 'snn-tickets'); ?></h2>
                        <p>
                            <label for="d_list"><?php esc_html_e('With event', 'snn-tickets'); ?></label>
                            <select id="d_list">
                                <option value="0"><?php esc_html_e('Sample event', 'snn-tickets'); ?></option>
                                <?php foreach ($lists as $l): ?><option value="<?php echo (int)$l->id; ?>"><?php echo esc_html($l->name); ?></option><?php endforeach; ?>
                            </select>
                        </p>
                        <p class="snn-actions">
                            <button type="button" class="button" data-preview-email><span class="dashicons dashicons-email" style="vertical-align:-4px"></span> <?php esc_html_e('Preview ticket email', 'snn-tickets'); ?></button>
                            <button type="button" class="button" data-preview-pdf><span class="dashicons dashicons-media-document" style="vertical-align:-4px"></span> <?php esc_html_e('Preview PDF', 'snn-tickets'); ?></button>
                        </p>
                        <p class="snn-muted" style="font-size:12px"><?php esc_html_e('Previews use your unsaved changes.', 'snn-tickets'); ?></p>
                    </div>
                </div>

                <p class="submit"><button class="button button-primary button-large"><?php esc_html_e('Save design', 'snn-tickets'); ?></button></p>
            </form>
        </div>

        <style>
        .snn-presets{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
        .snn-preset{display:flex;flex-direction:column;gap:4px;padding:10px;border:2px solid #dcdcde;border-radius:10px;cursor:pointer;background:#fff}
        .snn-preset:hover{border-color:#8c8f94}
        .snn-preset:has(input:checked){border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
        .snn-preset input{position:absolute;opacity:0;pointer-events:none}
        .snn-preset .snn-muted{font-size:12px;line-height:1.4}
        .snn-thumb{display:block;padding:12px;border-radius:6px;margin-bottom:6px}
        </style>
        <script>
        jQuery(function($){
            var form = $('#snn-design-form');
            $('.snn-color').wpColorPicker();

            function presetColors(){
                var r = form.find('input[name="design[preset]"]:checked');
                return r.length ? JSON.parse(r.attr('data-colors')) : {};
            }
            function applyPreset(){
                var c = presetColors();
                $('.snn-color').each(function(){ var k = $(this).data('key'); if (c[k]) $(this).wpColorPicker('color', c[k]); });
            }
            form.on('change', 'input[name="design[preset]"]', applyPreset);
            form.on('click', '[data-reset]', applyPreset);

            form.on('click', '[data-media]', function(e){
                e.preventDefault();
                var frame = wp.media({title: <?php echo wp_json_encode(__('Choose a logo', 'snn-tickets')); ?>, library: {type: 'image'}, multiple: false});
                frame.on('select', function(){
                    var a = frame.state().get('selection').first().toJSON();
                    $('#d_logo').val(a.url);
                    $('#d_logo_prev').show().find('img').attr('src', a.url);
                });
                frame.open();
            });

            function draft(){
                var d = {preset: form.find('input[name="design[preset]"]:checked').val(), colors: {}, logo_url: $('#d_logo').val(),
                         footer: $('#d_footer').val(), wallet_links: form.find('input[name="design[wallet_links]"]').is(':checked') ? 1 : 0};
                var base = presetColors();
                $('.snn-color').each(function(){
                    var k = $(this).data('key'), v = ($(this).val() || '').toLowerCase();
                    if (v && v !== String(base[k] || '').toLowerCase()) d.colors[k] = v;
                });
                return d;
            }

            form.on('click', '[data-preview-email]', function(){
                var fd = new FormData();
                fd.append('action', 'snn_email_preview');
                fd.append('nonce', <?php echo wp_json_encode(wp_create_nonce('snn_email_tools')); ?>);
                fd.append('role', 'ticket');
                fd.append('list_id', $('#d_list').val());
                fd.append('preset', '1');
                fd.append('design', JSON.stringify(draft()));
                window.snnShowPreview(fd).catch(function(e){ alert(e.message); });
            });

            form.on('click', '[data-preview-pdf]', function(){
                var url = <?php echo wp_json_encode(wp_nonce_url(admin_url('admin-post.php?action=snn_design_pdf'), 'snn_design_pdf')); ?>
                        + '&list_id=' + encodeURIComponent($('#d_list').val())
                        + '&design=' + encodeURIComponent(JSON.stringify(draft()));
                window.open(url, '_blank');
            });
        });
        </script>
        <?php
        SNN_T_Admin::email_tools_script_only();
    }

    public static function handle_save() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_save_design');

        $in = wp_unslash($_POST['design'] ?? []);
        $in['wallet_links'] = !empty($in['wallet_links']);

        // Store only the colours that differ from the preset, so switching
        // presets later is not blocked by stale overrides.
        $clean  = SNN_T_Design::sanitize_settings($in);
        $preset = SNN_T_Design::presets()[$clean['preset']];
        foreach ($clean['colors'] as $k => $v) {
            if (strtolower($v) === strtolower($preset[$k])) unset($clean['colors'][$k]);
        }

        update_option(SNN_T_Design::OPTION, $clean);
        SNN_T_Admin::back('snn-tickets-design', __('Design saved.', 'snn-tickets'));
    }

    public static function handle_pdf_preview() {
        SNN_T_Admin::cap();
        check_admin_referer('snn_design_pdf');

        if (!empty($_GET['design'])) {
            $draft = SNN_T_Design::sanitize_settings(json_decode(wp_unslash($_GET['design']), true));
            add_filter('pre_option_' . SNN_T_Design::OPTION, function () use ($draft) { return $draft; });
        }

        $t = SNN_T_Events::sample_ticket_data((int)($_GET['list_id'] ?? 0));
        $pdf = SNN_T_PDF::ticket($t);
        if (is_wp_error($pdf)) wp_die(esc_html($pdf->get_error_message()));

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="ticket-preview.pdf"');
        echo $pdf; // phpcs:ignore -- binary
        exit;
    }
}
