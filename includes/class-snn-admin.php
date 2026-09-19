<?php
/**
 * Shared admin UI (styles, tabs, editor, email tools), settings, email
 * templates and the mail queue monitor.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Admin {

    public static function init() {
        add_action('admin_post_snn_save_settings',  [__CLASS__, 'handle_save_settings']);
        add_action('admin_post_snn_save_wallet',    [__CLASS__, 'handle_save_wallet']);
        add_action('admin_post_snn_wallet_test',    [__CLASS__, 'handle_wallet_test']);
        add_action('admin_post_snn_save_template',  [__CLASS__, 'handle_save_template']);
        add_action('admin_post_snn_delete_template',[__CLASS__, 'handle_delete_template']);
        add_action('admin_post_snn_queue_action',   [__CLASS__, 'handle_queue_action']);
    }

    public static function cap() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions', 'snn-tickets'));
    }

    public static function notice() {
        if (isset($_GET['snn_msg'])) {
            $msg  = wp_unslash($_GET['snn_msg']);
            $type = isset($_GET['snn_type']) && $_GET['snn_type'] === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
    }

    public static function back($page, $msg, $extra = [], $error = false) {
        $args = array_merge(['page' => $page, 'snn_msg' => rawurlencode($msg)], $extra);
        if ($error) $args['snn_type'] = 'error';
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /** Is the current admin screen one of ours? */
    public static function is_our_screen() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return strpos($page, 'snn-tickets') === 0;
    }

    /* ==================================================================
     * Shared UI
     * ================================================================== */

    public static function tab_groups() {
        return [
            'tickets' => [
                'snn-tickets-lists'      => __('Events & tickets', 'snn-tickets'),
                'snn-tickets-generator'  => __('Generate', 'snn-tickets'),
                'snn-tickets-csv-import' => __('Import CSV', 'snn-tickets'),
            ],
            'emails' => [
                'snn-tickets-templates' => __('Templates', 'snn-tickets'),
                'snn-tickets-mailer'    => __('Send to a list', 'snn-tickets'),
                'snn-tickets-queue'     => __('Queue', 'snn-tickets'),
            ],
        ];
    }

    public static function tabs($group, $current) {
        $tabs = self::tab_groups()[$group] ?? [];
        echo '<nav class="nav-tab-wrapper snn-tabs">';
        foreach ($tabs as $slug => $label) {
            $extra = '';
            if ($slug === 'snn-tickets-queue') {
                $c = SNN_T_Mailer::queue_counts();
                if ($c['failed']) $extra = ' <span class="snn-pill snn-pill-bad">' . (int)$c['failed'] . '</span>';
                elseif ($c['pending']) $extra = ' <span class="snn-pill">' . (int)$c['pending'] . '</span>';
            }
            printf('<a href="%s" class="nav-tab%s">%s%s</a>',
                esc_url(admin_url('admin.php?page=' . $slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label), $extra);
        }
        echo '</nav>';
    }

    public static function print_styles() {
        if (!self::is_our_screen()) return;
        ?>
        <style>
        .snn-wrap{max-width:1280px}
        .snn-wrap h1 .dashicons{font-size:26px;width:26px;height:26px;vertical-align:-3px}
        .snn-tabs{margin:14px 0 18px}
        .snn-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin:0 0 20px}
        .snn-card>h2:first-child,.snn-card>h3:first-child{margin-top:0}
        .snn-card h2{font-size:15px}
        .snn-grid{display:grid;gap:20px}
        .snn-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
        .snn-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
        .snn-grid-side{grid-template-columns:minmax(0,1fr) 340px;align-items:start}
        @media(max-width:1100px){.snn-grid-2,.snn-grid-3,.snn-grid-side{grid-template-columns:1fr}}
        .snn-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:0 0 20px}
        .snn-stat{display:block;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;text-decoration:none;color:#1d2327}
        a.snn-stat:hover{border-color:#2271b1}
        .snn-stat .v{font-size:28px;font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums}
        .snn-stat .l{color:#646970;margin-top:4px}
        .snn-stat .s{color:#646970;font-size:12px;margin-top:2px}
        .snn-stat.warn{border-left:4px solid #dba617}.snn-stat.bad{border-left:4px solid #b3261e}.snn-stat.ok{border-left:4px solid #0a7d32}
        .snn-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.6;background:#f0f0f1;color:#50575e;white-space:nowrap}
        .snn-badge.ok{background:#e7f5ea;color:#0a6b2c}.snn-badge.warn{background:#fcf3dc;color:#8a5a00}.snn-badge.bad{background:#fbeaea;color:#a0231b}.snn-badge.info{background:#e5f0fa;color:#135e96}
        .snn-pill{display:inline-block;min-width:18px;padding:0 6px;border-radius:9px;background:#dba617;color:#fff;font-size:11px;line-height:18px;text-align:center}
        .snn-pill-bad{background:#b3261e}
        .snn-progress{height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;min-width:80px}
        .snn-progress i{display:block;height:100%;background:#0a7d32}
        .snn-muted{color:#646970}
        .snn-mono{font-family:Menlo,Consolas,monospace}
        .snn-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
        .snn-actions form{margin:0}
        .snn-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
        .snn-toolbar .spacer{flex:1}
        .snn-copy{cursor:pointer}
        .snn-copy.copied::after{content:" ✓";color:#0a7d32}
        .snn-check{list-style:none;margin:0;padding:0}
        .snn-check li{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid #f0f0f1}
        .snn-check li:first-child{border-top:0}
        .snn-check .dashicons{flex:none;margin-top:1px}
        .snn-check .yes{color:#0a7d32}.snn-check .no{color:#b3261e}.snn-check .opt{color:#8c8f94}
        .snn-empty-state{text-align:center;padding:40px 20px}
        .snn-empty-state .dashicons{font-size:40px;width:40px;height:40px;color:#8c8f94}
        .snn-tagbar{display:flex;flex-wrap:wrap;gap:4px;margin:6px 0 8px}
        .snn-tagbar button{font-family:Menlo,Consolas,monospace;font-size:11px;padding:0 6px;min-height:24px;line-height:22px}
        .snn-email-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 0}
        .snn-email-tools input[type=email]{min-width:220px}
        .snn-email-tools .res{font-size:13px}
        .snn-modal{position:fixed;inset:0;z-index:100100;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:24px}
        .snn-modal.on{display:flex}
        .snn-modal-box{background:#fff;border-radius:10px;width:min(760px,100%);max-height:100%;display:flex;flex-direction:column;overflow:hidden}
        .snn-modal-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #dcdcde}
        .snn-modal-head strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .snn-modal iframe{width:100%;height:70vh;border:0;background:#fff}
        .snn-device{display:flex;gap:4px}
        .snn-dl{display:grid;grid-template-columns:180px minmax(0,1fr);gap:8px 16px;margin:0}
        .snn-dl dt{font-weight:600;color:#50575e}.snn-dl dd{margin:0}
        @media(max-width:782px){.snn-dl{grid-template-columns:1fr}.snn-dl dt{margin-top:8px}}
        .wp-list-table .column-check{width:2.2em}
        .snn-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:0 0 12px}
        .snn-card-head h2{margin:0}
        .snn-table th,.snn-table td{padding:10px 12px;vertical-align:middle}
        .snn-meter{display:flex;align-items:center;gap:10px;font-size:12px;white-space:nowrap}
        .snn-meter .snn-progress{flex:1}
        /* Pagination: real buttons instead of tiny links. */
        .snn-wrap .tablenav{height:auto;margin:10px 0}
        .snn-wrap .tablenav-pages{display:flex;align-items:center;gap:6px;flex-wrap:wrap;float:right;margin:0}
        .snn-wrap .tablenav-pages .displaying-num{margin-right:8px;font-size:13px}
        .snn-wrap .tablenav-pages .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;box-sizing:border-box;border:1px solid #c3c4c7;border-radius:6px;background:#fff;color:#2c3338;text-decoration:none;font-size:14px;font-weight:500}
        .snn-wrap .tablenav-pages a.page-numbers:hover{border-color:#2271b1;color:#2271b1;background:#f6f7f7}
        .snn-wrap .tablenav-pages .page-numbers.current{background:#2271b1;border-color:#2271b1;color:#fff}
        .snn-wrap .tablenav-pages .page-numbers.dots{border:0;background:none;min-width:auto}
        .snn-wrap .tablenav-pages .snn-page-of{color:#646970;font-size:13px;margin-left:4px}
        .snn-wrap .tablenav.bottom{margin-top:14px}
        /* Live email preview */
        .snn-live{position:relative;background:#f0f0f1;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;min-height:200px}
        .snn-live iframe{display:block;border:0;background:#fff;transform-origin:0 0}
        .snn-live-subject{padding:8px 12px;background:#fff;border-bottom:1px solid #dcdcde;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .snn-live-subject b{color:#646970;font-weight:500;margin-right:6px}
        .snn-live.loading::after{content:"";position:absolute;inset:0;background:rgba(255,255,255,.55)}
        .snn-live-err{padding:20px;color:#b3261e}
        .snn-sticky{position:sticky;top:46px}
        .snn-hint{background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;padding:10px 12px;margin:8px 0 0;font-size:13px;line-height:1.5}
        .snn-hint.warn{background:#fcf9e8;border-color:#e9d9a0}
        .snn-hint p{margin:0 0 4px}.snn-hint p:last-child{margin:0}
        .snn-design-chip{display:flex;gap:12px;align-items:center}
        .snn-design-chip .snn-thumb{width:120px;flex:none;margin:0;padding:8px}
        </style>
        <?php
    }

    /**
     * A rich-text editor tuned for email: no p-stripping, absolute URLs,
     * colour tools, and a tag picker that inserts at the cursor.
     */
    public static function editor($id, $name, $content, $rows = 14) {
        self::tag_picker($id);
        wp_editor($content, $id, [
            'textarea_name' => $name,
            'textarea_rows' => $rows,
            'media_buttons' => true,
            'wpautop'       => false,
            'editor_class'  => 'snn-mail-editor',
            'tinymce'       => [
                'convert_urls'       => false,
                'relative_urls'      => false,
                'remove_script_host' => false,
                'toolbar1'           => 'formatselect,bold,italic,underline,forecolor,backcolor,alignleft,aligncenter,alignright,bullist,numlist,link,unlink,hr,removeformat,undo,redo',
                'toolbar2'           => '',
                'block_formats'      => 'Paragraph=p;Heading 1=h1;Heading 2=h2;Heading 3=h3',
            ],
            'quicktags'     => ['buttons' => 'strong,em,link,ul,ol,li,close'],
        ]);
    }

    public static function tag_picker($editor_id) {
        echo '<div class="snn-tagbar" data-editor="' . esc_attr($editor_id) . '">';
        foreach (SNN_T_Mailer::tags() as $tag => $help) {
            if ($tag === '{field:key}') continue;
            echo '<button type="button" class="button button-small" data-tag="' . esc_attr($tag) . '" title="' . esc_attr($help) . '">'
               . esc_html($tag) . '</button>';
        }
        echo '</div>';
    }

    /**
     * Preview + test-send controls for an email being edited.
     *
     * $cfg keys: role (fixed) or role_el, subject_el, body_el, template_el,
     * list (fixed id) or list_el.
     */
    public static function email_tools($cfg) {
        $me = wp_get_current_user()->user_email;
        echo '<div class="snn-email-tools" data-cfg="' . esc_attr(wp_json_encode($cfg)) . '">'
           . '<button type="button" class="button" data-preview><span class="dashicons dashicons-visibility" style="vertical-align:-4px"></span> ' . esc_html__('Preview', 'snn-tickets') . '</button>'
           . '<input type="email" class="regular-text" data-to value="' . esc_attr($me) . '" aria-label="' . esc_attr__('Send a test to', 'snn-tickets') . '">'
           . '<button type="button" class="button" data-test>' . esc_html__('Send test', 'snn-tickets') . '</button>'
           . '<span class="res" data-res></span>'
           . '</div>';

        self::email_tools_script_only();
    }

    /** An inline preview that re-renders as the fields in $cfg change. */
    public static function live_preview($cfg) {
        echo '<div class="snn-live" data-cfg="' . esc_attr(wp_json_encode($cfg)) . '">'
           . '<div class="snn-live-subject"><b>' . esc_html__('Subject', 'snn-tickets') . '</b><span data-live-subject>…</span></div>'
           . '<div data-frame-wrap style="overflow:hidden"></div>'
           . '</div>';
        self::email_tools_script_only();
    }

    /** Print the shared editor / preview / copy script once per page. */
    public static function email_tools_script_only() {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        self::email_tools_script();
    }

    private static function email_tools_script() {
        $cfg = [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snn_email_tools'),
            'i18n'  => [
                'sending' => __('Sending…', 'snn-tickets'),
                'error'   => __('Something went wrong.', 'snn-tickets'),
                'close'   => __('Close', 'snn-tickets'),
                'attach'  => __('Attachments:', 'snn-tickets'),
            ],
        ];
        ?>
        <div class="snn-modal" id="snn-preview-modal" aria-hidden="true">
            <div class="snn-modal-box" role="dialog" aria-modal="true">
                <div class="snn-modal-head">
                    <strong data-subject></strong>
                    <span class="snn-device">
                        <button type="button" class="button button-small" data-w="760">Desktop</button>
                        <button type="button" class="button button-small" data-w="380">Mobile</button>
                        <button type="button" class="button button-small" data-close><?php esc_html_e('Close', 'snn-tickets'); ?></button>
                    </span>
                </div>
                <div class="snn-muted" data-attach style="padding:6px 16px;font-size:12px;border-bottom:1px solid #f0f0f1;display:none"></div>
                <div style="display:flex;justify-content:center;background:#f0f0f1;overflow:auto"><iframe title="Email preview" data-frame></iframe></div>
            </div>
        </div>
        <script>
        (function(){
            var CFG = <?php echo wp_json_encode($cfg); ?>;
            window.snnEditorValue = function(id){
                if (!id) return '';
                if (window.tinymce) {
                    var ed = tinymce.get(id);
                    if (ed && !ed.isHidden()) return ed.getContent();
                }
                var el = document.getElementById(id);
                return el ? el.value : '';
            };
            function val(id){ var el = id && document.getElementById(id); return el ? el.value : ''; }

            // Tag picker: insert at the cursor in whichever editor mode is on.
            document.addEventListener('click', function(e){
                var b = e.target.closest('.snn-tagbar [data-tag]');
                if (!b) return;
                var id = b.closest('.snn-tagbar').getAttribute('data-editor');
                var tag = b.getAttribute('data-tag');
                if (window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden()) {
                    tinymce.get(id).insertContent(tag);
                    return;
                }
                var ta = document.getElementById(id);
                if (!ta) return;
                var s = ta.selectionStart || 0, en = ta.selectionEnd || 0;
                ta.value = ta.value.slice(0, s) + tag + ta.value.slice(en);
                ta.focus(); ta.selectionStart = ta.selectionEnd = s + tag.length;
                ta.dispatchEvent(new Event('input', {bubbles:true}));
            });

            var modal = document.getElementById('snn-preview-modal');
            var frame = modal.querySelector('[data-frame]');
            modal.addEventListener('click', function(e){
                if (e.target === modal || e.target.closest('[data-close]')) modal.classList.remove('on');
                var w = e.target.closest('[data-w]');
                if (w) frame.style.width = w.getAttribute('data-w') + 'px';
            });
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape') modal.classList.remove('on'); });

            function payload(box, extra){
                var c = JSON.parse(box.getAttribute('data-cfg'));
                var fd = new FormData();
                fd.append('nonce', CFG.nonce);
                fd.append('role', c.role || val(c.role_el) || 'ticket');
                fd.append('subject', c.subject_el ? val(c.subject_el) : '');
                fd.append('body', c.body_el ? snnEditorValue(c.body_el) : '');
                fd.append('template', c.template_el ? val(c.template_el) : '');
                fd.append('list_id', c.list || val(c.list_el) || 0);
                Object.keys(extra).forEach(function(k){ fd.append(k, extra[k]); });
                return fd;
            }

            window.snnShowPreview = function(fd){
                return fetch(CFG.ajax, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
                    if (!j || !j.success) throw new Error((j && j.data && j.data.message) || CFG.i18n.error);
                    modal.querySelector('[data-subject]').textContent = j.data.subject || '';
                    var at = modal.querySelector('[data-attach]');
                    at.style.display = j.data.attachments ? '' : 'none';
                    at.textContent = j.data.attachments ? CFG.i18n.attach + ' ' + j.data.attachments.toUpperCase().split(',').join(', ') : '';
                    frame.style.width = '760px';
                    frame.srcdoc = j.data.html;
                    modal.classList.add('on');
                });
            };

            document.addEventListener('click', function(e){
                var box = e.target.closest('.snn-email-tools');
                if (!box) return;
                var res = box.querySelector('[data-res]');
                if (e.target.closest('[data-preview]')) {
                    var fd = payload(box, {action:'snn_email_preview'});
                    snnShowPreview(fd).catch(function(err){ res.textContent = err.message; });
                }
                if (e.target.closest('[data-test]')) {
                    res.textContent = CFG.i18n.sending;
                    var fd2 = payload(box, {action:'snn_email_test', to: box.querySelector('[data-to]').value});
                    fetch(CFG.ajax, {method:'POST', body:fd2, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
                        res.textContent = (j && j.data && j.data.message) || CFG.i18n.error;
                        res.style.color = j && j.success ? '#0a7d32' : '#b3261e';
                    }).catch(function(){ res.textContent = CFG.i18n.error; res.style.color = '#b3261e'; });
                }
            });

            // Live preview: an inline, scaled-down render that follows the
            // fields named in its data-cfg as the user edits them.
            var W = 640;
            function fit(box){
                var f = box.querySelector('iframe'), wrap = box.querySelector('[data-frame-wrap]');
                if (!f || !f.contentDocument || !f.contentDocument.body) return;
                var h = f.contentDocument.documentElement.scrollHeight, s = Math.min(1, box.clientWidth / W);
                f.style.width = W + 'px'; f.style.height = h + 'px';
                f.style.transform = 'scale(' + s + ')';
                wrap.style.height = Math.ceil(h * s) + 'px';
            }
            function live(box){
                box.classList.add('loading');
                fetch(CFG.ajax, {method:'POST', body:payload(box, {action:'snn_email_preview'}), credentials:'same-origin'})
                    .then(function(r){ return r.json(); }).then(function(j){
                        box.classList.remove('loading');
                        if (!j || !j.success) throw new Error((j && j.data && j.data.message) || CFG.i18n.error);
                        box.querySelector('[data-live-subject]').textContent = j.data.subject || '';
                        var wrap = box.querySelector('[data-frame-wrap]');
                        var f = wrap.querySelector('iframe');
                        if (!f) { f = document.createElement('iframe'); f.title = 'Email preview'; f.setAttribute('scrolling', 'no'); f.onload = function(){ fit(box); setTimeout(function(){ fit(box); }, 300); }; wrap.innerHTML = ''; wrap.appendChild(f); }
                        f.srcdoc = j.data.html;
                    }).catch(function(err){
                        box.classList.remove('loading');
                        box.querySelector('[data-frame-wrap]').innerHTML = '<div class="snn-live-err"></div>';
                        box.querySelector('.snn-live-err').textContent = err.message;
                    });
            }
            var timers = new WeakMap();
            function schedule(box, ms){
                clearTimeout(timers.get(box));
                timers.set(box, setTimeout(function(){ live(box); }, ms == null ? 600 : ms));
            }
            function liveBoxes(){ return Array.prototype.slice.call(document.querySelectorAll('.snn-live[data-cfg]')); }
            function watches(box, id){
                var c = JSON.parse(box.getAttribute('data-cfg'));
                return [c.role_el, c.subject_el, c.body_el, c.template_el, c.list_el].indexOf(id) !== -1;
            }
            window.snnLiveRefresh = function(){ liveBoxes().forEach(function(b){ schedule(b, 0); }); };
            ['input', 'change'].forEach(function(ev){
                document.addEventListener(ev, function(e){
                    var id = e.target && e.target.id;
                    if (id) liveBoxes().forEach(function(b){ if (watches(b, id)) schedule(b); });
                });
            });
            if (window.jQuery) jQuery(document).on('tinymce-editor-init', function(e, ed){
                liveBoxes().forEach(function(b){
                    if (watches(b, ed.id)) ed.on('keyup change input undo redo SetContent ExecCommand', function(){ schedule(b); });
                });
            });
            window.addEventListener('resize', function(){ liveBoxes().forEach(fit); });
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.snnLiveRefresh);
            else setTimeout(window.snnLiveRefresh, 0);

            // Copy-to-clipboard for shortcodes.
            document.addEventListener('click', function(e){
                var c = e.target.closest('.snn-copy');
                if (!c) return;
                e.preventDefault();
                navigator.clipboard && navigator.clipboard.writeText(c.getAttribute('data-copy') || c.textContent.trim());
                c.classList.add('copied');
                setTimeout(function(){ c.classList.remove('copied'); }, 1500);
            });
        })();
        </script>
        <?php
    }

    /** Page buttons with "Page 2 of 14"; empty when everything fits on one page. */
    public static function pager($total, $per, $paged) {
        $pages = max(1, (int)ceil($total / max(1, $per)));
        if ($pages < 2) return '';
        return paginate_links([
            'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages,
            'prev_text' => '‹ ' . __('Previous', 'snn-tickets'), 'next_text' => __('Next', 'snn-tickets') . ' ›',
            'end_size' => 1, 'mid_size' => 2,
        ]) . '<span class="snn-page-of">' . esc_html(sprintf(__('Page %1$s of %2$s', 'snn-tickets'), number_format_i18n($paged), number_format_i18n($pages))) . '</span>';
    }

    /** Clickable code snippet that copies itself. */
    public static function copy_code($text) {
        return '<code class="snn-copy" title="' . esc_attr__('Click to copy', 'snn-tickets') . '" data-copy="' . esc_attr($text) . '">' . esc_html($text) . '</code>';
    }

    /** "3 minutes ago" for recent, the date otherwise. */
    public static function when($mysql) {
        if (!$mysql || strpos((string)$mysql, '0000') === 0) return '—';
        $ts  = strtotime($mysql);
        $now = current_time('timestamp');
        $abs = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts);
        if ($ts <= $now && $now - $ts < DAY_IN_SECONDS) {
            return '<span title="' . esc_attr($abs) . '">' . esc_html(sprintf(__('%s ago', 'snn-tickets'), human_time_diff($ts, $now))) . '</span>';
        }
        return esc_html($abs);
    }

    /* ==================================================================
     * Settings
     * ================================================================== */

    public static function render_settings_page() {
        self::cap();
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Settings', 'snn-tickets'); ?></h1>
            <?php self::notice(); ?>
            <nav class="nav-tab-wrapper snn-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-settings')); ?>" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'snn-tickets'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-settings&tab=wallet')); ?>" class="nav-tab <?php echo $tab === 'wallet' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Wallet passes', 'snn-tickets'); ?></a>
            </nav>
            <?php $tab === 'wallet' ? self::render_wallet_tab() : self::render_general_tab(); ?>
        </div>
        <?php
    }

    private static function render_general_tab() {
        $from_name  = get_option(SNN_T_Mailer::FROM_NAME_OPTION, get_bloginfo('name'));
        $from_email = get_option(SNN_T_Mailer::FROM_EMAIL_OPTION, get_option('admin_email'));
        $batch_size = SNN_T_Mailer::batch_size();
        $scan_url   = get_option(SNN_T_QR::SCAN_URL_OPTION, '');

        $next_cron = wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);
        $gd        = function_exists('imagecreatetruecolor');
        $zlib      = function_exists('gzcompress');

        // Pages that already hold the scanner, offered as one-click picks.
        $scan_pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 's' => '[tickets_scan_page', 'numberposts' => 10]);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snn-card">
            <input type="hidden" name="action" value="snn_save_settings">
            <?php wp_nonce_field('snn_save_settings'); ?>

            <h2><?php esc_html_e('Sending', 'snn-tickets'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="from_name"><?php esc_html_e('From name', 'snn-tickets'); ?></label></th>
                    <td><input type="text" id="from_name" name="from_name" class="regular-text" value="<?php echo esc_attr($from_name); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="from_email"><?php esc_html_e('From address', 'snn-tickets'); ?></label></th>
                    <td><input type="email" id="from_email" name="from_email" class="regular-text" value="<?php echo esc_attr($from_email); ?>">
                        <p class="description"><?php esc_html_e('Leave blank to use whatever WordPress is configured to send as.', 'snn-tickets'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="batch_size"><?php esc_html_e('Emails per minute', 'snn-tickets'); ?></label></th>
                    <td><input type="number" id="batch_size" name="batch_size" min="1" max="200" class="small-text" value="<?php echo esc_attr($batch_size); ?>">
                        <p class="description"><?php esc_html_e('The queue runs once a minute and sends up to this many messages each time. Keep it under what your host or SMTP provider allows.', 'snn-tickets'); ?></p></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Scanning', 'snn-tickets'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="scan_url"><?php esc_html_e('Scanner page URL', 'snn-tickets'); ?></label></th>
                    <td>
                        <input type="url" id="scan_url" name="scan_url" class="large-text" value="<?php echo esc_attr($scan_url); ?>" placeholder="<?php echo esc_attr(home_url('/scan/')); ?>">
                        <?php if ($scan_pages): ?>
                            <p><?php esc_html_e('Pages with the scanner:', 'snn-tickets'); ?>
                            <?php foreach ($scan_pages as $p): ?>
                                <button type="button" class="button button-small" onclick="document.getElementById('scan_url').value=<?php echo esc_attr(wp_json_encode(get_permalink($p))); ?>"><?php echo esc_html(get_the_title($p)); ?></button>
                            <?php endforeach; ?></p>
                        <?php endif; ?>
                        <p class="description"><?php printf(esc_html__('The page holding the %s shortcode. Staff who scan a QR with their phone camera land there and the ticket is checked in. Attendees who open their own QR only see their ticket.', 'snn-tickets'), '<code>[tickets_scan_page]</code>'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="staff_pin"><?php esc_html_e('Door staff PIN', 'snn-tickets'); ?></label></th>
                    <td>
                        <input type="password" id="staff_pin" name="staff_pin" class="regular-text" autocomplete="new-password" placeholder="<?php echo SNN_T_Scanner::pin_set() ? esc_attr__('PIN is set – type to change', 'snn-tickets') : esc_attr__('Not set', 'snn-tickets'); ?>">
                        <?php if (SNN_T_Scanner::pin_set()): ?>
                            <label style="margin-left:8px"><input type="checkbox" name="clear_pin" value="1"> <?php esc_html_e('Remove PIN', 'snn-tickets'); ?></label>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Lets volunteers use the scanner page without a WordPress account. They stay signed in for 24 hours; changing the PIN signs everyone out. Without a PIN, only logged-in admins can check tickets in.', 'snn-tickets'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit"><button class="button button-primary"><?php esc_html_e('Save settings', 'snn-tickets'); ?></button></p>
        </form>

        <div class="snn-card">
            <h2><?php esc_html_e('System check', 'snn-tickets'); ?></h2>
            <ul class="snn-check">
                <?php
                $rows = [
                    [$gd || $zlib, __('QR rendering', 'snn-tickets'), $gd ? __('GD is present — PNGs render through GD.', 'snn-tickets') : ($zlib ? __('GD is not installed; the built-in PNG encoder is used instead. This works fine.', 'snn-tickets') : __('Neither GD nor zlib is available. QR images cannot be generated.', 'snn-tickets'))],
                    [(bool)$next_cron, __('Mail queue cron', 'snn-tickets'), $next_cron
                        ? sprintf(__('Next run in %s.', 'snn-tickets'), human_time_diff($next_cron, time())) . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? ' ' . __('DISABLE_WP_CRON is on — make sure a real cron job calls wp-cron.php.', 'snn-tickets') : '')
                        : __('Not scheduled. Deactivate and reactivate the plugin to fix.', 'snn-tickets')],
                    [class_exists('ZipArchive'), __('Apple Wallet support', 'snn-tickets'), class_exists('ZipArchive') ? __('The zip extension is available.', 'snn-tickets') : __('The PHP zip extension is missing, so .pkpass files cannot be built.', 'snn-tickets')],
                    [function_exists('openssl_sign'), __('Wallet signing', 'snn-tickets'), function_exists('openssl_sign') ? __('OpenSSL is available.', 'snn-tickets') : __('OpenSSL is missing, so wallet passes cannot be signed.', 'snn-tickets')],
                    [true, __('Signing key', 'snn-tickets'), __('Set. QR codes carry a signature so scans can be trusted.', 'snn-tickets')],
                ];
                foreach ($rows as $r): ?>
                    <li><span class="dashicons <?php echo $r[0] ? 'dashicons-yes-alt yes' : 'dashicons-warning no'; ?>"></span>
                        <div><strong><?php echo esc_html($r[1]); ?></strong><br><span class="snn-muted"><?php echo esc_html($r[2]); ?></span></div></li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px"
                  onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Regenerate the signing key? Every QR code and download link already sent out will stop working. Only do this if the key has leaked.', 'snn-tickets'))); ?>);">
                <input type="hidden" name="action" value="snn_save_settings">
                <input type="hidden" name="regenerate_secret" value="1">
                <?php wp_nonce_field('snn_save_settings'); ?>
                <button class="button button-small"><?php esc_html_e('Regenerate signing key', 'snn-tickets'); ?></button>
            </form>
        </div>
        <?php
    }

    private static function render_wallet_tab() {
        $a = SNN_T_Wallet::apple_settings();
        $g = SNN_T_Wallet::google_settings();
        $apple_ok  = SNN_T_Wallet::apple_ready();
        $google_ok = SNN_T_Wallet::google_ready();
        $test_url = function ($which) {
            return wp_nonce_url(admin_url('admin-post.php?action=snn_wallet_test&which=' . $which), 'snn_wallet_test');
        };
        ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="snn_save_wallet">
            <?php wp_nonce_field('snn_save_wallet'); ?>

            <div class="snn-grid snn-grid-2">
            <div class="snn-card">
                <h2><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e('Apple Wallet', 'snn-tickets'); ?>
                    <span class="snn-badge <?php echo $apple_ok ? 'ok' : ''; ?>"><?php echo $apple_ok ? esc_html__('Ready', 'snn-tickets') : esc_html__('Not set up', 'snn-tickets'); ?></span></h2>
                <p class="snn-muted"><?php esc_html_e('Needs a paid Apple Developer account. Create a Pass Type ID, generate its certificate, and export it (with its private key) as a .p12 file.', 'snn-tickets'); ?>
                    <a href="https://developer.apple.com/documentation/walletpasses/building-a-pass" target="_blank" rel="noopener"><?php esc_html_e("Apple's guide", 'snn-tickets'); ?></a></p>
                <table class="form-table" role="presentation">
                    <tr><th><label for="a_pt"><?php esc_html_e('Pass Type ID', 'snn-tickets'); ?></label></th>
                        <td><input id="a_pt" name="apple[pass_type_id]" class="regular-text" value="<?php echo esc_attr($a['pass_type_id']); ?>" placeholder="pass.com.example.tickets"></td></tr>
                    <tr><th><label for="a_team"><?php esc_html_e('Team ID', 'snn-tickets'); ?></label></th>
                        <td><input id="a_team" name="apple[team_id]" class="regular-text" value="<?php echo esc_attr($a['team_id']); ?>" placeholder="ABCDE12345"></td></tr>
                    <tr><th><label for="a_org"><?php esc_html_e('Organisation name', 'snn-tickets'); ?></label></th>
                        <td><input id="a_org" name="apple[org_name]" class="regular-text" value="<?php echo esc_attr($a['org_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
                    <tr><th><label for="a_p12"><?php esc_html_e('Certificate (.p12)', 'snn-tickets'); ?></label></th>
                        <td><input type="file" id="a_p12" name="apple_p12" accept=".p12,.pfx">
                            <?php if ($a['p12']): ?><br><span class="snn-badge ok"><?php esc_html_e('Uploaded', 'snn-tickets'); ?></span><?php endif; ?></td></tr>
                    <tr><th><label for="a_pw"><?php esc_html_e('Certificate password', 'snn-tickets'); ?></label></th>
                        <td><input type="password" id="a_pw" name="apple[p12_password]" class="regular-text" autocomplete="new-password" placeholder="<?php echo $a['p12_password'] !== '' ? esc_attr__('Saved – type to change', 'snn-tickets') : ''; ?>"></td></tr>
                    <tr><th><?php esc_html_e('Or PEM files', 'snn-tickets'); ?></th>
                        <td><label><?php esc_html_e('Certificate', 'snn-tickets'); ?> <input type="file" name="apple_cert_pem" accept=".pem,.crt"></label><br>
                            <label><?php esc_html_e('Private key', 'snn-tickets'); ?> <input type="file" name="apple_key_pem" accept=".pem,.key"></label>
                            <?php if ($a['cert_pem'] && $a['key_pem']): ?><br><span class="snn-badge ok"><?php esc_html_e('Uploaded', 'snn-tickets'); ?></span><?php endif; ?>
                            <p class="description"><?php esc_html_e('Use these if your server refuses the .p12 (common with OpenSSL 3 and older exports).', 'snn-tickets'); ?></p></td></tr>
                    <tr><th><label for="a_wwdr"><?php esc_html_e('Apple WWDR certificate', 'snn-tickets'); ?></label></th>
                        <td><input type="file" id="a_wwdr" name="apple_wwdr" accept=".cer,.pem,.crt">
                            <?php if ($a['wwdr_pem']): ?><br><span class="snn-badge ok"><?php esc_html_e('Uploaded', 'snn-tickets'); ?></span><?php endif; ?>
                            <p class="description"><?php printf(esc_html__('Download %s from Apple and upload it here.', 'snn-tickets'), '<a href="https://www.apple.com/certificateauthority/AppleWWDRCAG4.cer">AppleWWDRCAG4.cer</a>'); ?></p></td></tr>
                </table>
                <p class="snn-actions">
                    <?php if ($apple_ok): ?><a class="button" href="<?php echo esc_url($test_url('apple')); ?>"><?php esc_html_e('Download a sample pass', 'snn-tickets'); ?></a><?php endif; ?>
                    <?php if ($a['p12'] || $a['cert_pem']): ?><label><input type="checkbox" name="apple_clear" value="1"> <?php esc_html_e('Remove Apple credentials', 'snn-tickets'); ?></label><?php endif; ?>
                </p>
            </div>

            <div class="snn-card">
                <h2><span class="dashicons dashicons-google"></span> <?php esc_html_e('Google Wallet', 'snn-tickets'); ?>
                    <span class="snn-badge <?php echo $google_ok ? 'ok' : ''; ?>"><?php echo $google_ok ? esc_html__('Ready', 'snn-tickets') : esc_html__('Not set up', 'snn-tickets'); ?></span></h2>
                <p class="snn-muted"><?php esc_html_e('Free. Create an issuer in the Google Pay & Wallet Console, create a Google Cloud service account with the Wallet API enabled, add it to the issuer, and upload its JSON key.', 'snn-tickets'); ?>
                    <a href="https://developers.google.com/wallet/tickets/events/web/prerequisites" target="_blank" rel="noopener"><?php esc_html_e("Google's guide", 'snn-tickets'); ?></a></p>
                <table class="form-table" role="presentation">
                    <tr><th><label for="g_iss"><?php esc_html_e('Issuer ID', 'snn-tickets'); ?></label></th>
                        <td><input id="g_iss" name="google[issuer_id]" class="regular-text" value="<?php echo esc_attr($g['issuer_id']); ?>" placeholder="3388000000012345678"></td></tr>
                    <tr><th><label for="g_json"><?php esc_html_e('Service account key (.json)', 'snn-tickets'); ?></label></th>
                        <td><input type="file" id="g_json" name="google_json" accept=".json">
                            <?php if ($g['client_email']): ?><br><span class="snn-badge ok"><?php echo esc_html($g['client_email']); ?></span><?php endif; ?></td></tr>
                </table>
                <p class="description"><?php esc_html_e('Until Google approves your issuer for publishing, only accounts added as test users can save passes.', 'snn-tickets'); ?></p>
                <p class="snn-actions">
                    <?php if ($google_ok): ?><a class="button" target="_blank" href="<?php echo esc_url($test_url('google')); ?>"><?php esc_html_e('Try a sample pass', 'snn-tickets'); ?></a><?php endif; ?>
                    <?php if ($g['client_email']): ?><label><input type="checkbox" name="google_clear" value="1"> <?php esc_html_e('Remove Google credentials', 'snn-tickets'); ?></label><?php endif; ?>
                </p>
            </div>
            </div>

            <div class="snn-card">
                <p style="margin:0"><?php esc_html_e('Once a wallet is ready, its button appears under {wallet_buttons} in ticket emails and on the attendee ticket page. To attach the .pkpass file itself, tick it in the event settings of each list.', 'snn-tickets'); ?></p>
            </div>

            <p class="submit"><button class="button button-primary"><?php esc_html_e('Save wallet settings', 'snn-tickets'); ?></button></p>
        </form>
        <?php
    }

    public static function handle_save_settings() {
        self::cap();
        check_admin_referer('snn_save_settings');

        if (!empty($_POST['regenerate_secret'])) {
            delete_option(SNN_T_QR::SECRET_OPTION);
            SNN_T_QR::secret();
            $n = SNN_T_QR::flush_cache();
            self::back('snn-tickets-settings', sprintf(__('Signing key regenerated and %d cached QR image(s) cleared.', 'snn-tickets'), $n));
        }

        $from_name  = sanitize_text_field(wp_unslash($_POST['from_name'] ?? ''));
        $from_email = sanitize_email(wp_unslash($_POST['from_email'] ?? ''));
        $batch      = max(1, min(200, (int)($_POST['batch_size'] ?? 10)));
        $scan_url   = esc_url_raw(wp_unslash($_POST['scan_url'] ?? ''));

        update_option(SNN_T_Mailer::FROM_NAME_OPTION, $from_name);
        update_option(SNN_T_Mailer::FROM_EMAIL_OPTION, ($from_email && is_email($from_email)) ? $from_email : '');
        update_option(SNN_T_Mailer::BATCH_SIZE_OPTION, $batch);

        if (!empty($_POST['clear_pin'])) {
            SNN_T_Scanner::set_pin('');
        } elseif (isset($_POST['staff_pin']) && trim(wp_unslash($_POST['staff_pin'])) !== '') {
            SNN_T_Scanner::set_pin(wp_unslash($_POST['staff_pin']));
        }

        $old_scan = (string)get_option(SNN_T_QR::SCAN_URL_OPTION, '');
        update_option(SNN_T_QR::SCAN_URL_OPTION, $scan_url);

        $msg = __('Settings saved.', 'snn-tickets');
        if ($old_scan !== $scan_url) {
            // Cached PNGs encode the old destination, so they have to go.
            $n = SNN_T_QR::flush_cache();
            $msg .= ' ' . sprintf(__('Scan URL changed, so %d cached QR image(s) were cleared and will be rebuilt.', 'snn-tickets'), $n);
        }

        self::back('snn-tickets-settings', $msg);
    }

    private static function upload($field) {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if (!is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
        $data = file_get_contents($_FILES[$field]['tmp_name']);
        return ($data === false || strlen($data) > 512 * 1024) ? null : $data;
    }

    public static function handle_save_wallet() {
        self::cap();
        check_admin_referer('snn_save_wallet');

        $back = function ($msg, $err = false) { self::back('snn-tickets-settings', $msg, ['tab' => 'wallet'], $err); };
        $notes = [];

        // Apple
        $a  = SNN_T_Wallet::apple_settings();
        $in = (array)($_POST['apple'] ?? []);
        if (!empty($_POST['apple_clear'])) {
            $a = array_merge($a, ['p12' => '', 'p12_password' => '', 'cert_pem' => '', 'key_pem' => '']);
        }
        $a['pass_type_id'] = sanitize_text_field(wp_unslash($in['pass_type_id'] ?? ''));
        $a['team_id']      = strtoupper(sanitize_text_field(wp_unslash($in['team_id'] ?? '')));
        $a['org_name']     = sanitize_text_field(wp_unslash($in['org_name'] ?? ''));
        if (isset($in['p12_password']) && $in['p12_password'] !== '') $a['p12_password'] = wp_unslash($in['p12_password']);

        if (null !== ($p12 = self::upload('apple_p12')))      $a['p12'] = base64_encode($p12);
        if (null !== ($pem = self::upload('apple_cert_pem'))) $a['cert_pem'] = $pem;
        if (null !== ($key = self::upload('apple_key_pem')))  $a['key_pem'] = $key;
        if (null !== ($w = self::upload('apple_wwdr')))       $a['wwdr_pem'] = SNN_T_Wallet::to_pem($w);

        if ($a['p12'] !== '' || ($a['cert_pem'] !== '' && $a['key_pem'] !== '')) {
            $creds = SNN_T_Wallet::apple_credentials($a);
            if (is_wp_error($creds)) $notes[] = $creds->get_error_message();
        }
        update_option(SNN_T_Wallet::APPLE_OPTION, $a, false);

        // Google
        $g = SNN_T_Wallet::google_settings();
        if (!empty($_POST['google_clear'])) $g = array_merge($g, ['client_email' => '', 'private_key' => '']);
        $g['issuer_id'] = preg_replace('/[^0-9]/', '', (string)wp_unslash($_POST['google']['issuer_id'] ?? ''));
        if (null !== ($json = self::upload('google_json'))) {
            $sa = SNN_T_Wallet::parse_service_account($json);
            if (is_wp_error($sa)) $notes[] = $sa->get_error_message();
            else $g = array_merge($g, $sa);
        }
        update_option(SNN_T_Wallet::GOOGLE_OPTION, $g, false);

        if ($notes) $back(__('Saved, with problems: ', 'snn-tickets') . implode(' ', $notes), true);
        $back(__('Wallet settings saved.', 'snn-tickets'));
    }

    public static function handle_wallet_test() {
        self::cap();
        check_admin_referer('snn_wallet_test');
        $t = SNN_T_Events::sample_ticket_data();

        if (($_GET['which'] ?? '') === 'google') {
            $url = SNN_T_Wallet::google_save_url($t);
            if (is_wp_error($url)) wp_die(esc_html($url->get_error_message()));
            wp_redirect($url);
            exit;
        }

        $pass = SNN_T_Wallet::apple_pkpass($t);
        if (is_wp_error($pass)) wp_die(esc_html($pass->get_error_message()));
        nocache_headers();
        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="sample.pkpass"');
        echo $pass; // phpcs:ignore -- binary
        exit;
    }

    /* ==================================================================
     * Email templates
     * ================================================================== */

    /** When each kind of email goes out, and where a template of that kind gets picked. */
    public static function role_help() {
        return [
            'ticket' => [
                'short' => __('Ticket email', 'snn-tickets'),
                'when'  => __('Goes out when an attendee gets their ticket: right after they register (or once you approve them), when you press Send on a ticket, or when you email a whole event.', 'snn-tickets'),
                'where' => __('Pick it in a form\'s Emails settings, or on the "Send to a list" tab.', 'snn-tickets'),
                'tip'   => __('Keep {ticket_card} or {qr_block} in the message, otherwise the attendee has no QR code to show at the door.', 'snn-tickets'),
            ],
            'confirmation' => [
                'short' => __('Received email', 'snn-tickets'),
                'when'  => __('Goes out immediately when someone registers through a form that needs your approval. It has no ticket yet — it just says "we got it, we will get back to you".', 'snn-tickets'),
                'where' => __('Pick it in a form\'s Emails settings. Forms that approve automatically skip this email.', 'snn-tickets'),
                'tip'   => '',
            ],
            'rejection' => [
                'short' => __('Rejection email', 'snn-tickets'),
                'when'  => __('Goes out when you reject a registration on the Submissions screen.', 'snn-tickets'),
                'where' => __('Pick it in a form\'s Emails settings.', 'snn-tickets'),
                'tip'   => '',
            ],
        ];
    }

    public static function render_templates_page() {
        self::cap();

        $templates = SNN_T_Mailer::get_templates();
        $roles     = SNN_T_Mailer::roles();
        $help      = self::role_help();

        $editing = isset($_GET['template']) ? sanitize_text_field(wp_unslash($_GET['template'])) : '';
        $current = $editing !== '' ? ($templates[$editing] ?? null) : null;

        $role    = $current['role'] ?? (isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : 'ticket');
        if (!isset($roles[$role])) $role = 'ticket';
        $default = SNN_T_Mailer::default_template($role);
        $subject = $current['subject'] ?? $default['subject'];
        $body    = $current['body']    ?? $default['body'];

        $design  = SNN_T_Design::settings();
        $presets = SNN_T_Design::presets();
        $preset  = $presets[$design['preset']] ?? reset($presets);

        global $wpdb;
        $lists = $wpdb->get_results("SELECT id, name FROM " . SNN_T_DB::lists() . " ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Emails', 'snn-tickets'); ?></h1>
            <?php self::tabs('emails', 'snn-tickets-templates'); ?>
            <?php self::notice(); ?>

            <div class="snn-card">
                <div class="snn-card-head">
                    <h2><?php esc_html_e('Your templates', 'snn-tickets'); ?></h2>
                    <span class="snn-muted" style="font-size:12px"><?php esc_html_e('Kinds without a template use the built-in wording.', 'snn-tickets'); ?></span>
                </div>
                <div class="snn-tpl-groups">
                    <?php foreach ($roles as $rkey => $rlabel): $in_role = SNN_T_Mailer::templates_for_role($rkey); ?>
                        <div class="snn-tpl-group <?php echo $role === $rkey ? 'on' : ''; ?>">
                            <strong><?php echo esc_html($help[$rkey]['short']); ?></strong>
                            <div class="snn-muted" style="font-size:12px;margin:2px 0 8px"><?php echo esc_html($rlabel); ?></div>
                            <?php if ($in_role): ?>
                                <ul>
                                <?php foreach ($in_role as $name => $t): ?>
                                    <li><a class="<?php echo $editing === $name ? 'current' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'snn-tickets-templates', 'template' => $name], admin_url('admin.php'))); ?>"><span class="dashicons dashicons-email"></span> <?php echo esc_html($name); ?></a></li>
                                <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="snn-muted" style="font-size:12px;margin:0 0 8px"><?php esc_html_e('Using the built-in email.', 'snn-tickets'); ?></p>
                            <?php endif; ?>
                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-templates&role=' . $rkey)); ?>">+ <?php esc_html_e('New', 'snn-tickets'); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="snn-grid snn-grid-mail">
                <div class="snn-card">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="snn_save_template">
                        <?php wp_nonce_field('snn_save_template'); ?>
                        <input type="hidden" name="original_name" value="<?php echo esc_attr($editing); ?>">

                        <h2 style="margin-top:0"><?php echo $editing !== '' ? esc_html(sprintf(__('Edit "%s"', 'snn-tickets'), $editing)) : esc_html(sprintf(__('New %s', 'snn-tickets'), strtolower($help[$role]['short']))); ?></h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="tpl_role"><?php esc_html_e('Kind of email', 'snn-tickets'); ?></label></th>
                                <td>
                                    <select id="tpl_role" name="role">
                                        <?php foreach ($roles as $rkey => $rlabel): ?>
                                            <option value="<?php echo esc_attr($rkey); ?>" <?php selected($role, $rkey); ?>><?php echo esc_html($help[$rkey]['short'] . ' — ' . $rlabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php foreach ($help as $rkey => $h): ?>
                                        <div class="snn-hint" data-role-help="<?php echo esc_attr($rkey); ?>" <?php echo $rkey === $role ? '' : 'hidden'; ?>>
                                            <p><strong><?php esc_html_e('When is it sent?', 'snn-tickets'); ?></strong> <?php echo esc_html($h['when']); ?></p>
                                            <p><strong><?php esc_html_e('Where is it used?', 'snn-tickets'); ?></strong> <?php echo esc_html($h['where']); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tpl_name"><?php esc_html_e('Template name', 'snn-tickets'); ?></label></th>
                                <td><input type="text" id="tpl_name" name="name" class="regular-text" required value="<?php echo esc_attr($editing); ?>" placeholder="<?php esc_attr_e('Summer meetup ticket', 'snn-tickets'); ?>">
                                    <p class="description"><?php esc_html_e('Only you see this — it is how you find the template in a form or when sending to a list.', 'snn-tickets'); ?></p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tpl_subject"><?php esc_html_e('Subject', 'snn-tickets'); ?></label></th>
                                <td><input type="text" id="tpl_subject" name="subject" class="large-text" required value="<?php echo esc_attr($subject); ?>"></td>
                            </tr>
                        </table>

                        <p style="margin:0 0 2px;font-weight:600"><?php esc_html_e('Message', 'snn-tickets'); ?></p>
                        <p class="snn-muted" style="margin:0;font-size:12px"><?php esc_html_e('Click a tag to insert it. Tags are replaced with each attendee\'s details when the email goes out.', 'snn-tickets'); ?></p>
                        <?php self::editor('snntplbody', 'body', $body, 16); ?>
                        <div class="snn-hint warn" data-qr-warn hidden><p><?php echo esc_html($help['ticket']['tip']); ?></p></div>

                        <details style="margin:10px 0 0">
                            <summary style="cursor:pointer;color:#2271b1"><?php esc_html_e('What does each tag do?', 'snn-tickets'); ?></summary>
                            <dl class="snn-dl" style="font-size:12px;margin-top:8px;grid-template-columns:150px minmax(0,1fr)">
                                <?php foreach (SNN_T_Mailer::tags() as $tag => $tag_help): ?>
                                    <dt><code><?php echo esc_html($tag); ?></code></dt><dd class="snn-muted"><?php echo esc_html($tag_help); ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        </details>

                        <h3 style="margin:20px 0 6px;font-size:13px"><?php esc_html_e('Send yourself a test', 'snn-tickets'); ?></h3>
                        <?php self::email_tools(['role_el' => 'tpl_role', 'subject_el' => 'tpl_subject', 'body_el' => 'snntplbody', 'list_el' => 'tpl_list']); ?>

                        <p class="submit">
                            <button class="button button-primary button-large"><?php esc_html_e('Save template', 'snn-tickets'); ?></button>
                            <?php if ($editing !== ''): ?>
                                <button class="button button-link-delete" formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        name="action" value="snn_delete_template" formnovalidate
                                        onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete this template? Forms using it fall back to the built-in default.', 'snn-tickets'))); ?>);">
                                    <?php esc_html_e('Delete', 'snn-tickets'); ?>
                                </button>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div class="snn-sticky">
                    <div class="snn-card">
                        <div class="snn-card-head">
                            <h2><?php esc_html_e('Live preview', 'snn-tickets'); ?></h2>
                            <select id="tpl_list" aria-label="<?php esc_attr_e('Preview with event', 'snn-tickets'); ?>" style="max-width:55%">
                                <option value="0"><?php esc_html_e('Sample event', 'snn-tickets'); ?></option>
                                <?php foreach ($lists as $l): ?><option value="<?php echo (int)$l->id; ?>"><?php echo esc_html($l->name); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="snn-design-chip" style="margin:0 0 12px">
                            <?php echo SNN_T_Design_Admin::thumb($preset); // built from sanitised preset values ?>
                            <div style="font-size:12px;line-height:1.5">
                                <strong><?php printf(esc_html__('Design: %s', 'snn-tickets'), esc_html($preset['label'])); ?></strong><br>
                                <span class="snn-muted"><?php esc_html_e('The template is the words; the design is the frame around them — colours, logo, footer and the ticket card. An event with its own design uses that instead; pick it above to see.', 'snn-tickets'); ?></span><br>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-design')); ?>"><?php esc_html_e('Change design', 'snn-tickets'); ?> &rarr;</a>
                            </div>
                        </div>
                        <?php self::live_preview(['role_el' => 'tpl_role', 'subject_el' => 'tpl_subject', 'body_el' => 'snntplbody', 'list_el' => 'tpl_list']); ?>
                    </div>
                </div>
            </div>
        </div>
        <style>
        .snn-grid-mail{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);align-items:start}
        @media(max-width:1100px){.snn-grid-mail{grid-template-columns:1fr}.snn-grid-mail .snn-sticky{position:static}}
        .snn-tpl-groups{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        @media(max-width:900px){.snn-tpl-groups{grid-template-columns:1fr}}
        .snn-tpl-group{border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}
        .snn-tpl-group.on{border-color:#2271b1;background:#f6fafd}
        .snn-tpl-group ul{margin:0 0 8px}
        .snn-tpl-group li{margin:0 0 4px}
        .snn-tpl-group li a{text-decoration:none}
        .snn-tpl-group li a.current{font-weight:700}
        .snn-tpl-group li .dashicons{font-size:16px;width:16px;height:16px;vertical-align:-3px;color:#8c8f94}
        </style>
        <script>
        (function(){
            var role = document.getElementById('tpl_role'), warn = document.querySelector('[data-qr-warn]');
            role.addEventListener('change', function(){
                document.querySelectorAll('[data-role-help]').forEach(function(el){ el.hidden = el.getAttribute('data-role-help') !== role.value; });
                check();
            });
            function check(){
                var b = window.snnEditorValue ? snnEditorValue('snntplbody') : '';
                warn.hidden = !(role.value === 'ticket' && b.indexOf('{ticket_card}') === -1 && b.indexOf('{qr_block}') === -1);
            }
            setInterval(check, 1500); check();
        })();
        </script>
        <?php
    }

    public static function handle_save_template() {
        self::cap();
        check_admin_referer('snn_save_template');

        $name     = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $original = sanitize_text_field(wp_unslash($_POST['original_name'] ?? ''));
        $role     = sanitize_key($_POST['role'] ?? 'ticket');
        $subject  = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $body     = wp_kses_post(wp_unslash($_POST['body'] ?? ''));

        if ($name === '') {
            self::back('snn-tickets-templates', __('A template name is required.', 'snn-tickets'), [], true);
        }
        if (!array_key_exists($role, SNN_T_Mailer::roles())) {
            $role = 'ticket';
        }

        $templates = SNN_T_Mailer::get_templates();

        // Renaming replaces the old key rather than leaving a stale copy.
        if ($original !== '' && $original !== $name) {
            unset($templates[$original]);
        }

        $templates[$name] = [
            'role'    => $role,
            'subject' => $subject,
            'body'    => $body,
            'created' => $templates[$name]['created'] ?? current_time('mysql'),
            'updated' => current_time('mysql'),
        ];

        update_option(SNN_T_Mailer::TEMPLATES_OPTION, $templates);
        self::back('snn-tickets-templates', __('Template saved.', 'snn-tickets'), ['template' => $name]);
    }

    public static function handle_delete_template() {
        self::cap();
        check_admin_referer('snn_save_template');

        $name      = sanitize_text_field(wp_unslash($_POST['original_name'] ?? ''));
        $templates = SNN_T_Mailer::get_templates();

        if ($name !== '' && isset($templates[$name])) {
            unset($templates[$name]);
            update_option(SNN_T_Mailer::TEMPLATES_OPTION, $templates);
            self::back('snn-tickets-templates', __('Template deleted.', 'snn-tickets'));
        }

        self::back('snn-tickets-templates', __('Template not found.', 'snn-tickets'), [], true);
    }

    /* ==================================================================
     * Mail queue
     * ================================================================== */

    public static function status_badge($status) {
        $map = [
            'pending' => 'warn', 'sending' => 'info', 'sent' => 'ok', 'failed' => 'bad',
            'approved' => 'ok', 'rejected' => 'bad', 'active' => 'ok', 'revoked' => 'bad',
        ];
        $labels = [
            'pending' => __('pending', 'snn-tickets'), 'sending' => __('sending', 'snn-tickets'), 'sent' => __('sent', 'snn-tickets'),
            'failed' => __('failed', 'snn-tickets'), 'approved' => __('approved', 'snn-tickets'), 'rejected' => __('rejected', 'snn-tickets'),
            'active' => __('active', 'snn-tickets'), 'revoked' => __('revoked', 'snn-tickets'),
        ];
        return '<span class="snn-badge ' . esc_attr($map[$status] ?? '') . '">' . esc_html($labels[$status] ?? $status) . '</span>';
    }

    public static function render_queue_page() {
        self::cap();
        global $wpdb;

        $table = SNN_T_DB::queue();

        if (!empty($_GET['view'])) {
            self::render_queue_message((int)$_GET['view']);
            return;
        }

        $counts = SNN_T_Mailer::queue_counts();
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged  = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
        $per    = 30;

        $where = ['1=1'];
        $args  = [];
        if (in_array($status, ['pending', 'sending', 'sent', 'failed'], true)) {
            $where[] = 'status = %s';
            $args[]  = $status;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(to_email LIKE %s OR subject LIKE %s OR ticket_code LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        $where = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int)$wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, role, to_email, subject, ticket_code, attempts, scheduled_at, sent_at, last_error, attachments FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($args, [$per, ($paged - 1) * $per])
        ));

        $next_cron = wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);
        $action_url = admin_url('admin-post.php');
        ?>
        <div class="wrap snn-wrap">
            <h1><?php esc_html_e('Emails', 'snn-tickets'); ?></h1>
            <?php self::tabs('emails', 'snn-tickets-queue'); ?>
            <?php self::notice(); ?>

            <p class="snn-muted">
                <?php printf(esc_html__('Sent in the background by WP-Cron, up to %d per minute — you can close this page.', 'snn-tickets'), (int)SNN_T_Mailer::batch_size()); ?>
                <?php if ($next_cron): ?><?php printf(esc_html__('Next run in %s.', 'snn-tickets'), esc_html(human_time_diff($next_cron, time()))); ?><?php endif; ?>
            </p>

            <div class="snn-stats">
                <?php foreach (['pending' => 'warn', 'sending' => '', 'sent' => 'ok', 'failed' => 'bad'] as $k => $cls): ?>
                    <a class="snn-stat <?php echo $counts[$k] || $k === 'sent' ? esc_attr($cls) : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'snn-tickets-queue', 'status' => $k], admin_url('admin.php'))); ?>">
                        <div class="v"><?php echo (int)$counts[$k]; ?></div>
                        <div class="l"><?php echo esc_html(ucfirst(wp_strip_all_tags(self::status_badge($k)))); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="snn-toolbar">
                <form method="post" action="<?php echo esc_url($action_url); ?>" class="snn-actions">
                    <input type="hidden" name="action" value="snn_queue_action">
                    <?php wp_nonce_field('snn_queue_action'); ?>
                    <button class="button button-primary" name="do" value="process"><?php esc_html_e('Send a batch now', 'snn-tickets'); ?></button>
                    <?php if ($counts['failed']): ?><button class="button" name="do" value="retry"><?php esc_html_e('Retry all failed', 'snn-tickets'); ?></button><?php endif; ?>
                    <button class="button" name="do" value="clear" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Remove every sent record from the log?', 'snn-tickets'))); ?>);"><?php esc_html_e('Clear sent log', 'snn-tickets'); ?></button>
                </form>
                <span class="spacer"></span>
                <form method="get" class="snn-actions">
                    <input type="hidden" name="page" value="snn-tickets-queue">
                    <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Email, subject or code', 'snn-tickets'); ?>">
                    <button class="button"><?php esc_html_e('Search', 'snn-tickets'); ?></button>
                    <?php if ($status !== 'all' || $search !== ''): ?><a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-queue')); ?>"><?php esc_html_e('Show all', 'snn-tickets'); ?></a><?php endif; ?>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:90px;"><?php esc_html_e('Status', 'snn-tickets'); ?></th>
                        <th><?php esc_html_e('Recipient', 'snn-tickets'); ?></th>
                        <th><?php esc_html_e('Subject', 'snn-tickets'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Type', 'snn-tickets'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('When', 'snn-tickets'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Actions', 'snn-tickets'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6"><?php esc_html_e('Nothing here.', 'snn-tickets'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo self::status_badge($r->status); ?><?php if ($r->attempts > 1): ?><br><span class="snn-muted" style="font-size:11px"><?php printf(esc_html__('%d tries', 'snn-tickets'), (int)$r->attempts); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($r->to_email); ?><?php if ($r->ticket_code): ?><br><code style="font-size:11px"><?php echo esc_html($r->ticket_code); ?></code><?php endif; ?></td>
                        <td><?php echo esc_html($r->subject); ?>
                            <?php if ($r->attachments): ?><br><span class="snn-muted" style="font-size:11px">📎 <?php echo esc_html(strtoupper(str_replace(',', ', ', $r->attachments))); ?></span><?php endif; ?>
                            <?php if ($r->last_error): ?><br><span style="color:#b3261e;font-size:12px"><?php echo esc_html($r->last_error); ?></span><?php endif; ?></td>
                        <td><?php echo esc_html($r->role); ?></td>
                        <td><?php echo self::when($r->sent_at ?: $r->scheduled_at); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" class="snn-actions">
                                <input type="hidden" name="action" value="snn_queue_action">
                                <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                                <?php wp_nonce_field('snn_queue_action'); ?>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-queue&view=' . (int)$r->id)); ?>"><?php esc_html_e('View', 'snn-tickets'); ?></a>
                                <?php if (in_array($r->status, ['failed', 'sending'], true)): ?>
                                    <button class="button button-small" name="do" value="retry_one"><?php esc_html_e('Retry', 'snn-tickets'); ?></button>
                                <?php endif; ?>
                                <button class="button button-small button-link-delete" name="do" value="delete_one" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Remove this message from the queue?', 'snn-tickets'))); ?>);"><?php esc_html_e('Delete', 'snn-tickets'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(esc_html(_n('%s item', '%s items', $total, 'snn-tickets')), number_format_i18n($total)); ?></span>
                    <?php echo SNN_T_Admin::pager($total, $per, $paged); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_queue_message($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . SNN_T_DB::queue() . " WHERE id = %d", $id));
        $back = admin_url('admin.php?page=snn-tickets-queue');
        ?>
        <div class="wrap snn-wrap">
            <h1><a href="<?php echo esc_url($back); ?>" class="dashicons dashicons-arrow-left-alt" style="text-decoration:none" aria-label="<?php esc_attr_e('Back', 'snn-tickets'); ?>"></a> <?php esc_html_e('Queued message', 'snn-tickets'); ?></h1>
            <?php if (!$r): ?>
                <p><?php esc_html_e('That message no longer exists.', 'snn-tickets'); ?></p></div>
                <?php return; endif;
            $html = $r->body;
            if ($r->ticket_code !== '') {
                $html = str_replace('cid:' . SNN_T_Mailer::QR_CID, SNN_T_QR::data_uri($r->ticket_code, 6, 2), $html);
            }
            if (!SNN_T_Mailer::is_full_document($html)) $html = '<!doctype html><html><body style="font-family:sans-serif">' . $html . '</body></html>';
            ?>
            <div class="snn-card">
                <dl class="snn-dl">
                    <dt><?php esc_html_e('Status', 'snn-tickets'); ?></dt><dd><?php echo self::status_badge($r->status); ?> <?php if ($r->last_error): ?><span style="color:#b3261e"><?php echo esc_html($r->last_error); ?></span><?php endif; ?></dd>
                    <dt><?php esc_html_e('To', 'snn-tickets'); ?></dt><dd><?php echo esc_html(trim($r->to_name . ' <' . $r->to_email . '>')); ?></dd>
                    <dt><?php esc_html_e('Subject', 'snn-tickets'); ?></dt><dd><?php echo esc_html($r->subject); ?></dd>
                    <dt><?php esc_html_e('Attachments', 'snn-tickets'); ?></dt><dd><?php echo $r->attachments ? esc_html(strtoupper(str_replace(',', ', ', $r->attachments))) : '—'; ?></dd>
                    <dt><?php esc_html_e('Queued', 'snn-tickets'); ?></dt><dd><?php echo self::when($r->created_at); ?></dd>
                    <dt><?php esc_html_e('Sent', 'snn-tickets'); ?></dt><dd><?php echo self::when($r->sent_at); ?></dd>
                </dl>
            </div>
            <iframe title="<?php esc_attr_e('Message body', 'snn-tickets'); ?>" style="width:100%;max-width:760px;height:900px;border:1px solid #dcdcde;border-radius:8px;background:#fff" sandbox="" srcdoc="<?php echo esc_attr($html); ?>"></iframe>
        </div>
        <?php
    }

    public static function handle_queue_action() {
        self::cap();
        check_admin_referer('snn_queue_action');

        $do = sanitize_key($_POST['do'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        switch ($do) {
            case 'process':
                $r = SNN_T_Mailer::process_queue();
                $msg = !empty($r['locked'])
                    ? __('Another send is already running. Try again in a moment.', 'snn-tickets')
                    : sprintf(__('Processed a batch: %1$d sent, %2$d failed.', 'snn-tickets'), $r['sent'], $r['failed']);
                break;
            case 'retry':
                $msg = sprintf(__('%d message(s) requeued.', 'snn-tickets'), SNN_T_Mailer::retry_failed());
                break;
            case 'retry_one':
                SNN_T_Mailer::retry_failed($id);
                $msg = __('Message requeued.', 'snn-tickets');
                break;
            case 'delete_one':
                SNN_T_Mailer::delete_row($id);
                $msg = __('Message removed.', 'snn-tickets');
                break;
            case 'clear':
                $msg = sprintf(__('%d sent record(s) removed.', 'snn-tickets'), SNN_T_Mailer::clear_sent());
                break;
            default:
                $msg = __('Nothing to do.', 'snn-tickets');
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=snn-tickets-queue');
        wp_safe_redirect(add_query_arg('snn_msg', rawurlencode($msg), remove_query_arg(['snn_msg', 'snn_type'], $back)));
        exit;
    }
}
