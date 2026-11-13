<?php

defined('ABSPATH') || exit;

/** Public server-side contracts for the clinical records add-on. */
final class OV_Clinical_Integration {
    public static function register() {
        add_filter('ov_booking_module_enabled_v1', array(__CLASS__, 'module_enabled'), 10, 2);
        add_filter('ov_booking_clinical_candidate_v1', array(__CLASS__, 'candidate'), 10, 2);
        add_filter('ov_booking_clinical_manager_v1', array(__CLASS__, 'manager_access'));
        add_filter('ov_booking_clinical_candidate_v2', array(__CLASS__, 'manager_candidate'), 10, 2);
        add_filter('ov_booking_clinical_professionals_v1', array(__CLASS__, 'professionals'), 10, 2);
        add_filter('ov_booking_clinical_services_v1', array(__CLASS__, 'services'));
        add_filter('ov_booking_clinical_professionals_v2', array(__CLASS__, 'professionals_by_services'), 10, 3);
    }

    public static function module_enabled($enabled, $module) {
        return is_string($module) && OV_Module_Manager::is_enabled($module);
    }

    public static function candidate($result, $query) {
        if (!OV_Module_Manager::is_enabled('clinical_records') || !is_user_logged_in()) {
            return self::error('ov_clinical_forbidden', 'No tienes permiso para buscar pacientes.', 403);
        }

        return self::find_candidate($query, true);
    }

    public static function manager_access($allowed) {
        $user = wp_get_current_user();
        return $user->exists() && in_array(OV_Roles::ROLE, $user->roles, true)
            && user_can($user, OV_Roles::CAPABILITY);
    }

    private static function can_query() {
        return self::manager_access(false) && OV_Module_Manager::is_enabled('clinical_records')
            && in_array(wp_get_environment_type(), array('local', 'development', 'staging'), true);
    }

    public static function manager_candidate($result, $query) {
        if (!self::can_query()) {
            return self::error('ov_clinical_forbidden', 'No tienes permiso para buscar pacientes.', 403);
        }
        return self::find_candidate($query, false);
    }

    private static function find_candidate($query, $legacy) {
        if (!is_array($query) || count($query) !== 1) {
            return self::error('ov_clinical_query', 'Indica un RUT o una reserva.', 400);
        }

        $patients = new OV_Patient();
        if (isset($query['rut']) && is_string($query['rut'])
            && preg_match('/^[1-9][0-9]{6,7}[0-9K]$/D', $query['rut'])) {
            if ($legacy && !current_user_can('ov_clinical_identify')) {
                return self::error('ov_clinical_forbidden', 'No tienes permiso para buscar pacientes.', 403);
            }
            $patient = $patients->find_by_rut($query['rut']);
        } elseif (isset($query['reservation_id']) && is_int($query['reservation_id'])
            && $query['reservation_id'] > 0) {
            $reservation = (new OV_Reservation())->get($query['reservation_id']);
            if ($legacy && !current_user_can('ov_clinical_identify') && (!$reservation || empty($reservation->patient_id)
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

    public static function professionals($result, $id) {
        if (!self::can_query()) {
            return self::error('ov_clinical_forbidden', 'No tienes permiso para consultar profesionales.', 403);
        }
        if ($id !== null && (!is_int($id) || $id < 1)) {
            return self::error('ov_clinical_query', 'El identificador del profesional no es válido.', 400);
        }
        return self::find_professionals($id);
    }

    public static function services($result) {
        if (!self::can_query()) {
            return self::error('ov_clinical_forbidden', 'No tienes permiso para consultar servicios.', 403);
        }
        global $wpdb;
        $database = new OV_Database();
        $services = $database->get_table_name('services');
        $forms = $database->get_table_name('forms');
        $rows = $wpdb->get_results(
            "SELECT s.id,s.name,f.name AS form_name FROM $services s
             INNER JOIN $forms f ON f.id = s.form_id
             WHERE s.active = 1 ORDER BY f.name,s.display_order,s.name,s.id", ARRAY_A
        );
        if ($wpdb->last_error) {
            return self::error('ov_clinical_unavailable', 'No se pudo consultar la agenda de servicios.', 503);
        }
        return array_map(static function ($row) {
            return array('id' => (int) $row['id'], 'name' => $row['name'], 'form_name' => $row['form_name']);
        }, $rows);
    }

    public static function professionals_by_services($result, $service_ids, $id) {
        if (!self::can_query()) {
            return self::error('ov_clinical_forbidden', 'No tienes permiso para consultar profesionales.', 403);
        }
        if ($id !== null && (!is_int($id) || $id < 1)) {
            return self::error('ov_clinical_query', 'El identificador del profesional no es válido.', 400);
        }
        if (!is_array($service_ids) || !$service_ids) {
            return self::error('ov_clinical_query', 'Selecciona servicios de agenda para la especialidad.', 400);
        }
        foreach ($service_ids as $service_id) {
            if (!is_int($service_id) || $service_id < 1) {
                return self::error('ov_clinical_query', 'El identificador del servicio no es válido.', 400);
            }
        }
        return self::find_professionals($id, array_values(array_unique($service_ids)));
    }

    private static function find_professionals($id, $service_ids = array()) {
        global $wpdb;
        $database = new OV_Database();
        $table = $database->get_table_name('professionals');
        $sql = "SELECT p.id,p.name FROM $table p WHERE p.active = 1";
        $params = array();
        if ($service_ids) {
            $services = $database->get_table_name('services');
            $forms = $database->get_table_name('forms');
            $assignments = $wpdb->prefix . 'ov_service_professionals';
            $slots = implode(',', array_fill(0, count($service_ids), '%d'));
            $sql .= " AND EXISTS (
                SELECT 1 FROM $assignments a
                INNER JOIN $services s ON s.id = a.service_id
                INNER JOIN $forms f ON f.id = s.form_id
                WHERE a.professional_id = p.id AND s.form_id = p.form_id
                    AND s.active = 1 AND s.id IN ($slots)
            )";
            $params = $service_ids;
        }
        if ($id !== null) {
            $sql .= ' AND p.id = %d';
            $params[] = $id;
        }
        $sql .= ' ORDER BY p.display_order,p.name,p.id';
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);
        if ($wpdb->last_error) {
            return self::error('ov_clinical_unavailable', 'No se pudo consultar la agenda de profesionales.', 503);
        }
        $professionals = array_map(static function ($row) {
            return array('id' => (int) $row['id'], 'name' => (string) $row['name']);
        }, $rows);
        if ($id === null) {
            return $professionals;
        }
        $message = $service_ids
            ? 'Selecciona un profesional activo asignado a un servicio de esta especialidad.'
            : 'Selecciona un profesional activo de la agenda.';
        return $professionals[0] ?? self::error('ov_clinical_missing', $message, 404);
    }

    private static function error($code, $message, $status) {
        return new WP_Error($code, $message, array('status' => $status));
    }
}
