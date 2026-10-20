<?php
/**
 * Desinstalación segura del plugin
 *
 * Desde la versión 1.0.4.1 este archivo NO elimina tablas ni datos automáticamente.
 * Los servicios, formularios, horarios y reservas se conservan para evitar pérdida de información.
 *
 * @package OV_Booking_System
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Limpieza ligera: solo tareas programadas/transients temporales.
global $wpdb;

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ov_booking_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ov_booking_%'");

wp_clear_scheduled_hook('ov_booking_cleanup_temp_reservations');
wp_clear_scheduled_hook('ov_booking_send_reminders');
wp_clear_scheduled_hook('ov_booking_sync_calendar');
