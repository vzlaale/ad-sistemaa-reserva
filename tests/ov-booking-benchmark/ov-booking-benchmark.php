<?php
/**
 * Plugin Name: OV Booking Audit Probe (Clone Only)
 * Description: Medición temporal de disponibilidad de OV Booking en el clon agenda.geneal.cl.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH') || wp_parse_url(home_url(), PHP_URL_HOST) !== 'agenda.geneal.cl') {
    return;
}

// wpdb comprueba esta constante al ejecutar cada consulta. Nunca se activa fuera del clon.
if (!defined('SAVEQUERIES')) {
    define('SAVEQUERIES', true);
}

add_action('admin_menu', function () {
    add_management_page('OV Booking Audit Probe', 'OV Booking Audit Probe', 'manage_options', 'ov-booking-audit-probe', 'ov_bench_page');
});
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_ov-booking-audit-probe') {
        return;
    }
    wp_enqueue_script('jquery');
    $config = array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ov_bench_probe'),
        'savequeries' => defined('SAVEQUERIES') && SAVEQUERIES,
    );
    wp_add_inline_script('jquery', 'window.ovBenchConfig = ' . wp_json_encode($config) . ';', 'before');
    wp_add_inline_script('jquery', file_get_contents(__DIR__ . '/probe.js'), 'after');
});
add_action('wp_ajax_ov_get_month_availability', 'ov_bench_tap', 0);
add_action('wp_ajax_ov_get_form_config', 'ov_bench_tap', 0);
add_action('wp_ajax_ov_bench_take', 'ov_bench_take');
add_action('wp_ajax_ov_bench_context', 'ov_bench_context');

function ov_bench_authorized() {
    $nonce = isset($_REQUEST['benchmark_nonce']) && is_string($_REQUEST['benchmark_nonce'])
        ? wp_unslash($_REQUEST['benchmark_nonce']) : '';
    return current_user_can('manage_options') && wp_verify_nonce($nonce, 'ov_bench_probe');
}

function ov_bench_token() {
    $raw = isset($_REQUEST['benchmark_token']) && is_string($_REQUEST['benchmark_token'])
        ? wp_unslash($_REQUEST['benchmark_token']) : '';
    return preg_match('/^[a-f0-9]{32}$/D', $raw) ? $raw : false;
}

function ov_bench_key($token) {
    return 'ov_bench_' . get_current_user_id() . '_' . $token;
}

/** Medir la ruta AJAX real sin modificar su JSON ni añadir consultas antes del cálculo. */
function ov_bench_tap() {
    if (!ov_bench_authorized() || !ov_bench_token()) {
        return;
    }
    if (!defined('SAVEQUERIES') || !SAVEQUERIES) {
        return;
    }

    global $wpdb;
    $token = ov_bench_token();
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    $start_time = microtime(true);
    $start_count = (int) $wpdb->num_queries;
    $start_log = count($wpdb->queries);
    $user_id = get_current_user_id();

    register_shutdown_function(function () use ($wpdb, $token, $action, $start_time, $start_count, $start_log, $user_id) {
        // Capturar antes de ejecutar EXPLAIN o de guardar el resultado.
        $elapsed_ms = round((microtime(true) - $start_time) * 1000, 3);
        $query_count = (int) $wpdb->num_queries - $start_count;
        $queries = array_slice($wpdb->queries, $start_log);
        $sql_time_ms = 0;
        $overlap_sql = null;
        foreach ($queries as $query) {
            $sql_time_ms += isset($query[1]) ? (float) $query[1] * 1000 : 0;
            if ($overlap_sql === null && isset($query[0]) &&
                strpos($query[0], 'DATE_ADD(booking_date, INTERVAL duration DAY)') !== false &&
                strpos($query[0], $wpdb->prefix . 'ov_booking_reservations') !== false) {
                $overlap_sql = $query[0];
            }
        }

        $explain = null;
        $explain_error = null;
        if ($overlap_sql !== null && preg_match('/^\s*SELECT\b/i', $overlap_sql)) {
            $explain = $wpdb->get_results('EXPLAIN ' . $overlap_sql, ARRAY_A);
            $explain_error = $wpdb->last_error;
        }
        $result = array(
            'plugin_version' => defined('OV_BOOKING_VERSION') ? OV_BOOKING_VERSION : null,
            'php_version' => PHP_VERSION,
            'action' => $action,
            'elapsed_ms' => $elapsed_ms,
            'sql_queries' => $query_count,
            'sql_time_ms' => round($sql_time_ms, 3),
            'date_add_explain' => $explain,
            'explain_error' => $explain_error,
        );
        set_transient('ov_bench_' . $user_id . '_' . $token, $result, 5 * MINUTE_IN_SECONDS);
    });
}

