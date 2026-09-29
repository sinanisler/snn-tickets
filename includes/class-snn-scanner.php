<?php
/**
 * The door scanner: staff access, the built-in door page, the
 * [tickets_scan_page] shortcode, and the validation endpoint.
 *
 * Only staff check tickets in, and only here. Staff are logged-in admins,
 * or anyone who entered the door PIN (a signed cookie, 24 hours). Everyone
 * else who opens a ticket's QR link -- usually the attendee tapping it in
 * their inbox -- sees their ticket and nothing is counted.
 *
 * A ticket that arrives in the URL (a phone's own camera app, a link) is
 * never checked in silently: the scanner asks first. Tickets read by the
 * scanner's own camera are checked in straight away.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Scanner {

    const PIN_OPTION = 'snn_tickets_staff_pin';
    const COOKIE     = 'snn_t_staff';
    const SESSION    = DAY_IN_SECONDS;

    public static function init() {
        add_shortcode('tickets_scan_page', [__CLASS__, 'shortcode']);
        add_action('wp_ajax_snn_validate_ticket',        [__CLASS__, 'ajax_validate']);
        add_action('wp_ajax_nopriv_snn_validate_ticket', [__CLASS__, 'ajax_validate']);
        add_action('wp_ajax_snn_staff_login',            [__CLASS__, 'ajax_login']);
        add_action('wp_ajax_nopriv_snn_staff_login',     [__CLASS__, 'ajax_login']);
        add_action('wp_ajax_snn_staff_logout',           [__CLASS__, 'ajax_logout']);
        add_action('wp_ajax_nopriv_snn_staff_logout',    [__CLASS__, 'ajax_logout']);
    }

    /* ------------------------------------------------------------------
     * Staff access
     * ---------------------------------------------------------------- */

    public static function pin_set() {
        return (string)get_option(self::PIN_OPTION, '') !== '';
    }

    public static function set_pin($pin) {
        $pin = trim((string)$pin);
        if ($pin === '') {
            delete_option(self::PIN_OPTION);
            return;
        }
        update_option(self::PIN_OPTION, wp_hash_password($pin), false);
    }

    /** Cookie value: expiry|hmac. Changing the PIN invalidates sessions. */
    private static function token($expires) {
        return $expires . '|' . hash_hmac('sha256', 'staff|' . $expires . '|' . get_option(self::PIN_OPTION, ''), SNN_T_QR::secret());
    }

    public static function is_staff() {
        if (current_user_can(SNN_T_Tickets::cap())) return true;
        if (!self::pin_set() || empty($_COOKIE[self::COOKIE])) return false;

        $raw = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
        $parts = explode('|', $raw);
        if (count($parts) !== 2 || (int)$parts[0] < time()) return false;
        return hash_equals(self::token((int)$parts[0]), $raw);
    }

    public static function ajax_login() {
        $ip   = SNN_T_Tickets::client_ip();
        $key  = 'snn_t_pin_' . md5($ip);
        $hits = (int)get_transient($key);
        if ($hits >= 8) {
            wp_send_json_error(['message' => __('Too many attempts. Wait ten minutes and try again.', 'snn-tickets')], 429);
        }

        $pin  = trim(sanitize_text_field(wp_unslash($_POST['pin'] ?? '')));
        $hash = (string)get_option(self::PIN_OPTION, '');

        if ($hash === '' || $pin === '' || !wp_check_password($pin, $hash)) {
            set_transient($key, $hits + 1, 10 * MINUTE_IN_SECONDS);
            wp_send_json_error(['message' => __('That PIN is not right.', 'snn-tickets')], 403);
        }

        delete_transient($key);
        $expires = time() + self::SESSION;
        setcookie(self::COOKIE, self::token($expires), [
            'expires'  => $expires,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        wp_send_json_success(['ok' => true]);
    }

    public static function ajax_logout() {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => COOKIEPATH ?: '/']);
        wp_send_json_success(['ok' => true]);
    }

    /* ------------------------------------------------------------------
     * Validation endpoint
     * ---------------------------------------------------------------- */

    public static function stats($list_id = 0) {
        global $wpdb;
        $t = SNN_T_DB::tickets();
        $where = "status = 'active'";
        if ($list_id) $where .= $wpdb->prepare(' AND list_id = %d', (int)$list_id);
        $row = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(CASE WHEN validate_count > 0 THEN 1 ELSE 0 END) AS inside FROM {$t} WHERE {$where}");
        return ['total' => (int)($row->total ?? 0), 'checked_in' => (int)($row->inside ?? 0)];
    }

    public static function ajax_validate() {
        $staff = self::is_staff();

        if (!$staff && !SNN_T_Tickets::rate_limit_ok()) {
            wp_send_json_error(['message' => __('Too many scans from this address. Wait a minute and try again.', 'snn-tickets')], 429);
        }

        $code    = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $sig     = sanitize_text_field(wp_unslash($_POST['sig'] ?? ''));
        $list_id = (int)($_POST['list'] ?? 0);
        $peek    = !empty($_POST['peek']);

        if ($code === '') {
            wp_send_json_error(['message' => __('Missing code', 'snn-tickets')], 400);
        }

        $result = SNN_T_Tickets::validate($code, $sig, $staff && !$peek, $list_id);
        $result['staff'] = $staff;
        $result['peek']  = $peek;
        if (!empty($result['last_validated'])) {
            $result['last_time'] = date_i18n(get_option('time_format', 'H:i'), strtotime($result['last_validated']));
        }
        if ($staff) $result['stats'] = self::stats($list_id);

        wp_send_json_success($result);
    }

    /* ------------------------------------------------------------------
     * Pages
     * ---------------------------------------------------------------- */

    public static function shortcode($atts) {
        $atts = shortcode_atts(['list' => 0], $atts, 'tickets_scan_page');
        $list_id = (int)$atts['list'];

        // A non-staff visitor arriving from a QR link sees their ticket.
        if (!self::is_staff() && ($ticket = self::ticket_from_url())) {
            $t = SNN_T_Events::ticket_data($ticket);
            return '<div class="snn-attendee-ticket" style="max-width:520px;margin:0 auto;">'
                 . SNN_T_Design::ticket_card_html($t, SNN_T_QR::data_uri($t['code'], 8, 2))
                 . SNN_T_Design::buttons_html(SNN_T_Files::links($t), $t['design'])
                 . '</div>';
        }

        list($html, $js) = self::markup($list_id ? SNN_T_Events::get($list_id) : null);
        SNN_T_Forms::footer_script('snn-tickets-scanner', $js);
        return $html;
    }

    /** The built-in door page: a phone-sized app with no theme around it. */
    public static function render_page($event) {
        if (!self::is_staff() && ($ticket = self::ticket_from_url())) {
            SNN_T_Files::render_ticket_page(SNN_T_Events::ticket_data($ticket));
            return;
        }
        list($html, $js) = self::markup($event);
        $title = $event ? sprintf(__('Door – %s', 'snn-tickets'), $event->name) : __('Door scanner', 'snn-tickets');
        status_header(200);
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#1d2327">
<title><?php echo esc_html($title); ?></title>
<style>
html,body{margin:0;background:#f6f7f7;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:16px}
body{padding:16px 16px calc(24px + env(safe-area-inset-bottom,0px))}
</style>
</head>
<body>
<?php echo $html; // built from escaped parts ?>
<script><?php echo $js; // static script with JSON config ?></script>
</body>
</html><?php
    }

    /** The ticket in ?snn_ticket=&snn_sig=, when the signature is good. */
    private static function ticket_from_url() {
        if (empty($_GET['snn_ticket'])) return null;
        $code = sanitize_text_field(wp_unslash($_GET['snn_ticket']));
        $sig  = sanitize_text_field(wp_unslash($_GET['snn_sig'] ?? ''));
        return SNN_T_QR::verify($code, $sig) ? SNN_T_Tickets::get_by_code($code) : null;
    }

    /**
     * Scanner HTML and its script.
     *
     * @return array [html, js]
     */
    private static function markup($event) {
        $list_id = $event ? (int)$event->id : 0;
        $staff   = self::is_staff();
        $here    = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

        $cfg = [
            'ajax'  => admin_url('admin-ajax.php'),
            'list'  => $list_id,
            'staff' => $staff,
            'jsqr'  => SNN_TICKETS_URL . 'src/jsQR.js',
            'stats' => $staff ? self::stats($list_id) : null,
            'i18n'  => [
                'valid'      => __('Welcome in', 'snn-tickets'),
                'used'       => __('Already checked in', 'snn-tickets'),
                'firstAt'    => __('First scanned at %1$s · this is scan %2$d', 'snn-tickets'),
                'stop'       => __("Don't let in", 'snn-tickets'),
                'otherEvent' => __('Wrong event', 'snn-tickets'),
                'unsigned'   => __('Typed in by hand – no QR signature.', 'snn-tickets'),
                'starting'   => __('Starting camera…', 'snn-tickets'),
                'scanning'   => __('Point the camera at a ticket', 'snn-tickets'),
                'noCamera'   => __('Camera unavailable. Type the code below.', 'snn-tickets'),
                'checking'   => __('Checking…', 'snn-tickets'),
                'network'    => __('Network error – try again.', 'snn-tickets'),
                'inside'     => __('in', 'snn-tickets'),
                'tapNext'    => __('Tap for the next ticket', 'snn-tickets'),
                'badPin'     => __('That PIN is not right.', 'snn-tickets'),
                'confirmQ'   => __('Check in this ticket?', 'snn-tickets'),
                'confirmYes' => __('Check in', 'snn-tickets'),
                'confirmNo'  => __('Not now', 'snn-tickets'),
                'alreadyIn'  => __('Already checked in at %s', 'snn-tickets'),
            ],
        ];

        ob_start();
        ?>
        <div class="snn-scan" id="snn-scan" data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
            <style>
            .snn-scan{--ok:#0a7d32;--warn:#b86e00;--bad:#b3261e;max-width:560px;margin:0 auto;font-family:inherit;box-sizing:border-box}
            .snn-scan *{box-sizing:border-box}
            .snn-scan-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px}
            .snn-scan-title{margin:0;font-size:1.2rem;line-height:1.2}
            .snn-scan-sub{margin:2px 0 0;font-size:.85rem;opacity:.7}
            .snn-scan-count{text-align:right;font-variant-numeric:tabular-nums;line-height:1.1}
            .snn-scan-count b{font-size:1.5rem}
            .snn-scan-count span{font-size:.8rem;opacity:.7}
            .snn-scan-bar{height:6px;background:rgba(0,0,0,.08);border-radius:3px;overflow:hidden;margin:0 0 12px}
            .snn-scan-bar i{display:block;height:100%;background:var(--ok);width:0;transition:width .3s}
            .snn-scan-cam{position:relative;width:100%;aspect-ratio:1/1;max-height:62vh;background:#000;border-radius:14px;overflow:hidden}
            .snn-scan-cam video{width:100%;height:100%;object-fit:cover;display:block}
            .snn-scan-frame{position:absolute;inset:18%;border:3px solid rgba(255,255,255,.85);border-radius:16px;box-shadow:0 0 0 999px rgba(0,0,0,.35);pointer-events:none}
            .snn-scan-status{position:absolute;left:0;right:0;bottom:0;padding:10px 12px;color:#fff;font-size:.85rem;text-align:center;background:linear-gradient(transparent,rgba(0,0,0,.7))}
            .snn-scan-flash{position:fixed;inset:0;z-index:99999;display:none;flex-direction:column;align-items:center;justify-content:center;color:#fff;text-align:center;padding:24px;cursor:pointer}
            .snn-scan-flash.on{display:flex}
            .snn-scan-flash.ok{background:var(--ok)}.snn-scan-flash.warn{background:var(--warn)}.snn-scan-flash.bad{background:var(--bad)}
            .snn-scan-flash.ask{background:#1d2327;cursor:default}
            .snn-scan-flash .ic{font-size:72px;line-height:1;margin-bottom:10px;font-weight:700}
            .snn-scan-flash .hd{font-size:1.9rem;font-weight:800;margin:0 0 8px}
            .snn-scan-flash .nm{font-size:1.35rem;font-weight:600}
            .snn-scan-flash .mt{font-size:.95rem;opacity:.9;margin-top:6px}
            .snn-scan-flash .ct{background:rgba(255,255,255,.2);border-radius:999px;padding:5px 14px;font-weight:700;margin-top:14px;font-variant-numeric:tabular-nums}
            .snn-scan-flash .hint{position:absolute;bottom:calc(22px + env(safe-area-inset-bottom,0px));left:0;right:0;font-size:.85rem;opacity:.85}
            .snn-scan-flash .acts{display:flex;gap:10px;margin-top:22px}
            .snn-scan-flash .acts button{font-size:1.1rem;padding:14px 22px}
            .snn-scan-flash .acts .go{background:var(--ok)}
            .snn-scan-manual{display:flex;gap:8px;margin:12px 0 0}
            .snn-scan-manual input{flex:1;min-width:0;padding:12px;border:1px solid #c3c4c7;border-radius:10px;font:inherit;font-family:ui-monospace,Menlo,Consolas,monospace;text-transform:uppercase;background:#fff}
            .snn-scan button{padding:12px 16px;border:0;border-radius:10px;background:#1d2327;color:#fff;font:inherit;font-weight:600;cursor:pointer}
            .snn-scan button.ghost{background:transparent;color:inherit;border:1px solid rgba(0,0,0,.2)}
            .snn-scan-flash button.ghost{color:#fff;border-color:rgba(255,255,255,.4)}
            .snn-scan-recent{list-style:none;margin:14px 0 0;padding:0;font-size:.9rem}
            .snn-scan-recent li{display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid rgba(0,0,0,.08)}
            .snn-scan-recent .dot{width:10px;height:10px;border-radius:50%;flex:none}
            .snn-scan-recent .who{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .snn-scan-recent time{opacity:.6;font-size:.8rem;font-variant-numeric:tabular-nums}
            .snn-scan-login{padding:22px;border:1px solid rgba(0,0,0,.12);border-radius:14px;text-align:center;background:#fff}
            .snn-scan-login input{width:100%;max-width:220px;padding:12px;margin:10px auto;display:block;font-size:1.4rem;text-align:center;letter-spacing:.3em;border:1px solid #c3c4c7;border-radius:10px}
            .snn-scan-err{color:var(--bad);font-size:.9rem;min-height:1.2em}
            .snn-scan-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:12px;flex-wrap:wrap}
            </style>

            <div class="snn-scan-top">
                <div>
                    <h2 class="snn-scan-title"><?php esc_html_e('Door scanner', 'snn-tickets'); ?></h2>
                    <p class="snn-scan-sub"><?php echo $event ? esc_html($event->name) : esc_html__('All events', 'snn-tickets'); ?></p>
                </div>
                <?php if ($staff): ?>
                    <div class="snn-scan-count"><b data-count>–</b> <span data-count-label></span></div>
                <?php endif; ?>
            </div>

            <?php if (!$staff): ?>
                <div class="snn-scan-login">
                    <?php if (self::pin_set()): ?>
                        <p><strong><?php esc_html_e('Staff only', 'snn-tickets'); ?></strong><br>
                        <?php esc_html_e('Enter the door PIN to start checking tickets in.', 'snn-tickets'); ?></p>
                        <form data-login>
                            <input type="password" inputmode="numeric" autocomplete="off" name="pin" aria-label="<?php esc_attr_e('Door PIN', 'snn-tickets'); ?>" required>
                            <p class="snn-scan-err" data-err></p>
                            <button type="submit"><?php esc_html_e('Unlock scanner', 'snn-tickets'); ?></button>
                        </form>
                    <?php else: ?>
                        <p><strong><?php esc_html_e('Staff only', 'snn-tickets'); ?></strong><br>
                        <?php esc_html_e('Log in to check tickets in.', 'snn-tickets'); ?></p>
                        <p><a href="<?php echo esc_url(wp_login_url($here)); ?>"><?php esc_html_e('Log in', 'snn-tickets'); ?></a></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="snn-scan-bar"><i data-bar></i></div>
                <div class="snn-scan-cam">
                    <video playsinline muted autoplay></video>
                    <canvas hidden></canvas>
                    <div class="snn-scan-frame"></div>
                    <div class="snn-scan-status" data-status><?php esc_html_e('Starting camera…', 'snn-tickets'); ?></div>
                </div>
                <form class="snn-scan-manual" data-manual>
                    <input type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php esc_attr_e('Type a ticket code', 'snn-tickets'); ?>" aria-label="<?php esc_attr_e('Ticket code', 'snn-tickets'); ?>">
                    <button type="submit"><?php esc_html_e('Check', 'snn-tickets'); ?></button>
                </form>
                <ul class="snn-scan-recent" data-recent></ul>
                <div class="snn-scan-foot">
                    <label style="font-size:.85rem;"><input type="checkbox" data-sound checked> <?php esc_html_e('Sound', 'snn-tickets'); ?></label>
                    <?php if (!current_user_can(SNN_T_Tickets::cap())): ?>
                        <button type="button" class="ghost" data-logout><?php esc_html_e('Lock scanner', 'snn-tickets'); ?></button>
                    <?php endif; ?>
                </div>
                <div class="snn-scan-flash" data-flash role="alert" aria-live="assertive"></div>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();
        ob_start();
        ?>
            (function(){
                var root = document.getElementById('snn-scan');
                if (!root) return;
                var cfg = JSON.parse(root.getAttribute('data-cfg'));
                var T = cfg.i18n;
                function post(data){
                    var fd = new FormData();
                    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
                    return fetch(cfg.ajax, {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){ return r.json(); });
                }

                var login = root.querySelector('[data-login]');
                if (login) {
                    login.addEventListener('submit', function(e){
                        e.preventDefault();
                        var err = root.querySelector('[data-err]');
                        post({action:'snn_staff_login', pin: login.pin.value}).then(function(j){
                            if (j && j.success) { location.reload(); }
                            else { err.textContent = (j && j.data && j.data.message) || T.badPin; login.pin.select(); }
                        }).catch(function(){ err.textContent = T.network; });
                    });
                    return;
                }
                if (!cfg.staff) return;

                var video   = root.querySelector('video');
                var canvas  = root.querySelector('canvas');
                var status  = root.querySelector('[data-status]');
                var flash   = root.querySelector('[data-flash]');
                var recent  = root.querySelector('[data-recent]');
                var manual  = root.querySelector('[data-manual]');
                var sound   = root.querySelector('[data-sound]');
                var busy = false, paused = false, stream = null, detector = null, resumeTimer = null, lastCode = '', lastAt = 0, lastStats = cfg.stats;

                function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
                function setStatus(s){ if (status) status.textContent = s; }

                function renderStats(s){
                    if (!s) return;
                    lastStats = s;
                    var c = root.querySelector('[data-count]'), l = root.querySelector('[data-count-label]'), b = root.querySelector('[data-bar]');
                    if (c) c.textContent = s.checked_in + ' / ' + s.total;
                    if (l) l.textContent = T.inside;
                    if (b) b.style.width = (s.total ? Math.round(100 * s.checked_in / s.total) : 0) + '%';
                }
                renderStats(cfg.stats);

                var audio = null;
                function beep(kind){
                    if (!sound || !sound.checked) return;
                    try {
                        audio = audio || new (window.AudioContext || window.webkitAudioContext)();
                        var tones = kind === 'ok' ? [880, 1320] : (kind === 'warn' ? [520, 520] : [220, 180]);
                        tones.forEach(function(f, i){
                            var o = audio.createOscillator(), g = audio.createGain();
                            o.frequency.value = f; o.type = kind === 'bad' ? 'square' : 'sine';
                            g.gain.value = .15; o.connect(g); g.connect(audio.destination);
                            var t = audio.currentTime + i * .14; o.start(t); o.stop(t + .12);
                        });
                    } catch (e) {}
                    if (navigator.vibrate) navigator.vibrate(kind === 'ok' ? 80 : [120, 60, 120]);
                }

                function addRecent(kind, label){
                    var li = document.createElement('li');
                    var color = kind === 'ok' ? 'var(--ok)' : (kind === 'warn' ? 'var(--warn)' : 'var(--bad)');
                    li.innerHTML = '<span class="dot" style="background:' + color + '"></span><span class="who">' + esc(label) + '</span><time>'
                        + new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) + '</time>';
                    recent.insertBefore(li, recent.firstChild);
                    while (recent.children.length > 8) recent.removeChild(recent.lastChild);
                }

                function show(data){
                    var kind, icon, head, meta = '';
                    if (!data || !data.valid) {
                        kind = 'bad'; icon = '✕';
                        head = data && data.reason === 'other_list' ? T.otherEvent : T.stop;
                        meta = esc((data && data.message) || '');
                    } else if (data.already_used) {
                        kind = 'warn'; icon = '!';
                        head = T.used;
                        meta = esc(T.firstAt.replace('%1$s', data.last_time || '').replace('%2$d', data.validate_count));
                    } else {
                        kind = 'ok'; icon = '✓'; head = T.valid;
                    }
                    var name = data && data.name ? esc(data.name) : '';
                    var sub  = data && data.list_name ? esc(data.list_name) : '';
                    if (data && data.stats) renderStats(data.stats);
                    flash.className = 'snn-scan-flash on ' + kind;
                    flash.innerHTML = '<div class="ic">' + icon + '</div><p class="hd">' + esc(head) + '</p>'
                        + (name ? '<div class="nm">' + name + '</div>' : '')
                        + (meta ? '<div class="mt">' + meta + '</div>' : '')
                        + (sub && kind !== 'bad' ? '<div class="mt">' + sub + '</div>' : '')
                        + (data && data.valid && !data.signed ? '<div class="mt">' + esc(T.unsigned) + '</div>' : '')
                        + (kind === 'ok' && lastStats ? '<div class="ct">' + lastStats.checked_in + ' / ' + lastStats.total + ' ' + esc(T.inside) + '</div>' : '')
                        + '<div class="hint">' + esc(T.tapNext) + '</div>';
                    beep(kind);
                    addRecent(kind, (data && data.name) || (data && data.ticket_code) || head);

                    paused = true;
                    clearTimeout(resumeTimer);
                    resumeTimer = setTimeout(resume, kind === 'ok' ? 1800 : 4000);
                }

                function resume(){
                    clearTimeout(resumeTimer);
                    flash.className = 'snn-scan-flash';
                    paused = false;
                    setStatus(T.scanning);
                }
                flash.addEventListener('click', function(e){ if (!flash.classList.contains('ask')) resume(); });

                function parse(raw){
                    raw = String(raw || '').trim();
                    try {
                        var u = new URL(raw);
                        var c = u.searchParams.get('snn_ticket');
                        if (c) return {code: c, sig: u.searchParams.get('snn_sig') || ''};
                    } catch (e) {}
                    return {code: raw.toUpperCase(), sig: ''};
                }

                function check(raw){
                    var p = parse(raw);
                    if (!p.code) return;
                    // The camera sees the same code for several frames.
                    var now = Date.now();
                    if (p.code === lastCode && now - lastAt < 5000) return;
                    lastCode = p.code; lastAt = now;
                    busy = true;
                    setStatus(T.checking);
                    post({action:'snn_validate_ticket', code:p.code, sig:p.sig, list:cfg.list}).then(function(j){
                        show(j && j.success ? j.data : {valid:false, message:(j && j.data && j.data.message) || ''});
                    }).catch(function(){
                        show({valid:false, message:T.network});
                        lastCode = '';
                    }).then(function(){ busy = false; });
                }

                // A ticket that came in the URL is shown first and only
                // checked in when staff say so.
                function ask(raw){
                    var p = parse(raw);
                    paused = true;
                    post({action:'snn_validate_ticket', code:p.code, sig:p.sig, list:cfg.list, peek:1}).then(function(j){
                        var d = j && j.success ? j.data : null;
                        if (!d || !d.valid) { paused = false; show(d || {valid:false}); return; }
                        flash.className = 'snn-scan-flash on ask';
                        flash.innerHTML = '<p class="hd">' + esc(T.confirmQ) + '</p>'
                            + '<div class="nm">' + esc(d.name || d.ticket_code) + '</div>'
                            + '<div class="mt">' + esc(d.list_name || '') + '</div>'
                            + (d.already_used ? '<div class="mt">' + esc(T.alreadyIn.replace('%s', d.last_time || '')) + '</div>' : '')
                            + '<div class="acts"><button type="button" class="go" data-yes>' + esc(T.confirmYes) + '</button><button type="button" class="ghost" data-no>' + esc(T.confirmNo) + '</button></div>';
                        flash.querySelector('[data-yes]').onclick = function(){ lastCode = ''; check(raw); };
                        flash.querySelector('[data-no]').onclick = function(){ resume(); };
                    }).catch(function(){ paused = false; });
                }

                manual.addEventListener('submit', function(e){
                    e.preventDefault();
                    var input = manual.querySelector('input');
                    lastCode = '';
                    check(input.value);
                    input.value = '';
                });

                var logout = root.querySelector('[data-logout]');
                if (logout) logout.addEventListener('click', function(){
                    post({action:'snn_staff_logout'}).then(function(){ location.reload(); });
                });

                function loop(){
                    if (!stream) return;
                    if (!busy && !paused && video.readyState >= 2) {
                        if (detector) {
                            detector.detect(video).then(function(codes){
                                if (codes && codes.length) check(codes[0].rawValue);
                            }).catch(function(){});
                        } else if (window.jsQR) {
                            var w = video.videoWidth, h = video.videoHeight;
                            if (w && h) {
                                var scale = Math.min(1, 640 / Math.max(w, h));
                                canvas.width = w * scale; canvas.height = h * scale;
                                var ctx = canvas.getContext('2d', {willReadFrequently:true});
                                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                                var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                                var r = window.jsQR(img.data, img.width, img.height, {inversionAttempts:'dontInvert'});
                                if (r && r.data) check(r.data);
                            }
                        }
                    }
                    setTimeout(function(){ requestAnimationFrame(loop); }, 120);
                }

                function start(){
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { setStatus(T.noCamera); return; }
                    setStatus(T.starting);
                    navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}, audio:false}).then(function(s){
                        stream = s; video.srcObject = s;
                        return video.play();
                    }).then(function(){
                        setStatus(T.scanning);
                        loop();
                    }).catch(function(){ setStatus(T.noCamera); });
                }

                if ('BarcodeDetector' in window) {
                    try { detector = new BarcodeDetector({formats:['qr_code']}); } catch (e) { detector = null; }
                }
                if (!detector) {
                    var s = document.createElement('script');
                    s.src = cfg.jsqr; s.async = true;
                    document.head.appendChild(s);
                }

                var q = new URLSearchParams(location.search);
                if (q.get('snn_ticket')) {
                    var arrived = location.href;
                    if (history.replaceState) history.replaceState(null, '', location.pathname);
                    ask(arrived);
                }

                start();
                document.addEventListener('visibilitychange', function(){
                    if (document.hidden && stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
                    else if (!document.hidden && !stream) { start(); }
                });
            })();
        <?php
        return [$html, ob_get_clean()];
    }
}
