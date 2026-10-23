<?php
/**
 * Eventos de calendario para el cliente.
 *
 * No accede a la cuenta de Google del cliente. Genera un enlace de Google
 * Calendar y datos iCalendar; el cliente confirma la importación en su cuenta.
 *
 * @package OV_Booking_System
 */

class OV_Google_Calendar {

    /**
     * Construir datos portables para añadir una reserva al calendario.
     *
     * @param object      $reservation Reserva persistida.
     * @param object      $form Formulario dueño de la reserva.
     * @param object      $service Servicio reservado.
     * @param string|null $professional_name Nombre visible del profesional.
     * @return array|null
     */
    public static function build_client_event($reservation, $form, $service, $professional_name = null) {
        $config = is_array($form->calendar_config ?? null) ? $form->calendar_config : array();
        if (array_key_exists('client_add_enabled', $config) && empty($config['client_add_enabled'])) {
            return null;
        }

        $timezone_name = !empty($form->timezone) ? sanitize_text_field($form->timezone) : '';
        if (!$timezone_name && function_exists('wp_timezone_string')) {
            $timezone_name = wp_timezone_string();
        }
        if (!$timezone_name) {
            $timezone_name = 'UTC';
        }

        try {
            $timezone = new DateTimeZone($timezone_name);
        } catch (Exception $e) {
            $timezone_name = 'UTC';
            $timezone = new DateTimeZone('UTC');
        }

        $title = sprintf(__('Cita: %s', 'ov-booking-system'), sanitize_text_field($service->name));
        $details = sprintf(__('Reserva %s', 'ov-booking-system'), sanitize_text_field($reservation->booking_id));
        if ($professional_name) {
            $details .= "\n" . sprintf(__('Profesional: %s', 'ov-booking-system'), sanitize_text_field($professional_name));
        }

        $is_all_day = strtolower((string) $reservation->duration_unit) === 'days';
        try {
            if ($is_all_day) {
                $start = new DateTime($reservation->booking_date . ' 00:00:00', $timezone);
                $end = clone $start;
                $end->modify('+' . max(1, (int) $reservation->duration) . ' days');
                $google_dates = $start->format('Ymd') . '/' . $end->format('Ymd');
            } else {
                $start = new DateTime($reservation->booking_date . ' ' . $reservation->booking_time, $timezone);
                $end = clone $start;
                // El servicio define la duración inicial; la reserva puede haberse ampliado.
                $duration_source = !empty($reservation->duration) ? $reservation : $service;
                $duration_minutes = max(1, (new OV_Service())->get_duration_in_minutes($duration_source));
                $end->modify('+' . $duration_minutes . ' minutes');

                $google_start = clone $start;
                $google_end = clone $end;
                $google_start->setTimezone(new DateTimeZone('UTC'));
                $google_end->setTimezone(new DateTimeZone('UTC'));
                $google_dates = $google_start->format('Ymd\THis\Z') . '/' . $google_end->format('Ymd\THis\Z');
            }
        } catch (Exception $e) {
            return null;
        }

        $google_url = add_query_arg(array(
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $google_dates,
            'details' => $details,
            'ctz' => $timezone_name,
        ), 'https://calendar.google.com/calendar/render');

        $start_utc = clone $start;
        $end_utc = clone $end;
        $start_utc->setTimezone(new DateTimeZone('UTC'));
        $end_utc->setTimezone(new DateTimeZone('UTC'));

        return array(
            'title' => $title,
            'details' => $details,
            'google_url' => esc_url_raw($google_url),
            'all_day' => $is_all_day,
            'start_date' => $start->format('Ymd'),
            'end_date' => $end->format('Ymd'),
            'start_utc' => $start_utc->format('Ymd\THis\Z'),
            'end_utc' => $end_utc->format('Ymd\THis\Z'),
            'timezone' => $timezone_name,
            'filename' => sanitize_file_name('reserva-' . $reservation->booking_id . '.ics'),
        );
    }
}
