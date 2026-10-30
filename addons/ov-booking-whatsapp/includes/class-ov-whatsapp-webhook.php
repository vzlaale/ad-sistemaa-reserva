<?php
/**
 * Webhook firmado, deduplicado y correlacionado con la salida de Meta.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) {
    exit;
}

class OV_Booking_WhatsApp_Webhook {

    const HEALTH_OPTION = 'ov_booking_whatsapp_webhook_health';

    private static function outbox_table() {
        global $wpdb;
        return $wpdb->prefix . 'ov_booking_whatsapp_outbox';
    }

    private static function inbox_table() {
        global $wpdb;
        return $wpdb->prefix . 'ov_booking_whatsapp_inbox';
    }

    public static function register_routes() {
        register_rest_route('ov-booking-whatsapp/v1', '/webhook', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'verify'),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'receive'),
                'permission_callback' => '__return_true',
            ),
        ));
    }

    public static function verify(WP_REST_Request $request) {
        $mode = self::query_param($request, 'hub.mode', 'hub_mode');
        $token = self::query_param($request, 'hub.verify_token', 'hub_verify_token');
        $challenge = self::query_param($request, 'hub.challenge', 'hub_challenge');
        $expected = OV_Booking_WhatsApp_Config::secrets()['verify_token'];

        if ($mode === 'subscribe' && $expected !== '' && is_string($token) && hash_equals($expected, $token) &&
            is_scalar($challenge) && preg_match('/^[0-9]{1,100}$/', (string) $challenge)) {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string) $challenge;
            exit;
        }

        return new WP_Error(
            'ov_whatsapp_verification_failed',
            __('No fue posible verificar el webhook.', 'ov-booking-whatsapp'),
            array('status' => 403)
        );
    }

    public static function receive(WP_REST_Request $request) {
        $raw_body = $request->get_body();
        if (!is_string($raw_body) || $raw_body === '' || strlen($raw_body) > 1048576) {
            self::record_post_health('invalid_body', false);
            return new WP_Error('ov_whatsapp_invalid_body', __('Payload no válido.', 'ov-booking-whatsapp'), array('status' => 400));
        }

        $signature = self::signature_header($request);
        if (!self::valid_signature($signature, $raw_body)) {
            self::record_post_health('invalid_signature', $signature !== '');
            return new WP_Error('ov_whatsapp_invalid_signature', __('Firma no válida.', 'ov-booking-whatsapp'), array('status' => 403));
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            self::record_post_health('invalid_json', true);
            return new WP_Error('ov_whatsapp_invalid_json', __('Payload JSON no válido.', 'ov-booking-whatsapp'), array('status' => 400));
        }
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            self::record_post_health('ignored_object', true);
            return new WP_REST_Response(array('success' => true, 'ignored' => true), 200);
        }
        if (!OV_Booking_WhatsApp_Config::is_enabled()) {
            self::record_post_health('module_disabled', true);
            return new WP_REST_Response(array('success' => true, 'disabled' => true), 200);
        }

        self::record_post_health('accepted', true);

        foreach (($payload['entry'] ?? array()) as $entry) {
            if (!is_array($entry)) continue;
            foreach (($entry['changes'] ?? array()) as $change) {
                if (!is_array($change) || ($change['field'] ?? '') !== 'messages' || !is_array($change['value'] ?? null)) continue;
                self::process_value($change['value']);
            }
        }

        return new WP_REST_Response(array('success' => true), 200);
    }

    private static function process_value($value) {
        $secrets = OV_Booking_WhatsApp_Config::secrets();
        $receiver = preg_replace('/\D+/', '', (string) ($value['metadata']['phone_number_id'] ?? ''));
        if ($receiver === '' || !hash_equals($secrets['phone_number_id'], $receiver)) {
            if (!empty($value['messages'])) self::record_button_health('receiver_mismatch');
            return;
        }

        foreach (($value['statuses'] ?? array()) as $status) {
            if (is_array($status)) self::process_status($status);
        }
        foreach (($value['messages'] ?? array()) as $message) {
            if (is_array($message)) self::process_message($message, $receiver);
        }
    }

    private static function process_status($status_event) {
        $message_id = substr(sanitize_text_field($status_event['id'] ?? ''), 0, 191);
        $status = sanitize_key($status_event['status'] ?? '');
        if ($message_id === '' || !in_array($status, array('sent', 'delivered', 'read', 'failed'), true)) {
            return;
        }

        global $wpdb;
        $table = self::outbox_table();
        $item = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE meta_message_id = %s", $message_id));
        if (!$item || $item->status === 'responded') return;

        $now = gmdate('Y-m-d H:i:s');
        if ($status === 'read') {
            $wpdb->update($table, array('status' => 'read', 'delivered_at' => $now, 'read_at' => $now), array('id' => (int) $item->id), array('%s', '%s', '%s'), array('%d'));
        } elseif ($status === 'delivered' && in_array($item->status, array('sending', 'sent'), true)) {
            $wpdb->update($table, array('status' => 'delivered', 'delivered_at' => $now), array('id' => (int) $item->id), array('%s', '%s'), array('%d'));
        } elseif ($status === 'sent' && in_array($item->status, array('sending', 'queued', 'retry'), true)) {
            $wpdb->update($table, array('status' => 'sent', 'sent_at' => $now), array('id' => (int) $item->id), array('%s', '%s'), array('%d'));
        } elseif ($status === 'failed' && !in_array($item->status, array('delivered', 'read'), true)) {
            $error = is_array($status_event['errors'][0] ?? null) ? $status_event['errors'][0] : array();
            $wpdb->update($table, array(
                'status' => 'failed',
                'last_error_code' => substr(sanitize_key((string) ($error['code'] ?? 'delivery_failed')), 0, 100),
                'last_error_message' => OV_Booking_WhatsApp_API::redact_error($error['title'] ?? __('Entrega fallida.', 'ov-booking-whatsapp')),
                'failed_at' => $now,
            ), array('id' => (int) $item->id), array('%s', '%s', '%s', '%s'), array('%d'));
        }
    }

    private static function process_message($message, $receiver) {
        $message_id = substr(sanitize_text_field($message['id'] ?? ''), 0, 191);
        $from = OV_Booking_WhatsApp_API::normalize_phone($message['from'] ?? '');
        $context_id = substr(sanitize_text_field($message['context']['id'] ?? ''), 0, 191);
        $button = self::button_data($message);
        if (!$button['is_button']) {
            return;
        }
        if ($message_id === '' || $context_id === '' || $from === '') {
            self::record_button_health('invalid_button_context');
            return;
        }

        $action = '';
        $token = '';
        if (preg_match('/^(confirmar|anular)_([a-f0-9]{64})$/', $button['payload'], $matches)) {
            $action = $matches[1];
            $token = $matches[2];
        } else {
            foreach (array($button['payload'], $button['label']) as $candidate) {
                $candidate = sanitize_key($candidate);
                if (in_array($candidate, array('confirmar', 'anular'), true)) {
                    $action = $candidate;
                    break;
                }
            }
        }
        if ($action === '') {
            self::record_button_health('button_unrecognized');
            return;
        }

        global $wpdb;
        $outbox_table = self::outbox_table();
        if ($token !== '') {
            $outbox = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$outbox_table} WHERE action_token = %s AND meta_message_id = %s",
                $token,
                $context_id
            ));
        } else {
            $outbox = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$outbox_table} WHERE meta_message_id = %s",
                $context_id
            ));
        }

        $inbox_id = self::insert_inbox($message_id, $context_id, $receiver, $from, $action, $outbox);
        if (!$inbox_id) {
            self::record_button_health('duplicate_or_storage_error');
            return;
        }
        if (!$outbox) {
            self::finish_button($inbox_id, 'context_not_found');
            return;
        }
        if (!empty($outbox->action_expires_at) && strtotime($outbox->action_expires_at . ' UTC') <= time()) {
            self::finish_button($inbox_id, 'expired');
            return;
        }
        $sender_hash = OV_Booking_WhatsApp_API::phone_hash($from);
        if (!hash_equals((string) $outbox->recipient_hash, $sender_hash)) {
            self::finish_button($inbox_id, 'phone_mismatch');
            return;
        }

        $model = new OV_Reservation();
        $reservation = $model->get((int) $outbox->reservation_id);
        if (!$reservation || !hash_equals($sender_hash, OV_Booking_WhatsApp_API::phone_hash($reservation->customer_phone))) {
            self::finish_button($inbox_id, 'reservation_mismatch');
            return;
        }

        $target = $action === 'confirmar' ? 'confirmed' : 'cancelled';
        if ($reservation->status === $target) {
            self::finish_button($inbox_id, 'already_applied');
            return;
        }
        if ($reservation->status !== 'pending' || !$model->transition_status((int) $reservation->id, 'pending', $target)) {
            self::finish_button($inbox_id, 'rejected_state');
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update($outbox_table, array(
            'status' => 'responded',
            'response_action' => $action,
            'responded_at' => $now,
        ), array('id' => (int) $outbox->id), array('%s', '%s', '%s'), array('%d'));
        self::finish_button($inbox_id, 'applied');
    }

    private static function button_payload($message) {
        $button = self::button_data($message);
        return $button['payload'];
    }

    private static function button_data($message) {
        if (($message['type'] ?? '') === 'button' && is_array($message['button'] ?? null)) {
            return array(
                'is_button' => true,
                'payload' => sanitize_text_field($message['button']['payload'] ?? ''),
                'label' => sanitize_text_field($message['button']['text'] ?? ''),
            );
        }
        if (($message['type'] ?? '') === 'interactive' && ($message['interactive']['type'] ?? '') === 'button_reply') {
            return array(
                'is_button' => true,
                'payload' => sanitize_text_field($message['interactive']['button_reply']['id'] ?? ''),
                'label' => sanitize_text_field($message['interactive']['button_reply']['title'] ?? ''),
            );
        }
        return array('is_button' => false, 'payload' => '', 'label' => '');
    }

    private static function insert_inbox($message_id, $context_id, $receiver, $from, $action, $outbox) {
        global $wpdb;
        $inserted = $wpdb->insert(self::inbox_table(), array(
            'message_id' => substr($message_id, 0, 191),
            'context_message_id' => substr($context_id, 0, 191),
            'receiver_phone_number_id' => substr($receiver, 0, 30),
            'sender_hash' => OV_Booking_WhatsApp_API::phone_hash($from),
            'action' => $action,
            'reservation_id' => $outbox ? (int) $outbox->reservation_id : null,
            'outbox_id' => $outbox ? (int) $outbox->id : null,
            'result' => 'received',
        ), array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'));
        return $inserted === 1 ? (int) $wpdb->insert_id : 0;
    }

    private static function finish_inbox($id, $result) {
        global $wpdb;
        $wpdb->update(
            self::inbox_table(),
            array('result' => sanitize_key($result), 'processed_at' => gmdate('Y-m-d H:i:s')),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    private static function finish_button($inbox_id, $result) {
        self::finish_inbox($inbox_id, $result);
        self::record_button_health($result);
    }

    private static function signature_header($request) {
        $signature = $request->get_header('x-hub-signature-256');
        if ((!is_string($signature) || $signature === '') && isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
            $signature = wp_unslash($_SERVER['HTTP_X_HUB_SIGNATURE_256']);
        }
        return is_string($signature) ? trim($signature) : '';
    }

    private static function record_post_health($result, $signature_present) {
        $health = get_option(self::HEALTH_OPTION, array());
        $health = is_array($health) ? $health : array();
        $health['last_post_at'] = gmdate('Y-m-d H:i:s');
        $health['last_post_result'] = sanitize_key($result);
        $health['signature_header_present'] = $signature_present ? 1 : 0;
        update_option(self::HEALTH_OPTION, $health, false);
    }

    private static function record_button_health($result) {
        $health = get_option(self::HEALTH_OPTION, array());
        $health = is_array($health) ? $health : array();
        $health['last_button_at'] = gmdate('Y-m-d H:i:s');
        $health['last_button_result'] = sanitize_key($result);
        update_option(self::HEALTH_OPTION, $health, false);
    }

    private static function valid_signature($signature, $body) {
        $secret = OV_Booking_WhatsApp_Config::secrets()['app_secret'];
        if ($secret === '' || !is_string($signature) || !preg_match('/^sha256=([a-f0-9]{64})$/', $signature, $matches)) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $body, $secret), $matches[1]);
    }

    private static function query_param($request, $primary, $fallback) {
        $value = $request->get_param($primary);
        return ($value === null || $value === '') ? $request->get_param($fallback) : $value;
    }
}
