<?php
/** Ejecutar: php tests/whatsapp-duration-event.php. No usa WordPress ni envía mensajes. */
if (PHP_SAPI !== 'cli') exit(1);
define('ABSPATH', __DIR__);

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function __($message, $domain = null) { return $message; }

class Test_WPDB {
    public $prefix = 'wp_';
    public $reads = 0;
    public $queries = 0;
    public $updates = array();
    public function prepare($sql, ...$args) { return $sql; }
    public function get_row($sql) { ++$this->reads; return (object) array('id' => 11); }
    public function get_var($sql) { ++$this->reads; return 0; }
    public function query($sql) { ++$this->queries; return 1; }
    public function update($table, $data, $where) { $this->updates[] = $data; return 1; }
}
class OV_Reservation {
    public static $current;
    public static $reads = 0;
    public function get($id) { ++self::$reads; return self::$current ? clone self::$current : null; }
}
class OV_Form {
    public function get($id) { return (object) array('timezone' => 'America/Santiago'); }
}
class OV_Booking_WhatsApp_API {
    public static function normalize_phone($phone) { return preg_replace('/\D+/', '', $phone); }
    public static function phone_hash($phone) { return hash('sha256', $phone); }
}
class OV_Booking_WhatsApp_Config {
    public static function get() { return array('template_name' => 'test', 'language_code' => 'es_CL'); }
    public static function is_ready() { return false; }
}

require dirname(__DIR__) . '/addons/ov-booking-whatsapp/includes/class-ov-whatsapp-outbox.php';

$GLOBALS['wpdb'] = new Test_WPDB();
$previous = (object) array(
    'status' => 'pending', 'form_id' => 1, 'booking_date' => '2026-11-10',
    'booking_time' => '10:00:00', 'customer_name' => 'Cliente',
    'customer_phone' => '56911111111', 'professional_id' => 7,
);
OV_Reservation::$current = clone $previous;
OV_Reservation::$current->booking_time = '10:00';
OV_Booking_WhatsApp_Outbox::reservation_updated(7, array('duration' => 90, 'end_time' => '11:30'), $previous);
check(OV_Reservation::$reads === 0 && $GLOBALS['wpdb']->reads === 0, 'Duración sola no consulta la reserva ni la cola');

$admin_payload = array(
    'status' => 'pending', 'booking_date' => '2026-11-10', 'booking_time' => '10:00',
    'customer_name' => 'Cliente', 'customer_phone' => '56911111111',
    'duration' => 90, 'end_time' => '11:30',
);
OV_Booking_WhatsApp_Outbox::reservation_updated(7, $admin_payload, $previous);
check($GLOBALS['wpdb']->reads === 0 && $GLOBALS['wpdb']->queries === 0 &&
    $GLOBALS['wpdb']->updates === array(), 'Ampliación administrativa no modifica la cola');

$previous->status = 'confirmed';
OV_Reservation::$current->status = 'confirmed';
$admin_payload['status'] = 'confirmed';
OV_Booking_WhatsApp_Outbox::reservation_updated(7, $admin_payload, $previous);
check($GLOBALS['wpdb']->reads === 0 && $GLOBALS['wpdb']->queries === 0,
    'Ampliación confirmada tampoco invalida botones');

$previous->status = 'pending';
OV_Reservation::$current->status = 'pending';
OV_Reservation::$current->booking_date = '2026-11-11';
OV_Booking_WhatsApp_Outbox::reservation_updated(7, array('booking_date' => '2026-11-11'), $previous);
check(count($GLOBALS['wpdb']->updates) === 1 && $GLOBALS['wpdb']->updates[0]['status'] === 'queued',
    'Cambio real de fecha renueva la cola');

OV_Reservation::$current->status = 'confirmed';
OV_Booking_WhatsApp_Outbox::reservation_updated(7, array('status' => 'confirmed'));
check($GLOBALS['wpdb']->queries === 1, 'Transición sin snapshot invalida acciones pendientes');

echo "WhatsApp ante ampliaciones y cambios relevantes: OK\n";
