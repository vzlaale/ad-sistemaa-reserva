<?php
/** Ejecutar: php tests/reservation-extension.php. Prueba aislada sin WordPress ni MySQL. */
if (PHP_SAPI !== 'cli') exit(1);

class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($message, $domain = null) { return $message; }
function sanitize_text_field($value) { return $value; }
function sanitize_email($value) { return $value; }
function sanitize_key($value) { return $value; }
function sanitize_textarea_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_file_name($value) { return $value; }
function esc_url_raw($value) { return $value; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
class Test_Response extends Exception {
    public $success;
    public function __construct($success, $data, $status) {
        parent::__construct($data['message'], $status);
        $this->success = $success;
    }
}
function wp_send_json_error($data, $status = 200) { throw new Test_Response(false, $data, $status); }
function wp_send_json_success($data, $status = 200) { throw new Test_Response(true, $data, $status); }
function current_user_can($capability) { return $GLOBALS['can_manage']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === 'ov_admin_nonce'; }
class OV_Roles { const CAPABILITY = 'manage_ov_booking'; }

class Test_WPDB {
    public $lock_acquired = true;
    public $releases = 0;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        if (strpos($sql, 'RELEASE_LOCK') !== false) ++$this->releases;
        return strpos($sql, 'GET_LOCK') !== false && !$this->lock_acquired ? 0 : 1;
    }
}
$GLOBALS['wpdb'] = new Test_WPDB();

class OV_Form {
    public static $capacity = 1;
    public static $mode = 'per_professional';
    public function get($id) {
        return (object) array('id' => $id, 'enable_professionals' => 1,
            'max_bookings_per_slot_type' => self::$mode, 'max_bookings_per_slot' => self::$capacity,
            'booking_limit_hours' => 0, 'timezone' => 'America/Santiago');
    }
}
// Usar el modelo real: MySQL puede devolver duration como texto y el helper lo conserva.
class OV_Database {
    public static $buffer = 0;
    public static $reads = 0;
    public function get_row($table, $where) {
        check($table === 'services', 'Solo se espera leer servicios');
        ++self::$reads;
        return (object) array('id' => $where['id'], 'duration' => '30', 'duration_unit' => 'minutes', 'buffer_time' => self::$buffer, 'slot_interval' => 30, 'name' => 'Odontología');
    }
}
class OV_Schedule {
    public static $reads = 0;
    public static $start = '09:00:00';
    public static $end = '18:00:00';
    public static $breaks = array();
    public static $special = array();
    public static $holidays = array();
    public function get_special_by_date($form, $date, $day, $professional) { ++self::$reads; return self::$special; }
    public function get_all_by_day($form, $day, $professional) { return array((object) array('start_time' => self::$start, 'end_time' => self::$end)); }
    public function get_holidays($form, $professional) { return self::$holidays; }
    public function get_breaks($form, $professional, $date, $day) { return self::$breaks; }
}
class OV_Reservation {
    const ALLOWED_STATUSES = array('pending', 'confirmed', 'completed', 'cancelled');
    public static $rows = array();
    public static $last_emit = null;
    public static $last_update_data = array();
    public static $update_calls = 0;
    public static $availability_reads = 0;
    public function get($id) { return isset(self::$rows[$id]) ? clone self::$rows[$id] : null; }
    public function get_by_date($form, $date, $service = null, $professional = null, $exclude = null) {
        ++self::$availability_reads;
        return array_values(array_filter(self::$rows, static function($row) use ($form, $date, $service, $professional, $exclude) {
            return (int) $row->form_id === $form && $row->booking_date === $date && $row->status !== 'cancelled'
                && ($service === null || (int) $row->service_id === $service)
                && ($professional === null || (int) $row->professional_id === $professional)
                && ($exclude === null || (int) $row->id !== $exclude);
        }));
    }
    public function count_bookings_in_slot($form, $date, $time, $service = null, $professional = null, $exclude = null) {
        return count(array_filter($this->get_by_date($form, $date, $service, $professional, $exclude), static function($row) use ($time) {
            return substr($row->booking_time, 0, 5) === $time;
        }));
    }
    public function update($id, $data, $emit_updated_event = true) {
        ++self::$update_calls;
        self::$last_emit = $emit_updated_event;
        self::$last_update_data = $data;
        foreach ($data as $key => $value) self::$rows[$id]->$key = $value;
        return 1;
    }
}

require dirname(__DIR__) . '/includes/models/class-ov-service.php';
require dirname(__DIR__) . '/includes/helpers/class-ov-datetime.php';
require dirname(__DIR__) . '/includes/helpers/class-ov-validation.php';
require dirname(__DIR__) . '/includes/helpers/class-ov-availability.php';
require dirname(__DIR__) . '/includes/api/class-ov-ajax-handler.php';
require dirname(__DIR__) . '/includes/api/class-ov-google-calendar.php';

