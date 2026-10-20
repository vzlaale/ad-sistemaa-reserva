<?php
/**
 * Modelo para gestionar reservas
 * 
 * @package OV_Booking_System
 */

class OV_Reservation {

    const ALLOWED_STATUSES = array('pending', 'confirmed', 'completed', 'cancelled');

    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Obtener nombre de la tabla de reservas.
     */
    public function get_table_name() {
        return $this->db->get_table_name('reservations');
    }

    /**
     * Obtener reserva por ID
     */
    public function get($id) {
        $reservation = $this->db->get_row('reservations', array('id' => $id));
        
        if ($reservation && $reservation->customer_data) {
            $reservation->customer_data = json_decode($reservation->customer_data, true);
        }
        
        return $reservation;
    }

    /**
     * Obtener una reserva por su identificador público.
     */
    public function get_by_booking_id($booking_id) {
        return $this->decode_customer_data(
            $this->db->get_row('reservations', array('booking_id' => sanitize_text_field($booking_id)))
        );
    }

    /**
     * Obtener una reserva por la huella de idempotencia (nunca por el valor crudo).
     */
    public function get_by_request_key($request_key) {
        if (!is_string($request_key) || !preg_match('/^[a-f0-9]{64}$/', $request_key)) {
            return null;
        }

        return $this->decode_customer_data(
            $this->db->get_row('reservations', array('request_key' => $request_key))
        );
    }

    private function decode_customer_data($reservation) {
        if ($reservation && $reservation->customer_data) {
            $reservation->customer_data = json_decode($reservation->customer_data, true);
        }
        return $reservation;
    }

    /**
     * Obtener reservas por formulario
     */
    public function get_by_form($form_id, $status = null, $limit = null, $include_patient_rut = false) {
        $table_name = $this->db->get_table_name('reservations');
        $sql = 'SELECT r.*';
        if ($include_patient_rut) {
            $patients_table = $this->db->get_table_name('patients');
            $sql .= ", p.rut AS linked_patient_rut FROM {$table_name} r"
                . " LEFT JOIN {$patients_table} p ON p.id = r.patient_id";
        } else {
            $sql .= " FROM {$table_name} r";
        }
        $sql .= ' WHERE r.form_id = %d';
        
        if ($status) {
            $sql .= ' AND r.status = %s';
        }
        
        $sql .= ' ORDER BY r.booking_date DESC, r.booking_time DESC';
        
        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }
        
