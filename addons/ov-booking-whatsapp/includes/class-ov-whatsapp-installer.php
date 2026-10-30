<?php
/**
 * Esquema y tareas programadas del add-on.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) {
    exit;
}

class OV_Booking_WhatsApp_Installer {

    const DB_VERSION = '1.0.0';
    const DB_OPTION = 'ov_booking_whatsapp_db_version';

    public static function activate() {
        self::install();
        self::schedule_worker();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $outbox = $wpdb->prefix . 'ov_booking_whatsapp_outbox';
        $inbox = $wpdb->prefix . 'ov_booking_whatsapp_inbox';

        dbDelta("CREATE TABLE {$outbox} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_id BIGINT(20) UNSIGNED NOT NULL,
            recipient_hash CHAR(64) NOT NULL,
            template_name VARCHAR(512) NOT NULL,
            language_code VARCHAR(20) NOT NULL,
            action_token CHAR(64) NOT NULL,
            meta_message_id VARCHAR(191) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            action_expires_at DATETIME NULL,
            last_http_code SMALLINT(5) UNSIGNED NULL,
            last_error_code VARCHAR(100) NULL,
            last_error_message VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            read_at DATETIME NULL,
            response_action VARCHAR(20) NULL,
            responded_at DATETIME NULL,
            failed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY reservation_id (reservation_id),
            UNIQUE KEY action_token (action_token),
            UNIQUE KEY meta_message_id (meta_message_id),
            KEY queue_lookup (status, next_attempt_at),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$inbox} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id VARCHAR(191) NOT NULL,
            context_message_id VARCHAR(191) NOT NULL,
            receiver_phone_number_id VARCHAR(30) NOT NULL,
            sender_hash CHAR(64) NOT NULL,
            action VARCHAR(20) NOT NULL,
            reservation_id BIGINT(20) UNSIGNED NULL,
            outbox_id BIGINT(20) UNSIGNED NULL,
            result VARCHAR(40) NOT NULL DEFAULT 'received',
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY message_id (message_id),
            KEY reservation_id (reservation_id),
            KEY outbox_id (outbox_id),
            KEY received_at (received_at)
        ) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function maybe_update() {
        $installed = get_option(self::DB_OPTION, '0.0.0');
        if (version_compare($installed, self::DB_VERSION, '<')) {
            self::install();
        }
    }

    public static function add_cron_interval($schedules) {
        if (!isset($schedules['ov_whatsapp_five_minutes'])) {
            $schedules['ov_whatsapp_five_minutes'] = array(
                'interval' => 300,
                'display' => __('Cada cinco minutos (OV WhatsApp)', 'ov-booking-whatsapp'),
            );
        }
        return $schedules;
    }

    public static function schedule_worker() {
        if (!wp_next_scheduled('ov_booking_whatsapp_process_queue')) {
            wp_schedule_event(time() + 60, 'ov_whatsapp_five_minutes', 'ov_booking_whatsapp_process_queue');
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('ov_booking_whatsapp_process_queue');
    }
}
