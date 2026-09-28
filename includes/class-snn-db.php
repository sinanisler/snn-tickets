<?php
/**
 * Table names and schema for SNN Tickets.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_DB {

    const DB_VERSION_OPTION = 'snn_tickets_db_version';
    const DB_VERSION        = '6';

    public static function lists() {
        global $wpdb;
        return $wpdb->prefix . 'snn_ticket_lists';
    }

    public static function tickets() {
        global $wpdb;
        return $wpdb->prefix . 'snn_tickets';
    }

    public static function forms() {
        global $wpdb;
        return $wpdb->prefix . 'snn_ticket_forms';
    }

    public static function submissions() {
        global $wpdb;
        return $wpdb->prefix . 'snn_ticket_submissions';
    }

    public static function queue() {
        global $wpdb;
        return $wpdb->prefix . 'snn_ticket_mail_queue';
    }

    /**
     * Create or update every table. Safe to call repeatedly.
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $lists       = self::lists();
        $tickets     = self::tickets();
        $forms       = self::forms();
        $submissions = self::submissions();
        $queue       = self::queue();

        dbDelta("CREATE TABLE {$lists} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(200) DEFAULT '' NOT NULL,
            event_start DATETIME NULL,
            event_end DATETIME NULL,
            venue VARCHAR(255) DEFAULT '' NOT NULL,
            address VARCHAR(255) DEFAULT '' NOT NULL,
            organizer VARCHAR(255) DEFAULT '' NOT NULL,
            description TEXT NULL,
            design VARCHAR(40) DEFAULT '' NOT NULL,
            attachments VARCHAR(100) DEFAULT '' NOT NULL,
            emails LONGTEXT NULL,
            require_names TINYINT(1) DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY slug (slug)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$tickets} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            submission_id BIGINT UNSIGNED NULL,
            ticket_code VARCHAR(64) NOT NULL,
            name VARCHAR(255) DEFAULT '' NOT NULL,
            email VARCHAR(255) DEFAULT '' NOT NULL,
            status VARCHAR(20) DEFAULT 'active' NOT NULL,
            source VARCHAR(40) DEFAULT '' NOT NULL,
            note TEXT NULL,
            validate_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_validated DATETIME NULL,
            order_id BIGINT UNSIGNED NULL,
            order_item_id BIGINT UNSIGNED NULL,
            product_id BIGINT UNSIGNED NULL,
            holder VARCHAR(10) DEFAULT '' NOT NULL,
            claim_key VARCHAR(64) DEFAULT '' NOT NULL,
            claim_email VARCHAR(255) DEFAULT '' NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY claim_key (claim_key),
            KEY list_id (list_id),
            KEY submission_id (submission_id),
            KEY email (email),
            KEY last_validated (last_validated)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$forms} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            list_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) DEFAULT 'active' NOT NULL,
            fields LONGTEXT NULL,
            settings LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY list_id (list_id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$submissions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            ticket_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) DEFAULT 'pending' NOT NULL,
            name VARCHAR(255) DEFAULT '' NOT NULL,
            email VARCHAR(255) DEFAULT '' NOT NULL,
            data LONGTEXT NULL,
            decision_reason TEXT NULL,
            ip VARCHAR(100) DEFAULT '' NOT NULL,
            created_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY email (email),
            KEY ticket_id (ticket_id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NULL,
            submission_id BIGINT UNSIGNED NULL,
            role VARCHAR(32) DEFAULT 'ticket' NOT NULL,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) DEFAULT '' NOT NULL,
            subject TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            attach_qr TINYINT(1) DEFAULT 0 NOT NULL,
            attachments VARCHAR(100) DEFAULT '' NOT NULL,
            ticket_code VARCHAR(64) DEFAULT '' NOT NULL,
            status VARCHAR(20) DEFAULT 'pending' NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            scheduled_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status_scheduled (status, scheduled_at),
            KEY ticket_id (ticket_id),
            KEY submission_id (submission_id)
        ) {$charset_collate};");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Re-run install() when the plugin's schema version moves on. Plugin
     * updates do not fire the activation hook, so this runs on admin_init.
     */
    public static function maybe_upgrade() {
        $from = (string)get_option(self::DB_VERSION_OPTION);
        if ($from !== self::DB_VERSION) {
            self::install();
            if ($from !== '' && version_compare($from, '4', '<')) self::migrate_to_4();
        }
    }

    /**
     * Version 4 turns each ticket list into a self-contained event: it gets a
     * URL slug and its own emails (copied from the form that fed it), and
     * every ticket records where it came from.
     */
    public static function migrate_to_4() {
        global $wpdb;
        $lists   = self::lists();
        $tickets = self::tickets();

        foreach ($wpdb->get_results("SELECT id, name, slug, emails FROM {$lists}") as $l) {
            $row = [];
            if ((string)$l->slug === '') $row['slug'] = SNN_T_Events::unique_slug($l->name, (int)$l->id);
            if ((string)$l->emails === '') {
                $form = SNN_T_Forms::for_list((int)$l->id);
                $row['emails'] = wp_json_encode(SNN_T_Events::emails_from_form($form));
            }
            if ($row) $wpdb->update($lists, $row, ['id' => (int)$l->id]);
        }

        $wpdb->query("UPDATE {$tickets} SET source = 'form' WHERE source = '' AND submission_id IS NOT NULL AND submission_id > 0");
        $wpdb->query("UPDATE {$tickets} SET source = 'blank' WHERE source = '' AND name = '' AND email = ''");
        $wpdb->query("UPDATE {$tickets} SET source = 'import' WHERE source = ''");

        // QR codes now point at the built-in door page.
        if (class_exists('SNN_T_QR')) SNN_T_QR::flush_cache();
        delete_option('snn_tickets_rewrite_ver');
    }
}
