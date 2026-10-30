<?php
/** Ejecutar: php tests/reservation-updated-event.php. Prueba aislada del modelo. */
if (PHP_SAPI !== 'cli') exit(1);

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function sanitize_text_field($value) { return (string) $value; }
function sanitize_textarea_field($value) { return (string) $value; }
function sanitize_key($value) { return (string) $value; }
function do_action($name, ...$args) {
    $GLOBALS['events'][] = array($name, $args, clone OV_Database::$row);
}

class OV_Database {
    public static $row;
    public static $fail = false;
    public function get_row($table, $where) { return clone self::$row; }
    public function update($table, $data, $where) {
        if (self::$fail) return false;
        foreach ($data as $key => $value) self::$row->$key = $value;
        return 1;
    }
}

require dirname(__DIR__) . '/includes/models/class-ov-reservation.php';

OV_Database::$row = (object) array(
    'id' => 7, 'status' => 'confirmed', 'duration' => 30,
    'end_time' => '10:30', 'customer_data' => null,
);
$GLOBALS['events'] = array();
$model = new OV_Reservation();
check($model->update(7, array('duration' => 90, 'end_time' => '11:30')) === 1,
    'La ampliación se guarda');
check(count($GLOBALS['events']) === 1 && $GLOBALS['events'][0][0] === 'ov_booking_reservation_updated',
    'Se emite exactamente un evento');
check($GLOBALS['events'][0][1][0] === 7 && $GLOBALS['events'][0][1][1]['duration'] === 90 &&
    $GLOBALS['events'][0][1][1]['end_time'] === '11:30' &&
    $GLOBALS['events'][0][1][2]->duration === 30 &&
    $GLOBALS['events'][0][2]->duration === 90, 'El evento se emite después de guardar con el snapshot anterior');

OV_Database::$fail = true;
check($model->update(7, array('duration' => 120)) === false && count($GLOBALS['events']) === 1,
    'Un fallo de persistencia no emite eventos');
OV_Database::$fail = false;
check($model->update(7, array('status' => 'invalid')) === false && count($GLOBALS['events']) === 1,
    'Un estado inválido no emite eventos');
check($model->update(7, array('duration' => 120), false) === 1 && count($GLOBALS['events']) === 1,
    'La firma heredada conserva el tercer argumento');

echo "Evento de actualización de reservas: OK\n";
