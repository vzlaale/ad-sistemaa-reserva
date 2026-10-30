<?php
/**
 * Cola persistente y worker de envíos.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) {
    exit;
}

class OV_Booking_WhatsApp_Outbox {

    const MAX_ATTEMPTS = 5;

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ov_booking_whatsapp_outbox';
    }

    public static function enqueue_reservation($reservation_id, $reservation_data = array()) {
        if (!OV_Booking_WhatsApp_Config::is_enabled() || !class_exists('OV_Reservation')) {
            return false;
        }

        $settings = OV_Booking_WhatsApp_Config::get();
        $source = is_array($reservation_data) ? ($reservation_data['source'] ?? 'public') : 'public';
        if ($source === 'admin' && empty($settings['send_admin_bookings'])) {
            return false;
        }

        $reservation = (new OV_Reservation())->get((int) $reservation_id);
        if (!$reservation || $reservation->status !== 'pending') {
            return false;
        }
        $phone = OV_Booking_WhatsApp_API::normalize_phone($reservation->customer_phone);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
            return false;
        }

        $token = self::generate_token();
        if ($token === '') {
            return false;
        }

        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::table() . "
             (reservation_id, recipient_hash, template_name, language_code, action_token, status, attempts, next_attempt_at, action_expires_at)
             VALUES (%d, %s, %s, %s, %s, 'queued', 0, UTC_TIMESTAMP(), %s)",
            (int) $reservation_id,
            OV_Booking_WhatsApp_API::phone_hash($phone),
            $settings['template_name'],
            $settings['language_code'],
            $token,
            self::action_expiry($reservation)
        ));

        if ($inserted === 1) {
            self::schedule_immediate_worker();
            return true;
        }
        return false;
    }

    /** Revocar botones antiguos y enviar una plantilla nueva si cambia la cita. */
    public static function reservation_updated($reservation_id, $updated_fields, $previous = null) {
        if (!is_array($updated_fields)) return;
        $relevant = array('booking_date', 'booking_time', 'customer_name', 'customer_phone', 'service_id', 'professional_id', 'status');
        $has_relevant_field = false;
        foreach ($relevant as $field) {
            if (array_key_exists($field, $updated_fields)) {
                $has_relevant_field = true;
                break;
            }
        }
        if (!$has_relevant_field) return;

        $reservation = (new OV_Reservation())->get((int) $reservation_id);
        $changed = false;
        foreach ($relevant as $field) {
            if (!array_key_exists($field, $updated_fields)) continue;
            if (!is_object($previous) || !$reservation) {
                // transition_status() emite el evento sin snapshot anterior.
                $changed = true;
                break;
            }
            $old_value = (string) ($previous->$field ?? '');
            $new_value = (string) ($reservation->$field ?? '');
            if ($field === 'booking_time') {
                // El editor puede guardar 10:00 sobre un valor previo 10:00:00.
                $old_value = substr($old_value, 0, 5);
                $new_value = substr($new_value, 0, 5);
            }
            if ($old_value !== $new_value) {
                $changed = true;
                break;
            }
        }
        if (!$changed) return;

        global $wpdb;
        $table = self::table();
        $item = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE reservation_id = %d", (int) $reservation_id));
        if (!$item) return;

        if (!$reservation || $reservation->status !== 'pending') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET action_expires_at = UTC_TIMESTAMP(),
                 status = IF(status IN ('queued', 'retry', 'sending'), 'discarded', status),
                 next_attempt_at = NULL WHERE reservation_id = %d",
                (int) $reservation_id
            ));
            return;
        }

        $phone = OV_Booking_WhatsApp_API::normalize_phone($reservation->customer_phone);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
            self::discard_reservation((int) $reservation_id, 'recipient_invalid_after_update');
            return;
        }
        $token = self::generate_token();
        if ($token === '') {
            self::discard_reservation((int) $reservation_id, 'token_generation_failed');
            return;
        }
        $settings = OV_Booking_WhatsApp_Config::get();
        $updated = $wpdb->update($table, array(
            'recipient_hash' => OV_Booking_WhatsApp_API::phone_hash($phone),
            'template_name' => $settings['template_name'],
            'language_code' => $settings['language_code'],
            'action_token' => $token,
            'meta_message_id' => null,
            'status' => 'queued',
            'attempts' => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'action_expires_at' => self::action_expiry($reservation),
            'last_http_code' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'response_action' => null,
            'responded_at' => null,
            'failed_at' => null,
        ), array('id' => (int) $item->id));
        if ($updated !== false && OV_Booking_WhatsApp_Config::is_ready()) {
            self::schedule_immediate_worker();
        } elseif ($updated === false) {
            self::discard_reservation((int) $reservation_id, 'refresh_failed');
        }
    }

    public static function process_queue($mode = '') {
        if (!OV_Booking_WhatsApp_Config::is_ready() || !class_exists('OV_Reservation')) {
            return;
        }

        global $wpdb;
        $table = self::table();
        $wpdb->query(
            "UPDATE {$table} SET status = 'uncertain', next_attempt_at = NULL,
             last_error_code = 'send_result_unknown'
             WHERE status = 'sending' AND updated_at < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)"
        );

        $ids = $wpdb->get_col(
            "SELECT id FROM {$table}
             WHERE status IN ('queued', 'retry')
             AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
             AND attempts < " . self::MAX_ATTEMPTS . "
             ORDER BY id ASC LIMIT 10"
        );

        foreach ($ids as $id) {
            self::process_item((int) $id);
        }
    }

    public static function requeue($id) {
        global $wpdb;
        $table = self::table();
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id));
        if (!$item || !in_array($item->status, array('failed', 'discarded', 'uncertain'), true)) {
            return new WP_Error('not_retryable', __('Este envío no se puede reencolar.', 'ov-booking-whatsapp'));
        }
        $reservation = (new OV_Reservation())->get((int) $item->reservation_id);
        if (!$reservation || $reservation->status !== 'pending') {
            return new WP_Error('reservation_not_pending', __('La reserva ya no está pendiente.', 'ov-booking-whatsapp'));
        }
        $phone = OV_Booking_WhatsApp_API::normalize_phone($reservation->customer_phone);
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
            return new WP_Error('invalid_phone', __('El teléfono de la reserva no es válido.', 'ov-booking-whatsapp'));
        }
        $token = self::generate_token();
        if ($token === '') {
            return new WP_Error('token_failed', __('No fue posible generar un token seguro.', 'ov-booking-whatsapp'));
        }

        $settings = OV_Booking_WhatsApp_Config::get();
        $updated = $wpdb->update($table, array(
            'recipient_hash' => OV_Booking_WhatsApp_API::phone_hash($phone),
            'template_name' => $settings['template_name'],
            'language_code' => $settings['language_code'],
            'action_token' => $token,
            'meta_message_id' => null,
            'status' => 'queued',
            'attempts' => 0,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'action_expires_at' => self::action_expiry($reservation),
            'last_http_code' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'response_action' => null,
            'responded_at' => null,
            'failed_at' => null,
        ), array('id' => (int) $id));
        if ($updated === false) {
            return new WP_Error('requeue_failed', __('No fue posible reencolar el envío.', 'ov-booking-whatsapp'));
        }
        self::schedule_immediate_worker();
        return true;
    }

    private static function process_item($id) {
        global $wpdb;
        $table = self::table();
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'sending', attempts = attempts + 1
             WHERE id = %d AND status IN ('queued', 'retry')
             AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())",
            $id
        ));
        if ($claimed !== 1) {
            return;
        }

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$item || $item->status !== 'sending') {
            return;
        }
        $reservation = $item ? (new OV_Reservation())->get((int) $item->reservation_id) : null;
        if (!$item || !$reservation || $reservation->status !== 'pending') {
            self::mark_discarded($id, 'reservation_unavailable', $item ? $item->action_token : null);
            return;
        }
        if (!empty($item->action_expires_at) && strtotime($item->action_expires_at . ' UTC') <= time()) {
            self::mark_discarded($id, 'reservation_expired', $item->action_token);
            return;
        }

        $phone = OV_Booking_WhatsApp_API::normalize_phone($reservation->customer_phone);
        if (!hash_equals((string) $item->recipient_hash, OV_Booking_WhatsApp_API::phone_hash($phone))) {
            self::mark_discarded($id, 'recipient_changed', $item->action_token);
            return;
        }

        $parameters = self::template_parameters($reservation);
        if (is_wp_error($parameters)) {
            self::mark_failed($item, $parameters, false);
            return;
        }

        $result = OV_Booking_WhatsApp_API::send_confirmation_template(
            $phone,
            $parameters,
            'confirmar_' . $item->action_token,
            'anular_' . $item->action_token,
            $item->template_name,
            $item->language_code
        );
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            if (is_array($data) && !empty($data['uncertain'])) {
                self::mark_uncertain($item, $result);
                return;
            }
            self::mark_failed($item, $result, is_array($data) && !empty($data['retryable']));
            return;
        }

        $wpdb->update(
            $table,
            array(
                'meta_message_id' => $result['message_id'],
                'status' => 'sent',
                'last_http_code' => (int) $result['http_code'],
                'last_error_code' => null,
                'last_error_message' => null,
                'sent_at' => gmdate('Y-m-d H:i:s'),
                'next_attempt_at' => null,
            ),
            array('id' => $id, 'status' => 'sending', 'action_token' => $item->action_token),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d', '%s', '%s')
        );
    }

    private static function template_parameters($reservation) {
        $form = (new OV_Form())->get((int) $reservation->form_id);
        $service = (new OV_Service())->get((int) $reservation->service_id);
        if (!$form || !$service) {
            return new WP_Error('booking_context_missing', __('Falta el formulario o servicio de la reserva.', 'ov-booking-whatsapp'));
        }

        try {
            $timezone = new DateTimeZone(!empty($form->timezone) ? $form->timezone : 'UTC');
            $date = new DateTimeImmutable($reservation->booking_date . ' ' . $reservation->booking_time, $timezone);
        } catch (Exception $e) {
            return new WP_Error('booking_datetime_invalid', __('La fecha de la reserva no es válida.', 'ov-booking-whatsapp'));
        }

        $settings = OV_Booking_WhatsApp_Config::get();
        $name_parts = preg_split('/\s+/', trim((string) $reservation->customer_name));
        $first_name = !empty($name_parts[0]) ? $name_parts[0] : __('Cliente', 'ov-booking-whatsapp');

        return array(
            $first_name,
            $settings['business_name'] !== '' ? $settings['business_name'] : get_bloginfo('name'),
            $service->name,
            wp_date(get_option('date_format'), $date->getTimestamp(), $timezone),
            wp_date(get_option('time_format'), $date->getTimestamp(), $timezone),
        );
    }

    private static function mark_failed($item, $error, $retryable) {
        global $wpdb;
        $data = $error->get_error_data();
        $attempts = (int) $item->attempts;
        $retry = $retryable && $attempts < self::MAX_ATTEMPTS;
        $delay = self::retry_delay($attempts);
        $wpdb->update(
            self::table(),
            array(
                'status' => $retry ? 'retry' : 'failed',
                'next_attempt_at' => $retry ? gmdate('Y-m-d H:i:s', time() + $delay) : null,
                'last_http_code' => is_array($data) ? (int) ($data['http_code'] ?? 0) : 0,
                'last_error_code' => substr(sanitize_key($error->get_error_code()), 0, 100),
                'last_error_message' => OV_Booking_WhatsApp_API::redact_error($error->get_error_message()),
                'failed_at' => $retry ? null : gmdate('Y-m-d H:i:s'),
            ),
            array('id' => (int) $item->id, 'status' => 'sending', 'action_token' => $item->action_token),
            array('%s', '%s', '%d', '%s', '%s', '%s'),
            array('%d', '%s', '%s')
        );
    }

    /** Un resultado incierto necesita revisión humana para evitar duplicados. */
    private static function mark_uncertain($item, $error) {
        global $wpdb;
        $data = $error->get_error_data();
        $wpdb->update(self::table(), array(
            'status' => 'uncertain',
            'next_attempt_at' => null,
            'last_http_code' => is_array($data) ? (int) ($data['http_code'] ?? 0) : 0,
            'last_error_code' => substr(sanitize_key($error->get_error_code()), 0, 100),
            'last_error_message' => OV_Booking_WhatsApp_API::redact_error($error->get_error_message()),
        ), array('id' => (int) $item->id, 'status' => 'sending', 'action_token' => $item->action_token));
    }

    private static function mark_discarded($id, $reason, $token = null) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array('status' => 'discarded', 'last_error_code' => sanitize_key($reason), 'next_attempt_at' => null),
            $token === null ? array('id' => (int) $id) : array('id' => (int) $id, 'action_token' => $token, 'status' => 'sending'),
            array('%s', '%s', '%s'),
            $token === null ? array('%d') : array('%d', '%s', '%s')
        );
    }

    public static function discard_reservation($reservation_id, $reason = 'reservation_deleted') {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . " SET status = IF(status IN ('responded', 'read'), status, 'discarded'),
             action_expires_at = UTC_TIMESTAMP(), next_attempt_at = NULL,
             last_error_code = %s
             WHERE reservation_id = %d",
            sanitize_key($reason),
            (int) $reservation_id
        ));
    }

    public static function enqueue_deleted($reservation_id) {
        self::discard_reservation((int) $reservation_id);
    }

    public static function schedule_immediate_worker() {
        $args = array('immediate');
        if (!wp_next_scheduled('ov_booking_whatsapp_process_queue', $args)) {
            wp_schedule_single_event(time() + 5, 'ov_booking_whatsapp_process_queue', $args);
        }
    }

    private static function retry_delay($attempts) {
        $delays = array(60, 300, 900, 3600, 21600);
        $index = max(0, min(count($delays) - 1, $attempts - 1));
        return $delays[$index];
    }

    private static function generate_token() {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                return '';
            }
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM " . self::table() . " WHERE action_token = %s",
                $token
            ));
            if (!$exists) return $token;
        }
        return '';
    }

    private static function action_expiry($reservation) {
        $form = (new OV_Form())->get((int) $reservation->form_id);
        try {
            $timezone = new DateTimeZone($form && !empty($form->timezone) ? $form->timezone : 'UTC');
            $date = new DateTimeImmutable($reservation->booking_date . ' ' . $reservation->booking_time, $timezone);
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return gmdate('Y-m-d H:i:s');
        }
    }
}
