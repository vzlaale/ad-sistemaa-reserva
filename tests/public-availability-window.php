<?php
/**
 * Ejecutar: php tests/public-availability-window.php
 * Pruebas aisladas de las rutas públicas; no sustituyen una prueba en WordPress.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

class Test_Json_Response extends Error {
    public $data;
    public $status;
    public $success;

    public function __construct($data, $status, $success) {
        parent::__construct('JSON response');
        $this->data = $data;
        $this->status = $status;
        $this->success = $success;
    }
}

function wp_send_json_error($data = null, $status = null) {
    $response = new Test_Json_Response($data, $status ?: 200, false);
    if (!isset($GLOBALS['first_json_response'])) {
        $GLOBALS['first_json_response'] = $response;
    }
    throw $response;
}
function wp_send_json_success($data = null, $status = null) {
    throw new Test_Json_Response($data, $status ?: 200, true);
}
function __($message, $domain = null) { return $message; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim($value); }
function wp_salt($scheme = null) { return 'test-salt'; }
function wp_rand($min, $max) { return 2; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'public' && $action === 'ov_booking_nonce'; }

class Test_DB {
    public $prefix = 'wp_';
    public $attempts = 0;
    public $fail = false;
    public $availability_rate_keys = array();
    public function prepare($sql, ...$args) {
        if (strpos($sql, 'INSERT INTO') === 0 && strpos($sql, 'ov_booking_rate_limits') !== false) {
            $this->availability_rate_keys[] = $args[0];
        }
        return $sql;
    }
    public function query($sql) {
        if ($this->fail) { return false; }
        if (strpos($sql, 'INSERT INTO') === 0) {
            ++$this->attempts;
        }
        return 1;
    }
    public function get_var($sql) { return $this->attempts; }
}

class OV_Form {
    public function get($id) {
        return $id === 1 ? clone $GLOBALS['fixture_form'] : false;
    }
}
class OV_Service {
    public function get($id) {
        return $id === 2 ? clone $GLOBALS['fixture_service'] : false;
    }
}
class OV_Professional {
    public function is_public_for_service($professional_id, $service_id, $form_id = null) {
        return $professional_id === 3 && $service_id === 2 && $form_id === 1;
    }
    public function get_by_service($service_id, $active_only = true, $public_only = false) {
        return $GLOBALS['fixture_professionals'];
    }
}
class OV_Availability {
    public static $calls = 0;
    public function get_month_available_dates($form, $service, $start_date, $end_date, $professional_ids) {
        $dates = array();
        for ($date = new DateTimeImmutable($start_date); $date <= new DateTimeImmutable($end_date); $date = $date->modify('+1 day')) {
            foreach ($professional_ids as $professional_id) {
                foreach ($this->get_available_slots($form->id, $service->id, $date->format('Y-m-d'), $professional_id) as $slot) {
                    if (!empty($slot['available'])) {
                        $dates[] = $date->format('Y-m-d');
                        continue 3;
                    }
                }
            }
        }
        return $dates;
    }
    public function get_available_slots($form_id, $service_id, $date, $professional_id = null) {
        ++self::$calls;
        return array(array(
            'time' => '10:00', 'end_time' => '11:00', 'available' => true,
            'capacity' => 1, 'used' => 0, 'remaining' => 1,
        ));
    }
}
class OV_Schedule {
    public function get_holidays($form_id) { return array(); }
    public function get_by_form($form_id) { return array(); }
}
class OV_DateTime {
    public function get_current_datetime($timezone) {
        return new DateTime('now', new DateTimeZone($timezone));
    }
}

require dirname(__DIR__) . '/includes/api/class-ov-ajax-handler.php';

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function call_route($method, $params, $post = false) {
    $_REQUEST = $params;
    $_POST = $post ? $params : array();
    $GLOBALS['first_json_response'] = null;
    OV_Availability::$calls = 0;
    try {
        (new OV_Ajax_Handler())->$method();
    } catch (Test_Json_Response $response) {
        return $response;
    }
    throw new RuntimeException('La ruta no devolvió JSON: ' . $method);
}

$GLOBALS['wpdb'] = new Test_DB();
$GLOBALS['logged_in'] = true;
$GLOBALS['fixture_form'] = (object) array(
    'id' => 1, 'status' => 'active', 'timezone' => 'UTC',
    'enable_professionals' => 0, 'allow_any_professional' => 1,
    'booking_limit_hours' => 0, 'week_starts_on' => 1,
    'colors_config' => array(), 'terms_config' => array(),
);
$GLOBALS['fixture_service'] = (object) array('id' => 2, 'form_id' => 1, 'active' => 1);
$GLOBALS['fixture_professionals'] = array((object) array('id' => 3));

$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$first_month = $today->format('Y-m');
$last_month = $today->modify('first day of this month')->modify('+5 months');
$end = $last_month->modify('last day of this month')->format('Y-m-d');
$month_params = array('form_id' => '1', 'service_id' => '2', 'year' => $today->format('Y'), 'month' => (string) (int) $today->format('m'));

$response = call_route('get_month_availability', $month_params);
check($response->success && $response->status === 200, 'Mes actual válido');
check($response->data['available_dates'][0] === $today->format('Y-m-d'), 'Primer día público es hoy');
check(OV_Availability::$calls === count($response->data['available_dates']), 'Un cálculo por día permitido');

$response = call_route('get_month_availability', array_merge($month_params, array(
    'year' => $last_month->format('Y'), 'month' => (string) (int) $last_month->format('m'),
)));
check($response->success && end($response->data['available_dates']) === $end, 'Último día del sexto mes permitido');

foreach (array($today->modify('first day of this month')->modify('-1 month'), $last_month->modify('+1 month')) as $outside) {
    $response = call_route('get_month_availability', array_merge($month_params, array(
        'year' => $outside->format('Y'), 'month' => (string) (int) $outside->format('m'),
    )));
    check($response->status === 400 && OV_Availability::$calls === 0, 'Mes fuera de rango rechazado antes del cálculo');
}

foreach (array(
    array('form_id' => array('1')),
    array('year' => array('2026')),
    array('month' => '13'),
    array('service_id' => '999'),
    array('professional_id' => '-1'),
) as $bad) {
    $response = call_route('get_month_availability', array_merge($month_params, $bad));
    check($response->status === 400 && OV_Availability::$calls === 0, 'Entrada mensual inválida rechazada');
}

$GLOBALS['fixture_service']->form_id = 9;
$response = call_route('get_month_availability', $month_params);
check($response->status === 400 && OV_Availability::$calls === 0, 'Servicio de otro formulario rechazado');
$GLOBALS['fixture_service']->form_id = 1;
$GLOBALS['fixture_service']->active = 0;
$response = call_route('get_month_availability', $month_params);
check($response->status === 400 && OV_Availability::$calls === 0, 'Servicio inactivo rechazado');
$GLOBALS['fixture_service']->active = 1;

$GLOBALS['fixture_form']->status = 'inactive';
$response = call_route('get_month_availability', $month_params);
check($response->status === 400 && OV_Availability::$calls === 0, 'Formulario inactivo rechazado');
$response = call_route('get_form_config', array('form_id' => '1'));
check($response->status === 404, 'Configuración de formulario inactivo oculta');
$GLOBALS['fixture_form']->status = 'active';

$slot_params = array('form_id' => '1', 'service_id' => '2', 'date' => $end);
$response = call_route('get_available_slots', $slot_params);
check($response->success && OV_Availability::$calls === 1, 'Horario del último día permitido');
$response = call_route('get_available_slots', array_merge($slot_params, array('date' => $last_month->modify('+1 month')->format('Y-m-d'))));
check($response->status === 400 && OV_Availability::$calls === 0, 'Horario fuera del horizonte rechazado');
$response = call_route('get_available_slots', array_merge($slot_params, array('date' => $today->modify('-1 day')->format('Y-m-d'))));
check($response->status === 400 && OV_Availability::$calls === 0, 'Horario pasado rechazado');

$GLOBALS['fixture_form']->enable_professionals = 1;
$response = call_route('get_month_availability', array_merge($month_params, array('professional_id' => '0')));
check($response->success && OV_Availability::$calls > 0, 'Cualquier profesional permitido');
$GLOBALS['fixture_professionals'] = array();
$response = call_route('get_month_availability', array_merge($month_params, array('professional_id' => '0')));
check($response->success && $response->data['available_dates'] === array() && OV_Availability::$calls === 0, 'Sin profesionales públicos no hay fechas disponibles');
$GLOBALS['fixture_professionals'] = array((object) array('id' => 3));
$response = call_route('get_month_availability', array_merge($month_params, array('professional_id' => '3')));
check($response->success, 'Profesional público asignado permitido');
$response = call_route('get_month_availability', array_merge($month_params, array('professional_id' => '4')));
check($response->status === 400 && OV_Availability::$calls === 0, 'Profesional no público rechazado');
$response = call_route('get_month_availability', $month_params);
check($response->status === 400 && OV_Availability::$calls === 0, 'Profesional obligatorio cuando está habilitado');
$GLOBALS['fixture_form']->allow_any_professional = 0;
$response = call_route('get_month_availability', array_merge($month_params, array('professional_id' => '0')));
check($response->status === 400 && OV_Availability::$calls === 0, 'Cualquier profesional desactivado rechazado');
$GLOBALS['fixture_form']->enable_professionals = 0;
$GLOBALS['fixture_form']->allow_any_professional = 1;

$GLOBALS['logged_in'] = false;
$GLOBALS['wpdb']->availability_rate_keys = array();
$GLOBALS['wpdb']->attempts = 59;
$response = call_route('get_month_availability', array_merge($month_params, array('month' => '13')));
check($response->status === 400 && $GLOBALS['wpdb']->attempts === 59 && OV_Availability::$calls === 0, 'Mes inválido no consume el contador');
$response = call_route('get_available_slots', array_merge($slot_params, array('form_id' => array('1'))));
check($response->status === 400 && $GLOBALS['wpdb']->attempts === 59 && OV_Availability::$calls === 0, 'ID diario inválido no consume el contador');
$response = call_route('get_available_slots', array_merge($slot_params, array('date' => $last_month->modify('+1 month')->format('Y-m-d'))));
check($response->status === 400 && $GLOBALS['wpdb']->attempts === 59 && OV_Availability::$calls === 0, 'Fecha diaria fuera de rango no consume el contador');
$response = call_route('get_month_availability', $month_params);
check($response->success && $GLOBALS['wpdb']->attempts === 60, 'Solicitud mensual anónima número 60 permitida');
$response = call_route('get_available_slots', $slot_params);
check($response->status === 429 && OV_Availability::$calls === 0 && $GLOBALS['wpdb']->attempts === 61, 'Solicitud diaria anónima número 61 limitada');
check(count(array_unique($GLOBALS['wpdb']->availability_rate_keys)) === 1, 'Mes y día comparten la misma clave de contador');
$GLOBALS['wpdb']->attempts = 59;
$response = call_route('get_available_slots', $slot_params);
check($response->success && $GLOBALS['wpdb']->attempts === 60 && OV_Availability::$calls === 1, 'Solicitud diaria anónima número 60 permitida');
$response = call_route('get_month_availability', $month_params);
check($response->status === 429 && OV_Availability::$calls === 0, 'Solicitud mensual anónima número 61 limitada');
$GLOBALS['wpdb']->fail = true;
$response = call_route('get_available_slots', $slot_params);
check($response->status === 503 && OV_Availability::$calls === 0, 'Error del contador bloquea el cálculo diario');
$response = call_route('get_month_availability', $month_params);
check($response->status === 503 && OV_Availability::$calls === 0, 'Error del contador bloquea el cálculo mensual');
$GLOBALS['wpdb']->fail = false;
$GLOBALS['logged_in'] = true;
$prior_attempts = $GLOBALS['wpdb']->attempts;
$response = call_route('get_available_slots', $slot_params);
check($response->success && $GLOBALS['wpdb']->attempts === $prior_attempts, 'Usuario autenticado no consume el contador público');

$previous_error_log = ini_get('error_log');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
$response = call_route('create_booking', array(
    'nonce' => 'public', 'form_id' => '1', 'service_id' => '2',
    'date' => $last_month->modify('+1 month')->format('Y-m-d'), 'time' => '10:00',
), true);
ini_set('error_log', $previous_error_log);
// create_booking captura Throwable; el stub que simula wp_die registra la primera respuesta.
check($GLOBALS['first_json_response']->status === 400, 'Reserva pública directa fuera del horizonte rechazada');

$response = call_route('get_form_config', array('form_id' => '1'));
check($response->success && $response->data['booking_window']['end_date'] === $end, 'Configuración publica límites del servidor');
$GLOBALS['fixture_form']->timezone = 'Pacific/Kiritimati';
$response = call_route('get_form_config', array('form_id' => '1'));
$local_today = new DateTimeImmutable('today', new DateTimeZone('Pacific/Kiritimati'));
check($response->data['booking_window']['start_date'] === $local_today->format('Y-m-d'), 'La ventana usa la zona horaria del formulario');

$GLOBALS['fixture_form']->timezone = 'UTC';
$method = new ReflectionMethod('OV_Ajax_Handler', 'valid_booking_context');
$method->setAccessible(true);
$outside_date = $last_month->modify('+1 month')->format('Y-m-d');
check($method->invoke(new OV_Ajax_Handler(), 1, 2, null, $outside_date, false) === true, 'Administración conserva el horizonte previo');
check($method->invoke(new OV_Ajax_Handler(), 1, 2, null, $outside_date, true) === false, 'Contexto público rechaza el séptimo mes');

echo "Rutas públicas y límites: OK\n";
