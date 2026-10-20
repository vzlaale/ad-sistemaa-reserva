<?php
/**
 * Funcionalidad del área pública
 * 
 * @package OV_Booking_System
 */

class OV_Public {

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Registrar estilos del frontend
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            OV_BOOKING_PLUGIN_URL . 'public/assets/css/ov-booking.css',
            array(),
            OV_BOOKING_VERSION, // Versión dinámica
            'all'
        );

        // Font Awesome - solo si no está ya cargado
        if (!wp_style_is('font-awesome', 'enqueued') && !wp_style_is('fontawesome', 'enqueued')) {
            wp_enqueue_style(
                'ov-font-awesome',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
                array(),
                '6.5.1'
            );
        }
    }

    /**
     * Registrar scripts del frontend
     */
    public function enqueue_scripts() {
        // Script principal
        wp_enqueue_script(
            $this->plugin_name,
            OV_BOOKING_PLUGIN_URL . 'public/assets/js/ov-booking.js',
            array('jquery'),
            OV_BOOKING_VERSION, // Versión dinámica
            true
        );

        // Pasar datos traducibles al JavaScript según el idioma activo de WordPress.
        $i18n_data = class_exists('OV_i18n') ? OV_i18n::get_frontend_data() : array('locale' => get_locale(), 'language' => 'es', 'strings' => array());

        wp_localize_script(
            $this->plugin_name,
            'ovBooking',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ov_booking_nonce'),
                'locale' => $i18n_data['locale'],
                'language' => $i18n_data['language'],
                'strings' => $i18n_data['strings'],
            )
        );
    }
}
