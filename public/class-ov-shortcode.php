<?php
/**
 * Clase Shortcode
 * 
 * @package OV_Booking_System
 */

class OV_Shortcode {

    /**
     * Renderizar formulario de reserva
     * 
     * [ov_booking id="1"]
     */
    public function render_booking_form($atts) {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts, 'ov_booking');

        $form_id = intval($atts['id']);

        if (!$form_id) {
            return '<p>' . __('ID de formulario no válido', 'ov-booking-system') . '</p>';
        }

        // Verificar que el formulario existe
        $form_model = new OV_Form();
        $form = $form_model->get($form_id);

        if (!$form || $form->status !== 'active') {
            return '<p>' . __('Formulario no disponible', 'ov-booking-system') . '</p>';
        }

        // Generar HTML del formulario
        ob_start();
        include OV_BOOKING_PLUGIN_DIR . 'public/templates/booking-container.php';
        return ob_get_clean();
    }
}
