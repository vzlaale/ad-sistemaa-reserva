<?php
/**
 * Vista: Ajustes
 * 
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) exit;
?>

<div class="wrap ov-booking-admin">
    <h1><?php _e('Ajustes de OV Booking System', 'ov-booking-system'); ?></h1>

    <div class="ov-settings-card">
        <h2><?php _e('Información del Plugin', 'ov-booking-system'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php _e('Versión', 'ov-booking-system'); ?></th>
                <td><?php echo OV_BOOKING_VERSION; ?></td>
            </tr>
            <tr>
                <th><?php _e('Autor', 'ov-booking-system'); ?></th>
                <td>Oscar Viedma - <a href="https://ovdivi.com" target="_blank">ovdivi.com</a></td>
            </tr>
        </table>
    </div>

    <div class="ov-settings-card">
        <h2><?php _e('Documentación', 'ov-booking-system'); ?></h2>
        <p><?php _e('Para más información sobre cómo usar el plugin, visita:', 'ov-booking-system'); ?></p>
        <ul>
            <li><a href="https://ovdivi.com/blog/" target="_blank"><?php _e('Documentación Oficial', 'ov-booking-system'); ?></a></li>
            <li><a href="https://academia.ovdivi.com/mi-cuenta/soporte/" target="_blank"><?php _e('Soporte', 'ov-booking-system'); ?></a></li>
        </ul>
    </div>

    <div class="ov-settings-card">
        <h2><?php _e('Estado del Sistema', 'ov-booking-system'); ?></h2>
        <?php
        global $wpdb;
        $form_model = new OV_Form();
        $forms_count = count($form_model->get_all());
        
        $reservation_model = new OV_Reservation();
        $table_name = $wpdb->prefix . 'ov_booking_reservations';
        $reservations_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        ?>
        <table class="form-table">
            <tr>
                <th><?php _e('Formularios Creados', 'ov-booking-system'); ?></th>
                <td><?php echo $forms_count; ?></td>
            </tr>
            <tr>
                <th><?php _e('Total de Reservas', 'ov-booking-system'); ?></th>
                <td><?php echo $reservations_count; ?></td>
            </tr>
            <tr>
                <th><?php _e('Base de Datos', 'ov-booking-system'); ?></th>
                <td><?php _e('Funcionando correctamente', 'ov-booking-system'); ?> ✓</td>
            </tr>
        </table>
    </div>
</div>
