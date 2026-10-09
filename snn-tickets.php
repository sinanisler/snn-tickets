<?php
/*
    Plugin Name: Events & Tickets
    Description: Event tickets with server-side QR codes: a guided event setup, sign-up forms with automatic or rule-based approval, designed emails with PDF, Apple Wallet and Google Wallet tickets, and a mobile door scanner.
    Version: 0.31
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
define('SNN_TICKETS_VERSION', '0.31');

foreach ([
    'db', 'texts', 'qr', 'tickets', 'claims', 'events', 'design', 'pdf', 'wallet', 'files', 'mailer', 'forms', 'submissions',
    'people', 'router', 'scanner', 'admin', 'dashboard', 'events-admin', 'wizard', 'settings', 'woo', 'woo-admin',
] as $class) {
    require_once SNN_TICKETS_DIR . 'includes/class-snn-' . $class . '.php';
}

class SNN_Tickets_Plugin {

    private static $instance = null;

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
        add_filter('plugin_action_links_' . plugin_basename(SNN_TICKETS_FILE), [$this, 'action_links']);

        SNN_T_QR::init();
        SNN_T_Mailer::init();
        SNN_T_Files::init();
        SNN_T_Forms::init();
        SNN_T_Router::init();
        SNN_T_Scanner::init();
        SNN_T_Admin::init();
        SNN_T_Events_Admin::init();
        SNN_T_Wizard::init();
        SNN_T_Settings::init();
        SNN_T_Woo::boot();
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

        // Built-in event links need their rewrite rules.
        SNN_T_Router::rewrites();
        flush_rewrite_rules(false);
    }

    public function deactivate(){
        SNN_T_Mailer::deactivate();
        flush_rewrite_rules(false);
    }

    /** "Events | Settings" under the plugin's name on the Plugins screen. */
    public function action_links($links){
        array_unshift($links,
            '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-events')) . '">' . esc_html__('Events', 'snn-tickets') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=snn-tickets-settings')) . '">' . esc_html__('Settings', 'snn-tickets') . '</a>');
        return $links;
    }

    public function admin_menu(){
        $cap = SNN_T_Tickets::cap();
        $waiting = SNN_T_Submissions::counts()['pending'];
        $badge   = $waiting ? ' <span class="awaiting-mod"><span class="pending-count">' . (int)$waiting . '</span></span>' : '';

        add_menu_page(__('Events & Tickets', 'snn-tickets'), __('Events & Tickets', 'snn-tickets') . $badge, $cap, 'snn-tickets',
            ['SNN_T_Dashboard', 'render'], 'dashicons-tickets-alt', 26);

        add_submenu_page('snn-tickets', __('Events & Tickets', 'snn-tickets'), __('Home', 'snn-tickets'), $cap, 'snn-tickets', ['SNN_T_Dashboard', 'render']);
        add_submenu_page('snn-tickets', __('Events', 'snn-tickets'), __('Events', 'snn-tickets') . $badge, $cap, 'snn-tickets-events', ['SNN_T_Events_Admin', 'render_page']);
        add_submenu_page('snn-tickets', __('Add New Event', 'snn-tickets'), __('Add New Event', 'snn-tickets'), $cap, 'snn-tickets-new', ['SNN_T_Wizard', 'render']);
        $failed = SNN_T_Mailer::queue_counts()['failed'];
        add_submenu_page('snn-tickets', __('Emails', 'snn-tickets'), __('Emails', 'snn-tickets') . ($failed ? ' <span class="awaiting-mod"><span class="pending-count">' . (int)$failed . '</span></span>' : ''), $cap, 'snn-tickets-emails', ['SNN_T_Settings', 'render']);
        add_submenu_page('snn-tickets', __('Tickets Settings', 'snn-tickets'), __('Settings', 'snn-tickets'), $cap, 'snn-tickets-settings', ['SNN_T_Settings', 'render']);

        // Old screens stay reachable so bookmarks redirect to their new home.
        foreach (SNN_T_Admin::LEGACY as $slug) {
            add_submenu_page('', __('Tickets', 'snn-tickets'), '', $cap, $slug, '__return_null');
        }
    }
}

SNN_Tickets_Plugin::instance();
