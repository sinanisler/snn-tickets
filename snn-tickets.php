<?php
/*
    Plugin Name: SNN Tickets
    Description: Generate tickets, import via CSV, email invites with QR codes (with batching), and scan/validate tickets from a public page via shortcode.
    Version: 0.9
    Requires PHP: 8.1
    Author: sinanisler
    Author URI: https://sinanisler.com/                   
*/

if (!defined('ABSPATH')) exit;

class SNN_Tickets_Plugin {

    private static $instance = null;

    private $table_lists;
    private $table_tickets;

    // Options
    private $opt_batch_size_key = 'snn_tickets_mailer_batch_size';
    private $opt_batch_delay_key = 'snn_tickets_mailer_batch_delay';
    private $opt_email_templates_key = 'snn_tickets_email_templates';
    private $default_batch_size = 10;
    private $default_batch_delay = 2;

    public static function instance(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        global $wpdb;
        $this->table_lists   = $wpdb->prefix . 'snn_ticket_lists';
        $this->table_tickets = $wpdb->prefix . 'snn_tickets';

        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);

        // Generator (create, import, delete)
        add_action('admin_post_snn_generate_tickets', [$this, 'handle_generate_tickets']);
        add_action('admin_post_snn_import_csv',       [$this, 'handle_import_csv']);
        add_action('admin_post_snn_csv_template',     [$this, 'download_csv_template']);
        add_action('admin_post_snn_delete_list',      [$this, 'handle_delete_list']);

        // Mailer
        add_action('admin_post_snn_send_emails',           [$this, 'handle_send_emails']);
        add_action('admin_post_snn_save_mailer_settings',  [$this, 'handle_save_mailer_settings']);
        add_action('wp_ajax_snn_get_list_contacts',        [$this, 'ajax_get_list_contacts']);
        add_action('wp_ajax_snn_send_single_email',        [$this, 'ajax_send_single_email']);

        // AJAX validate (public)
        add_action('wp_ajax_snn_validate_ticket',       [$this, 'ajax_validate_ticket']);
        add_action('wp_ajax_nopriv_snn_validate_ticket',[$this, 'ajax_validate_ticket']);

        // AJAX inline update (admin)
        add_action('wp_ajax_snn_update_ticket_field',   [$this, 'ajax_update_ticket_field']);
        
        // AJAX upload QR image
        add_action('wp_ajax_snn_upload_qr_image',       [$this, 'ajax_upload_qr_image']);

        // AJAX template management
        add_action('wp_ajax_snn_save_email_template',   [$this, 'ajax_save_email_template']);
        add_action('wp_ajax_snn_load_email_template',   [$this, 'ajax_load_email_template']);
        add_action('wp_ajax_snn_delete_email_template', [$this, 'ajax_delete_email_template']);