function reservation($id, $start, $end, $duration = 30, $professional = 7, $service = 2) {
    return (object) array('id' => $id, 'form_id' => 1, 'service_id' => $service, 'professional_id' => $professional,
        'booking_date' => '2026-10-27', 'booking_time' => $start, 'end_time' => $end,
        'duration' => $duration, 'duration_unit' => 'minutes', 'status' => 'confirmed', 'booking_id' => 'OV-TEST',
        'customer_name' => 'Paciente de prueba', 'customer_email' => 'paciente@example.com',
        'customer_phone' => '56911111111', 'notes' => '');
}
function guarded_edit($data) {
    $handler = (new ReflectionClass('OV_Ajax_Handler'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod('OV_Ajax_Handler', 'guarded_admin_reservation_update');
    $method->setAccessible(true);
    return $method->invoke($handler, 1, $data);
}
function edit($duration, $time = '10:00', $date = '2026-10-27', $status = 'confirmed') {
    return guarded_edit(array('status' => $status, 'booking_date' => $date,
        'booking_time' => $time, 'requested_duration_minutes' => $duration));
}

// Regresión: el formulario envía un entero sobre una duración recuperada como string.
// Un feriado y un conflicto existentes no deben impedir corregir datos sin ocupar otro intervalo.
OV_Schedule::$holidays = array('2026-10-27');
foreach (array('30', '90', '2') as $stored_duration) {
    $hours = $stored_duration === '2';
    $minutes = $hours ? 120 : (int) $stored_duration;
    foreach (array('customer_name' => 'Nombre corregido', 'customer_phone' => '56922222222',
        'customer_email' => 'corregido@example.com', 'notes' => 'Nota corregida') as $field => $value) {
        $original = reservation(1, '10:00:00', $hours ? '12:00:00' : ($minutes === 90 ? '11:30:00' : '10:30:00'), $stored_duration);
        if ($hours) $original->duration_unit = 'hours';
        OV_Reservation::$rows = array(1 => clone $original, 2 => reservation(2, '10:00:00', '10:30:00'));
        $before_reads = array(OV_Database::$reads, OV_Schedule::$reads, OV_Reservation::$availability_reads);
        $before_releases = $GLOBALS['wpdb']->releases;
        check(guarded_edit(array($field => $value, 'requested_duration_minutes' => $minutes)) === 1,
            'Corregir ' . $field . ' sin ampliar duración ' . $stored_duration);
        check(OV_Reservation::$rows[1]->$field === $value, 'Se persiste el dato corregido');
        foreach (array('booking_date', 'booking_time', 'duration', 'duration_unit', 'end_time') as $unchanged) {
            check(OV_Reservation::$rows[1]->$unchanged === $original->$unchanged, 'Se conserva ' . $unchanged);
        }
        check($before_reads === array(OV_Database::$reads, OV_Schedule::$reads, OV_Reservation::$availability_reads),
            'Corregir datos no consulta disponibilidad ni recalcula el fin');
        check($GLOBALS['wpdb']->releases === $before_releases + 1, 'Se libera el bloqueo tras corregir');
        check(OV_Reservation::$last_emit === true && !isset(OV_Reservation::$last_update_data['requested_duration_minutes']),
            'Se conserva el evento y no se persiste el parámetro interno');
    }
}
OV_Schedule::$holidays = array();
OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00', '30'));
check(edit(30) === 1, 'Guardar sin cambios con duración original string');
check(guarded_edit(array('customer_name' => 'Otro nombre')) === 1, 'Editar sin enviar duración');

OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'));
check(edit(90) === 1, 'Ampliar de 30 a 90 minutos');
check(OV_Reservation::$rows[1]->end_time === '11:30' && OV_Reservation::$rows[1]->duration === 90, 'Se actualiza una sola reserva');
check(OV_Reservation::$last_emit === true, 'La ampliación permite emitir el hook de actualización');
check(OV_Reservation::$last_update_data['duration'] === 90 &&
    OV_Reservation::$last_update_data['end_time'] === '11:30', 'El hook recibe la duración y el fin nuevos');
check(edit(120) === 1 && OV_Reservation::$rows[1]->end_time === '12:00', 'Segunda ampliación');

$form = (object) array('calendar_config' => array(), 'timezone' => 'America/Santiago');
$event = OV_Google_Calendar::build_client_event(OV_Reservation::$rows[1], $form, (new OV_Service())->get(2));
check($event['start_utc'] !== $event['end_utc'] &&
    (strtotime($event['end_utc']) - strtotime($event['start_utc'])) === 120 * 60, 'Calendario usa duración de reserva');

OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'), 2 => reservation(2, '11:00:00', '11:30:00'));
$before_updates = OV_Reservation::$update_calls;
$result = edit(90);
check(is_wp_error($result) && $result->get_error_code() === 'professional_conflict', 'Otra cita impide ampliar');
check(OV_Reservation::$rows[1]->duration === 30, 'Conflicto conserva la reserva original');
check(OV_Reservation::$update_calls === $before_updates, 'La ampliación rechazada no actualiza ni emite eventos');

OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'));
OV_Schedule::$breaks = array((object) array('start_time' => '11:00:00', 'end_time' => '12:00:00'));
check(is_wp_error(edit(90)), 'Descanso impide ampliar');
OV_Schedule::$breaks = array();
OV_Schedule::$end = '11:00:00';
check(is_wp_error(edit(90)), 'Cierre impide ampliar');
OV_Schedule::$end = '18:00:00';
OV_Schedule::$special = array((object) array('start_time' => '09:00:00', 'end_time' => '11:00:00', 'closed_all_day' => 0));
check(is_wp_error(edit(90)), 'Horario especial impide ampliar');
OV_Schedule::$special = array();
OV_Schedule::$holidays = array('2026-10-27');
check(is_wp_error(edit(90)), 'Feriado impide ampliar');
OV_Schedule::$holidays = array();
OV_Database::$buffer = 30;
OV_Schedule::$end = '11:30:00';
check(is_wp_error(edit(90)), 'El buffer también debe caber antes del cierre');
OV_Database::$buffer = 0;
OV_Schedule::$end = '18:00:00';

check(is_wp_error(edit(90, '10:00', '2026-10-27', 'pending')), 'Cambio de estado y ampliación se guardan por separado');
check(edit(90) === 1, 'Ampliación tras quitar conflicto');
OV_Schedule::$breaks = array((object) array('start_time' => '12:00:00', 'end_time' => '13:00:00'));
check(is_wp_error(edit(90, '11:30')), 'Mover una cita extendida revalida el intervalo completo');
check(substr(OV_Reservation::$rows[1]->booking_time, 0, 5) === '10:00', 'Movimiento fallido conserva hora original');

OV_Schedule::$breaks = array();
$GLOBALS['wpdb']->lock_acquired = false;
check(is_wp_error(edit(120)) && OV_Reservation::$rows[1]->duration === 90, 'Bloqueo concurrente impide modificar');
$GLOBALS['wpdb']->lock_acquired = true;

OV_Form::$mode = 'global';
OV_Form::$capacity = 1;
OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'),
    2 => reservation(2, '10:30:00', '11:00:00', 30, 8, 3));
check(edit(90) === 1, 'Otro servicio con otro profesional no consume el cupo');

OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'),
    2 => reservation(2, '10:30:00', '11:00:00', 30, 8));
