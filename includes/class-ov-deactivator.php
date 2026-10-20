<?php
/**
 * Desactivación del plugin
 * 
 * @package OV_Booking_System
 */

class OV_Deactivator {

    /**
     * Acciones durante la desactivación del plugin
     */
    public static function deactivate() {
        // Limpiar rewrite rules
        flush_rewrite_rules();
        
        // Limpiar eventos programados si los hay
        wp_clear_scheduled_hook('ov_booking_cleanup_temp_reservations');
        wp_clear_scheduled_hook('ov_booking_send_reminders');
    }
}
