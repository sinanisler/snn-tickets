<?php
/**
 * Settings, email templates and the mail queue monitor.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Admin {

    public static function init() {
        add_action('admin_post_snn_save_settings',  [__CLASS__, 'handle_save_settings']);
        add_action('admin_post_snn_save_template',  [__CLASS__, 'handle_save_template']);
        add_action('admin_post_snn_delete_template',[__CLASS__, 'handle_delete_template']);
        add_action('admin_post_snn_queue_action',   [__CLASS__, 'handle_queue_action']);
    }

    private static function cap() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    }

    private static function notice() {
        if (isset($_GET['snn_msg'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'
               . esc_html(wp_unslash($_GET['snn_msg'])) . '</p></div>';
        }
    }

    private static function back($page, $msg, $extra = []) {
        wp_safe_redirect(add_query_arg(array_merge([
            'page'    => $page,
            'snn_msg' => rawurlencode($msg),
        ], $extra), admin_url('admin.php')));
        exit;
    }

    /* ==================================================================
     * Settings
     * ================================================================== */

    public static function render_settings_page() {
        self::cap();

        $from_name  = get_option(SNN_T_Mailer::FROM_NAME_OPTION, get_bloginfo('name'));
        $from_email = get_option(SNN_T_Mailer::FROM_EMAIL_OPTION, get_option('admin_email'));
        $batch_size = SNN_T_Mailer::batch_size();
        $scan_url   = get_option(SNN_T_QR::SCAN_URL_OPTION, '');

        $next_cron = wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);
        $gd        = function_exists('imagecreatetruecolor');
        $zlib      = function_exists('gzcompress');
        ?>
        <div class="wrap">
            <h1>Ticket Settings</h1>
            <?php self::notice(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="snn_save_settings">
                <?php wp_nonce_field('snn_save_settings'); ?>

                <h2>Sending</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="from_name">From name</label></th>
                        <td><input type="text" id="from_name" name="from_name" class="regular-text"
                                   value="<?php echo esc_attr($from_name); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="from_email">From address</label></th>
                        <td><input type="email" id="from_email" name="from_email" class="regular-text"
                                   value="<?php echo esc_attr($from_email); ?>">
                            <p class="description">Leave blank to use whatever WordPress is configured to send as.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size">Emails per minute</label></th>
                        <td><input type="number" id="batch_size" name="batch_size" min="1" max="200" class="small-text"
                                   value="<?php echo esc_attr($batch_size); ?>">
                            <p class="description">The queue runs once a minute and sends up to this many messages each time.
                            Keep it under whatever your host or SMTP provider allows.</p></td>
                    </tr>
                </table>

                <h2>Scanning</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="scan_url">Scan page URL</label></th>
                        <td>
                            <input type="url" id="scan_url" name="scan_url" class="large-text"
                                   value="<?php echo esc_attr($scan_url); ?>" placeholder="<?php echo esc_attr(home_url('/scan/')); ?>">
                            <p class="description">
                                The page holding the <code>[tickets_scan_page]</code> shortcode. QR codes point here,
                                so a phone camera opens your scanner directly. Changing it means QR images
                                already sent will still validate, but will land on the old page.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button class="button button-primary">Save settings</button></p>
            </form>

            <hr>

            <h2>System check</h2>
            <table class="widefat striped" style="max-width:760px;">
                <tbody>
                    <tr>
                        <td style="width:220px;"><strong>QR rendering</strong></td>
                        <td>
                            <?php if ($gd): ?>
                                GD extension present — PNGs render through GD.
                            <?php elseif ($zlib): ?>
                                GD is not installed; the built-in PNG encoder is being used instead. This works fine.
                            <?php else: ?>
                                <span style="color:#b3261e;">Neither GD nor zlib is available. QR images cannot be generated.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Mail queue cron</strong></td>
                        <td>
                            <?php if ($next_cron): ?>
                                Next run <?php echo esc_html(human_time_diff($next_cron, time())); ?>
                                (<?php echo esc_html(date_i18n('H:i:s', $next_cron + (int)(get_option('gmt_offset') * HOUR_IN_SECONDS))); ?>).
                            <?php else: ?>
                                <span style="color:#b3261e;">Not scheduled.</span> Deactivate and reactivate the plugin to fix.
                            <?php endif; ?>
                            <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                                <br><strong>DISABLE_WP_CRON is on</strong> — make sure a real cron job hits <code>wp-cron.php</code>,
                                otherwise queued mail will only move when you press "Process now".
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Signing key</strong></td>
                        <td>
                            Set. QR codes carry a signature so scans can be trusted.
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"
                                  onsubmit="return confirm('Regenerate the signing key? Every QR code already sent out will stop validating as signed. Only do this if the key has leaked.');">
                                <input type="hidden" name="action" value="snn_save_settings">
                                <input type="hidden" name="regenerate_secret" value="1">
                                <?php wp_nonce_field('snn_save_settings'); ?>
                                <button class="button button-small">Regenerate</button>
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_save_settings() {
        self::cap();
        check_admin_referer('snn_save_settings');

        if (!empty($_POST['regenerate_secret'])) {
            delete_option(SNN_T_QR::SECRET_OPTION);
            SNN_T_QR::secret();
            $n = SNN_T_QR::flush_cache();
            self::back('snn-tickets-settings', "Signing key regenerated and {$n} cached QR image(s) cleared.");
        }

        $from_name  = sanitize_text_field(wp_unslash($_POST['from_name'] ?? ''));
        $from_email = sanitize_email(wp_unslash($_POST['from_email'] ?? ''));
        $batch      = max(1, min(200, (int)($_POST['batch_size'] ?? 10)));
        $scan_url   = esc_url_raw(wp_unslash($_POST['scan_url'] ?? ''));

        update_option(SNN_T_Mailer::FROM_NAME_OPTION, $from_name);
        update_option(SNN_T_Mailer::FROM_EMAIL_OPTION, ($from_email && is_email($from_email)) ? $from_email : '');
        update_option(SNN_T_Mailer::BATCH_SIZE_OPTION, $batch);

        $old_scan = (string)get_option(SNN_T_QR::SCAN_URL_OPTION, '');
        update_option(SNN_T_QR::SCAN_URL_OPTION, $scan_url);

        $msg = 'Settings saved.';
        if ($old_scan !== $scan_url) {
            // Cached PNGs encode the old destination, so they have to go.
            $n = SNN_T_QR::flush_cache();
            $msg .= " Scan URL changed, so {$n} cached QR image(s) were cleared and will be rebuilt.";
        }

        self::back('snn-tickets-settings', $msg);
    }

    /* ==================================================================
     * Email templates
     * ================================================================== */

    public static function render_templates_page() {
        self::cap();

        $templates = SNN_T_Mailer::get_templates();
        $roles     = SNN_T_Mailer::roles();

        $editing = isset($_GET['template']) ? sanitize_text_field(wp_unslash($_GET['template'])) : '';
        $current = $editing !== '' ? ($templates[$editing] ?? null) : null;

        $role    = $current['role'] ?? (isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : 'ticket');
        $default = SNN_T_Mailer::default_template($role);
        $subject = $current['subject'] ?? $default['subject'];
        $body    = $current['body']    ?? $default['body'];
        ?>
        <div class="wrap">
            <h1>Email Templates</h1>
            <?php self::notice(); ?>

            <div style="display:grid;grid-template-columns:280px minmax(0,1fr);gap:24px;align-items:start;margin-top:16px;">

                <div>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;">
                        <h2 style="margin-top:0;font-size:14px;">Saved templates</h2>
                        <?php if (!$templates): ?>
                            <p class="description">None yet. Forms fall back to the built-in defaults.</p>
                        <?php endif; ?>
                        <?php foreach ($roles as $rkey => $rlabel): ?>
                            <?php $in_role = SNN_T_Mailer::templates_for_role($rkey); ?>
                            <?php if ($in_role): ?>
                                <p style="margin:12px 0 4px;font-weight:600;font-size:12px;color:#646970;text-transform:uppercase;">
                                    <?php echo esc_html($rkey); ?>
                                </p>
                                <ul style="margin:0;">
                                <?php foreach ($in_role as $name => $t): ?>
                                    <li style="margin:0 0 4px;">
                                        <a href="<?php echo esc_url(add_query_arg(['page' => 'snn-tickets-templates', 'template' => $name], admin_url('admin.php'))); ?>"
                                           <?php echo $editing === $name ? 'style="font-weight:700;"' : ''; ?>>
                                            <?php echo esc_html($name); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <p style="margin-top:16px;">
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-templates')); ?>">New template</a>
                        </p>
                    </div>

                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;margin-top:16px;">
                        <h2 style="margin-top:0;font-size:14px;">Placeholders</h2>
                        <ul style="font-family:monospace;font-size:12px;line-height:1.9;margin:0;">
                            <li><code>{name}</code> — ticket holder name</li>
                            <li><code>{email}</code> — their address</li>
                            <li><code>{ticket}</code> — ticket code</li>
                            <li><code>{qr_inline}</code> — QR attached to the email</li>
                            <li><code>{qr}</code> — QR as a hosted image URL</li>
                            <li><code>{scan_url}</code> — the URL the QR points at</li>
                            <li><code>{list}</code> — ticket list name</li>
                            <li><code>{form}</code> — form name</li>
                            <li><code>{site}</code>, <code>{site_url}</code>, <code>{date}</code></li>
                            <li><code>{field:key}</code> — any form field</li>
                        </ul>
                        <p class="description" style="margin-bottom:0;">
                            Prefer <code>{qr_inline}</code> in the <code>src</code> of an <code>&lt;img&gt;</code>.
                            It embeds the image in the message, so it shows up even when a mail client
                            blocks remote images.
                        </p>
                    </div>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="snn_save_template">
                        <?php wp_nonce_field('snn_save_template'); ?>
                        <input type="hidden" name="original_name" value="<?php echo esc_attr($editing); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="tpl_name">Template name</label></th>
                                <td><input type="text" id="tpl_name" name="name" class="regular-text" required
                                           value="<?php echo esc_attr($editing); ?>" placeholder="Summer meetup ticket"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tpl_role">Used for</label></th>
                                <td>
                                    <select id="tpl_role" name="role">
                                        <?php foreach ($roles as $rkey => $rlabel): ?>
                                            <option value="<?php echo esc_attr($rkey); ?>" <?php selected($role, $rkey); ?>>
                                                <?php echo esc_html($rlabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tpl_subject">Subject</label></th>
                                <td><input type="text" id="tpl_subject" name="subject" class="large-text" required
                                           value="<?php echo esc_attr($subject); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="tpl_body">Body (HTML)</label></th>
                                <td><textarea id="tpl_body" name="body" rows="18" class="large-text code"><?php
                                        echo esc_textarea($body); ?></textarea></td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button class="button button-primary">Save template</button>
                            <?php if ($editing !== ''): ?>
                                <button class="button" formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        name="action" value="snn_delete_template"
                                        onclick="return confirm('Delete this template? Forms using it fall back to the built-in default.');">
                                    Delete
                                </button>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>
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
            self::back('snn-tickets-templates', 'A template name is required.');
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
        self::back('snn-tickets-templates', 'Template saved.', ['template' => $name]);
    }

    public static function handle_delete_template() {
        self::cap();
        check_admin_referer('snn_save_template');

        $name      = sanitize_text_field(wp_unslash($_POST['original_name'] ?? ''));
        $templates = SNN_T_Mailer::get_templates();

        if ($name !== '' && isset($templates[$name])) {
            unset($templates[$name]);
            update_option(SNN_T_Mailer::TEMPLATES_OPTION, $templates);
            self::back('snn-tickets-templates', 'Template deleted.');
        }

        self::back('snn-tickets-templates', 'Template not found.');
    }

    /* ==================================================================
     * Mail queue
     * ================================================================== */

    public static function render_queue_page() {
        self::cap();
        global $wpdb;

        $table  = SNN_T_DB::queue();
        $counts = SNN_T_Mailer::queue_counts();

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $paged  = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
        $per    = 30;

        $where = '1=1';
        $args  = [];
        if (in_array($status, ['pending', 'sending', 'sent', 'failed'], true)) {
            $where  = 'status = %s';
            $args[] = $status;
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int)$wpdb->get_var($args ? $wpdb->prepare($count_sql, $args) : $count_sql);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            array_merge($args, [$per, ($paged - 1) * $per])
        ));

        $next_cron = wp_next_scheduled(SNN_T_Mailer::CRON_HOOK);
        ?>
        <div class="wrap">
            <h1>Mail Queue</h1>
            <?php self::notice(); ?>

            <p class="description">
                Emails are sent by WP-Cron, up to <?php echo (int)SNN_T_Mailer::batch_size(); ?> per minute.
                You can close this page — sending continues on the server.
                <?php if ($next_cron): ?>
                    Next run in <?php echo esc_html(human_time_diff($next_cron, time())); ?>.
                <?php endif; ?>
            </p>

            <div style="display:flex;gap:14px;margin:18px 0;flex-wrap:wrap;">
                <?php foreach (['pending' => '#dba617', 'sending' => '#2271b1', 'sent' => '#0a7d32', 'failed' => '#b3261e'] as $k => $color): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'snn-tickets-queue', 'status' => $k], admin_url('admin.php'))); ?>"
                       style="flex:1;min-width:130px;background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr($color); ?>;
                              border-radius:6px;padding:14px 16px;text-decoration:none;color:inherit;">
                        <div style="font-size:26px;font-weight:700;line-height:1.1;"><?php echo (int)$counts[$k]; ?></div>
                        <div style="color:#646970;text-transform:capitalize;"><?php echo esc_html($k); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                <input type="hidden" name="action" value="snn_queue_action">
                <?php wp_nonce_field('snn_queue_action'); ?>
                <button class="button button-primary" name="do" value="process">Process now</button>
                <button class="button" name="do" value="retry">Retry failed</button>
                <button class="button" name="do" value="clear"
                        onclick="return confirm('Remove every sent record from the queue log?');">Clear sent log</button>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'snn-tickets-queue', 'status' => 'all'], admin_url('admin.php'))); ?>">Show all</a>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:110px;">Role</th>
                        <th>Recipient</th>
                        <th>Subject</th>
                        <th style="width:90px;">Ticket</th>
                        <th style="width:70px;">Tries</th>
                        <th style="width:150px;">Scheduled</th>
                        <th>Last error</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9">Queue is empty.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $color = ['pending' => '#dba617', 'sending' => '#2271b1', 'sent' => '#0a7d32', 'failed' => '#b3261e'][$r->status] ?? '#666';
                    ?>
                    <tr>
                        <td><?php echo (int)$r->id; ?></td>
                        <td><span style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html($r->status); ?></span></td>
                        <td><?php echo esc_html($r->role); ?></td>
                        <td><?php echo esc_html($r->to_email); ?></td>
                        <td><?php echo esc_html($r->subject); ?></td>
                        <td><?php echo $r->ticket_code ? '<code>' . esc_html($r->ticket_code) . '</code>' : '—'; ?></td>
                        <td><?php echo (int)$r->attempts; ?></td>
                        <td><?php echo esc_html($r->sent_at ?: $r->scheduled_at); ?></td>
                        <td style="color:#b3261e;font-size:12px;"><?php echo esc_html($r->last_error ?: ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo (int)$total; ?> item(s)</span>
                    <?php
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => max(1, (int)ceil($total / $per)),
                        'prev_text' => '‹',
                        'next_text' => '›',
                    ]);
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handle_queue_action() {
        self::cap();
        check_admin_referer('snn_queue_action');

        $do = sanitize_key($_POST['do'] ?? '');

        switch ($do) {
            case 'process':
                $r = SNN_T_Mailer::process_queue();
                $msg = !empty($r['locked'])
                    ? 'Another send is already running. Try again in a moment.'
                    : sprintf('Processed a batch: %d sent, %d failed.', $r['sent'], $r['failed']);
                break;
            case 'retry':
                $n   = SNN_T_Mailer::retry_failed();
                $msg = sprintf('%d message(s) requeued.', $n);
                break;
            case 'clear':
                $n   = SNN_T_Mailer::clear_sent();
                $msg = sprintf('%d sent record(s) removed.', $n);
                break;
            default:
                $msg = 'Nothing to do.';
        }

        self::back('snn-tickets-queue', $msg);
    }
}
