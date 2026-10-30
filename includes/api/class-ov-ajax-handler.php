<?php
/**
 * Manejador AJAX para el frontend
 * 
 * @package OV_Booking_System
 */

class OV_Ajax_Handler {

    /**
     * Obtener zona horaria efectiva de un formulario.
     * Prioridad: formulario -> WordPress -> UTC.
     */
    private function get_form_timezone($form) {
        $timezone = '';

        if ($form && !empty($form->timezone)) {
            $timezone = sanitize_text_field($form->timezone);
        }

        if (empty($timezone)) {
            $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : get_option('timezone_string');
        }

        if (empty($timezone)) {
            $timezone = 'UTC';
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (Exception $e) {
            return 'UTC';
        }
    }

    /**
     * Fecha/hora actual según zona horaria del formulario.
     */
    private function current_time_for_form($form, $format = 'Y-m-d H:i:s') {
        $timezone = $this->get_form_timezone($form);
        try {
            $dt = new DateTime('now', new DateTimeZone($timezone));
            return $dt->format($format);
        } catch (Exception $e) {
            return current_time('mysql');
        }
    }

    /**
     * Ventana pública: hoy y los seis meses calendario, incluido el actual.
     */
    private function get_public_booking_window($form) {
        $timezone = new DateTimeZone($this->get_form_timezone($form));
        $today = new DateTimeImmutable('today', $timezone);
        $last_day = $today->modify('first day of this month')->modify('+5 months')->modify('last day of this month');

        return array(
            'start_date' => $today->format('Y-m-d'),
            'end_date' => $last_day->format('Y-m-d'),
            'start_month' => $today->format('Y-m'),
            'end_month' => $last_day->format('Y-m'),
        );
    }

    private function is_valid_public_date($date, $form) {
        if (!is_string($date) || strlen($date) !== 10 || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return false;
        }
        list($year, $month, $day) = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) {
            return false;
        }

        $window = $this->get_public_booking_window($form);
        return $date >= $window['start_date'] && $date <= $window['end_date'];
    }

    /** Rechazar arrays, valores truncados por intval y enteros fuera de rango. */
    private function parse_public_integer($raw, $allow_zero = false) {
        if (!is_string($raw) && !is_int($raw)) {
            return false;
        }
        $value = is_string($raw) ? wp_unslash($raw) : (string) $raw;
        if (strlen($value) > strlen((string) PHP_INT_MAX) ||
            !preg_match($allow_zero ? '/^(0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $value)) {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_INT, array(
            'options' => array('min_range' => $allow_zero ? 0 : 1),
        ));
    }

    /** Un formulario inexistente o inactivo no publica sus datos. */
    private function get_active_public_form($form_id) {
        $form = (new OV_Form())->get($form_id);
        return $form && $form->status === 'active' ? $form : false;
    }

    private function get_public_availability_form($form_id, $service_id, $professional_id, &$service_out = null) {
        $form = (new OV_Form())->get($form_id);
        $service = (new OV_Service())->get($service_id);
        if (!$form || $form->status !== 'active' || !$service || empty($service->active) || (int) $service->form_id !== $form_id) {
            return false;
        }
        $service_out = $service;
        if (empty($form->enable_professionals)) {
            return $professional_id === null ? $form : false;
        }
        if ($professional_id === 0) {
            return !empty($form->allow_any_professional) ? $form : false;
        }
        if (!$professional_id || !(new OV_Professional())->is_public_for_service($professional_id, $service_id, $form_id)) {
            return false;
        }
        return $form;
    }


    public function __construct() {
        // No hacer nada en el constructor
    }

    /**
     * Inicializar hooks AJAX
     */
    public function init() {
        // AJAX para usuarios logueados y no logueados
        add_action('wp_ajax_ov_get_services', array($this, 'get_services'));
        add_action('wp_ajax_nopriv_ov_get_services', array($this, 'get_services'));
        add_action('wp_ajax_ov_admin_get_services', array($this, 'ajax_get_services'));
        add_action('wp_ajax_ov_admin_get_available_slots',array($this, 'ajax_admin_get_available_slots'));

        add_action('wp_ajax_ov_get_professionals', array($this, 'get_professionals'));
        add_action('wp_ajax_nopriv_ov_get_professionals', array($this, 'get_professionals'));

        add_action('wp_ajax_ov_get_professionals_by_service', array($this, 'get_professionals_by_service'));
        add_action('wp_ajax_nopriv_ov_get_professionals_by_service', array($this, 'get_professionals_by_service'));
        add_action('wp_ajax_ov_admin_get_professionals',array($this, 'ajax_get_professionals'));

        add_action('wp_ajax_ov_get_available_slots', array($this, 'get_available_slots'));
        add_action('wp_ajax_nopriv_ov_get_available_slots', array($this, 'get_available_slots'));

        add_action('wp_ajax_ov_get_month_availability', array($this, 'get_month_availability'));
        add_action('wp_ajax_nopriv_ov_get_month_availability', array($this, 'get_month_availability'));

        add_action('wp_ajax_ov_get_form_fields', array($this, 'get_form_fields'));
        add_action('wp_ajax_nopriv_ov_get_form_fields', array($this, 'get_form_fields'));

        add_action('wp_ajax_ov_create_booking', array($this, 'create_booking'));
        add_action('wp_ajax_nopriv_ov_create_booking', array($this, 'create_booking'));

        add_action('wp_ajax_ov_get_form_config', array($this, 'get_form_config'));
        add_action('wp_ajax_nopriv_ov_get_form_config', array($this, 'get_form_config'));
        
        add_action('wp_ajax_ov_update_reservation_status', array($this, 'update_reservation_status'));
        add_action('wp_ajax_ov_update_reservation', array($this, 'admin_update_reservation'));
        add_action('wp_ajax_ov_delete_reservation', array($this, 'admin_delete_reservation'));
        add_action('wp_ajax_ov_preview_clear_reservations', array($this, 'admin_preview_clear_reservations'));
        add_action('wp_ajax_ov_clear_reservations', array($this, 'admin_clear_reservations'));

        
        // ===== ADMIN: GESTIÓN DE PROFESIONALES =====
        add_action('wp_ajax_ov_get_professional', array($this, 'admin_get_professional'));
        add_action('wp_ajax_ov_save_professional', array($this, 'admin_save_professional'));
        add_action('wp_ajax_ov_delete_professional', array($this, 'admin_delete_professional'));

        // ===== AJAX: Busqueda de paciente =====
        add_action('wp_ajax_ov_search_patient', array($this, 'search_patient'));
    }

