<?php
/*
    Plugin Name: SNN Tickets
    Description: Event tickets with server-side QR codes: a visual registration form builder, automatic or rule-based approval, confirmation emails, and a queued mailer that sends without keeping a browser tab open.
    Version: 0.21
    Requires PHP: 8.1
    Author: sinanisler
    Author URI: https://sinanisler.com/ 
*/

if (!defined('ABSPATH')) exit;

define('SNN_TICKETS_FILE', __FILE__);
define('SNN_TICKETS_DIR', plugin_dir_path(__FILE__));
define('SNN_TICKETS_URL', plugin_dir_url(__FILE__));

require_once SNN_TICKETS_DIR . 'includes/class-snn-db.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-qr.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-tickets.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-mailer.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-forms.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-forms-admin.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-submissions.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-admin.php';

class SNN_Tickets_Plugin {

    private static $instance = null;

    private $table_lists;
    private $table_tickets;


    public static function instance(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        $this->table_lists   = SNN_T_DB::lists();
        $this->table_tickets = SNN_T_DB::tickets();

        register_activation_hook(SNN_TICKETS_FILE,   [$this, 'activate']);
        register_deactivation_hook(SNN_TICKETS_FILE, [$this, 'deactivate']);

        add_action('admin_init', ['SNN_T_DB', 'maybe_upgrade']);
        add_action('admin_menu', [$this, 'admin_menu']);

        // Feature modules
        SNN_T_QR::init();
        SNN_T_Mailer::init();
        SNN_T_Forms::init();
        SNN_T_Forms_Admin::init();
        SNN_T_Submissions::init();
        SNN_T_Admin::init();

        // Generator (create, import, delete)
        add_action('admin_post_snn_generate_tickets', [$this, 'handle_generate_tickets']);
        add_action('admin_post_snn_import_csv',       [$this, 'handle_import_csv']);
        add_action('admin_post_snn_csv_template',     [$this, 'download_csv_template']);
        add_action('admin_post_snn_delete_list',      [$this, 'handle_delete_list']);

        // Mailer: queue a whole list, or one ticket at a time
        add_action('admin_post_snn_queue_list_emails', [$this, 'handle_queue_list_emails']);
        add_action('admin_post_snn_resend_ticket',     [$this, 'handle_resend_ticket']);
        add_action('admin_post_snn_ticket_qr',         [$this, 'handle_ticket_qr']);

        // AJAX validate (public)
        add_action('wp_ajax_snn_validate_ticket',        [$this, 'ajax_validate_ticket']);
        add_action('wp_ajax_nopriv_snn_validate_ticket', [$this, 'ajax_validate_ticket']);

        // AJAX inline update (admin)
        add_action('wp_ajax_snn_update_ticket_field', [$this, 'ajax_update_ticket_field']);

        // Shortcodes
        add_shortcode('tickets_scan_page', [$this, 'shortcode_scan_page']);
    }

    public function activate(){
        SNN_T_DB::install();
        SNN_T_QR::secret();

        if (get_option(SNN_T_Mailer::BATCH_SIZE_OPTION, null) === null) {
            add_option(SNN_T_Mailer::BATCH_SIZE_OPTION, 10);
        }
        if (get_option(SNN_T_Mailer::TEMPLATES_OPTION, null) === null) {
            add_option(SNN_T_Mailer::TEMPLATES_OPTION, []);
        }
        if (get_option(SNN_T_Mailer::FROM_NAME_OPTION, null) === null) {
            add_option(SNN_T_Mailer::FROM_NAME_OPTION, get_bloginfo('name'));
        }

        if (!wp_next_scheduled(SNN_T_Mailer::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'snn_minute', SNN_T_Mailer::CRON_HOOK);
        }
    }

    public function deactivate(){
        SNN_T_Mailer::deactivate();
    }

    public function admin_menu(){
        add_menu_page(
            'Tickets', 'Tickets', 'manage_options', 'snn-tickets',
            [$this, 'render_dashboard_page'], 'dashicons-tickets', 26
        );

        $pages = [
            ['Dashboard',        'snn-tickets',              [$this, 'render_dashboard_page']],
            ['Forms',            'snn-tickets-forms',        ['SNN_T_Forms_Admin', 'render_page']],
            ['Submissions',      'snn-tickets-submissions',  ['SNN_T_Submissions', 'render_page']],
            ['Tickets Generator','snn-tickets-generator',    [$this, 'render_generator_page']],
            ['Ticket Lists',     'snn-tickets-lists',        [$this, 'render_lists_page']],
            ['Tickets Mailer',   'snn-tickets-mailer',       [$this, 'render_mailer_page']],
            ['Mail Queue',       'snn-tickets-queue',        ['SNN_T_Admin', 'render_queue_page']],
            ['Email Templates',  'snn-tickets-templates',    ['SNN_T_Admin', 'render_templates_page']],
            ['CSV Import',       'snn-tickets-csv-import',   [$this, 'render_csv_import_page']],
            ['Settings',         'snn-tickets-settings',     ['SNN_T_Admin', 'render_settings_page']],
        ];

        foreach ($pages as $page) {
            list($title, $slug, $callback) = $page;

            $label = $title;
            if ($slug === 'snn-tickets-submissions') {
                $pending = SNN_T_Submissions::counts()['pending'];
                if ($pending) {
                    $label .= ' <span class="awaiting-mod"><span class="pending-count">'
                            . (int)$pending . '</span></span>';
                }
            }

            add_submenu_page('snn-tickets', $title, $label, 'manage_options', $slug, $callback);
        }
    }

    private function admin_cap_check(){
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
    }

