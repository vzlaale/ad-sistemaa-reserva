<?php
/** Run with php tests/benchmark-fixture.php. No WordPress or database needed. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ABSPATH', __DIR__ . '/');
function home_url() { return 'https://agenda.geneal.cl'; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function add_action($name, $callback) {}
function wp_unslash($value) { return $value; }
function ov_bench_authorized() {
    return $GLOBALS['can_manage'] && ($_REQUEST['benchmark_nonce'] ?? '') === 'test';
}
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function add_option($name, $value, $deprecated = '', $autoload = false) {
    if (array_key_exists($name, $GLOBALS['options'])) return false;
    $GLOBALS['options'][$name] = $value;
    return true;
}
function update_option($name, $value, $autoload = false) { $GLOBALS['options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['options'][$name]); return true; }

class Fixture_Response extends Exception {
    public $success;
    public $data;
    public $status;
    public function __construct($success, $data, $status) {
        parent::__construct('JSON response');
        $this->success = $success;
        $this->data = $data;
        $this->status = $status;
    }
}
function wp_send_json_success($data = null, $status = 200) { throw new Fixture_Response(true, $data, $status); }
function wp_send_json_error($data = null, $status = 200) { throw new Fixture_Response(false, $data, $status); }

class Fixture_DB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $tables = array();
    private $next_id = 1;
    private $booking_ids = array();

    public function insert($table, $data) {
        if ($table === 'wp_ov_booking_reservations' && isset($this->booking_ids[$data['booking_id']])) return false;
        $id = $this->next_id++;
        $this->insert_id = $id;
        $data['id'] = $id;
        $this->tables[$table][$id] = $data;
        if ($table === 'wp_ov_booking_reservations') $this->booking_ids[$data['booking_id']] = $id;
        return 1;
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        return array('sql' => $sql, 'args' => $args);
    }
    public function esc_like($value) { return $value; }
    public function get_row($query) {
        $id = $this->booking_ids[$query['args'][0]] ?? null;
        return $id === null ? null : (object) $this->tables['wp_ov_booking_reservations'][$id];
    }
    public function get_col($query) {
        $args = $query['args'];
        $prefix = rtrim($args[2], '%');
        $ids = array();
        foreach ($this->tables['wp_ov_booking_reservations'] ?? array() as $row) {
            if (in_array($row['form_id'], array($args[0], $args[1]), true) &&
                strpos($row['booking_id'], $prefix) === 0) $ids[] = $row['id'];
            if (count($ids) === 200) break;
        }
        return $ids;
    }
    public function get_var($query) {
        $args = $query['args'];
        if (strpos($query['sql'], 'SELECT slug') !== false) {
            return $this->tables['wp_ov_booking_forms'][$args[0]]['slug'] ?? null;
        }
        $count = 0;
        foreach ($this->tables['wp_ov_booking_reservations'] ?? array() as $row) {
            if (in_array($row['form_id'], $args, true)) $count++;
        }
        return $count;
    }
    public function query($query) {
        $args = $query['args'];
        $form_ids = array_slice($args, -2);
        $ids = array_slice($args, 0, -2);
        $deleted = 0;
        foreach ($ids as $id) {
            $row = $this->tables['wp_ov_booking_reservations'][$id] ?? null;
            if ($row && in_array($row['form_id'], $form_ids, true)) {
                unset($this->tables['wp_ov_booking_reservations'][$id], $this->booking_ids[$row['booking_id']]);
                $deleted++;
            }
        }
        return $deleted;
    }
    public function delete($table, $where) {
        $deleted = 0;
        foreach ($this->tables[$table] ?? array() as $id => $row) {
            foreach ($where as $field => $value) {
                if (($row[$field] ?? null) !== $value) continue 2;
            }
            unset($this->tables[$table][$id]);
            if ($table === 'wp_ov_booking_reservations') unset($this->booking_ids[$row['booking_id']]);
            $deleted++;
        }
        return $deleted;
    }
    public function count($table) { return count($this->tables['wp_' . $table] ?? array()); }
}

function fixture_call($function, $args = array()) {
    $_POST = array_merge(array('benchmark_nonce' => 'test'), $args);
    $_REQUEST = $_POST;
    try { $function(); } catch (Fixture_Response $response) { return $response; }
    throw new RuntimeException('El controlador no emitió respuesta.');
}
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }

$GLOBALS['options'] = array();
$GLOBALS['can_manage'] = true;
$GLOBALS['wpdb'] = new Fixture_DB();
require __DIR__ . '/ov-booking-benchmark/fixture.php';
$month = (new DateTimeImmutable('first day of next month', new DateTimeZone('America/Santiago')))->format('Y-m');

$response = fixture_call('ov_bench_fixture_create', array('month' => $month));
verify(!$response->success && $response->status === 400, 'Crear exige confirmar respaldo.');
$GLOBALS['can_manage'] = false;
$response = fixture_call('ov_bench_fixture_create', array('month' => $month, 'backup_confirmed' => '1'));
verify(!$response->success && $response->status === 403, 'Crear exige capacidad de administrador.');
$GLOBALS['can_manage'] = true;

$response = fixture_call('ov_bench_fixture_create', array('month' => $month, 'backup_confirmed' => '1'));
verify($response->success, 'Debe crear el fixture: ' . json_encode($response->data) .
    ' state=' . json_encode(get_option(OV_BENCH_FIXTURE_OPTION)) .
    ' tables=' . json_encode(array_map('count', $GLOBALS['wpdb']->tables)));
$run = $response->data['run'];
verify($GLOBALS['wpdb']->count('ov_booking_forms') === 2 &&
    $GLOBALS['wpdb']->count('ov_booking_professionals') === 2 &&
    $GLOBALS['wpdb']->count('ov_booking_reservations') === 9, 'Fixture inicial incompleto.');
$GLOBALS['can_manage'] = false;
verify(fixture_call('ov_bench_fixture_seed', array('run' => $run))->status === 403,
    'La carga exige capacidad de administrador.');
verify(fixture_call('ov_bench_fixture_cleanup', array('run' => $run))->status === 403,
    'La limpieza exige capacidad de administrador.');
$GLOBALS['can_manage'] = true;
verify(fixture_call('ov_bench_fixture_seed', array('run' => '000000000000'))->status === 400,
    'La carga exige el identificador exacto.');

for ($request = 0; $request < 100; $request++) {
    $response = fixture_call('ov_bench_fixture_seed', array('run' => $run));
    verify($response->success, 'La carga por lotes falló.');
    if ($response->data['phase'] === 'seeded') break;
}
verify($response->data['phase'] === 'seeded' && $GLOBALS['wpdb']->count('ov_booking_reservations') === 12009,
    'No se cargaron las 12 000 reservas históricas.');

$state = get_option(OV_BENCH_FIXTURE_OPTION);
$state['seeded_solo']--;
$state['phase'] = 'ready';
update_option(OV_BENCH_FIXTURE_OPTION, $state);
$response = fixture_call('ov_bench_fixture_seed', array('run' => $run));
verify($response->success && $GLOBALS['wpdb']->count('ov_booking_reservations') === 12009,
    'La reanudación no fue idempotente.');

$GLOBALS['wpdb']->insert('wp_ov_booking_reservations', array(
    'booking_id' => 'UNRELATED', 'form_id' => $state['solo_form'],
));
for ($request = 0; $request < 100; $request++) {
    $response = fixture_call('ov_bench_fixture_cleanup', array('run' => $run));
    if (!$response->success || !$response->data['remaining']) break;
}
verify(!$response->success && $response->status === 409 &&
    $GLOBALS['wpdb']->count('ov_booking_forms') === 2 &&
    $GLOBALS['wpdb']->count('ov_booking_reservations') === 1,
    'La limpieza debe detenerse ante reservas ajenas.');
$GLOBALS['wpdb']->delete('wp_ov_booking_reservations', array('booking_id' => 'UNRELATED'));
$response = fixture_call('ov_bench_fixture_cleanup', array('run' => $run));
verify($response->success && !$response->data['remaining'] &&
    $GLOBALS['wpdb']->count('ov_booking_forms') === 0 &&
    $GLOBALS['wpdb']->count('ov_booking_professionals') === 0 &&
    $GLOBALS['wpdb']->count('ov_booking_reservations') === 0 &&
    get_option(OV_BENCH_FIXTURE_OPTION, false) === false,
    'La limpieza no terminó.');

echo "Fixture P2: permisos, carga por lotes, reanudación y limpieza: OK\n";