    /**
     * Buscar paciente por teléfono o RUT.
     */
    public function search_patient() {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array('message' => __('No tienes permisos para buscar pacientes.', 'ov-booking-system')), 403);
        }

        check_ajax_referer('ov_admin_nonce', 'nonce');

        $search = sanitize_text_field($_POST['search'] ?? '');

        $patient_model = new OV_Patient();

        $patient = $patient_model->search_by_identifier($search);

        if (!$patient) {

            wp_send_json_success(array(
                'found' => false
            ));

        }

       $full_name = trim($patient->name);

        $parts = preg_split('/\s+/', $full_name, 2);

        wp_send_json_success(array(

            'found' => true,

            'patient' => array(

                'name'     => $parts[0] ?? '',
                'surname'  => $parts[1] ?? '',

                'phone'    => $patient->phone,
                'email'    => $patient->email,
                'rut'      => $patient->rut

            )

        ));

    }

    /**
     * Obtener servicios
     */
    public function get_services() {
        try {
            $form_id = $this->parse_public_integer($_REQUEST['form_id'] ?? null);
            $professional_id = null;
            if (isset($_REQUEST['professional_id']) && $_REQUEST['professional_id'] !== '') {
                $professional_id = $this->parse_public_integer($_REQUEST['professional_id'], true);
            }

            if ($form_id === false || $professional_id === false) {
                wp_send_json_error(array(
                    'message' => __('ID de formulario o profesional no válido', 'ov-booking-system')
                ), 400);
            }

            $form = $this->get_active_public_form($form_id);
            if (!$form) {
                wp_send_json_error(array('message' => __('Formulario no encontrado', 'ov-booking-system')), 404);
            }

            if (empty($form->enable_professionals)) {
                if ($professional_id !== null) {
                    wp_send_json_error(array('message' => __('Profesional no disponible', 'ov-booking-system')), 404);
                }
            } elseif ($professional_id === 0) {
                if (empty($form->allow_any_professional)) {
                    wp_send_json_error(array('message' => __('Profesional no disponible', 'ov-booking-system')), 404);
                }
            } elseif ($professional_id !== null) {
                $professional = (new OV_Professional())->get($professional_id);
                if (!$professional || (int) $professional->form_id !== $form_id ||
                    empty($professional->active) || ($professional->booking_visibility ?? '') !== 'public') {
                    wp_send_json_error(array('message' => __('Profesional no disponible', 'ov-booking-system')), 404);
                }
            }

            $service_model = new OV_Service();
            $public_only = !empty($form->enable_professionals);
            $services = $service_model->get_by_form($form_id, $professional_id, true, $public_only);

            // Formatear servicios para el frontend
            $formatted_services = array();
            foreach ($services as $service) {
                $formatted_services[] = array(
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'duration' => $service->duration,
                    'duration_unit' => $service->duration_unit,
                    'price' => $service->price_text,
                    'image_url' => $service->image_url
                );
            }

            wp_send_json_success($formatted_services);
        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    /**
     * Obtener profesionales
     */
    public function get_professionals() {
        try {
            $form_id = $this->parse_public_integer($_REQUEST['form_id'] ?? null);

            if ($form_id === false) {
                wp_send_json_error(array(
                    'message' => __('ID de formulario no válido', 'ov-booking-system')
                ), 400);
            }

            $form = $this->get_active_public_form($form_id);
            if (!$form) {
                wp_send_json_error(array('message' => __('Formulario no encontrado', 'ov-booking-system')), 404);
            }
            if (empty($form->enable_professionals)) {
                wp_send_json_success(array());
                return;
            }

            $professional_model = new OV_Professional();
            $professionals = $professional_model->get_by_form($form_id, true, true);

            // Formatear profesionales para el frontend
            $formatted_professionals = array();
            foreach ($professionals as $professional) {
                $formatted_professionals[] = array(
                    'id' => $professional->id,
                    'name' => $professional->name,
                    'bio' => $professional->bio,
                    'avatar_url' => $professional->avatar_url
                );
            }

            wp_send_json_success($formatted_professionals);
        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    /**
     * Obtener slots disponibles
     */
    public function get_available_slots() {
        try {
            $form_id = $this->parse_public_integer($_REQUEST['form_id'] ?? null);
            $service_id = $this->parse_public_integer($_REQUEST['service_id'] ?? null);
            $raw_date = $_REQUEST['date'] ?? null;
            $date = is_string($raw_date) ? wp_unslash($raw_date) : '';

            $professional_id = null;
            if (isset($_REQUEST['professional_id']) && $_REQUEST['professional_id'] !== '') {
                $professional_id = $this->parse_public_integer($_REQUEST['professional_id'], true);
            }

            if ($form_id === false || $service_id === false || $professional_id === false) {
                wp_send_json_error(array(
                    'message' => __('Formulario, servicio, profesional o fecha no válidos.', 'ov-booking-system')
                ), 400);
            }
            $form = $this->get_public_availability_form($form_id, $service_id, $professional_id);
            if (!$form || !$this->is_valid_public_date($date, $form)) {
                wp_send_json_error(array(
                    'message' => __('Formulario, servicio, profesional o fecha no válidos.', 'ov-booking-system')
                ), 400);
            }

            $this->enforce_public_availability_rate_limit();

            $availability = new OV_Availability();

            // Si es "cualquier profesional", obtener lista una vez para evaluar disponibilidad por día
            $any_pros = array();
            if ($professional_id === 0) {
                $form_model = new OV_Form();
                $form = $form_model->get($form_id);
                if ($form && !empty($form->enable_professionals) && !(isset($form->allow_any_professional) && !$form->allow_any_professional)) {
                    $professional_model = new OV_Professional();
                    $any_pros = $professional_model->get_by_service($service_id, true, true);
                }
            }

            // Si es "cualquier profesional", unificar slots: disponible si ALGÚN profesional lo tiene
            if ($professional_id === 0) {
                $form_model = new OV_Form();
                $form = $form_model->get($form_id);

                // Si por alguna razón no está habilitado, tratar como sin profesionales
                if (!$form || empty($form->enable_professionals) || (isset($form->allow_any_professional) && !$form->allow_any_professional)) {
                    $professional_id = null;
                    $slots = $availability->get_available_slots($form_id, $service_id, $date, $professional_id);
                } else {
                    $professional_model = new OV_Professional();
                    $pros = $professional_model->get_by_service($service_id, true, true);

                    $slot_map = array();
                    foreach ($pros as $prof) {
                        $prof_slots = $availability->get_available_slots($form_id, $service_id, $date, (int) $prof->id);
                        foreach ($prof_slots as $slot) {
                            $key = $slot['time'];
                            if (!isset($slot_map[$key])) {
                                $slot_map[$key] = $slot;
                            } else {
                                // Si al menos uno está disponible, marcar disponible
                                $slot_map[$key]['available'] = $slot_map[$key]['available'] || !empty($slot['available']);
                                // Sumar cupos restantes cuando se usa "cualquier profesional".
                                $slot_map[$key]['remaining'] = intval($slot_map[$key]['remaining'] ?? 0) + intval($slot['remaining'] ?? (!empty($slot['available']) ? 1 : 0));
                                $slot_map[$key]['capacity'] = intval($slot_map[$key]['capacity'] ?? 0) + intval($slot['capacity'] ?? 1);
                            }
                        }
                    }

                    // Ordenar por hora
                    ksort($slot_map);
                    $slots = array_values($slot_map);
                }
            } else {
                $slots = $availability->get_available_slots($form_id, $service_id, $date, $professional_id);
            }

            // Si no hay slots, devolver mensaje informativo
            if (empty($slots)) {
                wp_send_json_success(array());
                return;
            }

            // Filtrar solo los disponibles
            $available_slots = array();
            foreach ($slots as $slot) {
                $available_slots[] = array(
                    'time' => $slot['time'],
                    'end_time' => $slot['end_time'],
                    'available' => $slot['available'],
                    'capacity' => isset($slot['capacity']) ? intval($slot['capacity']) : null,
                    'used' => isset($slot['used']) ? intval($slot['used']) : null,
                    'remaining' => isset($slot['remaining']) ? intval($slot['remaining']) : null
                );
            }

            wp_send_json_success($available_slots);
        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    public function get_month_availability() {
        try {
            $form_id = $this->parse_public_integer($_REQUEST['form_id'] ?? null);
            $service_id = $this->parse_public_integer($_REQUEST['service_id'] ?? null);
            $raw_year = $_REQUEST['year'] ?? null;
            $raw_month = $_REQUEST['month'] ?? null;
            $year_text = is_string($raw_year) ? wp_unslash($raw_year) : (is_int($raw_year) ? (string) $raw_year : '');
            $month_text = is_string($raw_month) ? wp_unslash($raw_month) : (is_int($raw_month) ? (string) $raw_month : '');
            $professional_id = null;
            if (isset($_REQUEST['professional_id']) && $_REQUEST['professional_id'] !== '') {
                $professional_id = $this->parse_public_integer($_REQUEST['professional_id'], true);
            }

            if ($form_id === false || $service_id === false || $professional_id === false ||
                strlen($year_text) !== 4 || strlen($month_text) > 2 ||
                !preg_match('/^[1-9][0-9]{3}$/D', $year_text) ||
                !preg_match('/^(?:[1-9]|1[0-2])$/D', $month_text)) {
                wp_send_json_error(array(
                    'message' => __('Parámetros de calendario no válidos.', 'ov-booking-system')
                ), 400);
            }

            $year = (int) $year_text;
            $month = (int) $month_text;
            $service = null;
            $form = $this->get_public_availability_form($form_id, $service_id, $professional_id, $service);
            if (!$form) {
                wp_send_json_error(array('message' => __('Calendario no disponible.', 'ov-booking-system')), 400);
            }
            $window = $this->get_public_booking_window($form);
            $requested_month = sprintf('%04d-%02d', $year, $month);
            if ($requested_month < $window['start_month'] || $requested_month > $window['end_month']) {
                wp_send_json_error(array('message' => __('Mes fuera del período reservable.', 'ov-booking-system')), 400);
            }

            $this->enforce_public_availability_rate_limit();

            // El snapshot vive solo durante esta petición; la siguiente ve nuevas reservas.
            $professional_ids = array($professional_id);
            if ($professional_id === 0) {
                $pros = (new OV_Professional())->get_by_service($service_id, true, true);
                if (empty($pros)) {
                    wp_send_json_success(array('available_dates' => array()));
                }
                $professional_ids = array_map(function($prof) { return (int) $prof->id; }, $pros);
            }

            // Calcular rango del mes
            $start_date = sprintf('%04d-%02d-01', $year, $month);
            $days_in_month = (int) date('t', strtotime($start_date));

            $end_date = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);
            $first_date = max($start_date, $window['start_date']);
            $availability = new OV_Availability();
            $available_dates = $availability->get_month_available_dates(
                $form, $service, $first_date, $end_date, $professional_ids
            );

            wp_send_json_success(array(
                'available_dates' => $available_dates
            ));

        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    /**
     * Obtener campos del formulario
     */
    public function get_form_fields() {
        try {
            $form_id = $this->parse_public_integer($_REQUEST['form_id'] ?? null);

            if ($form_id === false) {
                wp_send_json_error(array(
                    'message' => __('ID de formulario no válido', 'ov-booking-system')
                ), 400);
            }

            if (!$this->get_active_public_form($form_id)) {
                wp_send_json_error(array('message' => __('Formulario no encontrado', 'ov-booking-system')), 404);
            }

            $field_model = new OV_Field();
            $fields = $field_model->get_by_form($form_id, true);

            // Formatear campos para el frontend
            $formatted_fields = array();
            foreach ($fields as $field) {
                $formatted_fields[] = array(
                    'name' => $field->field_name,
                    'label' => $field->field_label,
                    'type' => $field->field_type,
                    'required' => (bool) $field->required,
                    'placeholder' => $field->placeholder,
                    'options' => $field->field_options
                );
            }

            wp_send_json_success($formatted_fields);
        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    /**
     * Crear reserva
     */
    public function create_booking() {
        $lock_name = null;
        try {
            // Verificar nonce
            $nonce = $_POST['nonce'] ?? '';

            $valid_public_nonce = wp_verify_nonce($nonce, 'ov_booking_nonce');
            $valid_admin_nonce  = wp_verify_nonce($nonce, 'ov_admin_nonce');
            $is_admin_request = (bool) $valid_admin_nonce;

            // Si viene desde administración, exigir permisos
            if ($is_admin_request) {

                if (!current_user_can(OV_Roles::CAPABILITY)) {
                    wp_send_json_error(array(
                        'message' => __('No tienes permisos para crear reservas desde administración.', 'ov-booking-system')
                    ));
                    return;
                }

            } elseif (!$valid_public_nonce) {

                wp_send_json_error(array(
                    'message' => __('Error de seguridad', 'ov-booking-system')
                ));
                return;
            }

            // Obtener datos
            $form_id = $is_admin_request
                ? (isset($_POST['form_id']) ? intval($_POST['form_id']) : 0)
                : $this->parse_public_integer($_POST['form_id'] ?? null);
            $service_id = $is_admin_request
                ? (isset($_POST['service_id']) ? intval($_POST['service_id']) : 0)
                : $this->parse_public_integer($_POST['service_id'] ?? null);
            
            // Manejar professional_id:
            // - Si no se envía o está vacío string → NULL (sin profesionales habilitados)
            // - Si es "0" o 0 → 0 (cualquier profesional)
            // - Si es > 0 → ese profesional específico
            $professional_id = null;
            if (isset($_POST['professional_id']) && $_POST['professional_id'] !== '') {
                $professional_id = $is_admin_request
                    ? intval($_POST['professional_id'])
                    : $this->parse_public_integer($_POST['professional_id'], true);
            }
            if (!$is_admin_request && ($form_id === false || $service_id === false || $professional_id === false)) {
                wp_send_json_error(array('message' => __('Formulario, servicio o profesional no válidos.', 'ov-booking-system')), 400);
            }

            // La visibilidad pública se valida en servidor. Ocultar el nombre
            // en el frontend no debe permitir reservar con un ID manipulado.
            if (!$is_admin_request && $professional_id !== null && $professional_id > 0) {
                $professional_model = new OV_Professional();
                if (!$professional_model->is_public_for_service($professional_id, $service_id, $form_id)) {
                    wp_send_json_error(array(
                        'message' => __('El profesional seleccionado no está disponible para reservas públicas.', 'ov-booking-system')
                    ), 400);
                }
            }

            $raw_date = $_POST['date'] ?? null;
            $date = is_string($raw_date) ? sanitize_text_field(wp_unslash($raw_date)) : '';
            $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';
            $customer_data = isset($_POST['customer_data']) && is_array($_POST['customer_data'])
                ? wp_unslash($_POST['customer_data'])
                : array();

            // Comprobar también en los reintentos idempotentes: el navegador
            // no puede decidir por sí mismo si los términos son obligatorios.
            $booking_form = (new OV_Form())->get($form_id);
            if (!$is_admin_request && (!$booking_form || !$this->is_valid_public_date($date, $booking_form))) {
                wp_send_json_error(array('message' => __('Fecha fuera del período reservable o no válida.', 'ov-booking-system')), 400);
            }
            $terms = is_array($booking_form->terms_config ?? null) ? $booking_form->terms_config : array();
            if (!$is_admin_request && !empty($terms['required']) &&
                (!empty($terms['url']) || !empty($terms['text'])) &&
                (!isset($_POST['terms_accepted']) || wp_unslash($_POST['terms_accepted']) !== '1')) {
                wp_send_json_error(array('message' => __('Debes aceptar los términos y condiciones.', 'ov-booking-system')), 400);
            }

            if (!$is_admin_request && !empty($_POST['website'])) {
                wp_send_json_error(array('message' => __('No fue posible procesar la solicitud.', 'ov-booking-system')), 400);
            }

            $request_key = null;
            if (!$is_admin_request) {
                $request_id = isset($_POST['request_id']) ? sanitize_text_field(wp_unslash($_POST['request_id'])) : '';
                if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $request_id)) {
                    wp_send_json_error(array('message' => __('La solicitud de reserva no es válida. Recarga la página e inténtalo nuevamente.', 'ov-booking-system')), 400);
                }
                $request_fingerprint = implode('|', array(
                    $request_id,
                    (string) $form_id,
                    (string) $service_id,
                    ($professional_id === null ? 'none' : (string) $professional_id),
                    $date,
                    $time,
                    OV_Validation::normalize_phone($customer_data['phone'] ?? ''),
                ));
                $request_key = hash_hmac('sha256', $request_fingerprint, wp_salt('nonce'));

                $existing = (new OV_Reservation())->get_by_request_key($request_key);
                if ($existing) {
                    $this->send_booking_success($existing);
                }
            }

            if (!$this->valid_booking_context($form_id, $service_id, $professional_id, $date, !$is_admin_request)) {
                wp_send_json_error(array('message' => __('Revisa el formulario, servicio, profesional y fecha seleccionados.', 'ov-booking-system')), $is_admin_request ? 200 : 400);
            }

            // Validar datos básicos
            $validation = new OV_Validation();
            $basic_data = array(
                'form_id' => $form_id,
                'service_id' => $service_id,
                'booking_date' => $date,
                'booking_time' => $time,
                'customer_name' => isset($customer_data['name']) ? $customer_data['name'] : '',
                'customer_phone' => isset($customer_data['phone']) ? $customer_data['phone'] : ''
            );

            $errors = $validation->validate_booking_data($basic_data);
            if (!empty($errors)) {
                wp_send_json_error(array(
                    'message' => implode(', ', $errors)
                ));
                return;
            }

            // Validar campos personalizados
            $field_errors = $validation->validate_custom_fields($form_id, $customer_data);
            if (!empty($field_errors)) {
                wp_send_json_error(array(
                    'message' => __('Por favor completa todos los campos requeridos', 'ov-booking-system'),
                    'field_errors' => $field_errors
                ));
                return;
            }

            $phone_validation = $validation->validate_phone($customer_data['phone'] ?? '');
            if (empty($phone_validation['valid'])) {
                wp_send_json_error(array('message' => $phone_validation['message']), 400);
            }
            $customer_data['phone'] = $phone_validation['clean'];

            if (!$is_admin_request) {
                $rate_limit = $this->check_public_booking_rate_limits($customer_data['phone']);
                if (is_wp_error($rate_limit)) {
                    wp_send_json_error(array('message' => $rate_limit->get_error_message()), 429);
                }
            }

            $lock_name = $this->acquire_booking_lock($form_id);
            if (!$lock_name) {
                wp_send_json_error(array('message' => __('El sistema está procesando otra reserva para ese día. Inténtalo nuevamente.', 'ov-booking-system')), 409);
            }

            // Repetir la comprobación dentro del bloqueo cubre reintentos simultáneos.
            if ($request_key) {
                $existing = (new OV_Reservation())->get_by_request_key($request_key);
                if ($existing) {
                    $this->release_booking_lock($lock_name);
                    $lock_name = null;
                    $this->send_booking_success($existing);
                }
            }

            if (!$is_admin_request && !$this->valid_booking_context($form_id, $service_id, $professional_id, $date, true)) {
                $this->release_booking_lock($lock_name);
                $lock_name = null;
                wp_send_json_error(array('message' => __('Fecha o contexto de reserva no válidos.', 'ov-booking-system')), 400);
            }

            // Si se seleccionó "cualquier profesional", asignar uno automáticamente
            if ($professional_id === 0) {
                $assigned = $this->pick_first_available_professional($form_id, $service_id, $date, $time, !$is_admin_request);
                if (!$assigned) {
                    $this->release_booking_lock($lock_name);
                    $lock_name = null;
                    wp_send_json_error(array(
                        'message' => __('No hay profesionales disponibles para ese horario', 'ov-booking-system')
                    ));
                    return;
                }
                $professional_id = (int) $assigned;
            }

            // Validar disponibilidad
            $availability_check = $validation->validate_availability($form_id, $service_id, $date, $time, $professional_id);
            if (!$availability_check['valid']) {
                $this->release_booking_lock($lock_name);
                $lock_name = null;
                wp_send_json_error(array(
                    'message' => $availability_check['message']
                ));
                return;
            }

            // Obtener servicio para calcular end_time
            $service_model = new OV_Service();
            $service = $service_model->get($service_id);
            
            $datetime_helper = new OV_DateTime();
            $duration_minutes = $service_model->get_duration_in_minutes($service);
            
            // Calcular end_time incluyendo buffer
            $total_minutes = $duration_minutes + intval($service->buffer_time ?? 0);
            $end_time = (($service->duration_unit ?? 'minutes') === 'days') ? '23:59:59' : $datetime_helper->add_minutes($time, $total_minutes);

            // Obtener formulario y zona horaria oficial del negocio/formulario
            $form_model = new OV_Form();
            $form = $form_model->get($form_id);
            $form_created_at = $this->current_time_for_form($form);

            // Preparar datos de reserva
            $customer_name = isset($customer_data['name']) ? $customer_data['name'] : '';
            $customer_surname = isset($customer_data['surname']) ? $customer_data['surname'] : '';
            $full_name = trim($customer_name . ' ' . $customer_surname);

            $booking_data = array(
                'booking_id' => $this->generate_booking_id($form),
                'form_id' => $form_id,
                'service_id' => $service_id,
                'professional_id' => $professional_id,
                'booking_date' => $date,
                'booking_time' => $time,
                'end_time' => $end_time,
                'duration' => $service->duration,
                'duration_unit' => $service->duration_unit,
                'price' => !empty($service->price_text) ? sanitize_text_field($service->price_text) : null,
                'customer_name' => $full_name,
                'customer_email' => isset($customer_data['email']) ? sanitize_email($customer_data['email']) : '',
                'customer_phone' => $customer_data['phone'],
                'customer_data' => $validation->sanitize_customer_data($customer_data),
                'notes' => isset($customer_data['notes']) ? sanitize_textarea_field($customer_data['notes']) : '',
                'status' => 'pending',
                'source' => $is_admin_request ? 'admin' : 'public',
                'created_at' => $form_created_at,
                'request_key' => $request_key,
                'created_ip_hash' => $is_admin_request ? null : $this->get_request_ip_hash()
            );

            $booking_data = apply_filters(
                'ov_booking_before_reservation_create',
                $booking_data,
                $is_admin_request ? 'admin' : 'public'
            );
            if (is_wp_error($booking_data)) {
                $this->release_booking_lock($lock_name);
                $lock_name = null;
                wp_send_json_error(array('message' => $booking_data->get_error_message()), 400);
            }
            if (!is_array($booking_data)) {
                $this->release_booking_lock($lock_name);
                $lock_name = null;
                wp_send_json_error(array('message' => __('La integración devolvió datos de reserva no válidos.', 'ov-booking-system')), 500);
            }

            // Conocer un teléfono, correo o RUT no acredita la identidad del
            // paciente. Solo una reserva administrativa puede vincular o editar
            // una ficha; la pública conserva sus datos en la propia reserva.
            if ($is_admin_request) {
                $patient_model = new OV_Patient();
                $patient_data = array(
                    'name' => trim(($customer_data['name'] ?? '') . ' ' . ($customer_data['surname'] ?? '')),
                    'phone' => $customer_data['phone'] ?? '',
                    'email' => $customer_data['email'] ?? '',
                    'rut' => $customer_data['rut'] ?? '',
                    'notes' => '',
                );
                $booking_data['patient_id'] = $patient_model->find_or_create($patient_data);
            }


            // Crear reserva
            $reservation_model = new OV_Reservation();
            $booking_id = $reservation_model->create($booking_data, true);

            if (!$booking_id) {
                $duplicate = $request_key ? $reservation_model->get_by_request_key($request_key) : null;
                $this->release_booking_lock($lock_name);
                $lock_name = null;
                if ($duplicate) {
                    $this->send_booking_success($duplicate);
                }
                global $wpdb;
                $message = __('Error al crear la reserva', 'ov-booking-system');

                // Diagnóstico seguro: solo mostrar detalles técnicos a usuarios administradores.
                if (current_user_can('manage_options') && !empty($wpdb->last_error)) {
                    $message .= ': ' . $wpdb->last_error;
                }

                wp_send_json_error(array(
                    'message' => $message
                ));
                return;
            }

            $custom_id = $booking_data['booking_id'];
            $this->release_booking_lock($lock_name);
            $lock_name = null;

            // Los webhooks e integraciones se notifican fuera del bloqueo de disponibilidad.
            try {
                $reservation_model->dispatch_created($booking_id, $booking_data);
            } catch (Throwable $event_error) {
                error_log('OV Booking created hook error: ' . get_class($event_error));
            }

            // Preparar respuesta completa
            
            // Obtener nombre del profesional si existe
            $professional_display = null;
            if ($professional_id) {
                $professional_model = new OV_Professional();
                $professional = $professional_model->get($professional_id);
                $professional_display = $professional ? $professional->name : null;
            }
            
            // Si no hay profesional seleccionado, verificar si están habilitados
            if (!$professional_display) {
                $professionals_enabled = !empty($form->enable_professionals);
                
                if ($professionals_enabled) {
                    $professional_display = 'Cualquier profesional disponible';
                } else {
                    $professional_display = null; // No mostrar nada si no están habilitados
                }
            }
            
            $duration_value = (int) ($service->duration ?? 0);
            $duration_unit  = strtolower(trim($service->duration_unit ?? 'minutes'));

            switch ($duration_unit) {
                case 'hours':
                    $duration_label = _n( 'hora', 'horas', $duration_value, 'ov-booking-system' );
                    break;

                case 'days':
                    $duration_label = _n( 'día', 'días', $duration_value, 'ov-booking-system' );
                    break;

                case 'minutes':
                default:
                    $duration_label = _n( 'minuto', 'minutos', $duration_value, 'ov-booking-system' );
                    break;
            }

            $booking_info = array(
                'booking_id' => $custom_id,
                'service_name' => $service->name,
                'date' => $date,
                'time' => $time,
                'duration' => $duration_value . ' ' . $duration_label,
                'price' => !empty($service->price) ? $service->price : '',
                'professional_name' => $professional_display,
                'customer_name' => $full_name,
                'customer_email' => isset($customer_data['email']) ? $customer_data['email'] : '',
                'customer_phone' => isset($customer_data['phone']) ? $customer_data['phone'] : '',
                'notes' => isset($customer_data['notes']) ? $customer_data['notes'] : ''
            );

            $saved_reservation = $reservation_model->get($booking_id);
            $booking_info['calendar'] = OV_Google_Calendar::build_client_event(
                $saved_reservation,
                $form,
                $service,
                $professional_display
            );

            wp_send_json_success(array(
            'message' => __('Reserva creada exitosamente', 'ov-booking-system'),
            'booking' => $booking_info
        ));
        } catch (Throwable $e) {
            if ($lock_name) {
                $this->release_booking_lock($lock_name);
            }
            error_log('OV Booking create error: ' . get_class($e));
            wp_send_json_error(array(
                'message' => __('No fue posible crear la reserva. Inténtalo nuevamente.', 'ov-booking-system')
            ), 500);
        }
    }

    /**
     * Obtener configuración del formulario
     */
    public function get_form_config() {
        try {
            $form_id = $this->parse_public_integer($_REQUEST['form_id'] ?? null);

            if ($form_id === false) {
                wp_send_json_error(array(
                    'message' => __('ID de formulario no válido', 'ov-booking-system')
                ), 400);
            }

            $form = $this->get_active_public_form($form_id);
            if (!$form) {
                wp_send_json_error(array(
                    'message' => __('Formulario no encontrado', 'ov-booking-system')
                ), 404);
            }

            // Obtener días festivos
            $schedule_model = new OV_Schedule();
            $holidays = $schedule_model->get_holidays($form_id);

            // Conservar el campo público sin consultar los horarios uno por uno.
            $working_days = $schedule_model->get_working_days_for_form($form_id, !empty($form->enable_professionals));

            $config = array(
                'enable_professionals' => (bool) $form->enable_professionals,
                'allow_any_professional' => isset($form->allow_any_professional) ? (bool) $form->allow_any_professional : true,
                'colors' => $form->colors_config,
                'timezone' => $form->timezone,
                'booking_limit_hours' => (int) $form->booking_limit_hours,
                'booking_window' => $this->get_public_booking_window($form),
                'week_starts_on' => (int) $form->week_starts_on,
                'working_days' => $working_days,
                'holidays' => $holidays,
                'terms_config' => $form->terms_config // AGREGADO
            );

            wp_send_json_success($config);
        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    /**
     * Actualizar estado de reserva (solo admin)
     */
    public function update_reservation_status() {
        // Verificar permisos de admin
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')
            ));
            return;
        }

        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ov_admin_nonce')) {
            wp_send_json_error(array(
                'message' => __('Error de seguridad', 'ov-booking-system')
            ));
            return;
        }

        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$reservation_id || !$status) {
            wp_send_json_error(array(
                'message' => __('Datos inválidos', 'ov-booking-system')
            ));
            return;
        }

        // Validar que el estado es válido
        $valid_statuses = array('pending', 'confirmed', 'completed', 'cancelled');
        if (!in_array($status, $valid_statuses, true)) {
            wp_send_json_error(array(
                'message' => __('Estado inválido', 'ov-booking-system')
            ));
            return;
        }

        $updated = $this->guarded_admin_reservation_update($reservation_id, array('status' => $status));
        if (is_wp_error($updated)) {
            wp_send_json_error(array('message' => $updated->get_error_message()), 409);
        }
        if ($updated !== false) {
            wp_send_json_success(array(
                'message' => __('Estado actualizado correctamente', 'ov-booking-system')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Error al actualizar el estado', 'ov-booking-system')
            ));
        }
    }



    /**
     * Actualizar reserva completa (solo admin).
     */
    public function admin_update_reservation() {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')));
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ov_admin_nonce')) {
            wp_send_json_error(array('message' => __('Error de seguridad', 'ov-booking-system')));
            return;
        }

        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        if (!$reservation_id) {
            wp_send_json_error(array('message' => __('Reserva no válida', 'ov-booking-system')));
            return;
        }

        $data = array(
            'customer_name'  => isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '',
            'customer_email' => isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '',
            'customer_phone' => isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '',
            'booking_date'   => isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '',
            'booking_time'   => isset($_POST['booking_time']) ? sanitize_text_field(wp_unslash($_POST['booking_time'])) : '',
            'status'         => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'pending',
            'notes'          => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
        );

        if (isset($_POST['duration_minutes'])) {
            $raw_duration = is_scalar($_POST['duration_minutes']) ? (string) wp_unslash($_POST['duration_minutes']) : '';
            if (!preg_match('/^[0-9]{1,4}$/', $raw_duration) || (int) $raw_duration < 1 || (int) $raw_duration > 1440) {
                wp_send_json_error(array('message' => __('Duración no válida.', 'ov-booking-system')), 400);
            }
            $data['requested_duration_minutes'] = (int) $raw_duration;
        }

        if (!in_array($data['status'], OV_Reservation::ALLOWED_STATUSES, true)) {
            wp_send_json_error(array('message' => __('Estado inválido', 'ov-booking-system')), 400);
        }

        $reservation_model = new OV_Reservation();
        $reservation = $reservation_model->get($reservation_id);
        if (!$reservation) {
            wp_send_json_error(array('message' => __('Reserva no encontrada', 'ov-booking-system')));
            return;
        }

        $validation = new OV_Validation();
        $validation_errors = $validation->validate_booking_data(array(
            'form_id' => (int) $reservation->form_id,
            'service_id' => (int) $reservation->service_id,
            'booking_date' => $data['booking_date'],
            'booking_time' => $data['booking_time'],
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
        ));
        if (!$validation->validate_email($data['customer_email'])) {
            $validation_errors[] = __('Email inválido', 'ov-booking-system');
        }
        if (!empty($validation_errors)) {
            wp_send_json_error(array('message' => implode(' ', $validation_errors)), 400);
        }
        $data['customer_phone'] = OV_Validation::normalize_phone($data['customer_phone']);

        $updated = $this->guarded_admin_reservation_update($reservation_id, $data);
        if (is_wp_error($updated)) {
            wp_send_json_error(array('message' => $updated->get_error_message()), 409);
        }
        if ($updated !== false) {
            wp_send_json_success(array('message' => __('Reserva actualizada correctamente', 'ov-booking-system')));
        }
        wp_send_json_error(array('message' => __('Error al actualizar la reserva', 'ov-booking-system')));
    }

    /** Serializar ediciones con la creación de reservas y revalidar cupo al ocuparlo. */
    private function guarded_admin_reservation_update($reservation_id, $data) {
        $model = new OV_Reservation();
        $reservation = $model->get($reservation_id);
        if (!$reservation) {
            return new WP_Error('missing_reservation', __('Reserva no encontrada', 'ov-booking-system'));
        }
        $lock_name = $this->acquire_booking_lock((int) $reservation->form_id);
        if (!$lock_name) {
            return new WP_Error('booking_busy', __('El sistema está procesando otra reserva. Inténtalo nuevamente.', 'ov-booking-system'));
        }
        try {
            // El estado puede haber cambiado después de la primera lectura.
            $reservation = $model->get($reservation_id);
            if (!$reservation) {
                return new WP_Error('missing_reservation', __('Reserva no encontrada', 'ov-booking-system'));
            }
            $status = $data['status'] ?? $reservation->status;
            $date = $data['booking_date'] ?? $reservation->booking_date;
            $time = substr($data['booking_time'] ?? $reservation->booking_time, 0, 5);
            $moves = $date !== $reservation->booking_date ||
                $time !== substr($reservation->booking_time, 0, 5);
            $service_model = new OV_Service();
            $original_minutes = $service_model->get_duration_in_minutes($reservation);
            $requested_minutes = $data['requested_duration_minutes'] ?? $original_minutes;
            unset($data['requested_duration_minutes']);
            $extends = $requested_minutes !== $original_minutes;
            if ($extends && (
                !in_array($reservation->status, array('pending', 'confirmed'), true)
                || !in_array($status, array('pending', 'confirmed'), true)
                || ($reservation->duration_unit ?? '') === 'days'
                || $requested_minutes <= $original_minutes
                || $requested_minutes > 1440
                || ($requested_minutes - $original_minutes) % 30 !== 0
            )) {
                return new WP_Error('invalid_extension', __('Solo puedes ampliar una reserva activa en tramos de 30 minutos.', 'ov-booking-system'));
            }
            if ($extends && (
                $moves || $status !== $reservation->status
                || (isset($data['customer_name']) && $data['customer_name'] !== $reservation->customer_name)
                || (isset($data['customer_email']) && $data['customer_email'] !== $reservation->customer_email)
                || (isset($data['customer_phone']) && $data['customer_phone'] !== $reservation->customer_phone)
            )) {
                return new WP_Error('extension_separate_edit', __('Guarda los otros cambios primero y luego amplía la duración.', 'ov-booking-system'));
            }
            if ($extends) {
                $data['duration'] = $requested_minutes;
                $data['duration_unit'] = 'minutes';
            }
            $occupies_new_interval = $status !== 'cancelled' && ($extends || $moves || $reservation->status === 'cancelled');
            if ($occupies_new_interval && ($moves || $reservation->status === 'cancelled')) {
                $availability = new OV_Availability();
                if (!$availability->is_slot_available(
                    (int) $reservation->form_id,
                    (int) $reservation->service_id,
                    $date,
                    $time,
                    !empty($reservation->professional_id) ? (int) $reservation->professional_id : null,
                    $reservation_id
                )) {
                    return new WP_Error('slot_unavailable', __('El horario ya no está disponible; la reserva no se modificó.', 'ov-booking-system'));
                }
            }
            if ($occupies_new_interval) {
                $service = $service_model->get((int) $reservation->service_id);
                if (!$service || $requested_minutes <= 0) {
                    return new WP_Error('missing_service', __('No se pudo validar la duración del servicio.', 'ov-booking-system'));
                }
                if (($reservation->duration_unit ?? '') === 'days') {
                    $data['end_time'] = '23:59:59';
                } else {
                    $data['end_time'] = (new OV_DateTime())->add_minutes($time, $requested_minutes + max(0, (int) ($service->buffer_time ?? 0)));
                    $interval_check = (new OV_Availability())->validate_reservation_interval($reservation, $date, $time, $data['end_time']);
                    if (is_wp_error($interval_check)) {
                        return $interval_check;
                    }
                }
            }
            return $model->update($reservation_id, $data);
        } finally {
            $this->release_booking_lock($lock_name);
        }
    }

    /**
     * Eliminar reserva (solo admin).
     */
    public function admin_delete_reservation() {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')));
            return;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ov_admin_nonce')) {
            wp_send_json_error(array('message' => __('Error de seguridad', 'ov-booking-system')));
            return;
        }
        $reservation_id = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
        if (!$reservation_id) {
            wp_send_json_error(array('message' => __('Reserva no válida', 'ov-booking-system')));
            return;
        }
        $reservation_model = new OV_Reservation();
        $deleted = $reservation_model->delete($reservation_id);
        if ($deleted !== false) {
            wp_send_json_success(array('message' => __('Reserva eliminada correctamente', 'ov-booking-system')));
        }
        wp_send_json_error(array('message' => __('Error al eliminar la reserva', 'ov-booking-system')));
    }

    /** Filtros canonizados que también usa el listado. */
    private function admin_clear_filters() {
        $fields = array('form_id', 'status', 'professional_id', 'search');
        $input = array();
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            $input[$field] = is_scalar($value) ? wp_unslash((string) $value) : '';
        }
        return OV_Reservation::normalize_admin_filters($input);
    }

    private function check_admin_clear_request() {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')), 403);
        }
        $nonce = $_POST['nonce'] ?? '';
        if (!is_scalar($nonce) || !wp_verify_nonce(wp_unslash((string) $nonce), 'ov_admin_nonce')) {
            wp_send_json_error(array('message' => __('Error de seguridad', 'ov-booking-system')), 403);
        }
    }

    /** Previsualizar el conjunto completo, sin aplicar paginación. */
    public function admin_preview_clear_reservations() {
        $this->check_admin_clear_request();
        $filters = $this->admin_clear_filters();
        $selection = (new OV_Reservation())->scan_admin_selection($filters);
        if ($selection === false) {
            wp_send_json_error(array('message' => __('Error al consultar las reservas', 'ov-booking-system')), 500);
        }
        $count = $selection['count'];
        $form = $filters['form_id'] ? (new OV_Form())->get($filters['form_id']) : null;
        $professional = $filters['professional_id'] > 0
            ? (new OV_Professional())->get($filters['professional_id']) : null;
        $scope = array(
            sprintf(__('Formulario: %s', 'ov-booking-system'), $filters['form_id']
                ? ($form ? $form->name : '#' . $filters['form_id'])
                : __('todos los formularios activos', 'ov-booking-system')),
            sprintf(__('Estado: %s', 'ov-booking-system'), $filters['status'] !== ''
                ? $filters['status'] : __('todos', 'ov-booking-system')),
            sprintf(__('Profesional: %s', 'ov-booking-system'), $filters['professional_id'] === -1
                ? __('sin profesional', 'ov-booking-system')
                : ($filters['professional_id'] > 0
                    ? ($professional ? $professional->name : '#' . $filters['professional_id'])
                    : __('todos', 'ov-booking-system'))),
            sprintf(__('Búsqueda: %s', 'ov-booking-system'), $filters['search'] !== ''
                ? $filters['search'] : __('ninguna', 'ov-booking-system')),
        );
        wp_send_json_success(array(
            'count' => $count,
            'token' => $count ? wp_create_nonce('ov_clear_selection_' . $selection['fingerprint']) : '',
            'message' => $count
                ? sprintf(
                    __('Se eliminarán %d reservas de todas las páginas con estos filtros:', 'ov-booking-system'),
                    $count
                ) . "\n" . implode("\n", $scope) . "\n" . __('¿Deseas continuar?', 'ov-booking-system')
                : __('No hay reservas que coincidan con los filtros.', 'ov-booking-system'),
        ));
    }

    /** Borrar exclusivamente los ID que siguen coincidiendo con la previsualización. */
    public function admin_clear_reservations() {
        $this->check_admin_clear_request();
        $filters = $this->admin_clear_filters();
        $model = new OV_Reservation();
        // php://temp conserva los ID exactos y vuelca a disco tras 2 MB.
        $stream = @fopen('php://temp/maxmemory:2097152', 'w+');
        if ($stream === false) {
            wp_send_json_error(array('message' => __('No fue posible preparar el borrado.', 'ov-booking-system')), 500);
        }
        $selection = $model->scan_admin_selection($filters, static function($id) use ($stream) {
            $line = $id . "\n";
            return fwrite($stream, $line) === strlen($line);
        });
        if ($selection === false) {
            fclose($stream);
            wp_send_json_error(array('message' => __('Error al consultar las reservas', 'ov-booking-system')), 500);
        }
        $token = $_POST['selection_token'] ?? '';
        if (!is_scalar($token) || !wp_verify_nonce(wp_unslash((string) $token), 'ov_clear_selection_' . $selection['fingerprint'])) {
            fclose($stream);
            wp_send_json_error(array('message' => __('El listado cambió. Revisa la cantidad y confirma nuevamente.', 'ov-booking-system')), 409);
        }
        $deleted_count = 0;
        $failed_count = 0;
        rewind($stream);
        do {
            $batch = array();
            for ($index = 0; $index < 200 && ($line = fgets($stream)) !== false; $index++) {
                $batch[] = (int) $line;
            }
            foreach ($batch as $id) {
                // El modelo emite ov_booking_reservation_deleted para cada add-on.
                if ($model->delete($id) === 1) {
                    $deleted_count++;
                } else {
                    $failed_count++;
                }
            }
        } while ($batch);
        fclose($stream);
        if ($failed_count) {
            wp_send_json_error(array('message' => sprintf(__('Se eliminaron %1$d reservas; %2$d no se pudieron eliminar.', 'ov-booking-system'), $deleted_count, $failed_count)), 500);
        }
        wp_send_json_success(array('message' => sprintf(__('%d reservas eliminadas correctamente', 'ov-booking-system'), $deleted_count)));
    }


    // ========================================
    // ADMIN  Nueva reserva: Obtiene el servicio según el formulario seleccionado
    // ========================================
    public function ajax_get_services() {

    if (!current_user_can(OV_Roles::CAPABILITY)) {
        wp_send_json_error(array(
            'message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')
        ));
        return;
    }

    check_ajax_referer('ov_admin_nonce', 'nonce');

    $form_id = intval($_POST['form_id']);

    if (!$form_id) {
        wp_send_json_error();
    }

    $service_model = new OV_Service();

    $services = $service_model->get_by_form($form_id);

    wp_send_json_success($services);

    }

    // ========================================
    // ADMIN - Nueva reserva: Obtiene el profesional según el servicio seleccionado
    // ========================================
    public function ajax_get_professionals() {

        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')
            ));
            return;
        }

        check_ajax_referer('ov_admin_nonce', 'nonce');

        $service_id = intval($_POST['service_id']);

        if (!$service_id) {
            wp_send_json_error();
        }

        $professional_model = new OV_Professional();

        $professionals = $professional_model->get_by_service($service_id, true);

        wp_send_json_success($professionals);
    }


    // ========================================
    // ADMIN - Nueva reserva: Obtiene los horarios disponibles según el servicio y profesional seleccionado
    // ========================================
    /**
     * Validar que IDs y fecha pertenezcan al mismo formulario activo.
     */
    private function valid_booking_context($form_id, $service_id, $professional_id, $date, $public_request = false) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        list($year, $month, $day) = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) return false;
        $form = (new OV_Form())->get($form_id);
        $service = (new OV_Service())->get($service_id);
        if (!$form || $form->status !== 'active' || !$service || empty($service->active) || (int) $service->form_id !== (int) $form_id) return false;

        if (empty($form->enable_professionals)) {
            if ($professional_id !== null) return false;
        } elseif ($professional_id === 0) {
            if (empty($form->allow_any_professional)) return false;
        } else {
            if (!$professional_id) return false;
            if ($public_request) {
                if (!(new OV_Professional())->is_public_for_service($professional_id, $service_id, $form_id)) return false;
            } else {
                $professional_ids = array_map('intval', wp_list_pluck((new OV_Professional())->get_by_service($service_id, true), 'id'));
                if (!in_array((int) $professional_id, $professional_ids, true)) return false;
            }
        }

        if ($public_request) {
            return $this->is_valid_public_date($date, $form);
        }
        $now = (new OV_DateTime())->get_current_datetime($form->timezone);
        return $date >= $now->format('Y-m-d');
    }

    private function valid_admin_booking_context($form_id, $service_id, $professional_id, $date) {
        return $this->valid_booking_context($form_id, $service_id, $professional_id, $date, false);
    }

    /**
     * Registrar solo la clase del error y devolver un mensaje público estable.
     */
    private function send_internal_error($error) {
        error_log('OV Booking request error: ' . get_class($error));
        wp_send_json_error(array(
            'message' => __('No fue posible procesar la solicitud. Inténtalo nuevamente.', 'ov-booking-system')
        ), 500);
    }

    /**
     * Bloqueo asesor MySQL por formulario. Serializa solo la breve sección
     * comprobar-disponibilidad/escribir y cubre también servicios de varios días.
     */
    private function acquire_booking_lock($form_id) {
        global $wpdb;
        $lock_name = 'ovb_' . substr(hash('sha256', (string) (int) $form_id), 0, 59);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
        return ((int) $acquired === 1) ? $lock_name : false;
    }

    private function release_booking_lock($lock_name) {
        if (!$lock_name) return;
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    /**
     * ID público impredecible; el autoincremental queda reservado al uso interno.
     */
    private function generate_booking_id($form) {
        $prefix = strtoupper((string) ($form->booking_prefix ?? 'OV'));
        $prefix = substr(preg_replace('/[^A-Z0-9]/', '', $prefix), 0, 4);
        if ($prefix === '') $prefix = 'OV';

        $model = new OV_Reservation();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $random = strtoupper(bin2hex(random_bytes(8)));
            } catch (Exception $e) {
                $random = strtoupper(str_replace('-', '', wp_generate_uuid4()));
            }
            $candidate = $prefix . '-' . substr($random, 0, 16);
            if (!$model->get_by_booking_id($candidate)) return $candidate;
        }

        throw new RuntimeException('Unable to generate a unique booking identifier.');
    }

    private function get_request_ip_hash() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    /**
     * Las consultas públicas mensuales y diarias comparten el contador por IP.
     * Se llama únicamente después de validar el contexto y la fecha.
     */
    private function enforce_public_availability_rate_limit() {
        if (is_user_logged_in()) {
            return;
        }

        // Conservar el scope existente para no reiniciar los contadores al actualizar.
        $attempts = $this->increment_rate_limit('month_availability_ip', $this->get_request_ip_hash(), 600);
        if ($attempts === false) {
            wp_send_json_error(array('message' => __('No fue posible consultar la disponibilidad. Inténtalo nuevamente.', 'ov-booking-system')), 503);
        }
        if ($attempts > 60) {
            wp_send_json_error(array('message' => __('Demasiadas consultas de disponibilidad. Espera unos minutos.', 'ov-booking-system')), 429);
        }
    }

    /**
     * Límites atómicos: 8 intentos/10 min por IP y 5 intentos/hora por teléfono.
     */
    private function check_public_booking_rate_limits($phone) {
        $checks = array(
            array('scope' => 'ip', 'value' => $this->get_request_ip_hash(), 'limit' => 8, 'seconds' => 600),
            array('scope' => 'phone', 'value' => hash_hmac('sha256', $phone, wp_salt('auth')), 'limit' => 5, 'seconds' => 3600),
        );

        foreach ($checks as $check) {
            $result = $this->increment_rate_limit($check['scope'], $check['value'], $check['seconds']);
            if ($result === false) {
                return new WP_Error('rate_limit_unavailable', __('No fue posible validar la solicitud. Inténtalo nuevamente.', 'ov-booking-system'));
            }
            if ($result > $check['limit']) {
                return new WP_Error('rate_limited', __('Has realizado demasiados intentos. Espera unos minutos antes de volver a reservar.', 'ov-booking-system'));
            }
        }

        return true;
    }

    private function increment_rate_limit($scope, $identifier_hash, $window_seconds) {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_rate_limits';
        $rate_key = hash('sha256', $scope . '|' . $identifier_hash);
        $expires = gmdate('Y-m-d H:i:s', time() + (int) $window_seconds);
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (rate_key, attempts, window_expires) VALUES (%s, 1, %s)
             ON DUPLICATE KEY UPDATE
                attempts = IF(window_expires <= UTC_TIMESTAMP(), 1, attempts + 1),
                window_expires = IF(window_expires <= UTC_TIMESTAMP(), %s, window_expires)",
            $rate_key,
            $expires,
            $expires
        );
        if ($wpdb->query($sql) === false) return false;

        // Limpieza probabilística para evitar crecimiento indefinido sin añadir otro cron.
        if (wp_rand(1, 100) === 1) {
            $wpdb->query("DELETE FROM {$table} WHERE window_expires < (UTC_TIMESTAMP() - INTERVAL 1 DAY)");
        }

        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT attempts FROM {$table} WHERE rate_key = %s",
            $rate_key
        ));
        return ($attempts === null || $attempts === false) ? false : (int) $attempts;
    }

    /**
     * Responder un reintento idempotente con la misma reserva persistida.
     */
    private function send_booking_success($reservation) {
        $form = (new OV_Form())->get((int) $reservation->form_id);
        $service = (new OV_Service())->get((int) $reservation->service_id);
        if (!$form || !$service) {
            wp_send_json_error(array('message' => __('La reserva existe, pero no se pudo cargar su información.', 'ov-booking-system')), 500);
        }

        $professional_name = null;
        if (!empty($reservation->professional_id)) {
            $professional = (new OV_Professional())->get((int) $reservation->professional_id);
            $professional_name = $professional ? $professional->name : null;
        }

        $duration_value = (int) $reservation->duration;
        $unit = strtolower((string) $reservation->duration_unit);
        if ($unit === 'hours') $duration_label = _n('hora', 'horas', $duration_value, 'ov-booking-system');
        elseif ($unit === 'days') $duration_label = _n('día', 'días', $duration_value, 'ov-booking-system');
        else $duration_label = _n('minuto', 'minutos', $duration_value, 'ov-booking-system');

        $info = array(
            'booking_id' => $reservation->booking_id,
            'service_name' => $service->name,
            'date' => $reservation->booking_date,
            'time' => $reservation->booking_time,
            'duration' => $duration_value . ' ' . $duration_label,
            'price' => $reservation->price,
            'professional_name' => $professional_name,
            'customer_name' => $reservation->customer_name,
            'customer_email' => $reservation->customer_email,
            'customer_phone' => $reservation->customer_phone,
            'notes' => $reservation->notes,
            'calendar' => OV_Google_Calendar::build_client_event($reservation, $form, $service, $professional_name),
        );
        wp_send_json_success(array(
            'message' => __('Reserva creada exitosamente', 'ov-booking-system'),
            'booking' => $info,
            'idempotent' => true,
        ));
    }

    public function ajax_admin_get_available_slots() {

        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error(array(
                'message' => __('No tienes permisos para realizar esta acción', 'ov-booking-system')
            ));
            return;
        }

        check_ajax_referer('ov_admin_nonce', 'nonce');

        $form_id = isset($_POST['form_id'])
            ? intval($_POST['form_id'])
            : 0;

        $service_id = isset($_POST['service_id'])
            ? intval($_POST['service_id'])
            : 0;

        $professional_id = isset($_POST['professional_id'])
            ? intval($_POST['professional_id'])
            : 0;

        $date = isset($_POST['date'])
            ? sanitize_text_field($_POST['date'])
            : '';

        if (!$form_id || !$service_id || !$professional_id || !$date) {
            wp_send_json_error(array(
                'message' => __('Datos incompletos', 'ov-booking-system')
            ));
            return;
        }

        try {

            if (!$this->valid_admin_booking_context($form_id, $service_id, $professional_id, $date)) {
                wp_send_json_error(array('message' => __('Revisa el formulario, servicio, profesional y fecha seleccionados.', 'ov-booking-system')));
                return;
            }

            $availability = new OV_Availability();

            $slots = $availability->get_available_slots(
                $form_id,
                $service_id,
                $date,
                $professional_id
            );

            $available_slots = array();

            foreach ($slots as $slot) {

                if (empty($slot['available'])) {
                    continue;
                }

                $available_slots[] = array(
                    'time' => $slot['time'],
                    'end_time' => $slot['end_time'],
                    'available' => true,
                    'capacity' => isset($slot['capacity'])
                        ? intval($slot['capacity'])
                        : null,
                    'remaining' => isset($slot['remaining'])
                        ? intval($slot['remaining'])
                        : null
                );
            }

            wp_send_json_success($available_slots);

        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    // ========================================
    // ADMIN: GESTIÓN DE PROFESIONALES
    // ========================================

    /**
     * Obtener datos de un profesional (ADMIN)
     */
    public function admin_get_professional() {
        // Verificar permisos
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error('Permisos insuficientes');
            return;
        }

        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ov_professional_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }

        $professional_id = isset($_POST['professional_id']) ? intval($_POST['professional_id']) : 0;

        if (!$professional_id) {
            wp_send_json_error('ID de profesional no válido');
            return;
        }

        $professional_model = new OV_Professional();
        $professional = $professional_model->get($professional_id);

        if (!$professional) {
            wp_send_json_error('Profesional no encontrado');
            return;
        }

        // Obtener servicios asignados
        $assigned_services = $professional_model->get_assigned_services($professional_id);
        $service_ids = array_map(function($s) { return $s->id; }, $assigned_services);

        wp_send_json_success(array(
            'id' => $professional->id,
            'name' => $professional->name,
            'email' => $professional->email,
            'phone' => $professional->phone,
            'bio' => $professional->bio,
            'avatar_url' => $professional->avatar_url,
            'display_order' => $professional->display_order,
            'active' => $professional->active,
            'assigned_services' => $service_ids
        ));
    }

    /**
     * Guardar profesional (crear o actualizar) (ADMIN)
     */
    public function admin_save_professional() {
        // Verificar permisos
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error('Permisos insuficientes');
            return;
        }

        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ov_professional_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }

        $professional_id = isset($_POST['professional_id']) ? intval($_POST['professional_id']) : 0;
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;

        if (!$form_id) {
            wp_send_json_error('ID de formulario no válido');
            return;
        }

        // Validar nombre
        if (empty($_POST['name'])) {
            wp_send_json_error('El nombre es obligatorio');
            return;
        }

        $data = array(
            'form_id' => $form_id,
            'name' => sanitize_text_field($_POST['name']),
            'email' => isset($_POST['email']) ? sanitize_email($_POST['email']) : '',
            'phone' => isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '',
            'bio' => isset($_POST['bio']) ? sanitize_textarea_field($_POST['bio']) : '',
            'avatar_url' => isset($_POST['avatar_url']) ? esc_url_raw($_POST['avatar_url']) : '',
            'display_order' => isset($_POST['display_order']) ? intval($_POST['display_order']) : 0,
            'active' => isset($_POST['active']) ? 1 : 0,
            'booking_visibility' => isset($_POST['booking_visibility'])
                ? sanitize_key($_POST['booking_visibility'])
                : 'public'
        );

        $professional_model = new OV_Professional();

        // Crear o actualizar
        if ($professional_id) {
            // Actualizar
            $result = $professional_model->update($professional_id, $data);
        } else {
            // Crear
            $result = $professional_model->create($data);
            $professional_id = $result;
        }

        if ($result) {
            // Asignar servicios
            $service_ids = isset($_POST['services']) ? array_map('intval', $_POST['services']) : array();
            $professional_model->assign_services($professional_id, $service_ids);

            wp_send_json_success(array(
                'message' => 'Profesional guardado correctamente',
                'professional_id' => $professional_id
            ));
        } else {
            wp_send_json_error('Error al guardar el profesional');
        }
    }

    /**
     * Eliminar profesional (ADMIN)
     */
    public function admin_delete_professional() {
        // Verificar permisos
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_send_json_error('Permisos insuficientes');
            return;
        }

        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ov_professional_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }

        $professional_id = isset($_POST['professional_id']) ? intval($_POST['professional_id']) : 0;

        if (!$professional_id) {
            wp_send_json_error('ID de profesional no válido');
            return;
        }

        $professional_model = new OV_Professional();

        // Verificar si tiene reservas
        if ($professional_model->has_bookings($professional_id)) {
            wp_send_json_error('No se puede eliminar: el profesional tiene reservas asociadas. Desactívalo en su lugar.');
            return;
        }

        // Eliminar relaciones con servicios primero
        global $wpdb;
        $table_relation = $wpdb->prefix . 'ov_service_professionals';
        $wpdb->delete($table_relation, array('professional_id' => $professional_id));

        // Eliminar profesional
        $result = $professional_model->delete($professional_id);

        if ($result) {
            wp_send_json_success('Profesional eliminado correctamente');
        } else {
            wp_send_json_error('Error al eliminar el profesional');
        }
    }

    /**
     * Obtener profesionales por servicio (FRONTEND)
     */
    public function get_professionals_by_service() {
        try {
            $service_id = $this->parse_public_integer($_REQUEST['service_id'] ?? null);

            if ($service_id === false) {
                wp_send_json_error(array(
                    'message' => __('ID de servicio no válido', 'ov-booking-system')
                ), 400);
            }

            $service_model = new OV_Service();
            $service = $service_model->get($service_id);
            $form = $service ? $this->get_active_public_form((int) $service->form_id) : false;
            if (!$service || empty($service->active) || !$form || empty($form->enable_professionals)) {
                wp_send_json_error(array(
                    'message' => __('Servicio no disponible para reservas públicas', 'ov-booking-system')
                ), 404);
            }

            $professional_model = new OV_Professional();
            $professionals = $professional_model->get_by_service($service_id, true, true);

            // Formatear datos para frontend
            $formatted = array_map(function($prof) {
                return array(
                    'id' => $prof->id,
                    'name' => $prof->name,
                    'bio' => $prof->bio,
                    'avatar_url' => $prof->avatar_url,
                    'display_order' => $prof->display_order
                );
            }, $professionals);

            wp_send_json_success(array(
                'professionals' => $formatted
            ));

        } catch (Exception $e) {
            $this->send_internal_error($e);
        }
    }

    /**
     * Seleccionar automáticamente el primer profesional disponible para un slot
     *
     * Regresa ID del profesional o null si nadie está disponible.
     */
    private function pick_first_available_professional($form_id, $service_id, $date, $time, $public_only = true) {
        // Cargar formulario para validar configuración
        $form_model = new OV_Form();
        $form = $form_model->get($form_id);

        // Si profesionales no está habilitado, no asignar
        if (!$form || empty($form->enable_professionals)) {
            return null;
        }

        // Si el admin desactivó la opción "cualquier profesional", no permitir usar 0
        if (isset($form->allow_any_professional) && !$form->allow_any_professional) {
            return null;
        }

        // Obtener profesionales asignados al servicio
        $professional_model = new OV_Professional();
        $professionals = $professional_model->get_by_service($service_id, true, $public_only);

        if (empty($professionals)) {
            return null;
        }

        $validation = new OV_Validation();

        foreach ($professionals as $prof) {
            $check = $validation->validate_availability($form_id, $service_id, $date, $time, (int) $prof->id);
            if (!empty($check['valid'])) {
                return (int) $prof->id;
            }
        }

        return null;
    }
}