$result = edit(90);
check(is_wp_error($result) && $result->get_error_code() === 'capacity_conflict', 'El mismo servicio llena el cupo global');
check(OV_Reservation::$rows[1]->duration === 30, 'El conflicto de cupo conserva la duración original');

OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'),
    2 => reservation(2, '10:30:00', '11:00:00', 30, 7, 3));
$result = edit(90);
check(is_wp_error($result) && $result->get_error_code() === 'professional_conflict', 'Otro servicio del mismo profesional impide ampliar');
check(OV_Reservation::$rows[1]->duration === 30, 'El conflicto profesional conserva la duración original');

OV_Form::$capacity = 2;
OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'), 2 => reservation(2, '10:30:00', '11:00:00', 30, 8));
check(edit(90) === 1, 'Un cupo libre permite ampliar');
OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00'), 2 => reservation(2, '10:30:00', '11:00:00', 30, 8),
    3 => reservation(3, '10:45:00', '11:15:00', 30, 9));
check(is_wp_error(edit(90)), 'Dos citas simultáneas llenan el cupo global');

// Ejecutar el endpoint compartido por el panel y el listado/calendario con el POST real.
$GLOBALS['can_manage'] = true;
$handler = (new ReflectionClass('OV_Ajax_Handler'))->newInstanceWithoutConstructor();
OV_Form::$mode = 'per_professional';
OV_Form::$capacity = 1;
foreach (array(
    array('duration' => '30', 'minutes' => '30', 'changes' => array('customer_phone' => '+56 9 2222 2222')),
    array('duration' => '90', 'minutes' => '90', 'changes' => array('customer_name' => 'Nombre corregido')),
    array('duration' => '2', 'minutes' => '120', 'changes' => array()),
    array('duration' => '30', 'minutes' => null, 'changes' => array('notes' => 'Nota corregida')),
    array('duration' => '30', 'minutes' => '30', 'changes' => array()),
) as $case) {
    $original = reservation(1, '10:00:00', $case['duration'] === '2' ? '12:00:00' : ($case['duration'] === '90' ? '11:30:00' : '10:30:00'), $case['duration']);
    if ($case['duration'] === '2') $original->duration_unit = 'hours';
    OV_Reservation::$rows = array(1 => clone $original);
    $_POST = array_merge(array(
        'nonce' => 'valid', 'reservation_id' => '1',
        'customer_name' => $original->customer_name, 'customer_email' => $original->customer_email,
        'customer_phone' => $original->customer_phone, 'notes' => $original->notes,
        'booking_date' => $original->booking_date, 'booking_time' => '10:00', 'status' => $original->status,
    ), $case['changes']);
    if ($case['minutes'] !== null) $_POST['duration_minutes'] = $case['minutes'];
    $before_reads = array(OV_Database::$reads, OV_Schedule::$reads, OV_Reservation::$availability_reads);
    $before_updates = OV_Reservation::$update_calls;
    try { $handler->admin_update_reservation(); throw new RuntimeException('Faltó respuesta AJAX'); }
    catch (Test_Response $e) { check($e->success, 'El endpoint guarda la corrección: ' . $e->getMessage()); }
    check(OV_Reservation::$update_calls === $before_updates + 1, 'El endpoint guarda una sola vez');
    foreach (array('duration', 'duration_unit', 'end_time', 'booking_date') as $unchanged) {
        check(OV_Reservation::$rows[1]->$unchanged === $original->$unchanged, 'AJAX conserva ' . $unchanged);
    }
    check(substr(OV_Reservation::$rows[1]->booking_time, 0, 5) === '10:00', 'AJAX conserva la hora');
    foreach ($case['changes'] as $field => $value) {
        $expected = $field === 'customer_phone' ? OV_Validation::normalize_phone($value) : $value;
        check(OV_Reservation::$rows[1]->$field === $expected, 'AJAX persiste ' . $field);
    }
    check($before_reads === array(OV_Database::$reads, OV_Schedule::$reads, OV_Reservation::$availability_reads),
        'AJAX no recalcula disponibilidad al corregir datos');
}

