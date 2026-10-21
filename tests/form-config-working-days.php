<?php
/** Ejecutar: php tests/form-config-working-days.php */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

class OV_Database {
    public function get_results($table, $where, $order_by) {
        if ($table !== 'schedules' || $where !== array('form_id' => 1, 'active' => 1)) {
            throw new RuntimeException('Consulta general inesperada.');
        }
        ++$GLOBALS['general_queries'];
        return $GLOBALS['general_schedules'];
    }
}

class Test_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $table_exists = true;
    public $table_checks = 0;
    public $batch_queries = 0;
    public $professional_days = array();

    public function prepare($sql, ...$values) {
        if (strpos($sql, 'SHOW TABLES LIKE %s') !== false) {
            if ($values !== array('wp_ov_booking_professional_schedules')) {
                throw new RuntimeException('Tabla inesperada.');
            }
        } elseif (strpos($sql, 'SELECT DISTINCT ps.day_of_week') !== false) {
            if ($values !== array(1) || strpos($sql, 'p.form_id = %d AND p.active = 1 AND ps.active = 1') === false ||
                strpos($sql, 'p.id = ps.professional_id') === false) {
                throw new RuntimeException('El lote no conserva los filtros históricos.');
            }
        } else {
            throw new RuntimeException('SQL inesperado.');
        }
        return $sql;
    }
    public function get_var($sql) {
        ++$this->table_checks;
        return $this->table_exists ? 'wp_ov_booking_professional_schedules' : null;
    }
    public function get_col($sql) {
        ++$this->batch_queries;
        return $this->professional_days;
    }
}

require dirname(__DIR__) . '/includes/models/class-ov-schedule.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$GLOBALS['wpdb'] = new Test_WPDB();
$GLOBALS['general_queries'] = 0;
$GLOBALS['general_schedules'] = array((object) array('day_of_week' => '1'), (object) array('day_of_week' => '3'));
$schedule = new OV_Schedule();

$days = $schedule->get_working_days_for_form(1, false);
check($days === array(1, 3) && $GLOBALS['wpdb']->batch_queries === 0, 'Sin profesionales conserva horario general');

$GLOBALS['wpdb']->professional_days = array('3', '5');
$days = $schedule->get_working_days_for_form(1, true);
check($days === array(1, 3, 5), 'Un profesional agrega días sin duplicarlos');

$GLOBALS['wpdb']->professional_days = array('0', '2', '2', '4', '5', '6');
$before = $GLOBALS['wpdb']->batch_queries;
$days = $schedule->get_working_days_for_form(1, true);
check($days === array(0, 1, 2, 3, 4, 5, 6), 'Varios profesionales mantienen unión ordenada');
check($GLOBALS['wpdb']->batch_queries === $before + 1, 'Una sola consulta para todos los profesionales');
check($GLOBALS['wpdb']->table_checks === 2, 'Una comprobación de tabla por formulario');

$GLOBALS['wpdb']->table_exists = false;
$before = $GLOBALS['wpdb']->batch_queries;
$days = $schedule->get_working_days_for_form(1, true);
check($days === array(1, 3) && $GLOBALS['wpdb']->batch_queries === $before, 'Tabla antigua ausente conserva horario general');

$GLOBALS['general_schedules'] = array();
$GLOBALS['wpdb']->professional_days = array();
$GLOBALS['wpdb']->table_exists = true;
check($schedule->get_working_days_for_form(1, true) === array(), 'Sin horarios devuelve arreglo vacío');

echo "Días laborables en lote: OK\n";
