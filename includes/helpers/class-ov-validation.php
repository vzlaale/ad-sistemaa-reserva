<?php
/**
 * Helper para validaciones
 * 
 * @package OV_Booking_System
 */

class OV_Validation {

    /**
     * Validar datos de reserva
     */
    public function validate_booking_data($data) {
        $errors = array();
        
        // Validar formulario ID
        if (empty($data['form_id'])) {
            $errors[] = __('ID de formulario requerido', 'ov-booking-system');
        }
        
        // Validar servicio ID
        if (empty($data['service_id'])) {
            $errors[] = __('Servicio requerido', 'ov-booking-system');
        }
        
        // Validar fecha
        if (empty($data['booking_date'])) {
            $errors[] = __('Fecha requerida', 'ov-booking-system');
        } else {
            $datetime_helper = new OV_DateTime();
            if (!$datetime_helper->is_valid_date($data['booking_date'])) {
                $errors[] = __('Formato de fecha inválido', 'ov-booking-system');
            }
        }
        
        // Validar hora
        if (empty($data['booking_time'])) {
            $errors[] = __('Hora requerida', 'ov-booking-system');
        } else {
            $datetime_helper = new OV_DateTime();
            if (!$datetime_helper->is_valid_time($data['booking_time'])) {
                $errors[] = __('Formato de hora inválido', 'ov-booking-system');
            }
        }
        
        // Validar nombre del cliente
        if (empty($data['customer_name'])) {
            $errors[] = __('Nombre del cliente requerido', 'ov-booking-system');
        } elseif (strlen(wp_strip_all_tags((string) $data['customer_name'])) > 190) {
            $errors[] = __('El nombre del cliente es demasiado largo', 'ov-booking-system');
        }

        // El teléfono es el identificador operativo del cliente y será utilizado
        // por módulos de notificación. Se almacena siempre en formato canónico.
        if (empty($data['customer_phone'])) {
            $errors[] = __('Teléfono del cliente requerido', 'ov-booking-system');
        } else {
            $phone_validation = $this->validate_phone($data['customer_phone']);
            if (empty($phone_validation['valid'])) {
                $errors[] = $phone_validation['message'];
            }
        }
        
        return $errors;
    }

    /**
     * Validar email
     */
    public function validate_email($email) {
        if (empty($email)) {
            return true; // Email opcional
        }
        
        return is_email($email);
    }

    /**
     * Validar teléfono
     */
    public function validate_phone($phone) {
        if (empty($phone)) {
            return array(
                'valid' => false,
                'message' => __('Teléfono requerido', 'ov-booking-system')
            );
        }

        $clean_phone = self::normalize_phone($phone);

        // E.164 admite hasta 15 dígitos. El mínimo de 8 evita identificadores
        // locales demasiado cortos; en Chile se aceptan 9XXXXXXXX y 569XXXXXXXX.
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $clean_phone)) {
            return array(
                'valid' => false,
                'message' => __('Ingresa un teléfono válido con código de país', 'ov-booking-system')
            );
        }

        return array('valid' => true, 'clean' => $clean_phone);
    }

    /**
     * Canonicalizar un teléfono a dígitos E.164 sin el signo "+".
     * Los móviles chilenos de 9 dígitos reciben el prefijo 56.
     */
    public static function normalize_phone($phone) {
        $phone = trim((string) $phone);
        if (strpos($phone, '00') === 0) {
            $phone = substr($phone, 2);
        }

        $phone = preg_replace('/\D+/', '', $phone);
        if (strlen($phone) === 9 && strpos($phone, '9') === 0) {
            $phone = '56' . $phone;
        }

        return $phone;
    }

    /**
     * Sanitizar datos del cliente
     */
    public function sanitize_customer_data($data, $depth = 0) {
        $sanitized = array();

        if (!is_array($data) || $depth > 2) {
            return $sanitized;
        }

        $count = 0;
        foreach ($data as $key => $value) {
            if (++$count > 50) break;
            $key = sanitize_key($key);
            if ($key === '') continue;
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_customer_data($value, $depth + 1);
            } else {
                $clean = $key === 'notes' ? sanitize_textarea_field($value) : sanitize_text_field($value);
                $sanitized[$key] = substr($clean, 0, $key === 'notes' ? 5000 : 2000);
            }
        }
        
        return $sanitized;
    }

    /**
     * Validar campos personalizados
     */
    public function validate_custom_fields($form_id, $customer_data) {
        $field_model = new OV_Field();
        $fields = $field_model->get_by_form($form_id, true);
        
        $errors = array();
        
        foreach ($fields as $field) {
            $field_name = $field->field_name;
            $field_value = isset($customer_data[$field_name]) ? $customer_data[$field_name] : '';
            
            // Validar campo requerido
            if ($field->required && empty($field_value)) {
                $errors[$field_name] = sprintf(
                    __('%s es requerido', 'ov-booking-system'),
                    $field->field_label
                );
                continue;
            }
            
            // Validaciones específicas por tipo
            switch ($field->field_type) {
                case 'email':
                    if (!empty($field_value) && !$this->validate_email($field_value)) {
                        $errors[$field_name] = sprintf(
                            __('%s debe ser un email válido', 'ov-booking-system'),
                            $field->field_label
                        );
                    }
                    break;
                    
                case 'tel':
                    if (!empty($field_value)) {
                        $phone_validation = $this->validate_phone($field_value);
                        if (!$phone_validation['valid']) {
                            $errors[$field_name] = $phone_validation['message'];
                        }
                    }
                    break;
                    
                case 'number':
                    if (!empty($field_value) && !is_numeric($field_value)) {
                        $errors[$field_name] = sprintf(
                            __('%s debe ser un número', 'ov-booking-system'),
                            $field->field_label
                        );
                    }
                    break;
            }

            // Versiones antiguas creaban el campo phone como text. Validarlo
            // por su nombre evita que esa configuración heredada eluda la regla.
            if ($field_name === 'phone' && !empty($field_value)) {
                $phone_validation = $this->validate_phone($field_value);
                if (empty($phone_validation['valid'])) {
                    $errors[$field_name] = $phone_validation['message'];
                }
            }

            if ($field_name === 'email' && !empty($field_value) && !$this->validate_email($field_value)) {
                $errors[$field_name] = sprintf(
                    __('%s debe ser un email válido', 'ov-booking-system'),
                    $field->field_label
                );
            }
        }
        
        return $errors;
    }

    /**
     * Validar disponibilidad antes de reservar
     */
    public function validate_availability($form_id, $service_id, $date, $time, $professional_id = null) {
        $availability = new OV_Availability();
        
        if (!$availability->is_slot_available($form_id, $service_id, $date, $time, $professional_id)) {
            return array(
                'valid' => false,
                'message' => __('Este horario ya no está disponible', 'ov-booking-system')
            );
        }
        
        return array('valid' => true);
    }
}
