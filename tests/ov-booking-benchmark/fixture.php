<?php
/** Temporary, clone-only data for the P2 availability benchmark. */

if (!defined('ABSPATH') || wp_parse_url(home_url(), PHP_URL_HOST) !== 'agenda.geneal.cl') {
    return;
}

const OV_BENCH_FIXTURE_OPTION = 'ov_bench_fixture_v1';
const OV_BENCH_FIXTURE_BATCH = 150;

add_action('wp_ajax_ov_bench_fixture_status', 'ov_bench_fixture_status');
add_action('wp_ajax_ov_bench_fixture_create', 'ov_bench_fixture_create');
add_action('wp_ajax_ov_bench_fixture_seed', 'ov_bench_fixture_seed');
add_action('wp_ajax_ov_bench_fixture_cleanup', 'ov_bench_fixture_cleanup');

function ov_bench_fixture_authorize() {
    if (!ov_bench_authorized()) {
        wp_send_json_error(array('message' => 'No autorizado.'), 403);
    }
}

function ov_bench_fixture_state() {
    $state = get_option(OV_BENCH_FIXTURE_OPTION, false);
    return is_array($state) ? $state : false;
}

function ov_bench_fixture_request_state() {
    ov_bench_fixture_authorize();
    $run = isset($_POST['run']) && is_string($_POST['run']) ? wp_unslash($_POST['run']) : '';
    $state = ov_bench_fixture_state();
    if (!$state || !preg_match('/^[a-f0-9]{12}$/D', $run) || !hash_equals($state['run'], $run)) {
        wp_send_json_error(array('message' => 'Fixture inexistente o identificador incorrecto.'), 400);
    }
    return $state;
}

function ov_bench_fixture_status() {
    ov_bench_fixture_authorize();
    wp_send_json_success(ov_bench_fixture_state());
}

function ov_bench_fixture_insert($table, $data) {
    global $wpdb;
    if ($wpdb->insert($wpdb->prefix . $table, $data) !== 1 || (int) $wpdb->insert_id <= 0) {
        throw new RuntimeException('No se pudo crear un registro sintético. Revisa el esquema del clon.');
    }
    return (int) $wpdb->insert_id;
}

function ov_bench_fixture_save($state) {
    update_option(OV_BENCH_FIXTURE_OPTION, $state, false);
}

function ov_bench_fixture_create() {
    ov_bench_fixture_authorize();
    $backup = isset($_POST['backup_confirmed']) && is_string($_POST['backup_confirmed'])
        ? wp_unslash($_POST['backup_confirmed']) : '';
    $month = isset($_POST['month']) && is_string($_POST['month']) ? wp_unslash($_POST['month']) : '';
    if ($backup !== '1' || !preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) {
        wp_send_json_error(array('message' => 'Confirma el respaldo y selecciona un mes válido.'), 400);
    }
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Santiago'));
    $current_month = $today->format('Y-m');
    $last_month = $today->modify('first day of this month')->modify('+5 months')->format('Y-m');
    if ($month <= $current_month || $month > $last_month) {
        wp_send_json_error(array('message' => 'Selecciona un mes futuro dentro de la ventana pública.'), 400);
    }
    if (ov_bench_fixture_state()) {
        wp_send_json_error(array('message' => 'Ya existe un fixture. Límpialo antes de crear otro.'), 409);
    }

    $run = bin2hex(random_bytes(6));
    $state = array(
        'run' => $run,
        'month' => $month,
        'phase' => 'creating',
        'solo_form' => 0,
        'solo_service' => 0,
        'pro_form' => 0,
        'pro_service' => 0,
        'professional_ids' => array(),
        'seeded_solo' => 0,
        'seeded_pro' => 0,
        'target_solo' => 2000,
        'target_pro' => 10000,
    );
    if (!add_option(OV_BENCH_FIXTURE_OPTION, $state, '', false)) {
        wp_send_json_error(array('message' => 'No se pudo reservar el fixture.'), 409);
    }

    try {
        foreach (array('solo', 'pro') as $kind) {
            $form_id = ov_bench_fixture_insert('ov_booking_forms', array(
                'name' => 'P2 BENCH ' . $kind . ' ' . $run,
                'slug' => 'p2-bench-' . $run . '-' . $kind,
                'enable_professionals' => $kind === 'pro' ? 1 : 0,
                'allow_any_professional' => $kind === 'pro' ? 1 : 0,
                'timezone' => 'America/Santiago',
                'booking_limit_hours' => 0,
                'max_bookings_per_slot' => 2,
                'max_bookings_per_slot_type' => 'per_professional',
                'status' => 'active',
            ));
            $state[$kind . '_form'] = $form_id;
            ov_bench_fixture_save($state);

            $service_id = ov_bench_fixture_insert('ov_booking_services', array(
                'form_id' => $form_id,
                'name' => 'P2 BENCH tres días ' . $run,
                'duration' => 3,
                'duration_unit' => 'days',
                'slot_interval' => 30,
                'buffer_time' => 0,
                'active' => 1,
            ));
            $state[$kind . '_service'] = $service_id;
            ov_bench_fixture_save($state);

            if ($kind === 'solo') {
                for ($day = 0; $day < 7; $day++) {
                    ov_bench_fixture_insert('ov_booking_schedules', array(
                        'form_id' => $form_id,
                        'professional_id' => null,
                        'day_of_week' => $day,
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                        'active' => 1,
                    ));
                }
            } else {
                for ($number = 1; $number <= 2; $number++) {
                    $professional_id = ov_bench_fixture_insert('ov_booking_professionals', array(
                        'form_id' => $form_id,
                        'name' => 'P2 BENCH profesional ' . $number,
                        'active' => 1,
                        'booking_visibility' => 'public',
                    ));
                    $state['professional_ids'][] = $professional_id;
                    ov_bench_fixture_save($state);
                    ov_bench_fixture_insert('ov_service_professionals', array(
                        'service_id' => $service_id,
                        'professional_id' => $professional_id,
                    ));
                    for ($day = 0; $day < 7; $day++) {
                        ov_bench_fixture_insert('ov_booking_professional_schedules', array(
                            'professional_id' => $professional_id,
                            'day_of_week' => $day,
                            'start_time' => '09:00:00',
                            'end_time' => '17:00:00',
                            'active' => 1,
                        ));
                    }
                }
            }
        }

        $first = new DateTimeImmutable($month . '-01', new DateTimeZone('America/Santiago'));
        $crossing = $first->modify('-1 day')->format('Y-m-d');
        foreach (array('solo', 'pro') as $kind) {
            for ($index = 0; $index < ($kind === 'pro' ? 2 : 1); $index++) {
                ov_bench_fixture_reservation($state, $kind, 'C' . $index, $crossing, $index);
            }
            foreach (array(5, 10, 20) as $index => $day) {
                $date = $first->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
                ov_bench_fixture_reservation($state, $kind, 'M' . $day, $date, $index);
            }
        }
        $state['phase'] = 'ready';
        ov_bench_fixture_save($state);
        wp_send_json_success($state);
    } catch (RuntimeException $error) {
        wp_send_json_error(array('message' => 'Fixture parcial. Usa «Eliminar fixture» antes de reintentar.', 'run' => $run), 500);
    }
}

