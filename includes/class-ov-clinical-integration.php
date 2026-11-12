<?php

defined('ABSPATH') || exit;

/** Public server-side contracts for the clinical records add-on. */
final class OV_Clinical_Integration {
    public static function register() {
        add_filter('ov_booking_module_enabled_v1', array(__CLASS__, 'module_enabled'), 10, 2);
        add_filter('ov_booking_clinical_candidate_v1', array(__CLASS__, 'candidate'), 10, 2);
    }

    public static function module_enabled($enabled, $module) {
        return is_string($module) && OV_Module_Manager::is_enabled($module);
    }

    public static function candidate($result, $query) {
        if (!OV_Module_Manager::is_enabled('clinical_records') || !is_user_logged_in()) {
            return self::error('ov_clinical_forbidden', 'No tienes permiso para buscar pacientes.', 403);
        }

        if (!is_array($query) || count($query) !== 1) {
            return self::error('ov_clinical_query', 'Indica un RUT o una reserva.', 400);
        }

        $patients = new OV_Patient();
        if (isset($query['rut']) && is_string($query['rut'])
            && preg_match('/^[1-9][0-9]{6,7}[0-9K]$/D', $query['rut'])) {
            if (!current_user_can('ov_clinical_identify')) {
                return self::error('ov_clinical_forbidden', 'No tienes permiso para buscar pacientes.', 403);
            }
            $patient = $patients->find_by_rut($query['rut']);
        } elseif (isset($query['reservation_id']) && is_int($query['reservation_id'])
            && $query['reservation_id'] > 0) {
            $reservation = (new OV_Reservation())->get($query['reservation_id']);
            if (!current_user_can('ov_clinical_identify') && (!$reservation || empty($reservation->patient_id)
                || !apply_filters('ov_booking_clinical_reservation_access_v1', false, (int) $reservation->patient_id))) {
                return self::error('ov_clinical_forbidden', 'No tienes permiso para abrir esta reserva.', 403);
            }
            if (!$reservation || empty($reservation->patient_id)) {
                return self::error('ov_clinical_missing', 'La reserva no tiene un paciente vinculado.', 404);
            }
            $patient = $patients->get((int) $reservation->patient_id);
            $details = is_array($reservation->customer_data) ? $reservation->customer_data : array();
            $rut = isset($details['rut']) && is_scalar($details['rut'])
                ? strtoupper(preg_replace('/[.\s-]/', '', (string) $details['rut']))
                : '';
            if ($patient && ($rut === '' || $rut !== (string) $patient->rut)) {
                return self::error('ov_clinical_review', 'Revisa la identidad de la reserva. Busca por el RUT verificado antes de continuar.', 409);
            }
        } else {
            return self::error('ov_clinical_query', 'La búsqueda de paciente no es válida.', 400);
        }

        if (!$patient) {
            return self::error('ov_clinical_missing', 'No se encontró un paciente vinculado.', 404);
        }

        return array(
            'patient_id' => (int) $patient->id,
            'name' => (string) $patient->name,
            'rut' => (string) $patient->rut,
        );
    }

    private static function error($code, $message, $status) {
        return new WP_Error($code, $message, array('status' => $status));
    }
}
