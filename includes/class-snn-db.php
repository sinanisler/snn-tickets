<?php
/**
 * Table names and schema for SNN Tickets.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_DB {

    const DB_VERSION_OPTION = 'snn_tickets_db_version';
    const DB_VERSION        = '2';

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
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$tickets} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_id BIGINT UNSIGNED NOT NULL,
            submission_id BIGINT UNSIGNED NULL,
            ticket_code VARCHAR(64) NOT NULL,
            name VARCHAR(255) DEFAULT '' NOT NULL,
            email VARCHAR(255) DEFAULT '' NOT NULL,
            status VARCHAR(20) DEFAULT 'active' NOT NULL,
            validate_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_validated DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY list_id (list_id),
            KEY submission_id (submission_id),
            KEY email (email)
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
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }
}