/** Inserts without domain hooks, so the WhatsApp add-on receives no events. */
function ov_bench_fixture_reservation($state, $kind, $sequence, $date, $professional_index) {
    global $wpdb;
    $professional_id = $kind === 'pro'
        ? $state['professional_ids'][$professional_index % count($state['professional_ids'])] : null;
    $booking_id = 'P2' . $state['run'] . strtoupper(substr($kind, 0, 1)) . $sequence;
    $data = array(
        'booking_id' => $booking_id,
        'form_id' => $state[$kind . '_form'],
        'service_id' => $state[$kind . '_service'],
        'professional_id' => $professional_id,
        'booking_date' => $date,
        'booking_time' => '09:00:00',
        'end_time' => '23:59:59',
        'duration' => 3,
        'duration_unit' => 'days',
        'customer_name' => 'P2 Synthetic',
        'customer_email' => '',
        'customer_phone' => '',
        'status' => 'confirmed',
        'source' => 'admin',
        'notes' => 'P2BENCH-' . $state['run'],
    );
    $table = $wpdb->prefix . 'ov_booking_reservations';
    if ($wpdb->insert($table, $data) === 1) {
        return (int) $wpdb->insert_id;
    }
    // A timed-out AJAX request may have inserted its last row before saving progress.
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, form_id, service_id, professional_id, booking_date, duration, duration_unit FROM {$table} WHERE booking_id = %s",
        $booking_id
    ));
    if ($existing && (int) $existing->form_id === (int) $data['form_id'] &&
        (int) $existing->service_id === (int) $data['service_id'] &&
        (int) $existing->professional_id === (int) $professional_id &&
        $existing->booking_date === $date && (int) $existing->duration === 3 &&
        $existing->duration_unit === 'days') {
        return (int) $existing->id;
    }
    throw new RuntimeException('No se pudo insertar una reserva sintética.');
}

