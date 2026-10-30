<?php
/**
 * Cliente mínimo de WhatsApp Cloud API.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) {
    exit;
}

class OV_Booking_WhatsApp_API {

    public static function send_confirmation_template($recipient, $parameters, $confirm_payload, $cancel_payload, $template_name = null, $language_code = null) {
        $secrets = OV_Booking_WhatsApp_Config::secrets();
        $settings = OV_Booking_WhatsApp_Config::get();
        $recipient = self::normalize_phone($recipient);

        if (!OV_Booking_WhatsApp_Config::is_ready()) {
            return new WP_Error('ov_whatsapp_not_configured', __('WhatsApp no está configurado completamente.', 'ov-booking-whatsapp'));
        }
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $recipient)) {
            return new WP_Error('ov_whatsapp_invalid_recipient', __('El teléfono destinatario no tiene formato internacional válido.', 'ov-booking-whatsapp'));
        }
        if (!is_array($parameters) || count($parameters) !== 5) {
            return new WP_Error('ov_whatsapp_invalid_parameters', __('La plantilla de confirmación requiere exactamente cinco variables.', 'ov-booking-whatsapp'));
        }
        if (!self::valid_button_payload($confirm_payload) || !self::valid_button_payload($cancel_payload)) {
            return new WP_Error('ov_whatsapp_invalid_button_payload', __('El identificador de los botones no es válido.', 'ov-booking-whatsapp'));
        }

        $template_name = $template_name === null ? $settings['template_name'] : strtolower(sanitize_key($template_name));
        $language_code = $language_code === null ? $settings['language_code'] : sanitize_text_field($language_code);
        if ($template_name === '' || !preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $language_code)) {
            return new WP_Error('ov_whatsapp_invalid_template_config', __('La plantilla o su idioma no son válidos.', 'ov-booking-whatsapp'));
        }

        $body_parameters = array();
        foreach ($parameters as $parameter) {
            $text = trim(wp_strip_all_tags((string) $parameter));
            if ($text === '') {
                return new WP_Error('ov_whatsapp_empty_parameter', __('Ninguna variable de la plantilla puede quedar vacía.', 'ov-booking-whatsapp'));
            }
            $body_parameters[] = array('type' => 'text', 'text' => substr($text, 0, 1024));
        }

        $components = array(
            array('type' => 'body', 'parameters' => $body_parameters),
            array(
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) (int) $settings['confirm_button_index'],
                'parameters' => array(array('type' => 'payload', 'payload' => $confirm_payload)),
            ),
            array(
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) (int) $settings['cancel_button_index'],
                'parameters' => array(array('type' => 'payload', 'payload' => $cancel_payload)),
            ),
        );

        $payload = array(
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => array(
                'name' => $template_name,
                'language' => array('code' => $language_code),
                'components' => $components,
            ),
        );

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            rawurlencode($secrets['api_version']),
            rawurlencode($secrets['phone_number_id'])
        );
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'redirection' => 0,
            'headers' => array(
                'Authorization' => 'Bearer ' . $secrets['access_token'],
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return new WP_Error(
                'ov_whatsapp_transport_error',
                self::redact_error($response->get_error_message()),
                array('retryable' => false, 'uncertain' => true, 'http_code' => 0)
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($http_code >= 200 && $http_code < 300 && is_array($decoded) && !empty($decoded['messages'][0]['id'])) {
            return array(
                'message_id' => substr(sanitize_text_field($decoded['messages'][0]['id']), 0, 191),
                'http_code' => $http_code,
            );
        }

        $meta_code = is_array($decoded) && isset($decoded['error']['code'])
            ? sanitize_text_field((string) $decoded['error']['code'])
            : 'unknown';
        $message = is_array($decoded) && isset($decoded['error']['message'])
            ? $decoded['error']['message']
            : __('Meta devolvió una respuesta no válida.', 'ov-booking-whatsapp');
        $uncertain = $http_code === 0 || $http_code === 408 || $http_code >= 500 ||
            ($http_code >= 200 && $http_code < 300);
        $retryable = $http_code === 429;

        return new WP_Error(
            'ov_whatsapp_meta_' . sanitize_key($meta_code),
            self::redact_error($message),
            array('retryable' => $retryable, 'uncertain' => $uncertain, 'http_code' => $http_code, 'meta_code' => $meta_code)
        );
    }

    public static function normalize_phone($phone) {
        if (class_exists('OV_Validation')) {
            return OV_Validation::normalize_phone($phone);
        }
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($phone) === 9 && strpos($phone, '9') === 0) {
            $phone = '56' . $phone;
        }
        return $phone;
    }

    public static function phone_hash($phone) {
        return hash_hmac('sha256', self::normalize_phone($phone), wp_salt('auth'));
    }

    private static function valid_button_payload($payload) {
        return is_string($payload) && strlen($payload) <= 128 && preg_match('/^[a-z0-9_]+$/', $payload);
    }

    public static function redact_error($message) {
        $message = sanitize_text_field((string) $message);
        $message = preg_replace('/[A-Za-z0-9_\-]{40,}/', '[redacted]', $message);
        $message = preg_replace('/\b[0-9]{6,}\b/', '[redacted]', $message);
        return substr($message, 0, 500);
    }
}
