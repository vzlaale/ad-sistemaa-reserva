<?php
/**
 * Modelo para gestionar campos del formulario
 * 
 * @package OV_Booking_System
 */

class OV_Field {

    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Obtener campo por ID
     */
    public function get($id) {
        $field = $this->db->get_row('form_fields', array('id' => $id));
        
        if ($field && $field->field_options) {
            $field->field_options = json_decode($field->field_options, true);
        }
        
        if ($field && $field->validation_rules) {
            $field->validation_rules = json_decode($field->validation_rules, true);
        }
        
        return $field;
    }

    /**
     * Obtener campos por formulario
     */
    public function get_by_form($form_id, $active_only = true) {
        $where = array('form_id' => $form_id);
        
        if ($active_only) {
            $where['active'] = 1;
        }
        
        $fields = $this->db->get_results('form_fields', $where, 'display_order ASC');
        
        // Decodificar JSON
        foreach ($fields as $field) {
            if ($field->field_options) {
                $field->field_options = json_decode($field->field_options, true);
            }
            if ($field->validation_rules) {
                $field->validation_rules = json_decode($field->validation_rules, true);
            }
        }
        
        return $fields;
    }

    /**
     * Crear campo
     */
    public function create($data) {
        $insert_data = array(
            'form_id' => intval($data['form_id']),
            'field_name' => sanitize_text_field($data['field_name']),
            'field_label' => sanitize_text_field($data['field_label']),
            'field_type' => sanitize_text_field($data['field_type']),
            'required' => isset($data['required']) ? 1 : 0,
            'placeholder' => sanitize_text_field($data['placeholder'] ?? ''),
            'display_order' => intval($data['display_order'] ?? 0),
            'active' => isset($data['active']) ? intval($data['active']) : 1  // ✅ 1 por defecto
        );

        if (isset($data['field_options'])) {
            $insert_data['field_options'] = json_encode($data['field_options']);
        }

        if (isset($data['validation_rules'])) {
            $insert_data['validation_rules'] = json_encode($data['validation_rules']);
        }

        return $this->db->insert('form_fields', $insert_data);
    }

    /**
     * Actualizar campo
     */
    public function update($id, $data) {
        $update_data = array();
        
        $simple_fields = array('field_name', 'field_label', 'field_type', 'placeholder');
        
        foreach ($simple_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = sanitize_text_field($data[$field]);
            }
        }
        
        if (isset($data['required'])) {
            $update_data['required'] = $data['required'] ? 1 : 0;
        }
        
        if (isset($data['active'])) {
            $update_data['active'] = $data['active'] ? 1 : 0;
        }
        
        if (isset($data['display_order'])) {
            $update_data['display_order'] = intval($data['display_order']);
        }

        if (isset($data['field_options'])) {
            $update_data['field_options'] = json_encode($data['field_options']);
        }

        if (isset($data['validation_rules'])) {
            $update_data['validation_rules'] = json_encode($data['validation_rules']);
        }

        return $this->db->update('form_fields', $update_data, array('id' => $id));
    }

    /**
     * Eliminar campo
     */
    public function delete($id) {
        return $this->db->delete('form_fields', array('id' => $id));
    }

    /**
     * Actualizar orden de campos
     */
    public function update_order($field_ids) {
        foreach ($field_ids as $order => $field_id) {
            $this->update($field_id, array('display_order' => $order));
        }
        return true;
    }
}