function ov_bench_take() {
    if (!ov_bench_authorized() || !ov_bench_token()) {
        wp_send_json_error(array('message' => 'No autorizado.'), 403);
    }
    $key = ov_bench_key(ov_bench_token());
    $result = get_transient($key);
    if ($result === false) {
        wp_send_json_error(array('message' => 'Muestra todavía no disponible.'), 404);
    }
    delete_transient($key);
    wp_send_json_success($result);
}

/** Solo agregados, para detectar cambios de datos entre versiones sin exponer pacientes. */
function ov_bench_context() {
    if (!ov_bench_authorized()) {
        wp_send_json_error(array('message' => 'No autorizado.'), 403);
    }
    $form_id = isset($_REQUEST['form_id']) && is_scalar($_REQUEST['form_id'])
        ? filter_var(wp_unslash((string) $_REQUEST['form_id']), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) : false;
    $service_id = isset($_REQUEST['service_id']) && is_scalar($_REQUEST['service_id'])
        ? filter_var(wp_unslash((string) $_REQUEST['service_id']), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) : false;
    $month = isset($_REQUEST['month']) && is_string($_REQUEST['month']) ? wp_unslash($_REQUEST['month']) : '';
    if (!$form_id || !$service_id || !preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) {
        wp_send_json_error(array('message' => 'Formulario, servicio o mes inválido.'), 400);
    }

    $form = (new OV_Form())->get($form_id);
    $service = (new OV_Service())->get($service_id);
    if (!$form || !$service || (int) $service->form_id !== (int) $form_id) {
        wp_send_json_error(array('message' => 'Formulario y servicio no corresponden.'), 400);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'ov_booking_reservations';
    $start = $month . '-01';
    $total = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS reservations, COALESCE(SUM(id), 0) AS id_sum, MAX(updated_at) AS latest_update
         FROM {$table} WHERE form_id = %d", (int) $form_id
    ), ARRAY_A);
    $days = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS multi_day_reservations,
                COALESCE(SUM(CASE WHEN booking_date < %s AND DATE_ADD(booking_date, INTERVAL duration DAY) > %s THEN 1 ELSE 0 END), 0) AS crossing_month
         FROM {$table} WHERE form_id = %d AND duration_unit = 'days' AND status != 'cancelled'",
        $start, $start, (int) $form_id
    ), ARRAY_A);
    if (!$total || !$days || !empty($wpdb->last_error)) {
        wp_send_json_error(array('message' => 'No se pudieron contar las reservas.'), 500);
    }
    wp_send_json_success(array(
        'form_id' => (int) $form_id,
        'service_id' => (int) $service_id,
        'month' => $month,
        'reservations' => (int) $total['reservations'],
        'id_sum' => (string) $total['id_sum'],
        'latest_update' => $total['latest_update'],
        'multi_day_reservations' => (int) $days['multi_day_reservations'],
        'crossing_month' => (int) $days['crossing_month'],
    ));
}

function ov_bench_page() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado.');
    }
    ?>
    <div class="wrap">
        <h1>OV Booking Audit Probe</h1>
        <p>Solo clon. Una solicitud por muestra; no crea ni modifica reservas. Deja la pestaña abierta durante cada serie.</p>
        <p>OV Booking: <strong><?php echo esc_html(defined('OV_BOOKING_VERSION') ? OV_BOOKING_VERSION : 'no activo'); ?></strong>
        · PHP: <strong><?php echo esc_html(PHP_VERSION); ?></strong>
        · SAVEQUERIES: <strong><?php echo (defined('SAVEQUERIES') && SAVEQUERIES) ? 'sí' : 'no'; ?></strong></p>
        <p><label>Formulario ID <input id="ov-bench-form" type="number" min="1" required></label>
        <label>Servicio ID <input id="ov-bench-service" type="number" min="1" required></label>
        <label>Mes <input id="ov-bench-month" type="month" required value="<?php echo esc_attr(wp_date('Y-m')); ?>"></label>
        <label>Profesional ID (vacío sin profesional, 0 cualquiera) <input id="ov-bench-professional" type="number" min="0"></label></p>
        <p><label>Ruta <select id="ov-bench-route"><option value="month">Disponibilidad mensual</option><option value="config">Configuración del formulario</option></select></label>
        <label>Muestras <input id="ov-bench-runs" type="number" min="1" max="20" value="20"></label>
        <button type="button" class="button button-primary" id="ov-bench-run">Medir</button></p>
        <p id="ov-bench-status" aria-live="polite"></p>
        <p><button type="button" class="button" id="ov-bench-download" disabled>Descargar JSON</button></p>
        <textarea id="ov-bench-output" readonly rows="22" style="width:100%;font-family:monospace"></textarea>
    </div>
    <?php
}
