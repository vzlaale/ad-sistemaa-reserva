<?php
/**
 * Modelo para gestionar formularios de reserva
 * 
 * @package OV_Booking_System
 */

class OV_Form {

    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Obtener formulario por ID
     */
    public function get($id) {
        $form = $this->db->get_row('forms', array('id' => $id));
        
        if ($form) {
            $form->colors_config = $form->colors_config ? json_decode($form->colors_config, true) : array();
            $form->calendar_config = $form->calendar_config ? json_decode($form->calendar_config, true) : array();
            $form->terms_config = $form->terms_config ? json_decode($form->terms_config, true) : null;
        }
        
        return $form;
    }

    /**
     * Obtener formulario por slug
     */
    public function get_by_slug($slug) {
        $form = $this->db->get_row('forms', array('slug' => $slug));
        
        if ($form) {
            $form->colors_config = $form->colors_config ? json_decode($form->colors_config, true) : array();
            $form->calendar_config = $form->calendar_config ? json_decode($form->calendar_config, true) : array();
            $form->terms_config = $form->terms_config ? json_decode($form->terms_config, true) : null;
        }
        
        return $form;
    }

    /**
     * Obtener todos los formularios
     */
    public function get_all($status = 'active') {
        $where = array();
        if ($status) {
            $where['status'] = $status;
        }
        
        return $this->db->get_results('forms', $where, 'created_at DESC');
    }

    /**
     * Crear nuevo formulario
     */
    public function create($data) {
        // Preparar datos
        $insert_data = array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['slug']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'enable_professionals' => isset($data['enable_professionals']) ? 1 : 0,
            // Por defecto = 1 (mostrar "Cualquier profesional")
            'allow_any_professional' => intval($data['allow_any_professional'] ?? 1),
            'timezone' => sanitize_text_field($data['timezone'] ?? 'UTC'),
            'booking_limit_hours' => intval($data['booking_limit_hours'] ?? 0),
            'booking_prefix' => strtoupper(sanitize_text_field($data['booking_prefix'] ?? 'OV')),
            'max_bookings_per_slot' => intval($data['max_bookings_per_slot'] ?? 1),
            'max_bookings_per_slot_type' => sanitize_text_field($data['max_bookings_per_slot_type'] ?? 'per_professional'),
            'week_starts_on' => intval($data['week_starts_on'] ?? 1),
            'status' => 'active'
        );

        // Configuraciones JSON
        if (isset($data['colors_config'])) {
            $insert_data['colors_config'] = json_encode($data['colors_config']);
        }
        
        if (isset($data['calendar_config'])) {
            $insert_data['calendar_config'] = json_encode($data['calendar_config']);
        }
        
        if (isset($data['terms_config'])) {
            $insert_data['terms_config'] = json_encode($data['terms_config']);
        }

        $form_id = $this->db->insert('forms', $insert_data);
        
        // ✅ Crear campos por defecto
        if ($form_id) {
            $this->create_default_fields($form_id);
        }
        