    public function render_dashboard_page(){
        $this->admin_cap_check();
        global $wpdb;

        // Get statistics
        $total_lists = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_lists}");
        $total_tickets = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_tickets}");
        $total_validated = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_tickets} WHERE validate_count > 0");
        $total_with_email = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_tickets} WHERE email <> ''");
        
        $total_templates = count(SNN_T_Mailer::get_templates());

        $submissions = SNN_T_Submissions::counts();
        $queue       = SNN_T_Mailer::queue_counts();
        $total_forms = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . SNN_T_DB::forms());

        // Recent activity
        $recent_tickets = $wpdb->get_results("
            SELECT t.name, t.email, t.ticket_code, t.created_at, l.name as list_name
            FROM {$this->table_tickets} t
            LEFT JOIN {$this->table_lists} l ON l.id = t.list_id
            ORDER BY t.created_at DESC
            LIMIT 5
        ");

        ?>
        <style>
            .snn-dashboard-container {
                max-width: 1400px;
            }
            .snn-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            .snn-stat-card {
                background: #000;
                color: #fff;
                padding: 24px;
                border-radius: 4px;
            }
            .snn-stat-label {
                font-size: 14px;
                opacity: 0.9;
                margin-bottom: 8px;
            }
            .snn-stat-value {
                font-size: 36px;
                font-weight: bold;
            }
            .snn-main-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                margin-bottom: 30px;
            }
            .snn-card {
                background: #fff;
                padding: 24px;
                border-radius: 4px;
            }
            .snn-card h2 {
                margin-top: 0;
                color: #000;
                padding-bottom: 10px;
            }
            .snn-card h3 {
                color: #000;
                font-size: 16px;
                margin-bottom: 8px;
            }
            .snn-section {
                margin-bottom: 20px;
            }
            .snn-section p {
                color: #000;
                line-height: 1.6;
                margin: 0;
            }
            .snn-info-box {
                background: #f5f5f5;
                padding: 16px;
                border-radius: 4px;
            }
            .snn-info-box strong {
                color: #000;
            }
            .snn-info-box ul {
                margin: 8px 0 0 0;
                padding-left: 20px;
                color: #000;
            }
            .snn-info-box a {
                color: #000;
                text-decoration: underline;
            }
            .snn-shortcode-box {
                background: #f5f5f5;
                padding: 12px;
                border-radius: 4px;
                position: relative;
                font-family: monospace;
            }
            .snn-shortcode-code {
                color: #000;
                font-size: 14px;
                font-weight: bold;
            }
            .snn-copy-btn {
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                padding: 4px 12px;
                background: #000;
                color: #fff;
                border: none;
                border-radius: 3px;
                cursor: pointer;
                font-size: 12px;
            }
            .snn-copy-btn:hover {
                background: #333;
            }
            .snn-feature-list {
                color: #000;
                line-height: 1.8;
                margin: 0;
                padding-left: 20px;
            }
            .snn-tip-box {
                background: #fffef0;
                padding: 16px;
                border-radius: 4px;
            }
            .snn-tip-box strong {
                color: #000;
            }
            .snn-tip-box p {
                margin: 8px 0 0 0;
                color: #000;
                font-size: 14px;
            }
            .snn-recent-table {
                width: 100%;
                border-collapse: collapse;
            }
            .snn-recent-table thead tr {
                background: #f5f5f5;
            }
            .snn-recent-table th {
                padding: 12px;
                text-align: left;
                color: #000;
                font-weight: 600;
            }
            .snn-recent-table td {
                padding: 12px;
                color: #000;
            }
            .snn-recent-table .ticket-code {
                font-family: monospace;
                font-weight: 600;
            }
            .snn-recent-table .ticket-date {
                font-size: 13px;
            }
            .snn-empty-state {
                background: #fff;
                padding: 48px 24px;
                border-radius: 4px;
                text-align: center;
            }
            .snn-empty-icon {
                font-size: 48px;
                margin-bottom: 16px;
            }
            .snn-empty-state h3 {
                color: #000;
                margin: 0 0 8px 0;
            }
            .snn-empty-state p {
                color: #666;
                margin: 0 0 20px 0;
            }
            @media (max-width: 768px) {
                .snn-main-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="wrap snn-dashboard-container">
            <h1>🎫 Tickets - Dashboard</h1>

            <div class="snn-stats-grid">
                <div class="snn-stat-card">
                    <div class="snn-stat-label">Total Lists</div>
                    <div class="snn-stat-value"><?php echo number_format($total_lists); ?></div>
                </div>
                <div class="snn-stat-card">
                    <div class="snn-stat-label">Total Tickets</div>
                    <div class="snn-stat-value"><?php echo number_format($total_tickets); ?></div>
                </div>
                <div class="snn-stat-card">
                    <div class="snn-stat-label">Validated Tickets</div>
                    <div class="snn-stat-value"><?php echo number_format($total_validated); ?></div>
                </div>
                <div class="snn-stat-card">
                    <div class="snn-stat-label">With Email</div>
                    <div class="snn-stat-value"><?php echo number_format($total_with_email); ?></div>
                </div>
                <div class="snn-stat-card">
                    <div class="snn-stat-label">Email Templates</div>
                    <div class="snn-stat-value"><?php echo number_format($total_templates); ?></div>
                </div>
                <div class="snn-stat-card">
                    <div class="snn-stat-label">Forms</div>
                    <div class="snn-stat-value"><?php echo number_format($total_forms); ?></div>
                </div>
                <a class="snn-stat-card" style="text-decoration:none;color:#fff;<?php echo $submissions['pending'] ? 'background:#8a6100;' : ''; ?>"
                   href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-submissions&status=pending')); ?>">
                    <div class="snn-stat-label">Awaiting Review</div>
                    <div class="snn-stat-value"><?php echo number_format($submissions['pending']); ?></div>
                </a>
                <a class="snn-stat-card" style="text-decoration:none;color:#fff;<?php echo $queue['failed'] ? 'background:#8f1d16;' : ''; ?>"
                   href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-queue')); ?>">
                    <div class="snn-stat-label">Mail Queue</div>
                    <div class="snn-stat-value"><?php echo number_format($queue['pending']); ?></div>
                    <div class="snn-stat-label" style="margin:6px 0 0;font-size:12px;">
                        <?php echo number_format($queue['sent']); ?> sent<?php
                            echo $queue['failed'] ? ', ' . number_format($queue['failed']) . ' failed' : ''; ?>
                    </div>
                </a>
            </div>

            <div class="snn-main-grid">
                <div class="snn-card">
                    <h2>📚 How It Works</h2>
                    
                    <div class="snn-section">
                        <h3>1. Collect people</h3>
                        <p>Build a registration form and drop it on any page with a shortcode, or generate
                        tickets directly and import contacts from CSV.</p>
                    </div>

                    <div class="snn-section">
                        <h3>2. Decide who gets in</h3>
                        <p>Each form approves automatically, holds everything for manual review, or applies
                        rules to the answers &mdash; auto-approving the ones that match and queueing the rest.</p>
                    </div>

                    <div class="snn-section">
                        <h3>3. Tickets go out on their own</h3>
                        <p>Approval generates the QR code on the server, attaches it to the email and hands it
                        to the queue. Sending continues in the background whether or not you stay on the page.</p>
                    </div>

                    <div class="snn-section">
                        <h3>4. Scan &amp; validate</h3>
                        <p>QR codes carry a signed URL, so a phone camera opens your scan page directly.
                        Repeat scans are flagged rather than silently accepted.</p>
                    </div>

                    <div class="snn-info-box">
                        <strong>Quick Actions:</strong>
                        <ul>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-forms&action=new'); ?>">Build a registration form</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-submissions'); ?>">Review submissions</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-generator'); ?>">Create tickets</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-csv-import'); ?>">Import from CSV</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-mailer'); ?>">Email a whole list</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-settings'); ?>">Settings &amp; system check</a></li>
                        </ul>
                    </div>
                </div>

                <div class="snn-card">
                    <h2>🔍 Scanning System</h2>
                    
                    <div class="snn-section">
                        <h3>Public Scan Page Shortcode</h3>
                        <div class="snn-shortcode-box">
                            <code class="snn-shortcode-code">[tickets_scan_page]</code>
                            <button onclick="navigator.clipboard.writeText('[tickets_scan_page]')" class="snn-copy-btn">Copy</button>
                        </div>
                        <p class="description" style="margin-top: 8px; color: #666;">Add this shortcode to any page or post to create a public ticket scanning interface.</p>
                    </div>

                    <div class="snn-section">
                        <h3>Scan Features</h3>
                        <ul class="snn-feature-list">
                            <li>QR Code scanning via camera</li>
                            <li>Manual ticket code entry</li>
                            <li>Real-time validation feedback</li>
                            <li>Validation count tracking</li>
                            <li>Timestamp recording</li>
                        </ul>
                    </div>

                    <div class="snn-tip-box">
                        <strong>💡 Tip:</strong>
                        <p>Create a dedicated page called "Ticket Scanner" and add the shortcode for your event staff to validate tickets.</p>
                    </div>
                </div>
            </div>

            <?php if ($recent_tickets): ?>
            <div class="snn-card">
                <h2>🕐 Recent Tickets</h2>
                <table class="snn-recent-table">
                    <thead>
                        <tr>
                            <th>Ticket Code</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>List</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_tickets as $ticket): ?>
                        <tr>
                            <td class="ticket-code"><?php echo esc_html($ticket->ticket_code); ?></td>
                            <td><?php echo esc_html($ticket->name ?: '-'); ?></td>
                            <td class="ticket-date"><?php echo esc_html($ticket->email ?: '-'); ?></td>
                            <td><?php echo esc_html($ticket->list_name ?: '-'); ?></td>
                            <td class="ticket-date"><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($ticket->created_at))); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="snn-empty-state">
                <div class="snn-empty-icon">🎫</div>
                <h3>No tickets yet</h3>
                <p>Get started by creating your first ticket list!</p>
                <a href="<?php echo admin_url('admin.php?page=snn-tickets-generator'); ?>" class="button button-primary">Create Your First Tickets</a>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    private function generate_ticket_code($length = 8){
        return SNN_T_Tickets::generate_code($length);
    }

    private function unique_ticket_code($length = 8){
        return SNN_T_Tickets::unique_code($length);
    }

    private function create_list($name){
        return SNN_T_Tickets::create_list($name);
    }

    private function insert_ticket($list_id, $name, $email, $code = null){
        return SNN_T_Tickets::insert($list_id, $name, $email, $code);
    }

    public function render_generator_page(){
        $this->admin_cap_check();

        $nonce_generate = wp_create_nonce('snn_generate_tickets');
        $now_placeholder = date_i18n('Y-m-d H:i', current_time('timestamp'));
        ?>
        <style>
            .snn-gen-container {
                max-width: 1200px;
            }
            .snn-gen-accordion {
                background: #fff;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .snn-gen-accordion summary {
                cursor: pointer;
                padding: 16px;
                font-weight: 600;
                color: #000;
                background: #f5f5f5;
                user-select: none;
            }
            .snn-gen-accordion summary:hover {
                background: #e5e5e5;
            }
            .snn-gen-accordion[open] summary {
                background: #000;
                color: #fff;
            }
            .snn-gen-accordion-content {
                padding: 20px;
            }
        </style>

        <div class="wrap snn-gen-container">
            <h1>Tickets Generator</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($_GET['snn_msg']); ?></p></div>
            <?php endif; ?>

            <details class="snn-gen-accordion" open>
                <summary>Generate Random Tickets</summary>
                <div class="snn-gen-accordion-content">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="snn_generate_tickets">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_generate); ?>">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="snn_list_name">List Name</label></th>
                                <td><input type="text" id="snn_list_name" name="list_name" class="regular-text" placeholder="Generated <?php echo esc_attr($now_placeholder); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_count">How many?</label></th>
                                <td><input type="number" id="snn_count" name="count" value="10" min="1" max="5000"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_len">Ticket length</label></th>
                                <td><input type="number" id="snn_len" name="length" value="8" min="6" max="64"></td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">Generate</button></p>
                        <p class="description">Generates uppercase alphanumeric codes. A new list will be created.</p>
                    </form>
                </div>
            </details>

            <p class="description">
                After generating tickets, view and manage them in the <a href="<?php echo admin_url('admin.php?page=snn-tickets-lists'); ?>">Ticket Lists</a> page.
            </p>
        </div>
        <?php
    }

    public function render_lists_page(){
        $this->admin_cap_check();
        global $wpdb;

        $lists = $wpdb->get_results("
            SELECT l.*, 
                   COUNT(t.id) AS total_tickets,
                   SUM(CASE WHEN t.email <> '' THEN 1 ELSE 0 END) AS total_with_email
            FROM {$this->table_lists} l
            LEFT JOIN {$this->table_tickets} t ON t.list_id = l.id
            GROUP BY l.id
            ORDER BY l.id DESC
        ");

        $nonce_delete   = wp_create_nonce('snn_delete_list');
        $update_nonce = wp_create_nonce('snn_update_ticket');
        $ajax_url     = admin_url('admin-ajax.php');

        ?>
        <style>
            .snn-lists-container {
                max-width: 1200px;
            }
            .snn-list-item {
                margin-bottom: 12px;
                background: #fff;
                border-radius: 4px;
            }
            .snn-list-item summary {
                cursor: pointer;
                padding: 12px 16px;
                font-weight: 600;
                display: flex;
                justify-content: space-between;
                align-items: center;
                user-select: none;
            }
            .snn-list-item summary:hover {
                background: #f5f5f5;
            }
            .snn-list-title {
                color: #000;
            }
            .snn-list-meta {
                opacity: 0.7;
                font-weight: normal;
                font-size: 14px;
                color: #000;
            }
            .snn-list-content {
                padding: 0 16px 16px 16px;
            }
            .snn-table-wrap {
                max-height: 380px;
                overflow: auto;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .snn-inline-edit {
                display: inline-block;
                min-width: 120px;
                padding: 2px 4px;
                border-radius: 3px;
                transition: background-color .15s, box-shadow .15s;
                outline: none;
            }
            .snn-inline-edit:focus {
                background: #f5f5f5;
                box-shadow: 0 0 0 2px #00000033;
            }
            .snn-inline-saving {
                background: #f5f5f5 !important;
                box-shadow: 0 0 0 2px #00000066 !important;
            }
            .snn-inline-ok {
                background: #f5f5f5;
                box-shadow: 0 0 0 2px #00000066;
                animation: snn-fade-ok 1.2s ease forwards;
            }
            @keyframes snn-fade-ok {
                0% { background: #f5f5f5; }
                100% { background: transparent; box-shadow: none; }
            }
            .snn-inline-error {
                background: #ffebeb;
                box-shadow: 0 0 0 2px #ff000066;
            }
            .snn-delete-btn {
                background: #000;
                color: #fff;
                padding: 4px 12px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 13px;
            }
            .snn-delete-btn:hover {
                background: #333;
            }
        </style>

        <div class="wrap snn-lists-container">
            <h1>Ticket Lists</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($_GET['snn_msg']); ?></p></div>
            <?php endif; ?>

            <p class="description">Click to expand/collapse each list. Click Name or Email to edit inline. Press Enter or click outside to save. Use Esc to cancel.</p>

            <div id="snn-lists" data-ajax-url="<?php echo esc_attr($ajax_url); ?>" data-update-nonce="<?php echo esc_attr($update_nonce); ?>">
                <?php if ($lists): ?>
                    <?php foreach ($lists as $list): ?>
                        <?php
                        $tickets = $wpdb->get_results($wpdb->prepare("
                            SELECT * FROM {$this->table_tickets}
                            WHERE list_id = %d
                            ORDER BY id ASC
                        ", $list->id));
                        ?>
                        <details class="snn-list-item">
                            <summary>
                                <span class="snn-list-title">
                                    <?php echo esc_html($list->name); ?>
                                    <span class="snn-list-meta">
                                        &nbsp;• Created <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($list->created_at))); ?>
                                        &nbsp;• Tickets: <?php echo esc_html((int)$list->total_tickets); ?>
                                        &nbsp;• With email: <?php echo esc_html((int)$list->total_with_email); ?>
                                    </span>
                                </span>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this list and all its tickets? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="snn_delete_list">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_delete); ?>">
                                    <input type="hidden" name="list_id" value="<?php echo esc_attr($list->id); ?>">
                                    <button type="submit" class="snn-delete-btn" onclick="event.stopPropagation();">Delete</button>
                                </form>
                            </summary>
                            <div class="snn-list-content">
                                <div class="snn-table-wrap">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Ticket Code</th>
                                                <th>Validated</th>
                                                <th>Last Validated</th>
                                                <th>QR</th>
                                                <th>Ticket email</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($tickets): $i=1; foreach ($tickets as $t): ?>
                                                <tr data-ticket-id="<?php echo esc_attr((int)$t->id); ?>">
                                                    <td><?php echo esc_html($i++); ?></td>
                                                    <td>
                                                        <span class="snn-inline-edit snn-field-name" contenteditable="true"
                                                              data-field="name"
                                                              data-original="<?php echo esc_attr($t->name); ?>">
                                                            <?php echo esc_html($t->name); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="snn-inline-edit snn-field-email" contenteditable="true"
                                                              data-field="email"
                                                              data-original="<?php echo esc_attr($t->email); ?>">
                                                            <?php echo esc_html($t->email); ?>
                                                        </span>
                                                    </td>
                                                    <td><code><?php echo esc_html($t->ticket_code); ?></code></td>
                                                    <td><?php echo esc_html((int)$t->validate_count); ?></td>
                                                    <td><?php echo $t->last_validated ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($t->last_validated))) : '—'; ?></td>
                                                    <td>
                                                        <a class="button button-small" target="_blank" rel="noopener"
                                                           href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                                                               'action' => 'snn_ticket_qr',
                                                               'ticket' => (int)$t->id,
                                                           ], admin_url('admin-post.php')), 'snn_ticket_qr')); ?>">View</a>
                                                    </td>
                                                    <td>
                                                        <?php if ($t->email): ?>
                                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                                                <input type="hidden" name="action" value="snn_resend_ticket">
                                                                <input type="hidden" name="ticket" value="<?php echo (int)$t->id; ?>">
                                                                <?php wp_nonce_field('snn_resend_ticket'); ?>
                                                                <button class="button button-small">Send</button>
                                                            </form>
                                                        <?php else: ?>—<?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; else: ?>
                                                <tr><td colspan="8">No tickets in this list yet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No lists yet. <a href="<?php echo admin_url('admin.php?page=snn-tickets-generator'); ?>">Generate</a> or <a href="<?php echo admin_url('admin.php?page=snn-tickets-csv-import'); ?>">import from CSV</a> to get started.</p>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function(){
            const listsRoot = document.getElementById('snn-lists');
            if (!listsRoot) return;

            const ajaxUrl = listsRoot.getAttribute('data-ajax-url');
            const nonce   = listsRoot.getAttribute('data-update-nonce');

            function sanitizeForDisplay(text){
                // Ensure no line breaks in inline fields
                return (text || '').replace(/[\r\n]+/g, ' ').trim();
            }

            function startSaving(el){
                el.classList.remove('snn-inline-error','snn-inline-ok');
                el.classList.add('snn-inline-saving');
                el.dataset.locked = '1';
            }
            function endSaving(el){
                el.classList.remove('snn-inline-saving');
                delete el.dataset.locked;
            }

            async function saveField(el){
                if (el.dataset.locked === '1') return;
                const tr = el.closest('tr');
                if (!tr) return;

                const id    = parseInt(tr.getAttribute('data-ticket-id'), 10) || 0;
                const field = el.getAttribute('data-field');
                const original = el.getAttribute('data-original') || '';
                let value = sanitizeForDisplay(el.textContent);

                // Cancel save if unchanged
                if (value === original) return;

                // Basic client validation for email
                if (field === 'email') {
                    // allow empty value to clear email
                    if (value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        el.classList.add('snn-inline-error');
                        el.title = 'Invalid email. Leave empty to clear.';
                        // revert the visible text to original to avoid confusion
                        el.textContent = original;
                        return;
                    }
                }

                startSaving(el);

                const fd = new FormData();
                fd.append('action', 'snn_update_ticket_field');
                fd.append('nonce', nonce);
                fd.append('id', String(id));
                fd.append('field', field);
                fd.append('value', value);

                try{
                    const res = await fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    });
                    const json = await res.json();

                    if (!json || !json.success) {
                        const msg = json && json.data && json.data.message ? json.data.message : 'Save failed.';
                        el.classList.add('snn-inline-error');
                        el.title = msg;
                        // Revert on failure
                        el.textContent = original;
                    } else {
                        // Use value returned from server (sanitized/canonical)
                        const newVal = (json.data && typeof json.data.value !== 'undefined') ? json.data.value : value;
                        el.textContent = newVal;
                        el.setAttribute('data-original', newVal);
                        el.classList.add('snn-inline-ok');
                        el.title = 'Saved';
                    }
                }catch(e){
                    el.classList.add('snn-inline-error');
                    el.title = 'Network error.';
                    el.textContent = original;
                }finally{
                    endSaving(el);
                    // remove success style after some time
                    setTimeout(()=>{ el.classList.remove('snn-inline-ok'); el.title=''; }, 1200);
                }
            }

            // Prevent line breaks inside contenteditable spans
            listsRoot.addEventListener('keydown', (e) => {
                const el = e.target;
                if (el && el.classList && el.classList.contains('snn-inline-edit')) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        el.blur();
                    } else if (e.key === 'Escape') {
                        // revert and blur
                        const original = el.getAttribute('data-original') || '';
                        el.textContent = original;
                        el.blur();
                    }
                }
            }, true);

            // On focus, store original (in case markup changed outside)
            listsRoot.addEventListener('focusin', (e) => {
                const el = e.target;
                if (el && el.classList && el.classList.contains('snn-inline-edit')) {
                    el.classList.remove('snn-inline-error','snn-inline-ok');
                    el.title = '';
                    // normalize current visible text as baseline when focusing
                    el.setAttribute('data-original', sanitizeForDisplay(el.getAttribute('data-original') || el.textContent || ''));
                }
            });

            // Save on blur if changed
            listsRoot.addEventListener('focusout', (e) => {
                const el = e.target;
                if (el && el.classList && el.classList.contains('snn-inline-edit')) {
                    saveField(el);
                }
            });

            // Prevent pasting rich content
            listsRoot.addEventListener('paste', (e) => {
                const el = e.target;
                if (el && el.classList && el.classList.contains('snn-inline-edit')) {
                    e.preventDefault();
                    const text = (e.clipboardData || window.clipboardData).getData('text');
                    document.execCommand('insertText', false, sanitizeForDisplay(text));
                }
            });
        })();
        </script>
        <?php
    }

    public function render_csv_import_page(){
        $this->admin_cap_check();

        $nonce_import = wp_create_nonce('snn_import_csv');
        $template_url = admin_url('admin-post.php?action=snn_csv_template');
        $now_placeholder = date_i18n('Y-m-d H:i', current_time('timestamp'));
        ?>
        <style>
            .snn-csv-container {
                max-width: 800px;
                margin: 20px 0;
            }
            .snn-csv-card {
                background: #fff;
                border-radius: 4px;
                padding: 24px;
                margin-bottom: 20px;
            }
            .snn-csv-card h2 {
                margin-top: 0;
                color: #000;
                padding-bottom: 10px;
            }
            .snn-csv-info {
                background: #f5f5f5;
                padding: 16px;
                margin: 16px 0;
            }
            .snn-csv-info h3 {
                margin-top: 0;
                color: #000;
                font-size: 16px;
            }
            .snn-csv-info ul {
                margin: 8px 0;
                padding-left: 20px;
            }
            .snn-csv-info li {
                margin: 4px 0;
                color: #000;
            }
        </style>

        <div class="wrap">
            <h1>CSV Import</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($_GET['snn_msg']); ?></p></div>
            <?php endif; ?>

            <div class="snn-csv-container">
                <div class="snn-csv-card">
                    <h2>Import Contacts from CSV</h2>
                    
                    <div class="snn-csv-info">
                        <h3>CSV Format Requirements</h3>
                        <ul>
                            <li>CSV file must include a header row with columns: <strong>Name</strong> and <strong>Email</strong></li>
                            <li>Column names are case-insensitive</li>
                            <li>Each row will generate a unique ticket code</li>
                            <li>Download the template below to see the correct format</li>
                        </ul>
                    </div>

                    <p>
                        <a class="button button-primary" href="<?php echo esc_url($template_url); ?>">
                            Download CSV Template
                        </a>
                    </p>

                    <hr style="margin: 24px 0; border: 0; border-top: 1px solid #ddd;">

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="snn_import_csv">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_import); ?>">
                        
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="snn_import_list_name">List Name</label></th>
                                <td>
                                    <input type="text" id="snn_import_list_name" name="list_name" class="regular-text" placeholder="Imported <?php echo esc_attr($now_placeholder); ?>">
                                    <p class="description">Name for this import batch. If empty, will use "Imported [date time]"</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_csv_file">CSV File</label></th>
                                <td>
                                    <input type="file" id="snn_csv_file" name="csv_file" accept=".csv" required>
                                    <p class="description">Select your CSV file containing Name and Email columns</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_ticket_length">Ticket Code Length</label></th>
                                <td>
                                    <input type="number" id="snn_ticket_length" name="length" value="10" min="6" max="64">
                                    <p class="description">Number of characters for generated ticket codes (6-64)</p>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" class="button button-primary">Import and Generate Tickets</button>
                        </p>
                        
                        <p class="description">Each person in the CSV will receive a unique ticket code. The list will be created and you can view it in the Ticket Lists page.</p>
                    </form>
                </div>

                <div class="snn-csv-card">
                    <h2>After Import</h2>
                    <p>Once imported:</p>
                    <ul>
                        <li>A new ticket list will be created with your specified name</li>
                        <li>Each contact will have a unique ticket code generated</li>
                        <li>View and manage tickets in the <a href="<?php echo admin_url('admin.php?page=snn-tickets-lists'); ?>">Ticket Lists</a> page</li>
                        <li>Send email invitations from the <a href="<?php echo admin_url('admin.php?page=snn-tickets-mailer'); ?>">Tickets Mailer</a> page</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_generate_tickets(){
        $this->admin_cap_check();
        check_admin_referer('snn_generate_tickets');

        $count  = isset($_POST['count']) ? max(1, min(5000, intval($_POST['count']))) : 10;
        $length = isset($_POST['length']) ? max(6, min(64, intval($_POST['length']))) : 8;
        $default_name = 'Generated ' . date_i18n('Y-m-d H:i', current_time('timestamp'));
        $list_name = isset($_POST['list_name']) && trim($_POST['list_name']) !== '' ? sanitize_text_field($_POST['list_name']) : $default_name;

        $list_id = $this->create_list($list_name);

        for ($i=0; $i<$count; $i++){
            $this->insert_ticket($list_id, '', '', $this->unique_ticket_code($length));
        }

        wp_redirect(add_query_arg('snn_msg', rawurlencode("Generated $count tickets in list: $list_name"), admin_url('admin.php?page=snn-tickets-lists')));
        exit;
    }

    public function handle_import_csv(){
        $this->admin_cap_check();
        check_admin_referer('snn_import_csv');

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK){
            wp_die('CSV upload failed');
        }

        $length = isset($_POST['length']) ? max(6, min(64, intval($_POST['length']))) : 10;
        $default_name = 'Imported ' . date_i18n('Y-m-d H:i', current_time('timestamp'));
        $list_name = isset($_POST['list_name']) && trim($_POST['list_name']) !== '' ? sanitize_text_field($_POST['list_name']) : $default_name;
        $list_id = $this->create_list($list_name);

        $tmp = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmp, 'r');
        if (!$handle) wp_die('Cannot open uploaded file');

        // Expect header row with Name,Email (case-insensitive)
        $header = fgetcsv($handle);
        if (!$header) wp_die('CSV appears empty');

        $map = ['name' => null, 'email' => null];
        foreach ($header as $idx => $col) {
            $colnorm = strtolower(trim($col));
            if ($colnorm === 'name')  $map['name']  = $idx;
            if ($colnorm === 'email') $map['email'] = $idx;
        }
        if ($map['name'] === null || $map['email'] === null){
            wp_die('CSV must include columns: Name, Email (with a header row).');
        }

        $count = 0;
        while (($row = fgetcsv($handle)) !== false){
            $name  = isset($row[$map['name']]) ? sanitize_text_field($row[$map['name']]) : '';
            $email = isset($row[$map['email']]) ? sanitize_email($row[$map['email']]) : '';
            $this->insert_ticket($list_id, $name, is_email($email) ? $email : '', $this->unique_ticket_code($length));
            $count++;
        }
        fclose($handle);

        wp_redirect(add_query_arg('snn_msg', rawurlencode("Imported $count contacts and generated tickets in list: $list_name"), admin_url('admin.php?page=snn-tickets-csv-import')));
        exit;
    }

    public function handle_delete_list(){
        $this->admin_cap_check();
        check_admin_referer('snn_delete_list');

        $list_id = isset($_POST['list_id']) ? intval($_POST['list_id']) : 0;
        if ($list_id <= 0) {
            wp_die('Invalid list ID');
        }

        global $wpdb;

        // Get list name for message
        $list = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$this->table_lists} WHERE id = %d", $list_id));
        if (!$list) {
            wp_die('List not found');
        }

        // Remove the cached QR image for every ticket in the list.
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT id, ticket_code FROM {$this->table_tickets} WHERE list_id = %d", $list_id
        ));

        foreach ($tickets as $ticket) {
            SNN_T_QR::delete($ticket->ticket_code);
            $wpdb->delete(SNN_T_DB::queue(), ['ticket_id' => (int)$ticket->id], ['%d']);
        }

        // Forms pointing at this list can no longer issue tickets, so close
        // them rather than leaving a form that silently fails.
        $wpdb->update(SNN_T_DB::forms(), ['status' => 'closed'], ['list_id' => $list_id], ['%s'], ['%d']);

        $tickets_deleted = $wpdb->delete($this->table_tickets, ['list_id' => $list_id], ['%d']);
        $list_deleted    = $wpdb->delete($this->table_lists, ['id' => $list_id], ['%d']);

        if ($list_deleted) {
            wp_redirect(add_query_arg('snn_msg', rawurlencode("Deleted list '{$list->name}' and {$tickets_deleted} tickets"), admin_url('admin.php?page=snn-tickets-lists')));
        } else {
            wp_redirect(add_query_arg('snn_msg', rawurlencode("Failed to delete list"), admin_url('admin.php?page=snn-tickets-lists')));
        }
        exit;
    }

    public function download_csv_template(){
        $this->admin_cap_check();
        $filename = 'snn-tickets-template.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=$filename");
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Name', 'Email']);
        fputcsv($output, ['Jane Doe', 'jane@example.com']);
        fputcsv($output, ['John Smith', 'john@example.com']);
        fclose($output);
        exit;
    }

    public function render_mailer_page(){
        $this->admin_cap_check();
        global $wpdb;

        $lists  = $wpdb->get_results("SELECT * FROM {$this->table_lists} ORDER BY id DESC");
        $counts = SNN_T_Mailer::queue_counts();

        $selected_list = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;
        $templates     = SNN_T_Mailer::templates_for_role('ticket');
        $default       = SNN_T_Mailer::default_template('ticket');
        ?>
        <div class="wrap" style="max-width:1000px;">
            <h1>Tickets Mailer</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['snn_msg'])); ?></p></div>
            <?php endif; ?>

            <p class="description">
                Queue a ticket email for everyone on a list. QR codes are generated on the server and
                attached to each message, and sending runs in the background at
                <?php echo (int)SNN_T_Mailer::batch_size(); ?> per minute &mdash; you can close this page.
            </p>

            <div style="display:flex;gap:12px;margin:18px 0;flex-wrap:wrap;">
                <?php foreach (['pending', 'sent', 'failed'] as $k): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 18px;">
                        <strong style="font-size:20px;"><?php echo (int)$counts[$k]; ?></strong>
                        <span style="color:#646970;"> <?php echo esc_html($k); ?></span>
                    </div>
                <?php endforeach; ?>
                <a class="button" style="align-self:center;" href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-queue')); ?>">Open the queue</a>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;">
                <input type="hidden" name="action" value="snn_queue_list_emails">
                <?php wp_nonce_field('snn_queue_list_emails'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="snn_list_id">Ticket list</label></th>
                        <td>
                            <select id="snn_list_id" name="list_id" required>
                                <option value="">Choose a list&hellip;</option>
                                <?php foreach ($lists as $l):
                                    $n = SNN_T_Tickets::count_in_list((int)$l->id); ?>
                                    <option value="<?php echo (int)$l->id; ?>" <?php selected($selected_list, (int)$l->id); ?>>
                                        <?php echo esc_html($l->name . ' (' . $n . ' tickets)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="snn_template">Template</label></th>
                        <td>
                            <select id="snn_template" name="template">
                                <option value="">Built-in default</option>
                                <?php foreach ($templates as $name => $t): ?>
                                    <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Manage these on the
                                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-tickets-templates')); ?>">Email Templates</a> page.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Skip duplicates</th>
                        <td>
                            <label>
                                <input type="checkbox" name="skip_sent" value="1" checked>
                                Do not queue anyone who already has a ticket email queued or sent
                            </label>
                        </td>
                    </tr>
                </table>

                <details style="margin:16px 0;">
                    <summary style="cursor:pointer;font-weight:600;">Preview the default template</summary>
                    <div style="border:1px solid #dcdcde;border-radius:5px;padding:14px;margin-top:10px;background:#fbfbfc;">
                        <p><strong>Subject:</strong> <?php echo esc_html($default['subject']); ?></p>
                        <pre style="white-space:pre-wrap;font-size:12px;"><?php echo esc_html($default['body']); ?></pre>
                    </div>
                </details>

                <p class="submit"><button class="button button-primary button-large">Queue emails</button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Queue one ticket email per addressable ticket in a list.
     */
    public function handle_queue_list_emails(){
        $this->admin_cap_check();
        check_admin_referer('snn_queue_list_emails');

        global $wpdb;

        $list_id   = isset($_POST['list_id']) ? (int)$_POST['list_id'] : 0;
        $template  = sanitize_text_field(wp_unslash($_POST['template'] ?? ''));
        $skip_sent = !empty($_POST['skip_sent']);

        if (!$list_id) {
            wp_safe_redirect(add_query_arg('snn_msg', rawurlencode('Pick a list first.'), admin_url('admin.php?page=snn-tickets-mailer')));
            exit;
        }

        $list_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->table_lists} WHERE id = %d", $list_id));

        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_tickets} WHERE list_id = %d AND email <> '' AND status = 'active'",
            $list_id
        ));

        $queue   = SNN_T_DB::queue();
        $queued  = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($tickets as $ticket) {
            if ($skip_sent) {
                $already = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue}
                     WHERE ticket_id = %d AND role = 'ticket' AND status IN ('pending','sending','sent')",
                    (int)$ticket->id
                ));
                if ($already) { $skipped++; continue; }
            }

            $result = SNN_T_Mailer::enqueue_from_template('ticket', $template, [
                'name'        => $ticket->name,
                'email'       => $ticket->email,
                'ticket_code' => $ticket->ticket_code,
                'list_name'   => $list_name,
                'ticket_id'   => (int)$ticket->id,
            ]);

            if (is_wp_error($result)) { $failed++; } else { $queued++; }
        }

        $msg = sprintf('Queued %d email(s).', $queued);
        if ($skipped) $msg .= sprintf(' Skipped %d already handled.', $skipped);
        if ($failed)  $msg .= sprintf(' %d could not be queued (bad address?).', $failed);
        if (!$queued && !$skipped) $msg .= ' Nothing in this list has an email address.';

        wp_safe_redirect(add_query_arg([
            'page'    => 'snn-tickets-queue',
            'snn_msg' => rawurlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Queue (or requeue) the ticket email for a single ticket.
     */
    public function handle_resend_ticket(){
        $this->admin_cap_check();
        check_admin_referer('snn_resend_ticket');

        $ticket = SNN_T_Tickets::get(isset($_POST['ticket']) ? (int)$_POST['ticket'] : 0);

        if (!$ticket) {
            $msg = 'Ticket not found.';
        } elseif (!$ticket->email) {
            $msg = 'That ticket has no email address.';
        } else {
            $result = SNN_T_Mailer::enqueue_from_template('ticket', '', [
                'name'        => $ticket->name,
                'email'       => $ticket->email,
                'ticket_code' => $ticket->ticket_code,
                'list_name'   => SNN_T_Forms::list_name($ticket->list_id),
                'ticket_id'   => (int)$ticket->id,
            ]);

            if (is_wp_error($result)) {
                $msg = 'Could not queue: ' . $result->get_error_message();
            } else {
                SNN_T_Mailer::process_queue();
                $msg = 'Ticket email queued for ' . $ticket->email . '.';
            }
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=snn-tickets-lists');
        wp_safe_redirect(add_query_arg('snn_msg', rawurlencode($msg), remove_query_arg('snn_msg', $back)));
        exit;
    }

    /**
     * Generate the QR on demand and hand the browser its image URL.
     */
    public function handle_ticket_qr(){
        $this->admin_cap_check();
        check_admin_referer('snn_ticket_qr');

        $ticket = SNN_T_Tickets::get(isset($_GET['ticket']) ? (int)$_GET['ticket'] : 0);
        if (!$ticket) wp_die('Ticket not found');

        $url = SNN_T_QR::ensure_url($ticket->ticket_code);
        if (is_wp_error($url)) wp_die(esc_html($url->get_error_message()));

        wp_redirect($url);
        exit;
    }

    public function ajax_validate_ticket(){
        if (!SNN_T_Tickets::rate_limit_ok()) {
            wp_send_json_error(['message' => 'Too many scans from this address. Wait a minute and try again.'], 429);
        }

        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        $sig  = isset($_POST['sig'])  ? sanitize_text_field(wp_unslash($_POST['sig']))  : '';

        if ($code === '') {
            wp_send_json_error(['message' => 'Missing code'], 400);
        }

        // A scan only counts when it is signed or performed by an operator,
        // so guessing codes cannot burn through the list.
        $result = SNN_T_Tickets::validate($code, $sig, current_user_can('manage_options'));

        wp_send_json_success($result);
    }

    public function ajax_update_ticket_field(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'snn_update_ticket')) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $id    = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $field = isset($_POST['field']) ? sanitize_key($_POST['field']) : '';
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

        if ($id <= 0 || !in_array($field, ['name', 'email'], true)) {
            wp_send_json_error(['message' => 'Bad parameters.'], 400);
        }

        if ($field === 'name') {
            $new_value = sanitize_text_field($value);
        } else { // email
            $raw = trim($value);
            if ($raw === '') {
                $new_value = '';
            } else {
                $san = sanitize_email($raw);
                if (!$san || !is_email($san)) {
                    wp_send_json_error(['message' => 'Invalid email address.'], 400);
                }
                $new_value = $san;
            }
        }

        global $wpdb;
        $updated = $wpdb->update(
            $this->table_tickets,
            [$field => $new_value],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'Database error.'], 500);
        }

        wp_send_json_success([
            'id'    => $id,
            'field' => $field,
            'value' => $new_value,
        ]);
    }

    public function shortcode_scan_page($atts){
        ob_start();
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <div id="snn-scan-wrap" style="max-width:720px;margin:0 auto;">
            <h2>Scan Ticket</h2>
            <p>Use your camera to scan the QR code, or enter the ticket code manually.</p>

            <div id="snn-scan-ui" style="display:flex; gap:16px; flex-wrap:wrap;">
                <div style="flex:2; min-width:280px;">
                    <div style="position:relative; background:#000; border-radius:8px; overflow:hidden;">
                        <video id="snn-video" autoplay playsinline style="width:100%; height:auto; background:#000;"></video>
                        <canvas id="snn-canvas" style="display:none;"></canvas>
                        <div id="snn-overlay" style="position:absolute; inset:0; border:2px dashed rgba(255,255,255,0.6); margin:12%; border-radius:8px; pointer-events:none;"></div>
                        <div id="snn-status" style="position:absolute; bottom:8px; left:8px; right:8px; background:rgba(0,0,0,0.5); color:#fff; padding:6px 8px; font-size:12px; border-radius:4px;">Initializing camera...</div>
                    </div>
                    <div style="margin-top:8px;">
                        <button id="snn-start-scan" class="button">Start Scan</button>
                        <button id="snn-stop-scan" class="button">Stop Scan</button>
                        <button id="snn-scan-next" class="button">Scan Next</button>
                    </div>
                </div>

                <div style="flex:1; min-width:260px;">
                    <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:12px;">
                        <h3>Manual Entry</h3>
                        <form id="snn-manual-form">
                            <input type="text" id="snn-manual-code" class="regular-text" placeholder="Enter ticket code" style="width:100%; font-family:monospace;">
                            <button type="submit" class="button button-primary" style="margin-top:8px;">Validate</button>
                        </form>
                        <p class="description" style="margin-top:8px;">Paste or type the code if you can't scan.</p>
                    </div>

                    <div id="snn-result" style="margin-top:12px; background:#fff; border:1px solid #ddd; border-radius:8px; padding:12px; display:none;"></div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            const ajaxUrl = <?php echo json_encode($ajax_url); ?>;

            const video = document.getElementById('snn-video');
            const canvas = document.getElementById('snn-canvas');
            const statusEl = document.getElementById('snn-status');
            const resultEl = document.getElementById('snn-result');
            const startBtn = document.getElementById('snn-start-scan');
            const stopBtn = document.getElementById('snn-stop-scan');
            const nextBtn = document.getElementById('snn-scan-next');

            let stream = null;
            let scanning = false;
            let processing = false;
            let useBarcodeDetector = ('BarcodeDetector' in window);
            let detector = null;

            function setStatus(msg){
                if (statusEl) statusEl.textContent = msg;
            }

            async function startCamera(){
                try{
                    stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
                    video.srcObject = stream;
                    await video.play();
                    setStatus('Camera started. Align QR within the frame.');
                }catch(e){
                    setStatus('Camera access denied or unavailable. Use manual entry.');
                }
            }

            function stopCamera(){
                if (stream){
                    stream.getTracks().forEach(t => t.stop());
                    stream = null;
                }
            }

            function showResult(data){
                resultEl.style.display = 'block';

                if (!data || !data.valid){
                    resultEl.style.borderLeft = '5px solid #b3261e';
                    resultEl.innerHTML = '<div style="color:#b3261e;font-weight:700;font-size:17px;">Not valid</div>'
                        + '<div style="margin-top:6px;">' + escapeHtml((data && data.message) || 'This ticket does not exist.') + '</div>';
                    return;
                }

                // A ticket that has been scanned before is still genuine, but
                // the person on the door needs to see that loudly.
                var warn = data.already_used;
                resultEl.style.borderLeft = '5px solid ' + (warn ? '#dba617' : '#0a7d32');

                var name = data.name ? data.name : '—';
                var email = data.email ? data.email : '—';

                var head = warn
                    ? '<div style="color:#8a6100;font-weight:700;font-size:17px;">Already scanned '
                      + data.validate_count + '×</div>'
                    : '<div style="color:#0a7d32;font-weight:700;font-size:17px;">Valid ticket</div>';

                var unsigned = data.signed ? '' :
                    '<div style="margin-top:8px;font-size:12px;color:#646970;">'
                    + 'Entered manually — no QR signature.' + '</div>';

                resultEl.innerHTML = head
                    + '<div style="margin-top:8px;line-height:1.7;">'
                    +   '<div><strong>Name:</strong> ' + escapeHtml(name) + '</div>'
                    +   '<div><strong>Email:</strong> ' + escapeHtml(email) + '</div>'
                    +   '<div><strong>List:</strong> ' + escapeHtml(data.list_name || '') + '</div>'
                    +   '<div><strong>Ticket:</strong> <code>' + escapeHtml(data.ticket_code) + '</code></div>'
                    + '</div>'
                    + unsigned;
            }

            /**
             * A scanned QR holds the full signed URL. Pull the code and its
             * signature out of it; fall back to treating the payload as a
             * bare code for anything older or typed by hand.
             */
            function parsePayload(raw){
                raw = (raw || '').trim();
                try {
                    var u = new URL(raw);
                    var code = u.searchParams.get('snn_ticket');
                    if (code) {
                        return { code: code, sig: u.searchParams.get('snn_sig') || '' };
                    }
                } catch (e) { /* not a URL */ }
                return { code: raw, sig: '' };
            }

            function escapeHtml(str){
                return (''+str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
            }

            async function validateCode(code, sig){
                try{
                    const form = new FormData();
                    form.append('action', 'snn_validate_ticket');
                    form.append('code', code);
                    form.append('sig', sig || '');
                    const res = await fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' });
                    const json = await res.json();
                    if (json && json.success){
                        showResult(json.data);
                    } else {
                        showResult({valid:false});
                    }
                }catch(e){
                    showResult({valid:false});
                }
            }

            async function tickBarcodeDetector(){
                if (!scanning || processing || !stream) return;
                processing = true;
                try{
                    if (!detector) detector = new BarcodeDetector({formats: ['qr_code']});
                    const bit = await createImageBitmap(video);
                    const codes = await detector.detect(bit);
                    bit.close && bit.close();
                    if (codes && codes.length){
                        scanning = false;
                        setStatus('QR detected. Validating...');
                        const parsed = parsePayload(codes[0].rawValue || '');
                        await validateCode(parsed.code, parsed.sig);
                        setStatus('Ready. Click "Scan Next" to continue.');
                    }
                }catch(e){
                    // ignore
                }finally{
                    processing = false;
                }
                if (scanning) requestAnimationFrame(tickBarcodeDetector);
            }

            async function tickJsQR(){
                if (!scanning || processing || !stream) return;
                processing = true;
                try{
                    const w = video.videoWidth;
                    const h = video.videoHeight;
                    if (!w || !h) { processing = false; requestAnimationFrame(tickJsQR); return; }
                    canvas.width = w;
                    canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, w, h);
                    const imageData = ctx.getImageData(0, 0, w, h);
                    const code = window.jsQR ? window.jsQR(imageData.data, w, h) : null;
                    if (code && code.data){
                        scanning = false;
                        setStatus('QR detected. Validating...');
                        const parsed = parsePayload(code.data);
                        await validateCode(parsed.code, parsed.sig);
                        setStatus('Ready. Click "Scan Next" to continue.');
                    }
                }catch(e){
                    // ignore
                }finally{
                    processing = false;
                }
                if (scanning) requestAnimationFrame(tickJsQR);
            }

            startBtn.addEventListener('click', async () => {
                await startCamera();
                if (stream){
                    scanning = true;
                    if (useBarcodeDetector){
                        setStatus('Scanning (native) ...');
                        requestAnimationFrame(tickBarcodeDetector);
                    } else {
                        setStatus('Scanning (jsQR) ...');
                        requestAnimationFrame(tickJsQR);
                    }
                }
            });

            stopBtn.addEventListener('click', () => {
                scanning = false;
                stopCamera();
                setStatus('Camera stopped.');
            });

            nextBtn.addEventListener('click', () => {
                resultEl.style.display = 'none';
                if (stream){
                    scanning = true;
                    if (useBarcodeDetector){
                        setStatus('Scanning (native) ...');
                        requestAnimationFrame(tickBarcodeDetector);
                    } else {
                        setStatus('Scanning (jsQR) ...');
                        requestAnimationFrame(tickJsQR);
                    }
                } else {
                    setStatus('Click "Start Scan" to begin.');
                }
            });

            document.getElementById('snn-manual-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const val = document.getElementById('snn-manual-code').value.trim();
                if (!val) return;
                setStatus('Validating manual code...');
                const parsed = parsePayload(val);
                await validateCode(parsed.code, parsed.sig);
                setStatus('Ready.');
            });

            // Opened from a scanned QR? Validate straight away.
            (function(){
                var params = new URLSearchParams(window.location.search);
                var code = params.get('snn_ticket');
                if (!code) return;
                setStatus('Validating scanned ticket...');
                validateCode(code, params.get('snn_sig') || '');
            })();

            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia){
                startCamera().then(()=>{
                    if (stream){
                        scanning = true;
                        if (useBarcodeDetector){
                            setStatus('Scanning (native) ...');
                            requestAnimationFrame(tickBarcodeDetector);
                        } else {
                            setStatus('Scanning (jsQR) ...');
                            requestAnimationFrame(tickJsQR);
                        }
                    }
                });
            } else {
                setStatus('Camera not supported. Use manual entry.');
            }

            if (!useBarcodeDetector){
                const s = document.createElement('script');
                s.src = '<?php echo plugin_dir_url(__FILE__); ?>src/jsQR.js';
                s.async = true;
                document.head.appendChild(s);
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

SNN_Tickets_Plugin::instance();