// La normalización no debe aceptar reducciones, saltos inválidos ni saltarse la validación de entradas.
OV_Reservation::$rows = array(1 => reservation(1, '10:00:00', '10:30:00', '30'));
foreach (array(29, 45, 1441) as $invalid_duration) {
    $before_updates = OV_Reservation::$update_calls;
    $result = edit($invalid_duration);
    check(is_wp_error($result) && $result->get_error_code() === 'invalid_extension', 'Rechazar duración ' . $invalid_duration);
    check(OV_Reservation::$update_calls === $before_updates, 'Duración rechazada no se guarda');
}
$result = guarded_edit(array('requested_duration_minutes' => 60, 'customer_name' => 'Nombre corregido'));
check(is_wp_error($result) && $result->get_error_code() === 'extension_separate_edit', 'Ampliación y datos siguen guardándose por separado');
check(edit(60) === 1 && OV_Reservation::$rows[1]->end_time === '11:00', 'Ampliación real sobre duración string');
foreach (array(
    array('duration_minutes' => '30abc'),
    array('duration_minutes' => array('30')),
    array('customer_phone' => '123'),
) as $invalid_fields) {
    $_POST = array_merge($_POST, array('duration_minutes' => '60', 'customer_phone' => '56911111111'), $invalid_fields);
    $before_updates = OV_Reservation::$update_calls;
    try { $handler->admin_update_reservation(); throw new RuntimeException('Se aceptó una entrada inválida'); }
    catch (Test_Response $e) { check(!$e->success && $e->getCode() === 400, 'AJAX rechaza datos inválidos'); }
    check(OV_Reservation::$update_calls === $before_updates, 'Entrada inválida no modifica la reserva');
}

$GLOBALS['can_manage'] = false;
$_POST = array('nonce' => 'valid', 'reservation_id' => '1');
$handler = (new ReflectionClass('OV_Ajax_Handler'))->newInstanceWithoutConstructor();
try { $handler->admin_update_reservation(); throw new RuntimeException('Faltó control de capacidad'); }
catch (Test_Response $e) { check(strpos($e->getMessage(), 'permisos') !== false, 'Control de capacidad'); }
$GLOBALS['can_manage'] = true;
$_POST['nonce'] = 'invalid';
try { $handler->admin_update_reservation(); throw new RuntimeException('Faltó control de nonce'); }
catch (Test_Response $e) { check(strpos($e->getMessage(), 'seguridad') !== false, 'Control de nonce'); }

echo "Ampliación de reservas: OK\n";
