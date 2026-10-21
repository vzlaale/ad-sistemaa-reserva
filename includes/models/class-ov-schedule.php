<?php
/**
 * Modelo para gestionar horarios
 * 
 * @package OV_Booking_System
 */

class OV_Schedule {

    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Obtener horario por ID
     */
    public function get($id) {
        return $this->db->get_row('schedules', array('id' => $id));
    }

    /**
     * Obtener horarios por formulario
     */
    public function get_by_form($form_id, $professional_id = null) {
        if ($professional_id !== null && (int) $professional_id > 0) {
            return $this->get_professional_schedules((int) $professional_id);
        }

        $where = array('form_id' => $form_id, 'active' => 1);
        
        if ($professional_id !== null) {
            $where['professional_id'] = $professional_id;
        }
        
        return $this->db->get_results('schedules', $where, 'day_of_week ASC');
    }

    /** Mantener la unión histórica de días generales y profesionales activos sin consultas por profesional. */
    public function get_working_days_for_form($form_id, $include_professionals = false) {
        $days = array();
        foreach ((array) $this->get_by_form($form_id) as $schedule) {
            $days[(int) $schedule->day_of_week] = true;
        }

        if ($include_professionals) {
            global $wpdb;
            $schedule_table = $wpdb->prefix . 'ov_booking_professional_schedules';
            $professional_table = $wpdb->prefix . 'ov_booking_professionals';

            // La tabla puede no existir en instalaciones heredadas; comprobarla solo una vez.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $schedule_table)) === $schedule_table) {
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT ps.day_of_week FROM {$schedule_table} ps
                     INNER JOIN {$professional_table} p ON p.id = ps.professional_id
                     WHERE p.form_id = %d AND p.active = 1 AND ps.active = 1",
                    (int) $form_id
                ));
                if ($rows === null || !empty($wpdb->last_error)) {
                    throw new RuntimeException('No se pudieron cargar los días laborables del formulario.');
                }
                foreach ($rows as $day) {
                    $days[(int) $day] = true;
                }
            }
        }

        $working_days = array_keys($days);
        sort($working_days, SORT_NUMERIC);
        return $working_days;
    }

    /**
     * Obtener horario por día de la semana
     */
    public function get_by_day($form_id, $day_of_week, $professional_id = null) {
        global $wpdb;
        if ($professional_id !== null && (int) $professional_id > 0) {
            $rows = $this->get_professional_schedules_by_day((int) $professional_id, (int) $day_of_week);
            return !empty($rows) ? $rows[0] : null;
        }

        $table = $wpdb->prefix . 'ov_booking_schedules';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE form_id = %d AND day_of_week = %d
               AND professional_id IS NULL AND active = 1
             ORDER BY start_time ASC LIMIT 1",
            $form_id, $day_of_week
        ));
    }