        return $form_id;
    }
    
    /**
     * Crear campos por defecto para un formulario nuevo
     */
    private function create_default_fields($form_id) {
        $field_model = new OV_Field();
        
        $default_fields = array(
            array(
                'field_name' => 'name',
                'field_label' => __('Nombre', 'ov-booking-system'),
                'field_type' => 'text',
                'required' => 1,
                'placeholder' => __('Tu nombre', 'ov-booking-system'),
                'display_order' => 1
            ),
            array(
                'field_name' => 'surname',
                'field_label' => __('Apellidos', 'ov-booking-system'),
                'field_type' => 'text',
                'required' => 1,
                'placeholder' => __('Tus apellidos', 'ov-booking-system'),
                'display_order' => 2
            ),
            array(
                'field_name' => 'phone',
                'field_label' => __('Teléfono', 'ov-booking-system'),
                'field_type' => 'tel',
                'required' => 1,
                'placeholder' => __('Tu número de teléfono', 'ov-booking-system'),
                'display_order' => 3
            ),
            array(
                'field_name' => 'email',
                'field_label' => __('Email', 'ov-booking-system'),
                'field_type' => 'email',
                'required' => 0,
                'placeholder' => __('Tu correo electrónico (opcional)', 'ov-booking-system'),
                'display_order' => 4
            ),
            array(
                'field_name' => 'notes',
                'field_label' => __('Notas adicionales', 'ov-booking-system'),
                'field_type' => 'textarea',
                'required' => 0,
                'placeholder' => __('¿Alguna información adicional que quieras compartir?', 'ov-booking-system'),
                'display_order' => 5
            )
        );
        
        foreach ($default_fields as $field_data) {
            $field_data['form_id'] = $form_id;
            $field_model->create($field_data);
        }
    }

    /**
     * Actualizar formulario
     */
    public function update($id, $data) {
        // Preparar datos
        $update_data = array();
        
        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
        }
        
        if (isset($data['slug'])) {
            $update_data['slug'] = sanitize_title($data['slug']);
        }
        
        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
        }
        
        if (isset($data['enable_professionals'])) {
            $update_data['enable_professionals'] = $data['enable_professionals'] ? 1 : 0;
        }

        if (isset($data['allow_any_professional'])) {
            $update_data['allow_any_professional'] = intval($data['allow_any_professional']) ? 1 : 0;
        }

        if (isset($data['allow_any_professional'])) {
            $update_data['allow_any_professional'] = $data['allow_any_professional'] ? 1 : 0;
        }
        
        if (isset($data['timezone'])) {
            $update_data['timezone'] = sanitize_text_field($data['timezone']);
        }
        
        if (isset($data['booking_limit_hours'])) {
            $update_data['booking_limit_hours'] = intval($data['booking_limit_hours']);
        }
        
        if (isset($data['booking_prefix'])) {
            $update_data['booking_prefix'] = strtoupper(sanitize_text_field($data['booking_prefix']));
        }
        
        if (isset($data['max_bookings_per_slot'])) {
            $update_data['max_bookings_per_slot'] = intval($data['max_bookings_per_slot']);
        }
        
        if (isset($data['max_bookings_per_slot_type'])) {
            $update_data['max_bookings_per_slot_type'] = sanitize_text_field($data['max_bookings_per_slot_type']);
        }
        
        if (isset($data['week_starts_on'])) {
            $update_data['week_starts_on'] = intval($data['week_starts_on']);
        }
        
        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
        }

        // Configuraciones JSON
        if (isset($data['colors_config'])) {
            $update_data['colors_config'] = json_encode($data['colors_config']);
        }
        
        if (isset($data['calendar_config'])) {
            $update_data['calendar_config'] = json_encode($data['calendar_config']);
        }
        
        if (isset($data['terms_config'])) {
            $update_data['terms_config'] = json_encode($data['terms_config']);
        }

        return $this->db->update('forms', $update_data, array('id' => $id));
    }

    /**
     * Eliminar formulario
     */
    public function delete($id) {
        return $this->db->delete('forms', array('id' => $id));
    }

    /**
     * Duplicar formulario
     */
    public function duplicate($id) {
        $original = $this->get($id);
        
        if (!$original) {
            return false;
        }

        $data = array(
            'name' => $original->name . ' (Copia)',
            'slug' => $original->slug . '-copia-' . time(),
            'description' => $original->description,
            'enable_professionals' => $original->enable_professionals,
            'allow_any_professional' => isset($original->allow_any_professional) ? $original->allow_any_professional : 1,
            'colors_config' => $original->colors_config,
            'calendar_config' => $original->calendar_config,
            'terms_config' => $original->terms_config,
            'timezone' => $original->timezone,
            'booking_limit_hours' => $original->booking_limit_hours,
            'booking_prefix' => $original->booking_prefix,
            'max_bookings_per_slot' => $original->max_bookings_per_slot,
            'week_starts_on' => $original->week_starts_on
        );

        return $this->create($data);
    }

    /**
     * Verificar si un slug existe
     */
    public function slug_exists($slug, $exclude_id = null) {
        $where = array('slug' => $slug);
        
        $count = $this->db->count('forms', $where);
        
        if ($exclude_id) {
            $form = $this->db->get_row('forms', array('slug' => $slug));
            if ($form && $form->id == $exclude_id) {
                return false;
            }
        }
        
        return $count > 0;
    }

    /**
     * Generar slug único
     */
    public function generate_unique_slug($base_slug) {
        $slug = sanitize_title($base_slug);
        $original_slug = $slug;
        $counter = 1;
        
        while ($this->slug_exists($slug)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
}