        global $wpdb;
        if ($status) {
            return $wpdb->get_results($wpdb->prepare($sql, $form_id, $status));
        } else {
            return $wpdb->get_results($wpdb->prepare($sql, $form_id));
        }
    }

    /** Normalizar los filtros que comparten el listado y el borrado. */
    public static function normalize_admin_filters($filters) {
        $status = sanitize_key($filters['status'] ?? '');
        $professional_id = (int) ($filters['professional_id'] ?? 0);
        return array(
            'form_id' => max(0, (int) ($filters['form_id'] ?? 0)),
            'status' => in_array($status, self::ALLOWED_STATUSES, true) ? $status : '',
            'professional_id' => $professional_id === -1 || $professional_id > 0 ? $professional_id : 0,
            'search' => sanitize_text_field((string) ($filters['search'] ?? '')),
        );
    }

    /** Una sola definición SQL del alcance administrativo. */
    private function admin_query_parts($filters) {
        global $wpdb;
        $table = $this->get_table_name();
        $forms = $wpdb->prefix . 'ov_booking_forms';
        $filters = self::normalize_admin_filters($filters);
        $where = array();
        $params = array();
        $form_id = $filters['form_id'];
        if ($form_id > 0) {
            $where[] = 'r.form_id = %d';
            $params[] = $form_id;
        } else {
            $where[] = "f.status = 'active'";
        }
        $status = $filters['status'];
        if ($status !== '') {
            $where[] = 'r.status = %s';
            $params[] = $status;
        }
        $professional_id = $filters['professional_id'];
        if ($professional_id === -1) {
            $where[] = '(r.professional_id IS NULL OR r.professional_id = 0)';
        } elseif ($professional_id > 0) {
            $where[] = 'r.professional_id = %d';
            $params[] = $professional_id;
        }
        $search = $filters['search'];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(r.customer_name LIKE %s OR r.customer_email LIKE %s OR r.customer_phone LIKE %s OR r.booking_id LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $from = " FROM {$table} r INNER JOIN {$forms} f ON f.id = r.form_id";
        $where_sql = ' WHERE ' . implode(' AND ', $where);
        return array($from, $where_sql, $params);
    }

    /** Huella de los ID filtrados; la función opcional recibe cada ID sin cargar toda la selección. */
    public function scan_admin_selection($filters, $on_id = null) {
        global $wpdb;
        $filters = self::normalize_admin_filters($filters);
        list($from, $where_sql, $params) = $this->admin_query_parts($filters);
        $context = hash_init('sha256');
        hash_update($context, wp_json_encode($filters) . ':');
        $count = 0;
        $last_id = 0;
        do {
            $sql = 'SELECT r.id' . $from . $where_sql
                . ' AND r.id > %d ORDER BY r.id ASC LIMIT 500';
            $ids = $wpdb->get_col($wpdb->prepare($sql, array_merge($params, array($last_id))));
            if (!is_array($ids) || $wpdb->last_error !== '') {
                return false;
            }
            foreach ($ids as $id) {
                $last_id = (int) $id;
                hash_update($context, ($count ? ',' : '') . $last_id);
                if ($on_id !== null && $on_id($last_id) === false) {
                    return false;
                }
                $count++;
            }
        } while (count($ids) === 500);
        return array('count' => $count, 'fingerprint' => hash_final($context));
    }

    /** Consulta paginada para el listado administrativo, con filtros en SQL. */
    public function get_admin_page($filters, $per_page, $page) {
        global $wpdb;
        $patients = $this->db->get_table_name('patients');
        list($from, $where_sql, $params) = $this->admin_query_parts($filters);
        $count_sql = 'SELECT COUNT(*)' . $from . $where_sql;
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);
        $per_page = max(1, min(100, (int) $per_page));
        $pages = max(1, (int) ceil($total / $per_page));
        $page = max(1, min($pages, (int) $page));
        $orders = array(
            'created_asc' => 'r.created_at ASC, r.id ASC',
            'created_desc' => 'r.created_at DESC, r.id DESC',
            'booking_asc' => 'r.booking_date ASC, r.booking_time ASC, r.id ASC',
            'booking_desc' => 'r.booking_date DESC, r.booking_time DESC, r.id DESC',
        );
        $order = $orders[$filters['order_by'] ?? ''] ?? $orders['created_desc'];
        $sql = 'SELECT r.*, p.rut AS linked_patient_rut' . $from
            . " LEFT JOIN {$patients} p ON p.id = r.patient_id"
            . $where_sql . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d';
        $query_params = array_merge($params, array($per_page, ($page - 1) * $per_page));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params));
        return array('rows' => is_array($rows) ? $rows : array(), 'total' => $total, 'pages' => $pages, 'page' => $page);
    }

    /**
     * Obtener reservas por fecha
     */
    public function get_by_date($form_id, $date, $service_id = null, $professional_id = null, $exclude_id = null) {
        $table_name = $this->db->get_table_name('reservations');
        
        $sql = "SELECT * FROM $table_name 
                WHERE form_id = %d 
                AND booking_date = %s 
                AND status != 'cancelled'";
        
        $params = array($form_id, $date);
        
        if ($service_id) {
            $sql .= " AND service_id = %d";
            $params[] = $service_id;
        }
        
        if ($professional_id) {
            $sql .= " AND professional_id = %d";
            $params[] = $professional_id;
        }
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        $sql .= " ORDER BY booking_time ASC";
        
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Crear nueva reserva
     */
    public function create($data, $defer_created_event = false) {
        $status = sanitize_key($data['status'] ?? 'pending');
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        $insert_data = array(
            'booking_id' => sanitize_text_field($data['booking_id'] ?? ''),
            'form_id' => intval($data['form_id']),
            'patient_id' => !empty($data['patient_id']) ? intval($data['patient_id']) : null,
            'service_id' => intval($data['service_id']),
            'booking_date' => sanitize_text_field($data['booking_date']),
            'booking_time' => sanitize_text_field($data['booking_time']),
            'end_time' => sanitize_text_field($data['end_time']),
            'duration' => intval($data['duration']),
            'duration_unit' => sanitize_text_field($data['duration_unit']),
            'customer_name' => sanitize_text_field($data['customer_name']),
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'customer_phone' => OV_Validation::normalize_phone($data['customer_phone'] ?? ''),
            'status' => $status,
            'source' => in_array(($data['source'] ?? 'public'), array('public', 'admin', 'api'), true)
                ? $data['source']
                : 'public',
            'notes' => sanitize_textarea_field($data['notes'] ?? '')
        );

        if (isset($data['created_at']) && !empty($data['created_at'])) {
            $insert_data['created_at'] = sanitize_text_field($data['created_at']);
        }

        if (isset($data['professional_id']) && !empty($data['professional_id'])) {
            $insert_data['professional_id'] = intval($data['professional_id']);
        }
        
        if (isset($data['price']) && !empty($data['price'])) {
            $insert_data['price'] = substr(sanitize_text_field($data['price']), 0, 255);
        }

        if (isset($data['customer_data'])) {
            $insert_data['customer_data'] = json_encode($data['customer_data']);
        }

        if (isset($data['calendar_event_id'])) {
            $insert_data['calendar_event_id'] = sanitize_text_field($data['calendar_event_id']);
        }

        if (!empty($data['request_key']) && preg_match('/^[a-f0-9]{64}$/', $data['request_key'])) {
            $insert_data['request_key'] = $data['request_key'];
        }

        if (!empty($data['created_ip_hash']) && preg_match('/^[a-f0-9]{64}$/', $data['created_ip_hash'])) {
            $insert_data['created_ip_hash'] = $data['created_ip_hash'];
        }

        $reservation_id = $this->db->insert('reservations', $insert_data);

        if ($reservation_id && !$defer_created_event) {
            do_action('ov_booking_reservation_created', (int) $reservation_id, $insert_data);
        }

        return $reservation_id;
    }

    /**
     * Emitir el evento de creación después de abandonar una sección crítica.
     * Los consumidores deben encolar trabajo externo y evitar llamadas de red síncronas.
     */
    public function dispatch_created($reservation_id, $data) {
        if ((int) $reservation_id <= 0 || !is_array($data)) {
            return false;
        }

        do_action('ov_booking_reservation_created', (int) $reservation_id, $data);
        return true;
    }

    /**
     * Actualizar reserva
     */
    public function update($id, $data) {
        $previous = $this->get($id);
        if (!$previous) {
            return false;
        }
        $update_data = array();
        
        $allowed_fields = array(
            'booking_id', 'status', 'notes', 'calendar_event_id',
            'whatsapp_sent', 'customer_name', 'customer_email', 'customer_phone', 'booking_date', 'booking_time', 'end_time', 'duration', 'duration_unit', 'price', 'professional_id', 'patient_id'
        );
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'notes') {
                    $update_data[$field] = sanitize_textarea_field($data[$field]);
                } elseif ($field === 'professional_id') {
                    $update_data[$field] = !empty($data[$field]) ? intval($data[$field]) : null;
                } elseif ($field === 'patient_id') {
                    $update_data[$field] = !empty($data[$field]) ? intval($data[$field]) : null;
                } elseif ($field === 'duration') {
                    $update_data[$field] = intval($data[$field]);
                } elseif ($field === 'price') {
                    $update_data[$field] = substr(sanitize_text_field($data[$field]), 0, 255);
                } elseif ($field === 'status') {
                    $status = sanitize_key($data[$field]);
                    if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                        return false;
                    }
                    $update_data[$field] = $status;
                } elseif ($field === 'customer_phone') {
                    $update_data[$field] = OV_Validation::normalize_phone($data[$field]);
                } else {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                }
            }
        }

        if (isset($data['customer_data'])) {
            $update_data['customer_data'] = json_encode($data['customer_data']);
        }

        $result = $this->db->update('reservations', $update_data, array('id' => $id));

        if ($result !== false && !empty($update_data)) {
            do_action('ov_booking_reservation_updated', (int) $id, $update_data, $previous);
        }

        return $result;
    }

    /**
     * Transición condicional atómica, preparada para integraciones externas.
     */
    public function transition_status($id, $from_status, $to_status) {
        $from_status = sanitize_key($from_status);
        $to_status = sanitize_key($to_status);
        if (!in_array($from_status, self::ALLOWED_STATUSES, true) ||
            !in_array($to_status, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        global $wpdb;
        $table = $this->get_table_name();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = CURRENT_TIMESTAMP WHERE id = %d AND status = %s",
            $to_status,
            (int) $id,
            $from_status
        ));

        if ($updated === 1) {
            do_action('ov_booking_reservation_updated', (int) $id, array('status' => $to_status));
            return true;
        }

        return false;
    }

    /**
     * Eliminar reserva
     */
    public function delete($id) {
        $result = $this->db->delete('reservations', array('id' => $id));

        if ($result === 1) {
            do_action('ov_booking_reservation_deleted', (int) $id);
        }

        return $result;
    }

    /**
     * Contar reservas en un slot específico
     */
    public function count_bookings_in_slot($form_id, $date, $time, $service_id = null, $professional_id = null, $exclude_id = null) {
        $table_name = $this->db->get_table_name('reservations');
        
        $sql = "SELECT COUNT(*) FROM $table_name 
                WHERE form_id = %d 
                AND booking_date = %s 
                AND booking_time = %s
                AND status != 'cancelled'";
        
        $params = array($form_id, $date, $time);
        
        if ($service_id) {
            $sql .= " AND service_id = %d";
            $params[] = $service_id;
        }
        
        if ($professional_id) {
            $sql .= " AND professional_id = %d";
            $params[] = $professional_id;
        }
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Verificar si hay solapamiento
     */
    public function has_overlap($form_id, $date, $start_time, $end_time, $professional_id = null, $exclude_id = null) {
        $table_name = $this->db->get_table_name('reservations');
        
        $sql = "SELECT COUNT(*) FROM $table_name 
                WHERE form_id = %d 
                AND booking_date = %s 
                AND status != 'cancelled'
                AND (
                    (booking_time < %s AND end_time > %s) OR
                    (booking_time >= %s AND booking_time < %s) OR
                    (end_time > %s AND end_time <= %s)
                )";
        
        $params = array(
            $form_id, $date,
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time
        );
        
        if ($professional_id) {
            $sql .= " AND professional_id = %d";
            $params[] = $professional_id;
        }
        
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params)) > 0;
    }
}
