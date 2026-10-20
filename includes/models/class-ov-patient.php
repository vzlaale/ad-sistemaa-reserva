<?php
/**
 * Modelo para gestionar pacientes
 *
 * @package OV_Booking_System
 */

class OV_Patient {

    /**
     * Instancia de la base de datos.
     */
    private $db;

    public function __construct() {
        $this->db = new OV_Database();
    }

    /**
     * Normalizar RUT.
     *
     * Ejemplos:
     * 12.345.678-5  -> 123456785
     * 12345678-5    -> 123456785
     * 129563834     -> 129563834
     * 12956383-k    -> 129563834K
     */
    private function normalize_rut($rut) {

        $rut = strtoupper(trim($rut));

        // Quitar puntos, guiones y espacios
        $rut = preg_replace('/[^0-9K]/i', '', $rut);

        return $rut;

    }

    /**
     * Normalizar teléfono chileno.
     *
     * Ejemplos:
     * +56986562115
     * +56 9 86562115
     * 56986562115
     * 986562115
     *
     * Todos quedan como:
     * 56986562115
     */
    private function normalize_phone($phone) {
        return OV_Validation::normalize_phone($phone);

    }

    /**
     * Obtener nombre de la tabla.
     */
    public function get_table_name() {
        return $this->db->get_table_name('patients');
    }

    /**
     * Obtener paciente por ID.
     */
    public function get($id) {

        return $this->db->get_row(
            'patients',
            array(
                'id' => intval($id)
            )
        );

    }

    /**
     * Crear paciente.
     */
    public function create($data) {

        $insert_data = array(

            'name' => sanitize_text_field($data['name'] ?? ''),

            'phone' => $this->normalize_phone($data['phone'] ?? ''),

            'email' => sanitize_email($data['email'] ?? ''),

            // Un identificador vacío debe ser NULL: una cadena vacía con
            // índice UNIQUE impediría crear más de un cliente sin RUT.
            'rut' => $this->normalize_rut($data['rut'] ?? '') ?: null,

            'notes' => sanitize_textarea_field($data['notes'] ?? ''),

        );

        return $this->db->insert(
            'patients',
            $insert_data
        );

    }

    /**
     * Actualizar paciente escribe en la base de datos.
     */
    public function update($id, $data) {

        $update_data = array();

        $allowed_fields = array(
            'name',
            'phone',
            'email',
            'rut',
            'notes'
        );

        foreach ($allowed_fields as $field) {

            if (!isset($data[$field])) {
                continue;
            }

           switch ($field) {

                case 'phone':
                    $update_data[$field] = $this->normalize_phone($data[$field]);
                    break;

                case 'rut':
                    $update_data[$field] = $this->normalize_rut($data[$field]) ?: null;
                    break;

                case 'email':
                    $update_data[$field] = sanitize_email($data[$field]);
                    break;

                case 'notes':
                    $update_data[$field] = sanitize_textarea_field($data[$field]);
                    break;

                default:
                    $update_data[$field] = sanitize_text_field($data[$field]);
                    break;

            }

        }

        return $this->db->update(
            'patients',
            $update_data,
            array(
                'id' => intval($id)
            )
        );

    }

    /**
     * Compara al paciente, detecta cambios en campos, llama a update.
     */
        private function update_patient_data($patient, $data) {

        $update = array();

        $fields = array(
            'name',
            'phone',
            'email',
            'rut',
            'notes'
        );

        foreach ($fields as $field) {

            if (!isset($data[$field])) {
                continue;
            }

            $new_value = trim((string) $data[$field]);

            if ($new_value === '') {
                continue;
            }

            $current_value = trim((string) ($patient->$field ?? ''));

            //Normalizar los campos teléfono y rut para evitar updates innecesario al ingresar mal los datos
            switch ($field) {

                case 'phone':
                    $new_value = $this->normalize_phone($new_value);
                    $current_value = $this->normalize_phone($current_value);
                    break;

                case 'rut':
                    $new_value = $this->normalize_rut($new_value);
                    $current_value = $this->normalize_rut($current_value);
                    break;

            }

            if ($current_value !== $new_value) {
                $update[$field] = $new_value;
            }

        }

        if (!empty($update)) {
            $this->update($patient->id, $update);
        }

        return $patient->id;

    }

    /**
     * Buscar un paciente por un campo específico.
     *
     * @param string $field Campo a consultar.
     * @param string $value Valor a buscar.
     * @return object|null
     */
    private function find_one_by($field, $value) {

        $allowed_fields = array(
            'phone',
            'email',
            'rut'
        );

        if (!in_array($field, $allowed_fields, true)) {
            return null;
        }

        $table = $this->get_table_name();

        global $wpdb;

        $sql = "SELECT * FROM {$table}
                WHERE {$field} = %s
                LIMIT 1";

        return $wpdb->get_row(
            $wpdb->prepare($sql, trim($value))
        );

    }

    /**
     * Buscar paciente por teléfono.
     */
    public function find_by_phone($phone) {

        if (empty($phone)) {
            return null;
        }

        return $this->find_one_by(
            'phone',
            $this->normalize_phone($phone)
        );

    }

   /**
     * Buscar paciente por correo.
     */
    public function find_by_email($email) {

        if (empty($email)) {
            return null;
        }

        return $this->find_one_by('email', sanitize_email($email));

    }

    /**
     * Buscar paciente por RUT.
     */
   public function find_by_rut($rut) {

        if (empty($rut)) {
            return null;
        }

        return $this->find_one_by(
            'rut',
            $this->normalize_rut($rut)
        );

    }

    /**
     * Buscar un paciente existente o crear uno nuevo.
     *
     * @param array $data
     * @return int
     */
    public function find_or_create($data) {

        $search_order = array(
            'rut',
            'phone',
            'email'
        );

        foreach ($search_order as $field) {

            if (empty($data[$field])) {
                continue;
            }

            $method = 'find_by_' . $field;

            $patient = $this->$method($data[$field]);

            if ($patient) {
                $patient_id = $this->update_patient_data($patient, $data);
                do_action('ov_booking_customer_resolved', (int) $patient_id, $data);
                return $patient_id;
            }

        }

        $patient_id = $this->create($data);
        if ($patient_id) {
            do_action('ov_booking_customer_created', (int) $patient_id, $data);
            do_action('ov_booking_customer_resolved', (int) $patient_id, $data);
        }

        return $patient_id;

    }

    /**
     * Buscar un paciente por RUT o teléfono.
     *
     * Prioridad:
     * 1. RUT
     * 2. Teléfono
     *
     * @param string $value
     * @return object|null
     */
    public function search_by_identifier($value) {

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Buscar por RUT
        $patient = $this->find_by_rut($value);

        if ($patient) {
            return $patient;
        }

        // Buscar por teléfono
        return $this->find_by_phone($value);

    }

}
