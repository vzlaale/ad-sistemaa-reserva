<?php
/** Ejecutar: php tests/reservation-extension.php. Prueba aislada sin WordPress ni MySQL. */
if (PHP_SAPI !== 'cli') exit(1);

class WP_Error {
    private $code;
    public function __construct($code, $message) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($message, $domain = null) { return $message; }
function sanitize_text_field($value) { return $value; }
function sanitize_file_name($value) { return $value; }
function esc_url_raw($value) { return $value; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
class Test_Response extends Exception {}
function wp_send_json_error($data, $status = 200) { throw new Test_Response($data['message']); }
function current_user_can($capability) { return $GLOBALS['can_manage']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === 'ov_admin_nonce'; }
class OV_Roles { const CAPABILITY = 'manage_ov_booking'; }

class Test_WPDB {
    public $lock_acquired = true;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) { return strpos($sql, 'GET_LOCK') !== false && !$this->lock_acquired ? 0 : 1; }
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
class OV_Service {
    public static $buffer = 0;
    public function get($id) { return (object) array('id' => $id, 'duration' => 30, 'duration_unit' => 'minutes', 'buffer_time' => self::$buffer, 'slot_interval' => 30, 'name' => 'Odontología'); }
    public function get_duration_in_minutes($item) {
        return (int) $item->duration * ($item->duration_unit === 'hours' ? 60 : ($item->duration_unit === 'days' ? 1440 : 1));
    }
}
class OV_Schedule {
    public static $start = '09:00:00';
    public static $end = '18:00:00';
    public static $breaks = array();
    public static $special = array();
    public static $holidays = array();
    public function get_special_by_date($form, $date, $day, $professional) { return self::$special; }
    public function get_all_by_day($form, $day, $professional) { return array((object) array('start_time' => self::$start, 'end_time' => self::$end)); }
    public function get_holidays($form, $professional) { return self::$holidays; }
    public function get_breaks($form, $professional, $date, $day) { return self::$breaks; }
}
class OV_DateTime {
    public function get_day_of_week($date) { return (int) date('w', strtotime($date)); }
    public function is_today($date, $timezone) { return false; }
    public function add_minutes($time, $minutes) {
        $parts = explode(':', $time);
        $end = (int) $parts[0] * 60 + (int) $parts[1] + $minutes;
        return sprintf('%02d:%02d', intdiv($end, 60), $end % 60);
    }
}
class OV_Reservation {
    public static $rows = array();
    public static $last_emit = null;
    public static $last_update_data = array();
    public static $update_calls = 0;
    public function get($id) { return isset(self::$rows[$id]) ? clone self::$rows[$id] : null; }
    public function get_by_date($form, $date, $service = null, $professional = null, $exclude = null) {
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

require dirname(__DIR__) . '/includes/helpers/class-ov-availability.php';
require dirname(__DIR__) . '/includes/api/class-ov-ajax-handler.php';
require dirname(__DIR__) . '/includes/api/class-ov-google-calendar.php';

function reservation($id, $start, $end, $duration = 30, $professional = 7, $service = 2) {
    return (object) array('id' => $id, 'form_id' => 1, 'service_id' => $service, 'professional_id' => $professional,
        'booking_date' => '2026-10-27', 'booking_time' => $start, 'end_time' => $end,
        'duration' => $duration, 'duration_unit' => 'minutes', 'status' => 'confirmed', 'booking_id' => 'OV-TEST');
}
function edit($duration, $time = '10:00', $date = '2026-10-27', $status = 'confirmed') {
    $handler = (new ReflectionClass('OV_Ajax_Handler'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod('OV_Ajax_Handler', 'guarded_admin_reservation_update');
    $method->setAccessible(true);
    return $method->invoke($handler, 1, array('status' => $status, 'booking_date' => $date,
        'booking_time' => $time, 'requested_duration_minutes' => $duration));
}

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
OV_Service::$buffer = 30;
OV_Schedule::$end = '11:30:00';
check(is_wp_error(edit(90)), 'El buffer también debe caber antes del cierre');
OV_Service::$buffer = 0;
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
