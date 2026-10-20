<?php
/**
 * Modelo para gestionar profesionales
 * 
 * @package OV_Booking_System
 */

class OV_Professional {

    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Obtener profesional por ID
     */
    public function get($id) {
        $professional = $this->db->get_row('professionals', array('id' => $id));
        
        if ($professional) {
            $professional->schedule_config = json_decode($professional->schedule_config, true);
            $professional->working_days = json_decode($professional->working_days, true);
            $professional->breaks_config = json_decode($professional->breaks_config, true);
        }
        
        return $professional;
    }

    /**
     * Obtener todos los profesionales
     */
    public function get_all($active_only = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_professionals';
        
        $sql = "SELECT * FROM {$table}";
        if ($active_only) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY display_order ASC, name ASC";
        
        $professionals = $wpdb->get_results($sql);
        
        // Decodificar JSON
        foreach ($professionals as $professional) {
            $professional->schedule_config = json_decode($professional->schedule_config, true);
            $professional->working_days = json_decode($professional->working_days, true);
            $professional->breaks_config = json_decode($professional->breaks_config, true);
        }
        
        return $professionals;
    }

    /**
     * Obtener profesionales por formulario
     */
    public function get_by_form($form_id, $active_only = true, $public_only = false) {
        if ($public_only) {
            global $wpdb;
            $table = $wpdb->prefix . 'ov_booking_professionals';
            $sql = "SELECT * FROM {$table} WHERE form_id = %d AND booking_visibility = 'public'";
            if ($active_only) {
                $sql .= ' AND active = 1';
            }
            $sql .= ' ORDER BY display_order ASC, name ASC';
            $professionals = $wpdb->get_results($wpdb->prepare($sql, (int) $form_id));
            $this->decode_configurations($professionals);
            return $professionals;
        }

        $where = array('form_id' => $form_id);
        
        if ($active_only) {
            $where['active'] = 1;
        }
        
        $professionals = $this->db->get_results('professionals', $where, 'display_order ASC, name ASC');
        
        // Decodificar JSON
        foreach ($professionals as $professional) {
            $professional->schedule_config = json_decode($professional->schedule_config, true);
            $professional->working_days = json_decode($professional->working_days, true);
            $professional->breaks_config = json_decode($professional->breaks_config, true);
        }
        
        return $professionals;
    }

