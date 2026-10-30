<?php
/**
 * Configuración segura del add-on.
 *
 * Los secretos se leen exclusivamente desde constantes de wp-config.php.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) {
    exit;
}

class OV_Booking_WhatsApp_Config {

    const OPTION = 'ov_booking_whatsapp_settings';

    public static function secrets() {
        return array(
            'access_token' => self::constant_value('OV_BOOKING_WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => self::constant_value('OV_BOOKING_WHATSAPP_PHONE_NUMBER_ID'),
            'verify_token' => self::constant_value('OV_BOOKING_WHATSAPP_VERIFY_TOKEN'),
            'app_secret' => self::constant_value('OV_BOOKING_WHATSAPP_APP_SECRET'),
            'api_version' => self::constant_value('OV_BOOKING_WHATSAPP_API_VERSION'),
        );
    }

    private static function constant_value($name) {
        if (!defined($name)) {
            return '';
        }
        $value = constant($name);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function defaults() {
        return array(
            'enabled' => 0,
            'template_name' => 'confirmacion_de_reserva',
            'language_code' => 'es',
            'business_name' => get_bloginfo('name'),
            'confirm_button_index' => 0,
            'cancel_button_index' => 1,
            'send_admin_bookings' => 0,
        );
    }

    public static function get() {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
    }

    public static function sanitize($input) {
        $input = is_array($input) ? $input : array();
        $template = strtolower(sanitize_key($input['template_name'] ?? ''));
        $language = sanitize_text_field($input['language_code'] ?? '');
        $business = sanitize_text_field($input['business_name'] ?? '');
        $confirm_index = isset($input['confirm_button_index']) ? absint($input['confirm_button_index']) : 0;
        $cancel_index = isset($input['cancel_button_index']) ? absint($input['cancel_button_index']) : 1;

        if (!preg_match('/^[a-z0-9_]{1,512}$/', $template)) {
            $template = '';
        }
        if (!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $language)) {
            $language = '';
        }

        return array(
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'template_name' => $template,
            'language_code' => $language,
            'business_name' => substr($business, 0, 190),
            'confirm_button_index' => min(9, $confirm_index),
            'cancel_button_index' => min(9, $cancel_index),
            'send_admin_bookings' => !empty($input['send_admin_bookings']) ? 1 : 0,
        );
    }

    public static function validation_errors() {
        $errors = array();
        $secrets = self::secrets();
        $settings = self::get();

        if ($secrets['access_token'] === '') $errors[] = 'OV_BOOKING_WHATSAPP_ACCESS_TOKEN';
        if (!preg_match('/^[0-9]{5,30}$/', $secrets['phone_number_id'])) $errors[] = 'OV_BOOKING_WHATSAPP_PHONE_NUMBER_ID';
        if (strlen($secrets['verify_token']) < 32) $errors[] = 'OV_BOOKING_WHATSAPP_VERIFY_TOKEN';
        if (strlen($secrets['app_secret']) < 16) $errors[] = 'OV_BOOKING_WHATSAPP_APP_SECRET';
        if (!preg_match('/^v[0-9]{1,3}\.[0-9]{1,2}$/', $secrets['api_version'])) $errors[] = 'OV_BOOKING_WHATSAPP_API_VERSION';
        if ($settings['template_name'] === '') $errors[] = __('Nombre de plantilla', 'ov-booking-whatsapp');
        if ($settings['language_code'] === '') $errors[] = __('Idioma de plantilla', 'ov-booking-whatsapp');
        if ($settings['business_name'] === '') $errors[] = __('Nombre del negocio', 'ov-booking-whatsapp');
        if ((int) $settings['confirm_button_index'] === (int) $settings['cancel_button_index']) {
            $errors[] = __('Los índices de los botones deben ser distintos', 'ov-booking-whatsapp');
        }

        return $errors;
    }

    public static function is_enabled() {
        $settings = self::get();
        return !empty($settings['enabled']);
    }

    public static function is_ready() {
        return self::is_enabled() && empty(self::validation_errors());
    }
}