        // Shortcode
        add_shortcode('tickets_scan_page', [$this, 'shortcode_scan_page']);
    }

    public function activate(){
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_lists = "CREATE TABLE {$this->table_lists} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $sql_tickets = "CREATE TABLE {$this->table_tickets} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            ticket_code VARCHAR(64) NOT NULL,
            name VARCHAR(255) DEFAULT '' NOT NULL,
            email VARCHAR(255) DEFAULT '' NOT NULL,
            validate_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_validated DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY ticket_code (ticket_code),
            KEY list_id (list_id),
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql_lists);
        dbDelta($sql_tickets);

        // Seed defaults for mailer batching
        if (get_option($this->opt_batch_size_key, null) === null) {
            add_option($this->opt_batch_size_key, $this->default_batch_size);
        }
        if (get_option($this->opt_batch_delay_key, null) === null) {
            add_option($this->opt_batch_delay_key, $this->default_batch_delay);
        }
        if (get_option($this->opt_email_templates_key, null) === null) {
            add_option($this->opt_email_templates_key, []);
        }
    }

    public function admin_menu(){
        add_menu_page(
            'Tickets',
            'Tickets',
            'manage_options',
            'snn-tickets',
            [$this, 'render_dashboard_page'],
            'dashicons-tickets',
            26
        );

        add_submenu_page(
            'snn-tickets',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'snn-tickets',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'snn-tickets',
            'Tickets Generator',
            'Tickets Generator',
            'manage_options',
            'snn-tickets-generator',
            [$this, 'render_generator_page']
        );

        add_submenu_page(
            'snn-tickets',
            'Tickets Mailer',
            'Tickets Mailer',
            'manage_options',
            'snn-tickets-mailer',
            [$this, 'render_mailer_page']
        );
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
        
        // Get email templates count
        $email_templates = get_option($this->opt_email_templates_key, []);
        $total_templates = is_array($email_templates) ? count($email_templates) : 0;

        // Recent activity
        $recent_tickets = $wpdb->get_results("
            SELECT t.name, t.email, t.ticket_code, t.created_at, l.name as list_name
            FROM {$this->table_tickets} t
            LEFT JOIN {$this->table_lists} l ON l.id = t.list_id
            ORDER BY t.created_at DESC
            LIMIT 5
        ");

        ?>
        <div class="wrap">
            <h1 style="margin-bottom: 20px;">🎫 SNN Tickets - Dashboard</h1>

            <!-- Statistics Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: #000000; color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Total Lists</div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo number_format($total_lists); ?></div>
                </div>
                <div style="background: #000000; color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Total Tickets</div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo number_format($total_tickets); ?></div>
                </div>
                <div style="background: #000000; color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Validated Tickets</div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo number_format($total_validated); ?></div>
                </div>
                <div style="background: #000000; color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">With Email</div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo number_format($total_with_email); ?></div>
                </div>
                <div style="background: #000000; color: white; padding: 24px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <div style="font-size: 14px; opacity: 0.9; margin-bottom: 8px;">Email Templates</div>
                    <div style="font-size: 36px; font-weight: bold;"><?php echo number_format($total_templates); ?></div>
                </div>
            </div>

            <!-- Main Content -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
                
                <!-- How It Works -->
                <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h2 style="margin-top: 0; color: #2c3e50;">📚 How It Works</h2>
                    
                    <div style="margin-bottom: 20px;">
                        <h3 style="color: #667eea; font-size: 16px; margin-bottom: 8px;">1. Generate or Import Tickets</h3>
                        <p style="color: #555; line-height: 1.6; margin: 0;">Create random tickets or import from CSV files. Each ticket gets a unique QR code for validation.</p>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h3 style="color: #f5576c; font-size: 16px; margin-bottom: 8px;">2. Send Email Invitations</h3>
                        <p style="color: #555; line-height: 1.6; margin: 0;">Send personalized emails with QR codes to ticket holders. Supports batch sending with customizable templates.</p>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h3 style="color: #00f2fe; font-size: 16px; margin-bottom: 8px;">3. Scan & Validate</h3>
                        <p style="color: #555; line-height: 1.6; margin: 0;">Use the public scanning page to validate tickets in real-time using QR codes or manual entry.</p>
                    </div>

                    <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; border-left: 4px solid #667eea;">
                        <strong style="color: #2c3e50;">Quick Actions:</strong>
                        <ul style="margin: 8px 0 0 0; padding-left: 20px; color: #555;">
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-generator'); ?>">Create Tickets</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=snn-tickets-mailer'); ?>">Send Emails</a></li>
                        </ul>
                    </div>
                </div>

                <!-- Shortcode & Scanning -->
                <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h2 style="margin-top: 0; color: #2c3e50;">🔍 Scanning System</h2>
                    
                    <div style="margin-bottom: 20px;">
                        <h3 style="color: #667eea; font-size: 16px; margin-bottom: 12px;">Public Scan Page Shortcode</h3>
                        <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; font-family: monospace; border: 1px solid #dee2e6; position: relative;">
                            <code style="color: #e83e8c; font-size: 14px;">[tickets_scan_page]</code>
                            <button onclick="navigator.clipboard.writeText('[tickets_scan_page]')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); padding: 4px 12px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Copy</button>
                        </div>
                        <p style="color: #666; font-size: 13px; margin-top: 8px;">Add this shortcode to any page or post to create a public ticket scanning interface.</p>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h3 style="color: #f5576c; font-size: 16px; margin-bottom: 8px;">Scan Features</h3>
                        <ul style="color: #555; line-height: 1.8; margin: 0; padding-left: 20px;">
                            <li>QR Code scanning via camera</li>
                            <li>Manual ticket code entry</li>
                            <li>Real-time validation feedback</li>
                            <li>Validation count tracking</li>
                            <li>Timestamp recording</li>
                        </ul>
                    </div>

                    <div style="background: #fff3cd; padding: 16px; border-radius: 8px; border-left: 4px solid #ffc107;">
                        <strong style="color: #856404;">💡 Tip:</strong>
                        <p style="margin: 8px 0 0 0; color: #856404; font-size: 14px;">Create a dedicated page called "Ticket Scanner" and add the shortcode for your event staff to validate tickets.</p>
                    </div>
                </div>
            </div>

            <!-- Recent Tickets -->
            <?php if ($recent_tickets): ?>
            <div style="background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="margin-top: 0; color: #2c3e50;">🕐 Recent Tickets</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                            <th style="padding: 12px; text-align: left; color: #495057; font-weight: 600;">Ticket Code</th>
                            <th style="padding: 12px; text-align: left; color: #495057; font-weight: 600;">Name</th>
                            <th style="padding: 12px; text-align: left; color: #495057; font-weight: 600;">Email</th>
                            <th style="padding: 12px; text-align: left; color: #495057; font-weight: 600;">List</th>
                            <th style="padding: 12px; text-align: left; color: #495057; font-weight: 600;">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_tickets as $ticket): ?>
                        <tr style="border-bottom: 1px solid #f1f3f5;">
                            <td style="padding: 12px; font-family: monospace; color: #667eea; font-weight: 600;"><?php echo esc_html($ticket->ticket_code); ?></td>
                            <td style="padding: 12px; color: #495057;"><?php echo esc_html($ticket->name ?: '-'); ?></td>
                            <td style="padding: 12px; color: #6c757d; font-size: 13px;"><?php echo esc_html($ticket->email ?: '-'); ?></td>
                            <td style="padding: 12px; color: #495057;"><?php echo esc_html($ticket->list_name ?: '-'); ?></td>
                            <td style="padding: 12px; color: #6c757d; font-size: 13px;"><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($ticket->created_at))); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="background: white; padding: 48px 24px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center;">
                <div style="font-size: 48px; margin-bottom: 16px;">🎫</div>
                <h3 style="color: #6c757d; margin: 0 0 8px 0;">No tickets yet</h3>
                <p style="color: #adb5bd; margin: 0 0 20px 0;">Get started by creating your first ticket list!</p>
                <a href="<?php echo admin_url('admin.php?page=snn-tickets-generator'); ?>" class="button button-primary" style="padding: 12px 24px; font-size: 14px;">Create Your First Tickets</a>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    private function esc_textarea_keep_basic($html){
        return wp_kses_post($html);
    }

    private function generate_ticket_code($length = 8){
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($chars) - 1;
        $code = '';
        for ($i=0; $i < $length; $i++){
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }

    private function unique_ticket_code($length = 8){
        global $wpdb;
        do {
            $code = $this->generate_ticket_code($length);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_tickets} WHERE ticket_code = %s", $code));
        } while ($exists);
        return $code;
    }

    private function create_list($name){
        global $wpdb;
        $wpdb->insert($this->table_lists, [
            'name'       => $name,
            'created_at' => current_time('mysql'),
        ], ['%s', '%s']);
        return (int)$wpdb->insert_id;
    }

    private function insert_ticket($list_id, $name, $email, $code = null){
        global $wpdb;
        if (!$code) $code = $this->unique_ticket_code(8);
        $wpdb->insert($this->table_tickets, [
            'list_id'        => $list_id,
            'ticket_code'    => $code,
            'name'           => $name ?: '',
            'email'          => $email ?: '',
            'validate_count' => 0,
            'last_validated' => null,
            'created_at'     => current_time('mysql'),
        ], ['%d','%s','%s','%s','%d','%s','%s']);
        return (int)$wpdb->insert_id;
    }

    public function render_generator_page(){
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

        $nonce_generate = wp_create_nonce('snn_generate_tickets');
        $nonce_import   = wp_create_nonce('snn_import_csv');
        $nonce_delete   = wp_create_nonce('snn_delete_list');

        $update_nonce = wp_create_nonce('snn_update_ticket');
        $ajax_url     = admin_url('admin-ajax.php');

        $template_url = admin_url('admin-post.php?action=snn_csv_template');

        $now_placeholder = date_i18n('Y-m-d H:i', current_time('timestamp'));
        ?>
        <div class="wrap">
            <h1>Tickets - Generator</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($_GET['snn_msg']); ?></p></div>
            <?php endif; ?>

            <div id="snn-generator" style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:320px; background:#fff; padding:16px; border:1px solid #ccd0d4; border-radius:6px;">
                    <h2>Generate Random Tickets</h2>
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
                                <td><input type="number" id="snn_len" name="length" value="10" min="6" max="64"></td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">Generate</button></p>
                        <p class="description">Generates uppercase alphanumeric codes. A new list will be created.</p>
                    </form>
                </div>

                <div style="flex:1; min-width:320px; background:#fff; padding:16px; border:1px solid #ccd0d4; border-radius:6px;">
                    <h2>Import from CSV</h2>
                    <p>Upload a CSV with columns: Name,Email (header row required).</p>
                    <p><a class="button" href="<?php echo esc_url($template_url); ?>">Download CSV Template</a></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="snn_import_csv">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_import); ?>">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="snn_import_list_name">List Name</label></th>
                                <td><input type="text" id="snn_import_list_name" name="list_name" class="regular-text" placeholder="Imported <?php echo esc_attr($now_placeholder); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_csv_file">CSV File</label></th>
                                <td><input type="file" id="snn_csv_file" name="csv_file" accept=".csv" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_ticket_length">Ticket length</label></th>
                                <td><input type="number" id="snn_ticket_length" name="length" value="10" min="6" max="64"></td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button button-primary">Import and Generate</button></p>
                        <p class="description">Each person will receive a unique ticket code.</p>
                    </form>
                </div>
            </div>

            <hr>

            <h2>Lists</h2>
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
                        <details style="margin-bottom:12px; background:#fff; border:1px solid #ccd0d4; border-radius:6px;">
                            <summary style="cursor:pointer; padding:12px 16px; font-weight:600; display:flex; justify-content:space-between; align-items:center;">
                                <span>
                                    <?php echo esc_html($list->name); ?>
                                    <span style="opacity:0.7; font-weight:normal;">
                                        &nbsp;• Created <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($list->created_at))); ?>
                                        &nbsp;• Tickets: <?php echo esc_html((int)$list->total_tickets); ?>
                                        &nbsp;• With email: <?php echo esc_html((int)$list->total_with_email); ?>
                                    </span>
                                </span>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this list and all its tickets? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="snn_delete_list">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_delete); ?>">
                                    <input type="hidden" name="list_id" value="<?php echo esc_attr($list->id); ?>">
                                    <button type="submit" class="button button-small" style="background:#dc3232; color:#fff; border-color:#dc3232;" onclick="event.stopPropagation();">Delete</button>
                                </form>
                            </summary>
                            <div style="padding:0 16px 16px 16px;">
                                <div class="snn-table-wrap" style="max-height:380px; overflow:auto;">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Ticket Code</th>
                                                <th>Validated</th>
                                                <th>Last Validated</th>
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
                                                </tr>
                                            <?php endforeach; else: ?>
                                                <tr><td colspan="6">No tickets in this list yet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No lists yet. Generate or import to get started.</p>
                <?php endif; ?>
            </div>
        </div>

        <style>
            .snn-inline-edit {
                display: inline-block;
                min-width: 120px;
                padding: 2px 4px;
                border-radius: 3px;
                transition: background-color .15s, box-shadow .15s;
                outline: none;
            }
            .snn-inline-edit:focus {
                background: #fffdf6;
                box-shadow: 0 0 0 2px #f0c36d66;
            }
            .snn-inline-saving {
                background: #f3f6ff !important;
                box-shadow: 0 0 0 2px #6ea8fe66 !important;
            }
            .snn-inline-ok {
                background: #effaf1;
                box-shadow: 0 0 0 2px #46b45066;
                animation: snn-fade-ok 1.2s ease forwards;
            }
            @keyframes snn-fade-ok {
                0% { background: #effaf1; }
                100% { background: transparent; box-shadow: none; }
            }
            .snn-inline-error {
                background: #fff1f1;
                box-shadow: 0 0 0 2px #dc323266;
            }
            .snn-inline-hint {
                font-size: 11px;
                color: #666;
                margin-top: 6px;
            }
        </style>

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

    public function handle_generate_tickets(){
        $this->admin_cap_check();
        check_admin_referer('snn_generate_tickets');

        $count  = isset($_POST['count']) ? max(1, min(5000, intval($_POST['count']))) : 10;
        $length = isset($_POST['length']) ? max(6, min(64, intval($_POST['length']))) : 10;
        $default_name = 'Generated ' . date_i18n('Y-m-d H:i', current_time('timestamp'));
        $list_name = isset($_POST['list_name']) && trim($_POST['list_name']) !== '' ? sanitize_text_field($_POST['list_name']) : $default_name;

        $list_id = $this->create_list($list_name);

        for ($i=0; $i<$count; $i++){
            $this->insert_ticket($list_id, '', '', $this->unique_ticket_code($length));
        }

        wp_redirect(add_query_arg('snn_msg', rawurlencode("Generated $count tickets in list: $list_name"), admin_url('admin.php?page=snn-tickets-generator')));
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

        wp_redirect(add_query_arg('snn_msg', rawurlencode("Imported $count contacts and generated tickets in list: $list_name"), admin_url('admin.php?page=snn-tickets-generator')));
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

        // Get all ticket codes to delete their QR images
        $tickets = $wpdb->get_results($wpdb->prepare("
            SELECT ticket_code FROM {$this->table_tickets} WHERE list_id = %d
        ", $list_id));

        // Delete QR code files
        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/snn-tickets-qr';
        
        foreach ($tickets as $ticket) {
            $filename = 'qr-' . sanitize_file_name($ticket->ticket_code) . '.png';
            $filepath = $qr_dir . '/' . $filename;
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }

        // Delete tickets first (due to foreign key relationship)
        $tickets_deleted = $wpdb->delete($this->table_tickets, ['list_id' => $list_id], ['%d']);
        
        // Delete the list
        $list_deleted = $wpdb->delete($this->table_lists, ['id' => $list_id], ['%d']);

        if ($list_deleted) {
            wp_redirect(add_query_arg('snn_msg', rawurlencode("Deleted list '{$list->name}' and {$tickets_deleted} tickets"), admin_url('admin.php?page=snn-tickets-generator')));
        } else {
            wp_redirect(add_query_arg('snn_msg', rawurlencode("Failed to delete list"), admin_url('admin.php?page=snn-tickets-generator')));
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

    private function get_batch_settings(){
        $size = (int) get_option($this->opt_batch_size_key, $this->default_batch_size);
        $delay = (int) get_option($this->opt_batch_delay_key, $this->default_batch_delay);
        // Clamp
        $size = max(1, min(200, $size));
        $delay = max(0, min(60, $delay));
        return [$size, $delay];
    }

    private function get_email_templates(){
        return get_option($this->opt_email_templates_key, []);
    }

    public function render_mailer_page(){
        $this->admin_cap_check();
        global $wpdb;

        $lists = $wpdb->get_results("SELECT * FROM {$this->table_lists} ORDER BY id DESC");
        $nonce_send  = wp_create_nonce('snn_send_emails');
        $nonce_save  = wp_create_nonce('snn_save_mailer_settings');

        list($batch_size, $batch_delay) = $this->get_batch_settings();
        $templates = $this->get_email_templates();

        $ajax_url = admin_url('admin-ajax.php');
        $template_nonce = wp_create_nonce('snn_email_template');

        $default_subject = 'Your Ticket to Our Event';
        $default_body = <<<HTML
<p>Hi {name},</p>

<p>We're excited to invite you! Below is your unique ticket QR code. Please bring it to the event.</p>

<p style="text-align:center;"><img alt="Your Ticket QR" src="{qr}" width="200" height="200" style="display:block; margin:0 auto;"/></p>

<p>Your ticket code: <strong>{ticket}</strong></p>

<p>See you soon!</p>

HTML;

        ?>
        <div class="wrap">
            <h1>Tickets - Mailer</h1>

            <?php if (isset($_GET['snn_msg'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($_GET['snn_msg']); ?></p></div>
            <?php endif; ?>

            <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:300px; background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:16px;">
                    <h2>Batch Settings</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="snn_save_mailer_settings">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_save); ?>">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="snn_batch_size">Emails per batch</label></th>
                                <td><input type="number" id="snn_batch_size" name="batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="200"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_batch_delay">Delay between batches (seconds)</label></th>
                                <td><input type="number" id="snn_batch_delay" name="batch_delay" value="<?php echo esc_attr($batch_delay); ?>" min="0" max="60"></td>
                            </tr>
                        </table>
                        <p><button type="submit" class="button">Save Settings</button></p>
                        <p class="description">Emails are sent in batches to reduce server load. For large lists consider lowering per-batch size and increasing delay.</p>
                    </form>
                </div>

                <div style="flex:2; min-width:360px; background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:16px;">
                    <h2>Send Invitations</h2>
                    <form id="snn_mailer_form">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_send); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="snn_list_id">Select List</label></th>
                                <td>
                                    <select id="snn_list_id" name="list_id" required>
                                        <option value="">— Select a list —</option>
                                        <?php foreach ($lists as $l): ?>
                                            <?php
                                            $counts = $wpdb->get_row($wpdb->prepare("
                                                SELECT COUNT(*) AS total, SUM(CASE WHEN email <> '' THEN 1 ELSE 0 END) AS with_email
                                                FROM {$this->table_tickets} WHERE list_id = %d
                                            ", $l->id));
                                            ?>
                                            <option value="<?php echo esc_attr($l->id); ?>">
                                                <?php echo esc_html($l->name . " (emails: " . (int)$counts->with_email . " / tickets: " . (int)$counts->total . ")"); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_from_name">From Name</label></th>
                                <td><input type="text" id="snn_from_name" name="from_name" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_from_email">From Email</label></th>
                                <td><input type="email" id="snn_from_email" name="from_email" class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('admin_email')); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="snn_subject">Subject</label></th>
                                <td><input type="text" id="snn_subject" name="subject" class="regular-text" value="<?php echo esc_attr($default_subject); ?>" required></td>
                            </tr>
                            <tr>
                                <th scope="row">Email Content</th>
                                <td>
                                    <div style="margin-bottom:10px;">
                                        <div style="background:#f9f9f9; border:1px solid #ddd; padding:8px; border-radius:4px;">
                                            <div style="margin-bottom:8px;">
                                                <strong>Templates:</strong>
                                                <span id="snn_template_status" style="display:none; margin-left:10px; padding:3px 8px; border-radius:3px;"></span>
                                            </div>
                                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                                <select id="snn_template_list" style="min-width:200px;">
                                                    <option value="">— Select Template —</option>
                                                    <?php foreach ($templates as $name => $template): ?>
                                                        <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" id="snn_delete_template" class="button" style="display:none;">Delete</button>
                                                <div style="flex:1 0 100%; margin-top:8px; padding-top:8px; border-top:1px solid #ddd;">
                                                    <input type="text" id="snn_template_name" placeholder="Template name" style="min-width:200px;">
                                                    <button type="button" id="snn_save_template" class="button">Save as Template</button>
                                                    <button type="button" id="snn_new_template" class="button" style="display:none;">New Template</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="margin-bottom:8px;">
                                        <button type="button" class="button snn-html-btn" data-tag="<p>|</p>">P</button>
                                        <button type="button" class="button snn-html-btn" data-tag="<b>|</b>">B</button>
                                        <button type="button" class="button snn-html-btn" data-tag="<i>|</i>">I</button>
                                        <button type="button" class="button snn-html-btn" data-tag="<br>">BR</button>
                                        <button type="button" class="button snn-html-btn" data-tag="<a href=&quot;&quot;>|</a>">Link</button>
                                        <button type="button" class="button snn-html-btn" data-tag="<strong>|</strong>">Strong</button>
                                        <button type="button" class="button snn-html-btn" data-tag="<em>|</em>">Em</button>
                                    </div>
                                    <textarea id="snn_body" name="body" rows="12" style="width:100%; font-family:monospace; font-size:13px;"><?php echo esc_textarea($default_body); ?></textarea>
                                    <p class="description">
                                        Available tags: {name}, {ticket}, {qr}.<br>
                                        {qr} will be replaced with a QR image for the ticket code.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" class="button button-primary" id="snn_send_btn">Send Emails</button>
                        </p>

                        <div id="snn_progress_wrap" style="display:none; margin-top:16px; background:#f9f9f9; border:1px solid #ddd; padding:12px; border-radius:4px;">
                            <div style="margin-bottom:8px;">
                                <strong id="snn_progress_text">Preparing...</strong>
                            </div>
                            <div style="background:#fff; border:1px solid #ddd; height:24px; border-radius:4px; overflow:hidden; margin-bottom:8px;">
                                <div id="snn_progress_bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
                            </div>
                            <div style="margin-bottom:8px; font-size:12px; color:#666;">
                                <span id="snn_progress_details">0 / 0 sent</span>
                            </div>
                            <div style="background:#fff; border:1px solid #ddd; border-radius:4px; padding:8px; max-height:200px; overflow-y:auto; font-family:monospace; font-size:11px;">
                                <div id="snn_console_log"></div>
                            </div>
                        </div>

                        <p class="description" style="max-width:800px;">
                            Sending uses WordPress mailer. Current batch settings: <?php echo esc_html($batch_size); ?> per batch, <?php echo esc_html($batch_delay); ?> second(s) between batches. QR codes are generated in your browser (offline) and saved as image files for maximum email compatibility.
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <!-- Load QR Code library from CDN -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

        <script>
        (function(){
            const ajaxUrl = <?php echo json_encode($ajax_url); ?>;
            const nonce = <?php echo json_encode($template_nonce); ?>;

            const templateList = document.getElementById('snn_template_list');
            const deleteBtn = document.getElementById('snn_delete_template');
            const saveBtn = document.getElementById('snn_save_template');
            const newBtn = document.getElementById('snn_new_template');
            const templateNameInput = document.getElementById('snn_template_name');
            const subjectInput = document.getElementById('snn_subject');
            const bodyTextarea = document.getElementById('snn_body');
            const statusEl = document.getElementById('snn_template_status');

            let currentTemplateName = '';

            // HTML Button handlers
            document.querySelectorAll('.snn-html-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const tag = btn.getAttribute('data-tag');
                    const textarea = bodyTextarea;
                    const start = textarea.selectionStart;
                    const end = textarea.selectionEnd;
                    const text = textarea.value;
                    const selected = text.substring(start, end);
                    
                    let insert = tag.replace('|', selected);
                    if (tag.includes('|') && !selected) {
                        insert = tag.replace('|', '');
                    }
                    
                    textarea.value = text.substring(0, start) + insert + text.substring(end);
                    
                    // Set cursor position
                    if (tag.includes('|')) {
                        const cursorPos = start + tag.indexOf('|') + selected.length;
                        textarea.setSelectionRange(cursorPos, cursorPos);
                    } else {
                        textarea.setSelectionRange(start + insert.length, start + insert.length);
                    }
                    textarea.focus();
                });
            });

            function showStatus(msg, type = 'info') {
                statusEl.style.display = 'inline-block';
                statusEl.textContent = msg;
                statusEl.style.background = type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1';
                statusEl.style.color = type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460';
                setTimeout(() => {
                    statusEl.style.display = 'none';
                }, 3000);
            }

            // Template selection
            templateList.addEventListener('change', async () => {
                const selectedName = templateList.value;
                
                if (!selectedName) {
                    currentTemplateName = '';
                    templateNameInput.value = '';
                    templateNameInput.placeholder = 'Template name';
                    saveBtn.textContent = 'Save as Template';
                    deleteBtn.style.display = 'none';
                    newBtn.style.display = 'none';
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'snn_load_email_template');
                fd.append('nonce', nonce);
                fd.append('name', selectedName);

                try {
                    const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        subjectInput.value = json.data.subject || '';
                        bodyTextarea.value = json.data.body || '';

                        currentTemplateName = selectedName;
                        templateNameInput.value = selectedName;
                        templateNameInput.placeholder = 'Template name';
                        saveBtn.textContent = 'Update Template';
                        deleteBtn.style.display = 'inline-block';
                        newBtn.style.display = 'inline-block';
                        
                        showStatus('Template loaded', 'success');
                    } else {
                        showStatus('Failed to load template', 'error');
                    }
                } catch (e) {
                    console.error('Failed to load template:', e);
                    showStatus('Failed to load template', 'error');
                }
            });

            // Save template
            saveBtn.addEventListener('click', async () => {
                const templateName = templateNameInput.value.trim();
                if (!templateName) {
                    alert('Please enter a template name');
                    return;
                }

                const subject = subjectInput.value;
                const body = bodyTextarea.value;

                const isUpdate = (templateName === currentTemplateName);

                const fd = new FormData();
                fd.append('action', 'snn_save_email_template');
                fd.append('nonce', nonce);
                fd.append('name', templateName);
                fd.append('subject', subject);
                fd.append('body', body);

                try {
                    const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        if (!isUpdate) {
                            const existingOption = Array.from(templateList.options).find(opt => opt.value === templateName);
                            if (!existingOption) {
                                const option = new Option(templateName, templateName);
                                templateList.add(option);
                            }
                        }
                        
                        templateList.value = templateName;
                        currentTemplateName = templateName;
                        saveBtn.textContent = 'Update Template';
                        deleteBtn.style.display = 'inline-block';
                        newBtn.style.display = 'inline-block';
                        
                        showStatus(isUpdate ? 'Template updated!' : 'Template saved!', 'success');
                    } else {
                        showStatus('Failed to save template', 'error');
                    }
                } catch (e) {
                    console.error('Failed to save template:', e);
                    showStatus('Failed to save template', 'error');
                }
            });

            // Delete template
            deleteBtn.addEventListener('click', async () => {
                if (!currentTemplateName) return;
                
                if (!confirm(`Delete template "${currentTemplateName}"? This cannot be undone.`)) {
                    return;
                }

                const fd = new FormData();
                fd.append('action', 'snn_delete_email_template');
                fd.append('nonce', nonce);
                fd.append('name', currentTemplateName);

                try {
                    const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        const option = Array.from(templateList.options).find(opt => opt.value === currentTemplateName);
                        if (option) option.remove();
                        
                        templateList.value = '';
                        currentTemplateName = '';
                        templateNameInput.value = '';
                        templateNameInput.placeholder = 'Template name';
                        saveBtn.textContent = 'Save as Template';
                        deleteBtn.style.display = 'none';
                        newBtn.style.display = 'none';
                        
                        showStatus('Template deleted', 'success');
                    } else {
                        showStatus('Failed to delete template', 'error');
                    }
                } catch (e) {
                    console.error('Failed to delete template:', e);
                    showStatus('Failed to delete template', 'error');
                }
            });

            // New template
            newBtn.addEventListener('click', () => {
                templateList.value = '';
                currentTemplateName = '';
                templateNameInput.value = '';
                templateNameInput.placeholder = 'Template name';
                saveBtn.textContent = 'Save as Template';
                deleteBtn.style.display = 'none';
                newBtn.style.display = 'none';
                showStatus('Ready to create new template', 'info');
            });

            // Update save button text
            templateNameInput.addEventListener('input', () => {
                const newName = templateNameInput.value.trim();
                if (currentTemplateName && newName !== currentTemplateName) {
                    saveBtn.textContent = 'Save as New Template';
                } else if (currentTemplateName && newName === currentTemplateName) {
                    saveBtn.textContent = 'Update Template';
                } else {
                    saveBtn.textContent = 'Save as Template';
                }
            });

            // === EMAIL SENDING WITH PROGRESS ===
            const form = document.getElementById('snn_mailer_form');
            const submitBtn = document.getElementById('snn_send_btn');
            const originalBtnText = submitBtn.textContent;
            const progressWrap = document.getElementById('snn_progress_wrap');
            const progressBar = document.getElementById('snn_progress_bar');
            const progressText = document.getElementById('snn_progress_text');
            const progressDetails = document.getElementById('snn_progress_details');
            const consoleLog = document.getElementById('snn_console_log');

            function log(msg, type = 'info') {
                const time = new Date().toLocaleTimeString();
                const colors = {
                    info: '#333',
                    success: '#0a7c0a',
                    error: '#b00',
                    warning: '#d68000'
                };
                const line = document.createElement('div');
                line.style.color = colors[type] || colors.info;
                line.style.marginBottom = '2px';
                line.textContent = `[${time}] ${msg}`;
                consoleLog.appendChild(line);
                consoleLog.scrollTop = consoleLog.scrollHeight;
            }

            // Wait for QRCode library to be available
            function waitForQRCode() {
                return new Promise((resolve) => {
                    if (typeof QRCode !== 'undefined') {
                        resolve();
                        return;
                    }
                    
                    const checkInterval = setInterval(() => {
                        if (typeof QRCode !== 'undefined') {
                            clearInterval(checkInterval);
                            resolve();
                        }
                    }, 100);
                    
                    // Timeout after 10 seconds
                    setTimeout(() => {
                        clearInterval(checkInterval);
                        if (typeof QRCode === 'undefined') {
                            throw new Error('QRCode library failed to load');
                        }
                        resolve();
                    }, 10000);
                });
            }

            // Generate QR code and upload to server
            async function generateAndUploadQR(ticketCode, sendNonce) {
                try {
                    // Wait for QRCode library to be available
                    await waitForQRCode();

                    // Create a temporary DOM element to hold the QR code
                    const tempDiv = document.createElement('div');
                    tempDiv.style.position = 'absolute';
                    tempDiv.style.left = '-9999px';
                    document.body.appendChild(tempDiv);

                    // Generate QR code
                    const qr = new QRCode(tempDiv, {
                        text: ticketCode,
                        width: 300,
                        height: 300,
                        colorDark: '#000000',
                        colorLight: '#FFFFFF',
                        correctLevel: QRCode.CorrectLevel.M
                    });

                    // Wait for QR code to render
                    await new Promise(resolve => setTimeout(resolve, 200));

                    // Try to get data URL from <img> or <canvas>
                    let qrDataUrl = null;
                    const img = tempDiv.querySelector('img');
                    if (img && img.src) {
                        qrDataUrl = img.src;
                    } else {
                        const canvas = tempDiv.querySelector('canvas');
                        if (canvas) {
                            qrDataUrl = canvas.toDataURL('image/png');
                        }
                    }

                    // Clean up
                    tempDiv.remove();

                    if (!qrDataUrl) {
                        throw new Error('Failed to generate QR code image');
                    }

                    // Upload to server
                    const uploadResponse = await fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'snn_upload_qr_image',
                            nonce: sendNonce,
                            ticket_code: ticketCode,
                            image_data: qrDataUrl
                        })
                    });

                    const uploadResult = await uploadResponse.json();

                    if (!uploadResult.success) {
                        throw new Error(uploadResult.data?.message || 'Upload failed');
                    }

                    return uploadResult.data.url;
                } catch (e) {
                    console.error('QR generation/upload error:', e);
                    throw e;
                }
            }

            async function sendEmailsBatch() {
                submitBtn.disabled = true;
                progressWrap.style.display = 'block';
                progressBar.style.width = '0%';
                progressText.textContent = 'Preparing...';
                progressDetails.textContent = '0 / 0 sent';
                consoleLog.innerHTML = '';
                
                log('Starting email sending process...', 'info');
                
                // Check if QRCode library is loaded
                try {
                    log('Checking QR Code library...', 'info');
                    await waitForQRCode();
                    log('QR Code library loaded successfully', 'success');
                } catch (e) {
                    log('ERROR: QR Code library failed to load. Please refresh the page and try again.', 'error');
                    alert('QR Code library failed to load. Please refresh the page and try again.');
                    submitBtn.disabled = false;
                    return;
                }
                
                const formData = new FormData(form);
                const listId = parseInt(formData.get('list_id'));
                const fromName = formData.get('from_name');
                const fromEmail = formData.get('from_email');
                const subject = formData.get('subject');
                const body = bodyTextarea.value;
                const sendNonce = formData.get('_wpnonce');

                if (!listId || !subject || !body) {
                    log('ERROR: Please fill all required fields', 'error');
                    alert('Please fill all required fields');
                    submitBtn.disabled = false;
                    return;
                }

                try {
                    log('Fetching contacts from list...', 'info');
                    progressText.textContent = 'Loading contacts...';
                    
                    // Fetch contacts
                    const contactsResponse = await fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'snn_get_list_contacts',
                            nonce: sendNonce,
                            list_id: listId
                        })
                    });
                    
                    const contactsData = await contactsResponse.json();
                    if (!contactsData.success) {
                        throw new Error('Failed to load contacts');
                    }

                    const contacts = contactsData.data.contacts;
                    const batchSize = contactsData.data.batch_size;
                    const batchDelay = contactsData.data.batch_delay;

                    if (!contacts.length) {
                        log('ERROR: No contacts with email found in selected list', 'error');
                        alert('No contacts with email found in selected list');
                        submitBtn.disabled = false;
                        return;
                    }

                    log(`Found ${contacts.length} contacts with email`, 'success');
                    log(`Batch settings: ${batchSize} emails per batch, ${batchDelay}s delay`, 'info');

                    let sent = 0;
                    let failed = 0;
                    const total = contacts.length;

                    progressText.textContent = `Sending emails (${total} total)...`;

                    // Process in batches
                    const totalBatches = Math.ceil(contacts.length / batchSize);
                    for (let i = 0; i < contacts.length; i += batchSize) {
                        const batch = contacts.slice(i, i + batchSize);
                        const batchNum = Math.floor(i / batchSize) + 1;
                        
                        log(`--- Batch ${batchNum}/${totalBatches} (${batch.length} emails) ---`, 'warning');
                        progressText.textContent = `Sending batch ${batchNum} of ${totalBatches}...`;

                        // Send batch in parallel
                        const batchPromises = batch.map(async (contact) => {
                            try {
                                // Generate and upload QR code
                                log(`Generating QR for ${contact.ticket_code}...`, 'info');
                                const qrUrl = await generateAndUploadQR(contact.ticket_code, sendNonce);
                                
                                log(`Sending email to ${contact.email} (${contact.name || 'No name'})...`, 'info');
                                const response = await fetch(ajaxUrl, {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                    body: new URLSearchParams({
                                        action: 'snn_send_single_email',
                                        nonce: sendNonce,
                                        name: contact.name,
                                        email: contact.email,
                                        ticket: contact.ticket_code,
                                        subject: subject,
                                        body: body,
                                        qr_url: qrUrl,
                                        from_name: fromName,
                                        from_email: fromEmail
                                    })
                                });

                                const result = await response.json();
                                if (result.success) {
                                    sent++;
                                    log(`✓ Sent to ${contact.email}`, 'success');
                                } else {
                                    failed++;
                                    const errorMsg = result.data?.message || 'Unknown error';
                                    log(`✗ Failed to send to ${contact.email}: ${errorMsg}`, 'error');
                                    console.error('Server response:', result);
                                }
                            } catch (e) {
                                failed++;
                                log(`✗ Error sending to ${contact.email}: ${e.message}`, 'error');
                                console.error('Failed to send to', contact.email, e);
                            }

                            // Update progress
                            const progress = Math.round(((sent + failed) / total) * 100);
                            progressBar.style.width = progress + '%';
                            progressDetails.textContent = `${sent} sent, ${failed} failed / ${total} total`;
                        });

                        await Promise.all(batchPromises);

                        // Delay between batches
                        if (i + batchSize < contacts.length && batchDelay > 0) {
                            log(`Waiting ${batchDelay} seconds before next batch...`, 'warning');
                            progressText.textContent = `Waiting ${batchDelay}s before next batch...`;
                            await new Promise(resolve => setTimeout(resolve, batchDelay * 1000));
                        }
                    }

                    progressBar.style.width = '100%';
                    progressText.textContent = 'Complete!';
                    progressDetails.textContent = `${sent} sent, ${failed} failed / ${total} total`;
                    
                    log('=================================', 'info');
                    log(`COMPLETE: ${sent} sent, ${failed} failed, ${total} total`, sent === total ? 'success' : 'warning');
                    log('=================================', 'info');
                    
                    // alert(`Sending complete!\n\n✓ Sent: ${sent}\n✗ Failed: ${failed}\n━ Total: ${total}`);
                    
                } catch (error) {
                    console.error('Error:', error);
                    log(`FATAL ERROR: ${error.message}`, 'error');
                    alert('An error occurred: ' + error.message);
                    progressText.textContent = 'Error occurred';
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
            }

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                sendEmailsBatch();
            });
        })();
        </script>
        <?php
    }

    public function handle_save_mailer_settings(){
        $this->admin_cap_check();
        check_admin_referer('snn_save_mailer_settings');

        $size  = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : $this->default_batch_size;
        $delay = isset($_POST['batch_delay']) ? intval($_POST['batch_delay']) : $this->default_batch_delay;

        // Clamp
        $size  = max(1, min(200, $size));
        $delay = max(0, min(60, $delay));

        update_option($this->opt_batch_size_key, $size);
        update_option($this->opt_batch_delay_key, $delay);

        wp_redirect(add_query_arg('snn_msg', rawurlencode("Saved batch settings: $size per batch, $delay second(s) delay"), admin_url('admin.php?page=snn-tickets-mailer')));
        exit;
    }

    public function handle_send_emails(){
        // This method is now deprecated - emails are sent via AJAX
        // Redirect to mailer page if accessed directly
        $this->admin_cap_check();
        wp_redirect(admin_url('admin.php?page=snn-tickets-mailer'));
        exit;
    }

    public function ajax_validate_ticket(){
        if (!isset($_POST['code'])) {
            wp_send_json_error(['message' => 'Missing code'], 400);
        }
        $code = sanitize_text_field($_POST['code']);
        if ($code === '') {
            wp_send_json_error(['message' => 'Empty code'], 400);
        }

        global $wpdb;

        $ticket = $wpdb->get_row($wpdb->prepare("
            SELECT t.*, l.name AS list_name
            FROM {$this->table_tickets} t
            JOIN {$this->table_lists} l ON l.id = t.list_id
            WHERE t.ticket_code = %s
            LIMIT 1
        ", $code));

        if (!$ticket) {
            wp_send_json_success([
                'valid' => false
            ]);
        }

        $wpdb->update($this->table_tickets, [
            'validate_count' => (int)$ticket->validate_count + 1,
            'last_validated' => current_time('mysql'),
        ], [
            'id' => $ticket->id
        ], ['%d', '%s'], ['%d']);

        wp_send_json_success([
            'valid'           => true,
            'ticket_code'     => $ticket->ticket_code,
            'name'            => $ticket->name,
            'email'           => $ticket->email,
            'list_name'       => $ticket->list_name,
            'validate_count'  => (int)$ticket->validate_count + 1,
        ]);
    }

    // Inline update for Name/Email (admin)
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

    public function ajax_save_email_template(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'snn_email_template')) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $subject = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
        $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';

        if (!$name) {
            wp_send_json_error(['message' => 'Template name is required.'], 400);
        }

        $templates = $this->get_email_templates();
        $templates[$name] = [
            'subject' => $subject,
            'body' => $body,
            'created' => current_time('mysql')
        ];

        update_option($this->opt_email_templates_key, $templates);

        wp_send_json_success(['message' => 'Template saved successfully.']);
    }

    public function ajax_load_email_template(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'snn_email_template')) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        if (!$name) {
            wp_send_json_error(['message' => 'Template name is required.'], 400);
        }

        $templates = $this->get_email_templates();
        if (!isset($templates[$name])) {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        wp_send_json_success($templates[$name]);
    }

    public function ajax_delete_email_template(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'snn_email_template')) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        if (!$name) {
            wp_send_json_error(['message' => 'Template name is required.'], 400);
        }

        $templates = $this->get_email_templates();
        if (!isset($templates[$name])) {
            wp_send_json_error(['message' => 'Template not found.'], 404);
        }

        unset($templates[$name]);
        update_option($this->opt_email_templates_key, $templates);

        wp_send_json_success(['message' => 'Template deleted successfully.']);
    }

    public function ajax_get_list_contacts(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'snn_send_emails')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $list_id = isset($_POST['list_id']) ? intval($_POST['list_id']) : 0;
        
        if (!$list_id) {
            wp_send_json_error(['message' => 'Invalid list ID'], 400);
        }

        global $wpdb;
        $contacts = $wpdb->get_results($wpdb->prepare("
            SELECT name, email, ticket_code
            FROM {$this->table_tickets}
            WHERE list_id = %d AND email <> ''
        ", $list_id), ARRAY_A);

        list($batch_size, $batch_delay) = $this->get_batch_settings();

        wp_send_json_success([
            'contacts' => $contacts,
            'batch_size' => $batch_size,
            'batch_delay' => $batch_delay
        ]);
    }

    public function ajax_send_single_email(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'snn_send_emails')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $name       = sanitize_text_field($_POST['name'] ?? '');
        $email      = sanitize_email($_POST['email'] ?? '');
        $ticket     = sanitize_text_field($_POST['ticket'] ?? '');
        $subject    = wp_unslash($_POST['subject'] ?? '');
        $body_html  = wp_unslash($_POST['body'] ?? '');
        $qr_url     = esc_url_raw($_POST['qr_url'] ?? '');
        $from_name  = sanitize_text_field($_POST['from_name'] ?? '');
        $from_email = sanitize_email($_POST['from_email'] ?? '');

        if (!$email || !$subject || !$body_html || !$ticket || !$qr_url) {
            wp_send_json_error(['message' => 'Missing required fields'], 400);
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($from_email) {
            $from = $from_name ? sprintf('%s <%s>', $from_name, $from_email) : $from_email;
            $headers[] = 'From: ' . $from;
        }

        // Replace placeholders
        $personalized = strtr($body_html, [
            '{name}'   => $name ?: 'Guest',
            '{ticket}' => $ticket,
            '{qr}'     => $qr_url,
        ]);

        $html = '<html><body>' . $personalized . '</body></html>';

        $sent = wp_mail($email, $subject, $html, $headers);

        if ($sent) {
            wp_send_json_success(['message' => 'Email sent', 'qr_url' => $qr_url]);
        } else {
            error_log("SNN Tickets: Failed to send email to $email");
            wp_send_json_error(['message' => 'Failed to send email'], 500);
        }
    }

    public function ajax_upload_qr_image(){
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'snn_send_emails')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $ticket_code = sanitize_text_field($_POST['ticket_code'] ?? '');
        $image_data  = $_POST['image_data'] ?? '';

        if (!$ticket_code || !$image_data) {
            wp_send_json_error(['message' => 'Missing ticket code or image data'], 400);
        }

        // Check if QR already exists
        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/snn-tickets-qr';
        
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }

        $filename = 'qr-' . sanitize_file_name($ticket_code) . '.png';
        $filepath = $qr_dir . '/' . $filename;
        $fileurl = $upload_dir['baseurl'] . '/snn-tickets-qr/' . $filename;

        // If file already exists, return existing URL
        if (file_exists($filepath)) {
            wp_send_json_success(['url' => $fileurl, 'cached' => true]);
        }

        // Decode base64 image data
        if (preg_match('/^data:image\/png;base64,(.+)$/', $image_data, $matches)) {
            $image_binary = base64_decode($matches[1]);
            
            if ($image_binary === false) {
                wp_send_json_error(['message' => 'Invalid base64 data'], 400);
            }

            $result = file_put_contents($filepath, $image_binary);
            
            if ($result === false) {
                wp_send_json_error(['message' => 'Failed to save QR image'], 500);
            }

            wp_send_json_success(['url' => $fileurl, 'cached' => false]);
        } else {
            wp_send_json_error(['message' => 'Invalid image data format'], 400);
        }
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
                if (!data.valid){
                    resultEl.innerHTML = '<div style="color:#b00;">Invalid ticket</div>';
                    return;
                }
                const name = data.name ? data.name : '—';
                const email = data.email ? data.email : '—';
                resultEl.innerHTML = `
                    <div style="color:#0a0; font-weight:600;">Valid ticket</div>
                    <div style="margin-top:6px;">
                        <div><strong>Ticket:</strong> <code>${escapeHtml(data.ticket_code)}</code></div>
                        <div><strong>Name:</strong> ${escapeHtml(name)}</div>
                        <div><strong>Email:</strong> ${escapeHtml(email)}</div>
                        <div><strong>List:</strong> ${escapeHtml(data.list_name || '')}</div>
                        <div><strong>Validated Count:</strong> ${data.validate_count}</div>
                    </div>
                `;
            }

            function escapeHtml(str){
                return (''+str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
            }

            async function validateCode(code){
                try{
                    const form = new FormData();
                    form.append('action', 'snn_validate_ticket');
                    form.append('code', code);
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
                        const code = codes[0].rawValue || (codes[0].rawValue ?? '');
                        await validateCode(code);
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
                        await validateCode(code.data);
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
                await validateCode(val);
                setStatus('Ready.');
            });

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