/**
 * Obtener TODAS las franjas de horario por día de la semana
 * (soporta múltiples rangos, p.ej. 09:00-14:00 y 16:00-20:00)
 */
    public function get_all_by_day($form_id, $day_of_week, $professional_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_schedules';

    // Sin profesional seleccionado: sólo aplica el horario general del formulario.
    if ($professional_id === null || $professional_id === 0) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE form_id = %d
               AND day_of_week = %d
               AND professional_id IS NULL
               AND active = 1
             ORDER BY start_time ASC",
            $form_id, $day_of_week
        ));
    }

    // Un profesional nunca hereda silenciosamente el horario general.
    return $this->get_professional_schedules_by_day((int) $professional_id, (int) $day_of_week);
}

    /**
     * Obtener horarios especiales aplicables a una fecha.
     * Si existen, reemplazan por completo el horario semanal regular de ese día.
     */
    public function get_special_by_date($form_id, $date, $day_of_week, $professional_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_special_schedules';

        if ($professional_id !== null && intval($professional_id) > 0) {
            $professional_table = $wpdb->prefix . 'ov_booking_professional_schedule_exceptions';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $professional_table)) !== $professional_table) {
                return array();
            }
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$professional_table}
                 WHERE professional_id = %d AND start_date <= %s AND end_date >= %s
                   AND FIND_IN_SET(%s, days_of_week) AND active = 1
                 ORDER BY start_time ASC, id ASC",
                intval($professional_id), $date, $date, (string) $day_of_week
            ));
        }

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $professional_sql = 'professional_id IS NULL';
        $params = array($form_id, $date, $date, (string) $day_of_week);

        $sql = "SELECT * FROM {$table}
                WHERE form_id = %d
                  AND start_date <= %s
                  AND end_date >= %s
                  AND FIND_IN_SET(%s, days_of_week)
                  AND active = 1
                  AND {$professional_sql}
                ORDER BY professional_id DESC, start_time ASC, id ASC";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    private function get_professional_schedules($professional_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_professional_schedules';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE professional_id = %d AND active = 1 ORDER BY day_of_week ASC, start_time ASC",
            $professional_id
        ));
    }

    private function get_professional_schedules_by_day($professional_id, $day_of_week) {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_professional_schedules';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE professional_id = %d AND day_of_week = %d AND active = 1
             ORDER BY start_time ASC",
            $professional_id, $day_of_week
        ));
    }

    /**
     * Crear horario
     */
    public function create($data) {
        if (isset($data['professional_id']) && (int) $data['professional_id'] > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'ov_booking_professional_schedules';
            return $wpdb->insert($table, array(
                'professional_id' => (int) $data['professional_id'],
                'day_of_week' => (int) $data['day_of_week'],
                'start_time' => sanitize_text_field($data['start_time']),
                'end_time' => sanitize_text_field($data['end_time']),
                'active' => isset($data['active']) ? 1 : 0,
            ), array('%d', '%d', '%s', '%s', '%d')) ? $wpdb->insert_id : false;
        }

        $insert_data = array(
            'form_id' => intval($data['form_id']),
            'day_of_week' => intval($data['day_of_week']),
            'start_time' => sanitize_text_field($data['start_time']),
            'end_time' => sanitize_text_field($data['end_time']),
            'active' => isset($data['active']) ? 1 : 0
        );

        if (isset($data['professional_id']) && !empty($data['professional_id'])) {
            $insert_data['professional_id'] = intval($data['professional_id']);
        }

        return $this->db->insert('schedules', $insert_data);
    }

    /**
     * Actualizar horario
     */
    public function update($id, $data) {
        global $wpdb;
        $professional_table = $wpdb->prefix . 'ov_booking_professional_schedules';
        $professional_schedule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$professional_table} WHERE id = %d",
            (int) $id
        ));
        if ($professional_schedule) {
            $update_data = array();
            foreach (array('start_time', 'end_time') as $field) {
                if (isset($data[$field])) {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                }
            }
            if (isset($data['active'])) {
                $update_data['active'] = $data['active'] ? 1 : 0;
            }
            return $wpdb->update($professional_table, $update_data, array('id' => (int) $id));
        }

        $update_data = array();
        
        if (isset($data['start_time'])) {
            $update_data['start_time'] = sanitize_text_field($data['start_time']);
        }
        
        if (isset($data['end_time'])) {
            $update_data['end_time'] = sanitize_text_field($data['end_time']);
        }
        
        if (isset($data['active'])) {
            $update_data['active'] = $data['active'] ? 1 : 0;
        }

        return $this->db->update('schedules', $update_data, array('id' => $id));
    }

    /**
     * Eliminar horario
     */
    public function delete($id) {
        global $wpdb;
        $professional_table = $wpdb->prefix . 'ov_booking_professional_schedules';
        $professional_schedule = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$professional_table} WHERE id = %d",
            (int) $id
        ));
        if ($professional_schedule) {
            return $wpdb->delete($professional_table, array('id' => (int) $id));
        }
        return $this->db->delete('schedules', array('id' => $id));
    }

    /**
     * Obtener días festivos
     */
    public function get_holidays($form_id, $professional_id = null) {
        $where = array('form_id' => $form_id);
        
        if ($professional_id !== null) {
            $where['professional_id'] = $professional_id;
        }
        
        $holidays = $this->db->get_results('holidays', $where, 'holiday_date ASC');
        
        // Si no hay resultados, devolver array vacío
        if (!$holidays || !is_array($holidays)) {
            return array();
        }
        
        // Devolver solo array de fechas
        return array_map(function($holiday) {
            return $holiday->holiday_date;
        }, $holidays);
    }

    /**
     * Agregar día festivo
     */
    public function add_holiday($form_id, $date, $description = '', $professional_id = null) {
        $data = array(
            'form_id' => $form_id,
            'holiday_date' => $date,
            'description' => $description
        );
        
        if ($professional_id) {
            $data['professional_id'] = $professional_id;
        }
        
        return $this->db->insert('holidays', $data);
    }

    /**
     * Eliminar día festivo
     */
    public function delete_holiday($id) {
        return $this->db->delete('holidays', array('id' => $id));
    }

    /**
     * Obtener descansos
     */
    public function get_breaks($form_id, $professional_id = null, $date = null, $day_of_week = null) {
        $table_name = $this->db->get_table_name('breaks');
        
        $sql = "SELECT * FROM $table_name WHERE form_id = %d AND active = 1";
        $params = array($form_id);
        
        if ($professional_id !== null) {
            $sql .= " AND professional_id = %d";
            $params[] = $professional_id;
        }
        
        if ($date) {
            $sql .= " AND (break_date = %s OR break_date IS NULL)";
            $params[] = $date;
        }
        
        if ($day_of_week !== null) {
            $sql .= " AND (day_of_week = %d OR day_of_week IS NULL)";
            $params[] = $day_of_week;
        }
        
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Agregar descanso
     */
    public function add_break($data) {
        $insert_data = array(
            'form_id' => intval($data['form_id']),
            'start_time' => sanitize_text_field($data['start_time']),
            'end_time' => sanitize_text_field($data['end_time']),
            'description' => sanitize_text_field($data['description'] ?? ''),
            'active' => 1
        );

        if (isset($data['professional_id']) && !empty($data['professional_id'])) {
            $insert_data['professional_id'] = intval($data['professional_id']);
        }

        if (isset($data['break_date']) && !empty($data['break_date'])) {
            $insert_data['break_date'] = sanitize_text_field($data['break_date']);
        }

        if (isset($data['day_of_week']) && $data['day_of_week'] !== '') {
            $insert_data['day_of_week'] = intval($data['day_of_week']);
        }

        return $this->db->insert('breaks', $insert_data);
    }

    /**
     * Eliminar descanso
     */
    public function delete_break($id) {
        return $this->db->delete('breaks', array('id' => $id));
    }
}
