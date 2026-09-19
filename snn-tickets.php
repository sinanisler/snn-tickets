<?php 
/*
    Plugin Name: SNN Tickets
    Description: Event tickets with server-side QR codes: a visual registration form builder, automatic or rule-based approval, designed emails with PDF, Apple Wallet and Google Wallet tickets, and a mobile door scanner.
    Version: 0.24
    Requires PHP: 8.1
    Author: sinanisler
    Author URI: https://sinanisler.com/
    Text Domain: snn-tickets
    Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

define('SNN_TICKETS_FILE', __FILE__);
define('SNN_TICKETS_DIR', plugin_dir_path(__FILE__));
define('SNN_TICKETS_URL', plugin_dir_url(__FILE__));

require_once SNN_TICKETS_DIR . 'includes/class-snn-db.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-qr.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-tickets.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-events.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-design.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-pdf.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-wallet.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-files.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-mailer.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-forms.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-forms-admin.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-submissions.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-scanner.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-admin.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-tickets-admin.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-dashboard.php';
require_once SNN_TICKETS_DIR . 'includes/class-snn-design-admin.php';

class SNN_Tickets_Plugin {

    private static $instance = null;

    /** Screens reached through tabs rather than their own menu entry. */
    const TAB_PAGES = [
        'snn-tickets-generator'  => 'snn-tickets-lists',
        'snn-tickets-csv-import' => 'snn-tickets-lists',
        'snn-tickets-mailer'     => 'snn-tickets-templates',
        'snn-tickets-queue'      => 'snn-tickets-templates',
    ];

    public static function instance(){
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        register_activation_hook(SNN_TICKETS_FILE,   [$this, 'activate']);
        register_deactivation_hook(SNN_TICKETS_FILE, [$this, 'deactivate']);

        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', ['SNN_T_DB', 'maybe_upgrade']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_filter('submenu_file', [$this, 'submenu_file']);
        add_filter('parent_file', [$this, 'parent_file']);
        add_action('admin_head', ['SNN_T_Admin', 'print_styles']);

        SNN_T_QR::init();
        SNN_T_Mailer::init();
        SNN_T_Files::init();
        SNN_T_Forms::init();
        SNN_T_Forms_Admin::init();
        SNN_T_Submissions::init();
        SNN_T_Scanner::init();
        SNN_T_Admin::init();
        SNN_T_Tickets_Admin::init();
        SNN_T_Design_Admin::init();
    }

    public function load_textdomain(){
        load_plugin_textdomain('snn-tickets', false, dirname(plugin_basename(SNN_TICKETS_FILE)) . '/languages');
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
            __('Tickets', 'snn-tickets'), __('Tickets', 'snn-tickets'), 'manage_options', 'snn-tickets',
            ['SNN_T_Dashboard', 'render'], 'dashicons-tickets', 26
        );

        $pending = SNN_T_Submissions::counts()['pending'];
        $sub_label = __('Submissions', 'snn-tickets')
            . ($pending ? ' <span class="awaiting-mod"><span class="pending-count">' . (int)$pending . '</span></span>' : '');

        $pages = [
            [__('Dashboard', 'snn-tickets'),     __('Dashboard', 'snn-tickets'),         'snn-tickets',             ['SNN_T_Dashboard', 'render']],
            [__('Forms', 'snn-tickets'),         __('Forms', 'snn-tickets'),             'snn-tickets-forms',       ['SNN_T_Forms_Admin', 'render_page']],
            [__('Submissions', 'snn-tickets'),   $sub_label,                             'snn-tickets-submissions', ['SNN_T_Submissions', 'render_page']],
            [__('Tickets', 'snn-tickets'),       __('Events & Tickets', 'snn-tickets'),  'snn-tickets-lists',       ['SNN_T_Tickets_Admin', 'render_lists']],
            [__('Generate tickets', 'snn-tickets'), '',                                  'snn-tickets-generator',   ['SNN_T_Tickets_Admin', 'render_generator']],
            [__('Import CSV', 'snn-tickets'),    '',                                     'snn-tickets-csv-import',  ['SNN_T_Tickets_Admin', 'render_csv_import']],
            [__('Emails', 'snn-tickets'),        __('Emails', 'snn-tickets'),            'snn-tickets-templates',   ['SNN_T_Admin', 'render_templates_page']],
            [__('Send to a list', 'snn-tickets'), '',                                    'snn-tickets-mailer',      ['SNN_T_Tickets_Admin', 'render_mailer']],
            [__('Mail queue', 'snn-tickets'),    '',                                     'snn-tickets-queue',       ['SNN_T_Admin', 'render_queue_page']],
            [__('Design', 'snn-tickets'),        __('Design', 'snn-tickets'),            'snn-tickets-design',      ['SNN_T_Design_Admin', 'render']],
            [__('Settings', 'snn-tickets'),      __('Settings', 'snn-tickets'),          'snn-tickets-settings',    ['SNN_T_Admin', 'render_settings_page']],
        ];

        foreach ($pages as $p) {
            list($title, $label, $slug, $callback) = $p;
            // Tab-only screens are registered without a parent: reachable,
            // but kept out of the sidebar.
            $parent = isset(self::TAB_PAGES[$slug]) ? '' : 'snn-tickets';
            $hook = add_submenu_page($parent, $title, $label !== '' ? $label : $title, 'manage_options', $slug, $callback);

            // WordPress cannot look up the title of a parentless page and
            // would pass null to strip_tags() in the header.
            if ($parent === '' && $hook) {
                add_action('load-' . $hook, function () use ($title) { $GLOBALS['title'] = $title; });
            }
        }
    }

    /** Open the Tickets menu on tab-only screens. */
    public function parent_file($file){
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return isset(self::TAB_PAGES[$page]) ? 'snn-tickets' : $file;
    }

    /** Keep the parent menu item highlighted on tab-only screens. */
    public function submenu_file($file){
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return self::TAB_PAGES[$page] ?? $file;
    }
}

SNN_Tickets_Plugin::instance();
