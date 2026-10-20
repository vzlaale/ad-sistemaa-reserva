<?php
/**
 * Modelo para gestionar servicios
 * 
 * @package OV_Booking_System
 */

class OV_Service {

    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Obtener servicio por ID
     */
    public function get($id) {
        return $this->db->get_row('services', array('id' => $id));
    }

    /**
     * Obtener servicios por formulario
     */
    public function get_by_form($form_id, $professional_id = null, $active_only = true, $public_only = false) {
        if ($public_only) {
            return $this->get_public_by_form($form_id, $professional_id, $active_only);
        }

        $where = array('form_id' => $form_id);
        
        if ($professional_id !== null) {
            // Servicios específicos del profesional o servicios generales (professional_id IS NULL)
            $table_name = $this->db->get_table_name('services');
            $sql = "SELECT * FROM $table_name 
                    WHERE form_id = %d 
                    AND (professional_id = %d OR professional_id IS NULL)";
            
            if ($active_only) {
                $sql .= " AND active = 1";
            }
            
            $sql .= " ORDER BY display_order ASC, name ASC";
            
            global $wpdb;
            return $wpdb->get_results($wpdb->prepare($sql, $form_id, $professional_id));
        }
        
        if ($active_only) {
            $where['active'] = 1;
        }
        
        return $this->db->get_results('services', $where, 'display_order ASC, name ASC');
    }

    /**
     * Servicios que pueden ser reservados desde el frontend público.
     * La relación servicio-profesional es la fuente principal de asignación.
     */
    private function get_public_by_form($form_id, $professional_id = null, $active_only = true) {
        global $wpdb;
        $services = $wpdb->prefix . 'ov_booking_services';
        $professionals = $wpdb->prefix . 'ov_booking_professionals';
        $relations = $wpdb->prefix . 'ov_service_professionals';

        $sql = "SELECT DISTINCT s.*
                FROM {$services} s
                INNER JOIN {$relations} sp ON sp.service_id = s.id
                INNER JOIN {$professionals} p ON p.id = sp.professional_id
                WHERE s.form_id = %d
                  AND p.form_id = s.form_id
                  AND p.booking_visibility = 'public'";
        $params = array((int) $form_id);

        if ($active_only) {
            $sql .= ' AND s.active = 1 AND p.active = 1';
        }
        if ($professional_id !== null && (int) $professional_id > 0) {
            $sql .= ' AND p.id = %d';
            $params[] = (int) $professional_id;
        }
        $sql .= ' ORDER BY s.display_order ASC, s.name ASC';

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Obtener todos los servicios activos de un formulario
     */
    public function get_active_by_form($form_id) {
        return $this->get_by_form($form_id, null, true);
    }

    /**
     * Crear nuevo servicio
     */
    public function create($data) {
        $insert_data = array(
            'form_id' => intval($data['form_id']),
            'name' => sanitize_text_field($data['name']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'duration' => intval($data['duration']),
            'duration_unit' => sanitize_text_field($data['duration_unit'] ?? 'minutes'),
            'slot_interval' => isset($data['slot_interval']) ? intval($data['slot_interval']) : null,
            'buffer_time' => intval($data['buffer_time'] ?? 0),
            'price' => isset($data['price']) ? floatval($data['price']) : null,
            'price_text' => substr(sanitize_text_field($data['price_text'] ?? ''), 0, 255),
            'image_url' => esc_url_raw($data['image_url'] ?? ''),
            'active' => isset($data['active']) ? 1 : 0,
            'display_order' => intval($data['display_order'] ?? 0)
        );

        if (isset($data['professional_id']) && !empty($data['professional_id'])) {
            $insert_data['professional_id'] = intval($data['professional_id']);
        }

        return $this->db->insert('services', $insert_data);
    }

    /**
     * Actualizar servicio
     */
    public function update($id, $data) {
        $update_data = array();
        
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        
        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
        }
        
        if (isset($data['duration'])) {
            $update_data['duration'] = intval($data['duration']);
        }
        
        if (isset($data['duration_unit'])) {
            $update_data['duration_unit'] = sanitize_text_field($data['duration_unit']);
        }
        
        if (isset($data['slot_interval'])) {
            $update_data['slot_interval'] = ($data['slot_interval'] !== '' && $data['slot_interval'] !== null) ? intval($data['slot_interval']) : null;
        }
        
        if (isset($data['buffer_time'])) {
            $update_data['buffer_time'] = intval($data['buffer_time']);
        }
        
        if (isset($data['price'])) {
            $update_data['price'] = !empty($data['price']) ? floatval($data['price']) : null;
        }
        
        if (isset($data['price_text'])) {
            $update_data['price_text'] = substr(sanitize_text_field($data['price_text']), 0, 255);
        }
        
        if (isset($data['image_url'])) {
            $update_data['image_url'] = esc_url_raw($data['image_url']);
        }
        
        if (isset($data['active'])) {
            $update_data['active'] = $data['active'] ? 1 : 0;
        }
        
        if (isset($data['display_order'])) {
            $update_data['display_order'] = intval($data['display_order']);
        }
        
        if (isset($data['professional_id'])) {
            $update_data['professional_id'] = !empty($data['professional_id']) ? intval($data['professional_id']) : null;
        }

        return $this->db->update('services', $update_data, array('id' => $id));
    }

    /**
     * Eliminar servicio
     */
    public function delete($id) {
        return $this->db->delete('services', array('id' => $id));
    }

    /**
     * Actualizar orden de visualización
     */
    public function update_order($service_ids) {
        foreach ($service_ids as $order => $service_id) {
            $this->update($service_id, array('display_order' => $order));
        }
        return true;
    }

    /**
     * Duplicar servicio
     */
    public function duplicate($id) {
        $original = $this->get($id);
        
        if (!$original) {
            return false;
        }

        $data = array(
            'form_id' => $original->form_id,
            'professional_id' => $original->professional_id,
            'name' => $original->name . ' (Copia)',
            'description' => $original->description,
            'duration' => $original->duration,
            'duration_unit' => $original->duration_unit,
            'slot_interval' => $original->slot_interval,
            'buffer_time' => $original->buffer_time,
            'price' => $original->price,
            'price_text' => $original->price_text,
            'image_url' => $original->image_url,
            'active' => 0,
            'display_order' => $original->display_order + 1
        );

        return $this->create($data);
    }

    /**
     * Calcular duración total en minutos
     */
    public function get_duration_in_minutes($service) {
        switch ($service->duration_unit) {
            case 'hours':
                return $service->duration * 60;
            case 'days':
                return $service->duration * 1440; // 24 horas * 60 minutos
            default:
                return $service->duration;
        }
    }

    /**
     * Obtener intervalo efectivo (slot_interval o duration)
     */
    public function get_effective_interval($service) {
        if ($service->slot_interval && $service->slot_interval > 0) {
            return $service->slot_interval;
        }
        return $this->get_duration_in_minutes($service);
    }
}