function ov_bench_fixture_seed() {
    $state = ov_bench_fixture_request_state();
    if ($state['phase'] !== 'ready' && $state['phase'] !== 'seeded') {
        wp_send_json_error(array('message' => 'Fixture aún no está listo.'), 409);
    }
    if ($state['phase'] === 'seeded') {
        wp_send_json_success($state);
    }

    $base = new DateTimeImmutable('2018-01-01', new DateTimeZone('UTC'));
    try {
        for ($inserted = 0; $inserted < OV_BENCH_FIXTURE_BATCH; $inserted++) {
            $kind = $state['seeded_solo'] < $state['target_solo'] ? 'solo' : 'pro';
            $counter = 'seeded_' . $kind;
            if ($state[$counter] >= $state['target_' . $kind]) {
                $state['phase'] = 'seeded';
                break;
            }
            $index = (int) $state[$counter];
            $date = $base->modify('+' . ($index % 2920) . ' days')->format('Y-m-d');
            ov_bench_fixture_reservation($state, $kind, sprintf('%05d', $index), $date, $index);
            $state[$counter]++;
            ov_bench_fixture_save($state);
        }
        if ($state['seeded_solo'] >= $state['target_solo'] && $state['seeded_pro'] >= $state['target_pro']) {
            $state['phase'] = 'seeded';
        }
        ov_bench_fixture_save($state);
        wp_send_json_success($state);
    } catch (RuntimeException $error) {
        ov_bench_fixture_save($state);
        wp_send_json_error(array('message' => 'Carga interrumpida. Revisa el clon antes de continuar; se puede limpiar por identificador.', 'run' => $state['run']), 500);
    }
}

function ov_bench_fixture_delete($table, $where) {
    global $wpdb;
    if ($wpdb->delete($wpdb->prefix . $table, $where) === false) {
        throw new RuntimeException('Falló la limpieza de un registro del fixture.');
    }
}

function ov_bench_fixture_cleanup() {
    $state = ov_bench_fixture_request_state();
    global $wpdb;
    $form_ids = array((int) $state['solo_form'], (int) $state['pro_form']);
    $table = $wpdb->prefix . 'ov_booking_reservations';
    $prefix = 'P2' . $state['run'];
    $like = $wpdb->esc_like($prefix) . '%';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table} WHERE form_id IN (%d, %d) AND booking_id LIKE %s ORDER BY id LIMIT 200",
        $form_ids[0], $form_ids[1], $like
    ));
    if (!is_array($ids) || $wpdb->last_error !== '') {
        wp_send_json_error(array('message' => 'No se pudieron localizar las reservas sintéticas.'), 500);
    }
    try {
        if ($ids) {
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders}) AND form_id IN (%d, %d)",
                array_merge(array_map('intval', $ids), $form_ids)
            ));
            if ($deleted === false || $deleted !== count($ids)) {
                throw new RuntimeException('No se pudieron eliminar todas las reservas del lote.');
            }
        }
        if (count($ids) === 200) {
            wp_send_json_success(array('run' => $state['run'], 'remaining' => true, 'deleted' => count($ids)));
        }

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE form_id IN (%d, %d)", $form_ids[0], $form_ids[1]
        ));
        if ($wpdb->last_error !== '' || $remaining !== 0) {
            wp_send_json_error(array('message' => 'Hay reservas ajenas al fixture en sus formularios. No se borrarán las entidades.'), 409);
        }
        $forms_table = $wpdb->prefix . 'ov_booking_forms';
        foreach (array('solo', 'pro') as $kind) {
            $form_id = (int) $state[$kind . '_form'];
            if ($form_id <= 0) {
                continue;
            }
            $slug = $wpdb->get_var($wpdb->prepare("SELECT slug FROM {$forms_table} WHERE id = %d", $form_id));
            if ($wpdb->last_error !== '' || ($slug !== null && $slug !== 'p2-bench-' . $state['run'] . '-' . $kind)) {
                wp_send_json_error(array('message' => 'La identidad del formulario cambió. Limpieza detenida.'), 409);
            }
        }
        foreach ($state['professional_ids'] as $professional_id) {
            ov_bench_fixture_delete('ov_booking_professional_schedules', array('professional_id' => (int) $professional_id));
            ov_bench_fixture_delete('ov_service_professionals', array('professional_id' => (int) $professional_id));
        }
        foreach (array('solo', 'pro') as $kind) {
            $form_id = (int) $state[$kind . '_form'];
            $service_id = (int) $state[$kind . '_service'];
            if ($form_id > 0) {
                ov_bench_fixture_delete('ov_booking_schedules', array('form_id' => $form_id));
            }
            if ($service_id > 0) {
                ov_bench_fixture_delete('ov_service_professionals', array('service_id' => $service_id));
                ov_bench_fixture_delete('ov_booking_services', array('id' => $service_id, 'form_id' => $form_id));
            }
        }
        foreach ($state['professional_ids'] as $professional_id) {
            ov_bench_fixture_delete('ov_booking_professionals', array('id' => (int) $professional_id, 'form_id' => $form_ids[1]));
        }
        foreach (array('solo', 'pro') as $kind) {
            if ((int) $state[$kind . '_form'] > 0) {
                ov_bench_fixture_delete('ov_booking_forms', array(
                    'id' => (int) $state[$kind . '_form'],
                    'slug' => 'p2-bench-' . $state['run'] . '-' . $kind,
                ));
            }
        }
        delete_option(OV_BENCH_FIXTURE_OPTION);
        wp_send_json_success(array('run' => $state['run'], 'remaining' => false, 'deleted' => count($ids)));
    } catch (RuntimeException $error) {
        wp_send_json_error(array('message' => 'Limpieza incompleta. Conserva el respaldo y vuelve a intentarlo.'), 500);
    }
}
