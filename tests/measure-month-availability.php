<?php
/**
 * Instrumentación para un clon WordPress con WP-CLI y SAVEQUERIES activado.
 * Ejecutar con: wp eval-file tests/measure-month-availability.php
 * Requiere OV_BENCH_FORM_ID, OV_BENCH_SERVICE_ID, OV_BENCH_YEAR,
 * OV_BENCH_MONTH, OV_BENCH_USER_ID y OV_BENCH_OUTPUT; profesional opcional.
 * La ruta pública real genera el JSON del endpoint; este archivo guarda
 * métricas en OV_BENCH_OUTPUT durante shutdown, antes de terminar PHP.
 */

if (!defined('ABSPATH') || PHP_SAPI !== 'cli' || !class_exists('OV_Ajax_Handler')) {
    throw new RuntimeException('Ejecutar únicamente con WP-CLI y el plugin activo.');
}

$required = array('FORM_ID', 'SERVICE_ID', 'YEAR', 'MONTH', 'USER_ID');
$values = array();
foreach ($required as $name) {
    $raw = getenv('OV_BENCH_' . $name);
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]*$/D', $raw)) {
        throw new RuntimeException('Valor inválido o ausente: OV_BENCH_' . $name);
    }
    $values[$name] = (int) $raw;
}
if ($values['YEAR'] < 1000 || $values['YEAR'] > 9999 || !checkdate($values['MONTH'], 1, $values['YEAR'])) {
    throw new RuntimeException('Mes de medición inválido.');
}

$output = getenv('OV_BENCH_OUTPUT');
if (!is_string($output) || $output === '' || !is_dir(dirname($output))) {
    throw new RuntimeException('OV_BENCH_OUTPUT debe apuntar a un archivo en un directorio existente.');
}
$user = get_user_by('id', $values['USER_ID']);
if (!$user || !user_can($user, 'manage_options')) {
    throw new RuntimeException('OV_BENCH_USER_ID debe ser un administrador del clon.');
}
wp_set_current_user($values['USER_ID']);

$_REQUEST = array(
    'form_id' => (string) $values['FORM_ID'],
    'service_id' => (string) $values['SERVICE_ID'],
    'year' => (string) $values['YEAR'],
    'month' => (string) $values['MONTH'],
);
$professional = getenv('OV_BENCH_PROFESSIONAL_ID');
if (is_string($professional) && $professional !== '') {
    if (!preg_match('/^(0|[1-9][0-9]*)$/D', $professional)) {
        throw new RuntimeException('OV_BENCH_PROFESSIONAL_ID inválido.');
    }
    $_REQUEST['professional_id'] = $professional;
}

global $wpdb;
$start_queries = (int) $wpdb->num_queries;
$start_log = count($wpdb->queries);
$start_time = microtime(true);
register_shutdown_function(function () use ($wpdb, $start_queries, $start_log, $start_time, $output) {
    $elapsed_ms = round((microtime(true) - $start_time) * 1000, 3);
    $query_count = (int) $wpdb->num_queries - $start_queries;
    $snapshot = array_slice($wpdb->queries, $start_log);
    $overlap_sql = null;
    foreach ($snapshot as $query) {
        if (isset($query[0]) && strpos($query[0], 'DATE_ADD(booking_date, INTERVAL duration DAY)') !== false) {
            $overlap_sql = $query[0];
            break;
        }
    }

    // EXPLAIN se ejecuta después de tomar las métricas, para no contaminarlas.
    $explain = $overlap_sql === null ? null : $wpdb->get_results('EXPLAIN ' . $overlap_sql, ARRAY_A);
    $result = array(
        'plugin_version' => defined('OV_BOOKING_VERSION') ? OV_BOOKING_VERSION : null,
        'php_version' => PHP_VERSION,
        'form_id' => $_REQUEST['form_id'],
        'service_id' => $_REQUEST['service_id'],
        'year' => $_REQUEST['year'],
        'month' => $_REQUEST['month'],
        'professional_id' => isset($_REQUEST['professional_id']) ? $_REQUEST['professional_id'] : null,
        'elapsed_ms' => $elapsed_ms,
        'sql_queries' => $query_count,
        'savequeries_enabled' => defined('SAVEQUERIES') && SAVEQUERIES,
        'date_add_query' => $overlap_sql,
        'date_add_explain' => $explain,
        'explain_error' => $overlap_sql === null ? null : $wpdb->last_error,
    );
    file_put_contents($output, wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
});

(new OV_Ajax_Handler())->get_month_availability();
