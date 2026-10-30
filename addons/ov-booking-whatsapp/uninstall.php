<?php
/**
 * Conserva auditoría salvo consentimiento explícito en wp-config.php.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('ov_booking_whatsapp_process_queue');

if (!defined('OV_BOOKING_WHATSAPP_REMOVE_DATA') || OV_BOOKING_WHATSAPP_REMOVE_DATA !== true) {
    return;
}

global $wpdb;
$outbox = $wpdb->prefix . 'ov_booking_whatsapp_outbox';
$inbox = $wpdb->prefix . 'ov_booking_whatsapp_inbox';
$wpdb->query("DROP TABLE IF EXISTS {$inbox}");
$wpdb->query("DROP TABLE IF EXISTS {$outbox}");
delete_option('ov_booking_whatsapp_settings');
delete_option('ov_booking_whatsapp_db_version');
delete_option('ov_booking_whatsapp_webhook_health');
