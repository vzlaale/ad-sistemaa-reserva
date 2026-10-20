<?php
/** Exportación paginada y segura de reservas. */
if (!defined('ABSPATH')) exit;

class OV_CSV_Export {

    private static function cell($value) {
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        }
        $value = (string) $value;
        // Excel y otras hojas pueden evaluar fórmulas tras espacios o controles.
        return preg_match('/^[\x00-\x20\x{00A0}\x{FEFF}]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    public static function reservations() {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_die(esc_html__('No tienes permisos para exportar reservas.', 'ov-booking-system'), '', array('response' => 403));
        }
        check_admin_referer('ov_booking_export_csv');

        global $wpdb;
        $reservations = $wpdb->prefix . 'ov_booking_reservations';
        $services = $wpdb->prefix . 'ov_booking_services';
        $forms = $wpdb->prefix . 'ov_booking_forms';
        $form_id = isset($_GET['form_filter']) ? absint($_GET['form_filter']) : 0;
        $status = isset($_GET['status_filter']) ? sanitize_key(wp_unslash($_GET['status_filter'])) : '';
        $professional_id = isset($_GET['professional_filter']) ? intval($_GET['professional_filter']) : 0;
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

        if ($status !== '' && !in_array($status, OV_Reservation::ALLOWED_STATUSES, true)) {
            wp_die(esc_html__('Estado de reserva no válido.', 'ov-booking-system'), '', array('response' => 400));
        }

        $where = array('r.id > %d');
        $base_params = array();
        if ($form_id) {
            $where[] = 'r.form_id = %d';
            $base_params[] = $form_id;
        } else {
            $where[] = "f.status = 'active'";
        }
        if ($status !== '') {
            $where[] = 'r.status = %s';
            $base_params[] = $status;
        }
        if ($professional_id === -1) {
            $where[] = '(r.professional_id IS NULL OR r.professional_id = 0)';
        } elseif ($professional_id > 0) {
            $where[] = 'r.professional_id = %d';
            $base_params[] = $professional_id;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.customer_name LIKE %s OR r.customer_email LIKE %s OR r.customer_phone LIKE %s OR r.booking_id LIKE %s)';
            array_push($base_params, $like, $like, $like, $like);
        }

        $sql = "SELECT r.*, s.name AS service_name, s.price AS service_price
            FROM {$reservations} r
            INNER JOIN {$forms} f ON f.id = r.form_id
            LEFT JOIN {$services} s ON s.id = r.service_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY r.id ASC LIMIT %d';

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=reservas-' . gmdate('Y-m-d-His') . '.csv');
        header('Cache-Control: no-store');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array('ID Reserva', 'Servicio', 'Precio', 'Fecha Reserva', 'Hora', 'Cliente', 'Email', 'Teléfono', 'Notas', 'Datos Adicionales', 'Estado', 'Fecha Creación'));

        $last_id = 0;
        do {
            $params = array_merge(array($last_id), $base_params, array(500));
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
            if (!is_array($rows) || !$rows) break;
            foreach ($rows as $row) {
                $last_id = (int) $row->id;
                $customer_data = is_string($row->customer_data) ? json_decode($row->customer_data, true) : $row->customer_data;
                $additional = array();
                if (is_array($customer_data)) {
                    foreach ($customer_data as $key => $value) {
                        if (in_array($key, array('name', 'surname', 'email', 'phone', 'notes'), true) || $value === '' || $value === null) continue;
                        $additional[] = ucfirst((string) $key) . ': ' . (is_scalar($value) ? (string) $value : wp_json_encode($value));
                    }
                }
                $values = array(
                    $row->booking_id ?: '#' . $row->id,
                    $row->service_name ?: 'N/A',
                    $row->price ?: ($row->service_price ?: '-'),
                    $row->booking_date,
                    $row->booking_time,
                    $row->customer_name,
                    $row->customer_email,
                    $row->customer_phone,
                    $row->notes,
                    implode('; ', $additional),
                    $row->status,
                    $row->created_at,
                );
                fputcsv($output, array_map(function($value) { return self::cell($value); }, $values));
            }
        } while (count($rows) === 500);

        fclose($output);
        exit;
    }
}
