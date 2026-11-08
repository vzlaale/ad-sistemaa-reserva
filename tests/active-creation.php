<?php
/** Ejecutar: php tests/active-creation.php */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

function sanitize_text_field($value) { return trim($value); }
function sanitize_textarea_field($value) { return trim($value); }
function sanitize_email($value) { return trim($value); }
function esc_url_raw($value) { return trim($value); }

class OV_Database {
    public static $inserts = array();
    public static $original_service;

    public function insert($table, $data) {
        self::$inserts[] = array('table' => $table, 'data' => $data);
        return count(self::$inserts);
    }

    public function get_row($table, $where) {
        if ($table !== 'services' || $where !== array('id' => 42)) {
            throw new RuntimeException('Consulta inesperada al duplicar.');
        }
        return self::$original_service;
    }
}

require dirname(__DIR__) . '/includes/models/class-ov-service.php';
require dirname(__DIR__) . '/includes/models/class-ov-professional.php';

function check_active($table, $actual, $expected, $case) {
    if ($actual !== $expected) {
        throw new RuntimeException($table . ': active incorrecto en ' . $case);
    }
    $insert = OV_Database::$inserts[count(OV_Database::$inserts) - 1];
    if ($insert['table'] !== $table || $insert['data']['active'] !== $expected) {
        throw new RuntimeException($table . ': dato insertado incorrecto en ' . $case);
    }
}

$service = new OV_Service();
$professional = new OV_Professional();
$service_data = array('form_id' => 3, 'name' => 'Servicio de prueba', 'duration' => 30);
$professional_data = array('form_id' => 3, 'name' => 'Profesional de prueba');

foreach (array(array('input' => 0, 'expected' => 0), array('input' => 1, 'expected' => 1)) as $case) {
    $service->create($service_data + array('active' => $case['input']));
    check_active('services', OV_Database::$inserts[count(OV_Database::$inserts) - 1]['data']['active'], $case['expected'], 'servicio ' . $case['input']);

    $professional->create($professional_data + array('active' => $case['input']));
    check_active('professionals', OV_Database::$inserts[count(OV_Database::$inserts) - 1]['data']['active'], $case['expected'], 'profesional ' . $case['input']);
}

$service->create($service_data);
check_active('services', OV_Database::$inserts[count(OV_Database::$inserts) - 1]['data']['active'], 0, 'servicio sin active');
$professional->create($professional_data);
check_active('professionals', OV_Database::$inserts[count(OV_Database::$inserts) - 1]['data']['active'], 0, 'profesional sin active');

OV_Database::$original_service = (object) array(
    'form_id' => 3,
    'professional_id' => null,
    'name' => 'Servicio original',
    'description' => '',
    'duration' => 30,
    'duration_unit' => 'minutes',
    'slot_interval' => null,
    'buffer_time' => 0,
    'price' => null,
    'price_text' => '',
    'image_url' => '',
    'display_order' => 0,
);
$service->duplicate(42);
check_active('services', OV_Database::$inserts[count(OV_Database::$inserts) - 1]['data']['active'], 0, 'duplicado');

echo "Creacion y duplicacion inactivas: OK\n";