    /**
     * Determinar si un profesional público está asignado a un servicio.
     */
    public function is_public_for_service($professional_id, $service_id, $form_id = null) {
        global $wpdb;
        $professionals = $wpdb->prefix . 'ov_booking_professionals';
        $relations = $wpdb->prefix . 'ov_service_professionals';
        $services = $wpdb->prefix . 'ov_booking_services';
        $forms = $wpdb->prefix . 'ov_booking_forms';
        $sql = "SELECT p.id FROM {$professionals} p
                INNER JOIN {$relations} sp ON sp.professional_id = p.id
                INNER JOIN {$services} s ON s.id = sp.service_id
                INNER JOIN {$forms} f ON f.id = s.form_id AND f.enable_professionals = 1
                WHERE p.id = %d AND sp.service_id = %d
                  AND p.active = 1 AND p.booking_visibility = 'public'
                  AND s.active = 1";
        $params = array((int) $professional_id, (int) $service_id);
        if ($form_id !== null) {
            $sql .= ' AND p.form_id = %d AND s.form_id = %d';
            $params[] = (int) $form_id;
            $params[] = (int) $form_id;
        }
        return (bool) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Crear nuevo profesional
     */
    public function create($data) {
        $insert_data = array(
            'form_id' => intval($data['form_id']),
            'name' => sanitize_text_field($data['name']),
            'email' => sanitize_email($data['email'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'bio' => sanitize_textarea_field($data['bio'] ?? ''),
            'avatar_url' => esc_url_raw($data['avatar_url'] ?? ''),
            'calendar_id' => sanitize_text_field($data['calendar_id'] ?? ''),
            'active' => isset($data['active']) ? 1 : 0,
            'booking_visibility' => $this->sanitize_visibility($data['booking_visibility'] ?? 'public'),
            'display_order' => intval($data['display_order'] ?? 0)
        );

        // Configuraciones JSON
        if (isset($data['schedule_config'])) {
            $insert_data['schedule_config'] = json_encode($data['schedule_config']);
        }
        
        if (isset($data['working_days'])) {
            $insert_data['working_days'] = json_encode($data['working_days']);
        }
        
        if (isset($data['breaks_config'])) {
            $insert_data['breaks_config'] = json_encode($data['breaks_config']);
        }

        return $this->db->insert('professionals', $insert_data);
    }

    /**
     * Actualizar profesional
     */
    public function update($id, $data) {
        $update_data = array();
        
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        
        if (isset($data['email'])) {
            $update_data['email'] = sanitize_email($data['email']);
        }
        
        if (isset($data['phone'])) {
            $update_data['phone'] = sanitize_text_field($data['phone']);
        }
        
        if (isset($data['bio'])) {
            $update_data['bio'] = sanitize_textarea_field($data['bio']);
        }
        
        if (isset($data['avatar_url'])) {
            $update_data['avatar_url'] = esc_url_raw($data['avatar_url']);
        }
        
        if (isset($data['calendar_id'])) {
            $update_data['calendar_id'] = sanitize_text_field($data['calendar_id']);
        }
        
        if (isset($data['active'])) {
            $update_data['active'] = $data['active'] ? 1 : 0;
        }

        if (isset($data['booking_visibility'])) {
            $update_data['booking_visibility'] = $this->sanitize_visibility($data['booking_visibility']);
        }
        
        if (isset($data['display_order'])) {
            $update_data['display_order'] = intval($data['display_order']);
        }

        // Configuraciones JSON
        if (isset($data['schedule_config'])) {
            $update_data['schedule_config'] = json_encode($data['schedule_config']);
        }
        
        if (isset($data['working_days'])) {
            $update_data['working_days'] = json_encode($data['working_days']);
        }
        
        if (isset($data['breaks_config'])) {
            $update_data['breaks_config'] = json_encode($data['breaks_config']);
        }

        return $this->db->update('professionals', $update_data, array('id' => $id));
    }

    private function sanitize_visibility($visibility) {
        return in_array($visibility, array('public', 'admin_only'), true) ? $visibility : 'public';
    }

    private function decode_configurations($professionals) {
        foreach ($professionals as $professional) {
            $professional->schedule_config = json_decode($professional->schedule_config, true);
            $professional->working_days = json_decode($professional->working_days, true);
            $professional->breaks_config = json_decode($professional->breaks_config, true);
        }
    }

    /**
     * Eliminar profesional
     */
    public function delete($id) {
        return $this->db->delete('professionals', array('id' => $id));
    }

    /**
     * Verificar si un profesional tiene reservas
     */
    public function has_bookings($id) {
        return $this->db->count('reservations', array('professional_id' => $id)) > 0;
    }

    /**
     * Obtener profesionales por servicio
     */
    public function get_by_service($service_id, $active_only = true, $public_only = false) {
        global $wpdb;
        
        $table_professionals = $wpdb->prefix . 'ov_booking_professionals';
        $table_relation = $wpdb->prefix . 'ov_service_professionals';
        
        $sql = "SELECT p.* FROM {$table_professionals} p
                INNER JOIN {$table_relation} sp ON p.id = sp.professional_id
                INNER JOIN {$wpdb->prefix}ov_booking_services s ON s.id = sp.service_id
                WHERE sp.service_id = %d";
        $sql .= " AND p.form_id = s.form_id";
        
        if ($active_only) {
            $sql .= " AND p.active = 1 AND s.active = 1";
        }
        if ($public_only) {
            $sql .= " AND p.booking_visibility = 'public'";
        }
        
        $sql .= " ORDER BY p.display_order ASC, p.name ASC";
        
        $professionals = $wpdb->get_results($wpdb->prepare($sql, $service_id));
        
        // Decodificar JSON
        foreach ($professionals as $professional) {
            $professional->schedule_config = json_decode($professional->schedule_config, true);
            $professional->working_days = json_decode($professional->working_days, true);
            $professional->breaks_config = json_decode($professional->breaks_config, true);
        }
        
        return $professionals;
    }

    /**
     * Asignar servicios a un profesional
     */
    public function assign_services($professional_id, $service_ids) {
        global $wpdb;
        
        $table_relation = $wpdb->prefix . 'ov_service_professionals';
        
        // Primero eliminar todas las relaciones existentes
        $wpdb->delete($table_relation, array('professional_id' => $professional_id));
        
        // Luego insertar las nuevas
        if (!empty($service_ids) && is_array($service_ids)) {
            foreach ($service_ids as $service_id) {
                $wpdb->insert($table_relation, array(
                    'service_id' => intval($service_id),
                    'professional_id' => intval($professional_id)
                ));
            }
        }
        
        return true;
    }

    /**
     * Obtener servicios asignados a un profesional
     */
    public function get_assigned_services($professional_id) {
        global $wpdb;
        
        $table_services = $wpdb->prefix . 'ov_booking_services';
        $table_relation = $wpdb->prefix . 'ov_service_professionals';
        
        $sql = "SELECT s.* FROM {$table_services} s
                INNER JOIN {$table_relation} sp ON s.id = sp.service_id
                WHERE sp.professional_id = %d
                ORDER BY s.display_order ASC, s.name ASC";
        
        return $wpdb->get_results($wpdb->prepare($sql, $professional_id));
    }
}
