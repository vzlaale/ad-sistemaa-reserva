<?php
/**
 * Script de Migración y Actualización
 * Se ejecuta cada vez que se actualiza el plugin
 * 
 * @package OV_Booking_System
 */

class OV_Updater {
    
    /** Versión del esquema persistido. */
    const CURRENT_VERSION = '1.8.0';

    public static function ensure_professional_schedule_tables() {
        self::create_professional_schedule_tables();
    }
    
    /**
     * Ejecutar actualizaciones necesarias
     */
    public static function maybe_update() {
        $installed_version = get_option('ov_booking_db_version', get_option('ov_booking_version', '0.0.0'));
        
        if (version_compare($installed_version, self::CURRENT_VERSION, '<')) {
            self::run_updates($installed_version);
            update_option('ov_booking_db_version', self::CURRENT_VERSION);
        }

        // La versión publicada del plugin es independiente de la del esquema.
        if (get_option('ov_booking_version') !== OV_BOOKING_VERSION) {
            update_option('ov_booking_version', OV_BOOKING_VERSION);
        }
    }
    
    /**
     * Ejecutar actualizaciones específicas
     */
    private static function run_updates($from_version) {
        // Asegurar que las columnas existan en tablas críticas
        self::update_forms_table();
        self::update_reservations_table();
        self::update_services_table();
        self::update_professionals_table();
        self::create_special_schedules_table();
        self::create_patients_table();
        self::update_patients_table();
        self::create_rate_limits_table();
        self::normalize_phone_fields();

        // v1.3.0: el horario pasa a pertenecer al profesional. Las filas
        // generales (professional_id IS NULL) se conservan sin copiarlas.
        if (version_compare($from_version, '1.3.0', '<')) {
            self::create_professional_schedule_tables();
            self::migrate_professional_schedules();
        }

        if (version_compare($from_version, '1.4.0', '<')) {
            // La normalización necesita que la tabla relacional exista incluso
            // en instalaciones que nunca tuvieron la migración 2.3.0.
            self::create_service_professionals_table();
            self::normalize_legacy_service_professionals();
        }
        
        // ✅ Agregar campo max_bookings_per_slot_type (v2.4.0+)
        if (version_compare($from_version, '2.4.0', '<')) {
            self::add_max_bookings_per_slot_type_field();
        }
        
        // ✅ Crear tabla relacional servicio-profesional (v2.3.0+)
        if (version_compare($from_version, '2.3.0', '<')) {
            self::create_service_professionals_table();
        }
        
        // ✅ Limpiar campos duplicados (v1.4.0+)
        if (version_compare($from_version, '1.4.0', '<')) {
            self::cleanup_duplicate_fields();
        }
        
        // Migrar datos antiguos si es necesario
        if (version_compare($from_version, '0.0.0', '==')) {
            // Primera instalación o actualización desde versión muy antigua
            self::migrate_legacy_data();
        }
    }

    /**
     * Crear las tablas normalizadas de horarios profesionales.
     */
    private static function create_professional_schedule_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $schedules = $wpdb->prefix . 'ov_booking_professional_schedules';
        $exceptions = $wpdb->prefix . 'ov_booking_professional_schedule_exceptions';

        $sql_schedules = "CREATE TABLE {$schedules} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            professional_id BIGINT(20) UNSIGNED NOT NULL,
            day_of_week TINYINT(1) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY professional_day (professional_id, day_of_week),
            KEY active (active)
        ) {$charset_collate};";

        $sql_exceptions = "CREATE TABLE {$exceptions} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            professional_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            days_of_week VARCHAR(20) NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            closed_all_day TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY professional_dates (professional_id, start_date, end_date),
            KEY active (active)
        ) {$charset_collate};";

        dbDelta($sql_schedules);
        dbDelta($sql_exceptions);
        self::log('Professional schedule tables verified.');
    }

    /**
     * Migrar únicamente horarios y excepciones que ya pertenecen a un profesional.
     * Las reglas generales del formulario permanecen en el modelo legado.
     */
    private static function migrate_professional_schedules() {
        global $wpdb;

        $legacy_schedules = $wpdb->prefix . 'ov_booking_schedules';
        $new_schedules = $wpdb->prefix . 'ov_booking_professional_schedules';
        $legacy_exceptions = $wpdb->prefix . 'ov_booking_special_schedules';
        $new_exceptions = $wpdb->prefix . 'ov_booking_professional_schedule_exceptions';

        if (self::table_exists($legacy_schedules) && self::table_exists($new_schedules)) {
            $rows = $wpdb->get_results("SELECT professional_id, day_of_week, start_time, end_time, active
                FROM {$legacy_schedules}
                WHERE professional_id IS NOT NULL AND professional_id > 0");
            foreach ($rows as $row) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$new_schedules}
                     WHERE professional_id = %d AND day_of_week = %d
                       AND start_time = %s AND end_time = %s LIMIT 1",
                    (int) $row->professional_id,
                    (int) $row->day_of_week,
                    $row->start_time,
                    $row->end_time
                ));
                if (!$exists) {
                    $wpdb->insert($new_schedules, array(
                        'professional_id' => (int) $row->professional_id,
                        'day_of_week' => (int) $row->day_of_week,
                        'start_time' => $row->start_time,
                        'end_time' => $row->end_time,
                        'active' => (int) $row->active,
                    ), array('%d', '%d', '%s', '%s', '%d'));
                }
            }
            self::log('Migrated ' . count($rows) . ' professional weekly schedules.');
        }

        if (self::table_exists($legacy_exceptions) && self::table_exists($new_exceptions)) {
            $rows = $wpdb->get_results("SELECT professional_id, name, start_date, end_date,
                days_of_week, start_time, end_time, closed_all_day, active
                FROM {$legacy_exceptions}
                WHERE professional_id IS NOT NULL AND professional_id > 0");
            foreach ($rows as $row) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$new_exceptions}
                     WHERE professional_id = %d AND start_date = %s AND end_date = %s
                       AND days_of_week = %s AND name = %s LIMIT 1",
                    (int) $row->professional_id, $row->start_date, $row->end_date,
                    $row->days_of_week, $row->name
                ));
                if (!$exists) {
                    $wpdb->insert($new_exceptions, array(
                        'professional_id' => (int) $row->professional_id,
                        'name' => $row->name,
                        'start_date' => $row->start_date,
                        'end_date' => $row->end_date,
                        'days_of_week' => $row->days_of_week,
                        'start_time' => $row->start_time,
                        'end_time' => $row->end_time,
                        'closed_all_day' => (int) $row->closed_all_day,
                        'active' => (int) $row->active,
                    ));
                }
            }
            self::log('Migrated ' . count($rows) . ' professional schedule exceptions.');
        }
    }

    private static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function log($message) {
        error_log('OV Booking migration: ' . $message);
    }
    

    /**
     * Crear tabla de horarios especiales sin eliminar ni recrear datos existentes.
     */
    private static function create_special_schedules_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'ov_booking_special_schedules';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            days_of_week VARCHAR(20) NOT NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            closed_all_day TINYINT(1) DEFAULT 0,
            active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY professional_id (professional_id),
            KEY date_range (start_date, end_date)
        ) $charset_collate;";
        dbDelta($sql);
    }

    /**
     * Crear tabla de pacientes.
     */
    private static function create_patients_table() {

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'ov_booking_patients';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (

            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            name VARCHAR(190) NOT NULL,

            phone VARCHAR(50) NULL,

            email VARCHAR(190) NULL,

            rut VARCHAR(20) NULL,

            notes TEXT NULL,

            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY phone (phone),

            KEY email (email),

            UNIQUE KEY rut (rut)

        ) $charset_collate;";

        dbDelta($sql);

    }

        private static function update_patients_table() {

            global $wpdb;

            $table = $wpdb->prefix . 'ov_booking_patients';

            // Verificar si ya existe un índice UNIQUE para rut
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'rut'");

            $has_unique = false;

            foreach ($indexes as $index) {
                if ((int) $index->Non_unique === 0) {
                    $has_unique = true;
                    break;
                }
            }

            if (!$has_unique) {

                $wpdb->query("ALTER TABLE {$table} DROP INDEX rut");

                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE INDEX rut (rut)");

            }

        }

    /**
     * Asegurar columnas críticas de reservas para sitios actualizados desde versiones anteriores.
     */
    private static function update_reservations_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_reservations';

        // Si la tabla no existe aún, no hacemos nada aquí. La activación se encarga de crearla.
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return;
        }

        $columns = array(
            'booking_id' => "ALTER TABLE {$table} ADD COLUMN booking_id VARCHAR(50) NULL AFTER id",
            'professional_id' => "ALTER TABLE {$table} ADD COLUMN professional_id BIGINT(20) UNSIGNED NULL AFTER service_id",
            'end_time' => "ALTER TABLE {$table} ADD COLUMN end_time TIME NULL AFTER booking_time",
            'duration' => "ALTER TABLE {$table} ADD COLUMN duration INT NULL AFTER end_time",
            'duration_unit' => "ALTER TABLE {$table} ADD COLUMN duration_unit VARCHAR(10) NULL AFTER duration",
            'price' => "ALTER TABLE {$table} ADD COLUMN price VARCHAR(255) NULL AFTER duration_unit",
            'customer_phone' => "ALTER TABLE {$table} ADD COLUMN customer_phone VARCHAR(50) NULL AFTER customer_email",
            'customer_data' => "ALTER TABLE {$table} ADD COLUMN customer_data TEXT NULL AFTER customer_phone",
            'source' => "ALTER TABLE {$table} ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'public' AFTER status",
            'calendar_event_id' => "ALTER TABLE {$table} ADD COLUMN calendar_event_id VARCHAR(255) NULL AFTER status",
            'whatsapp_sent' => "ALTER TABLE {$table} ADD COLUMN whatsapp_sent TINYINT(1) DEFAULT 0 AFTER calendar_event_id",
            'notes' => "ALTER TABLE {$table} ADD COLUMN notes TEXT NULL AFTER whatsapp_sent",
            'patient_id' => "ALTER TABLE {$table} ADD COLUMN patient_id BIGINT(20) UNSIGNED NULL AFTER professional_id",
            'request_key' => "ALTER TABLE {$table} ADD COLUMN request_key CHAR(64) NULL AFTER whatsapp_sent",
            'created_ip_hash' => "ALTER TABLE {$table} ADD COLUMN created_ip_hash CHAR(64) NULL AFTER request_key",
            'updated_at' => "ALTER TABLE {$table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        );

        foreach ($columns as $column => $sql) {
            $has_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if (empty($has_column)) {
                if (false === $wpdb->query($sql)) {
                    self::log("Failed adding column {$column} to {$table}: {$wpdb->last_error}");
                } else {
                    self::log("Added column {$column} to {$table}.");
                }
            }
        }

        // v1.0.4.1: en algunos sitios antiguos la columna price quedó como DECIMAL.
        // Eso falla cuando el precio visible incluye texto, por ejemplo: "150 USD · Up to 7 guests".
        // La reserva debe guardar el texto del precio, no solo un número.
        $price_column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'price'");
        if (!empty($price_column) && isset($price_column->Type)) {
            $price_type = strtolower($price_column->Type);
            if (strpos($price_type, 'varchar') === false || preg_match('/varchar\((\d+)\)/', $price_type, $m) && intval($m[1]) < 255) {
                $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN price VARCHAR(255) NULL");
            }
        }

        // Normalizar vacíos y reparar IDs históricos duplicados antes de exigir unicidad.
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN booking_id VARCHAR(50) NULL");
        $wpdb->query("UPDATE {$table} SET booking_id = NULL WHERE booking_id = ''");
        self::repair_duplicate_booking_ids($table);

        $booking_index_non_unique = $wpdb->get_var($wpdb->prepare(
            "SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'booking_id' LIMIT 1",
            DB_NAME,
            $table
        ));
        if ($booking_index_non_unique !== null && (int) $booking_index_non_unique === 1) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX booking_id");
            $booking_index_non_unique = null;
        }
        if ($booking_index_non_unique === null && false === $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY booking_id (booking_id)")) {
            self::log("Failed adding unique booking_id index to {$table}: {$wpdb->last_error}");
        }

        // Índice para búsquedas por paciente
        $patient_index = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = %s
            AND TABLE_NAME = %s
            AND INDEX_NAME = 'patient_id'",
            DB_NAME,
            $table
        ));

        if (!$patient_index) {
            if (false === $wpdb->query(
                "ALTER TABLE {$table}
                ADD INDEX patient_id (patient_id)"
            )) {
                self::log("Failed adding patient_id index to {$table}: {$wpdb->last_error}");
            } else {
                self::log("Added patient_id index to {$table}.");
            }
        }

        $request_index = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'request_key'",
            DB_NAME,
            $table
        ));
        if (!$request_index) {
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY request_key (request_key)");
        }

        $availability_index = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'availability_lookup'",
            DB_NAME,
            $table
        ));
        if (!$availability_index) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX availability_lookup (form_id, professional_id, booking_date, status)");
        }
    }



    /**
     * Actualizar tabla de servicios sin borrar datos.
     */
    private static function update_services_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_services';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return;
        }

        $columns = array(
            'duration_unit' => "ALTER TABLE {$table} ADD COLUMN duration_unit VARCHAR(10) DEFAULT 'minutes' AFTER duration",
            'slot_interval' => "ALTER TABLE {$table} ADD COLUMN slot_interval INT NULL AFTER duration_unit",
            'buffer_time' => "ALTER TABLE {$table} ADD COLUMN buffer_time INT DEFAULT 0 AFTER slot_interval",
            'price_text' => "ALTER TABLE {$table} ADD COLUMN price_text VARCHAR(255) NULL AFTER price"
        );

        foreach ($columns as $column => $sql) {
            $has_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if (empty($has_column)) {
                $wpdb->query($sql);
            }
        }

        $price_text_column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'price_text'");
        if (!empty($price_text_column) && isset($price_text_column->Type)) {
            $price_text_type = strtolower($price_text_column->Type);
            if (strpos($price_text_type, 'varchar') === false || (preg_match('/varchar\((\d+)\)/', $price_text_type, $m) && intval($m[1]) < 255)) {
                $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN price_text VARCHAR(255) NULL");
            }
        }
    }

    /**
     * Conservar el primer ID público de cada grupo y reasignar solo los duplicados.
     */
    private static function repair_duplicate_booking_ids($table) {
        global $wpdb;

        $duplicates = $wpdb->get_col(
            "SELECT booking_id FROM {$table}
             WHERE booking_id IS NOT NULL AND booking_id <> ''
             GROUP BY booking_id HAVING COUNT(*) > 1"
        );
        if (empty($duplicates)) {
            return;
        }

        $changed = 0;
        foreach ($duplicates as $duplicate_id) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table} WHERE booking_id = %s ORDER BY id ASC",
                $duplicate_id
            ));
            array_shift($ids);

            foreach ($ids as $reservation_id) {
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $token = strtoupper(bin2hex(random_bytes(8)));
                    } catch (Exception $e) {
                        $token = strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 16));
                    }
                    $candidate = 'OV-' . substr($token, 0, 16);
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(1) FROM {$table} WHERE booking_id = %s",
                        $candidate
                    ));
                    if (!$exists) {
                        if ($wpdb->update($table, array('booking_id' => $candidate), array('id' => (int) $reservation_id), array('%s'), array('%d')) !== false) {
                            $changed++;
                        }
                        break;
                    }
                }
            }
        }

        if ($changed > 0) {
            self::log("Reassigned {$changed} duplicate historical booking IDs.");
        }
    }

    /**
     * Agregar visibilidad pública a profesionales sin alterar los existentes.
     */
    private static function update_professionals_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_professionals';
        if (!self::table_exists($table)) {
            return;
        }

        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'booking_visibility'));
        if (empty($column)) {
            $result = $wpdb->query(
                "ALTER TABLE {$table}
                 ADD COLUMN booking_visibility VARCHAR(20) NOT NULL DEFAULT 'public' AFTER active"
            );
            if (false === $result) {
                self::log("Failed adding booking_visibility to {$table}: {$wpdb->last_error}");
            } else {
                self::log("Added booking_visibility to {$table}; existing professionals remain public.");
            }
        }
    }

    /**
     * Convertir asignaciones antiguas directas a la tabla relacional, sin duplicar.
     */
    private static function normalize_legacy_service_professionals() {
        global $wpdb;
        $services = $wpdb->prefix . 'ov_booking_services';
        $professionals = $wpdb->prefix . 'ov_booking_professionals';
        $relations = $wpdb->prefix . 'ov_service_professionals';

        if (!self::table_exists($services) || !self::table_exists($professionals) || !self::table_exists($relations)) {
            self::log('Skipped legacy service-professional normalization because a required table is missing.');
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT s.id AS service_id, s.professional_id
             FROM {$services} s
             INNER JOIN {$professionals} p ON p.id = s.professional_id
             LEFT JOIN {$relations} sp
                ON sp.service_id = s.id AND sp.professional_id = s.professional_id
             WHERE s.professional_id IS NOT NULL
               AND s.professional_id > 0
               AND sp.id IS NULL"
        );

        $inserted = 0;
        foreach ($rows as $row) {
            $result = $wpdb->insert($relations, array(
                'service_id' => (int) $row->service_id,
                'professional_id' => (int) $row->professional_id,
            ), array('%d', '%d'));
            if (false !== $result) {
                $inserted++;
            }
        }
        self::log("Normalized {$inserted} legacy service-professional assignments.");
    }

    /**
     * Actualizar tabla de formularios con nuevas columnas
     */
    private static function update_forms_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_forms';

        if (!self::table_exists($table)) {
            return;
        }

        // ✅ Verificar si existe allow_any_professional
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'allow_any_professional'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN allow_any_professional TINYINT(1) DEFAULT 1 AFTER enable_professionals");
        }
        
        // Verificar si existe calendar_config
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'calendar_config'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN calendar_config TEXT AFTER allow_any_professional");
        }
        
        // Verificar si existe terms_config
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'terms_config'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN terms_config TEXT AFTER calendar_config");
        }
        
        // Verificar si existe booking_prefix
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'booking_prefix'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN booking_prefix VARCHAR(4) DEFAULT 'OV' AFTER booking_limit_hours");
        }
        
        // ✅ Crear campos por defecto para formularios que no tienen
        self::ensure_forms_have_fields();
    }
    
    /**
     * Asegurar que todos los formularios tengan campos
     */
    private static function ensure_forms_have_fields() {
        global $wpdb;
        
        $forms_table = $wpdb->prefix . 'ov_booking_forms';
        $fields_table = $wpdb->prefix . 'ov_booking_form_fields';
        
        // Obtener todos los formularios
        $forms = $wpdb->get_results("SELECT id FROM {$forms_table}");
        
        foreach ($forms as $form) {
            // Verificar si tiene campos
            $field_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$fields_table} WHERE form_id = %d",
                $form->id
            ));
            
            // Si no tiene campos, crearlos
            if ($field_count == 0) {
                $default_fields = array(
                    array(
                        'form_id' => $form->id,
                        'field_name' => 'name',
                        'field_label' => 'Nombre',
                        'field_type' => 'text',
                        'required' => 1,
                        'placeholder' => 'Tu nombre',
                        'display_order' => 1
                    ),
                    array(
                        'form_id' => $form->id,
                        'field_name' => 'surname',
                        'field_label' => 'Apellidos',
                        'field_type' => 'text',
                        'required' => 1,
                        'placeholder' => 'Tus apellidos',
                        'display_order' => 2
                    ),
                    array(
                        'form_id' => $form->id,
                        'field_name' => 'phone',
                        'field_label' => 'Teléfono',
                        'field_type' => 'tel',
                        'required' => 1,
                        'placeholder' => 'Tu número de teléfono',
                        'display_order' => 3
                    ),
                    array(
                        'form_id' => $form->id,
                        'field_name' => 'email',
                        'field_label' => 'Email',
                        'field_type' => 'email',
                        'required' => 0,
                        'placeholder' => 'Tu correo electrónico (opcional)',
                        'display_order' => 4
                    ),
                    array(
                        'form_id' => $form->id,
                        'field_name' => 'notes',
                        'field_label' => 'Notas adicionales',
                        'field_type' => 'textarea',
                        'required' => 0,
                        'placeholder' => '¿Alguna información adicional que quieras compartir?',
                        'display_order' => 5
                    )
                );
                
                foreach ($default_fields as $field) {
                    $wpdb->insert($fields_table, $field);
                }
            }
        }
    }
    
    /**
     * Migrar datos heredados si existen
     */
    private static function migrate_legacy_data() {
        // Aquí puedes agregar lógica para migrar desde versiones anteriores
        // Por ejemplo, si tenías datos en otro formato
    }

    /**
     * Crear la tabla de rate limiting sin alterar reservas existentes.
     */
    private static function create_rate_limits_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'ov_booking_rate_limits';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            rate_key CHAR(64) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_expires DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rate_key),
            KEY window_expires (window_expires)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Hacer obligatorio el teléfono y corregir el tipo text heredado.
     */
    private static function normalize_phone_fields() {
        global $wpdb;
        $table = $wpdb->prefix . 'ov_booking_form_fields';
        if (!self::table_exists($table)) {
            return;
        }

        $wpdb->query(
            "UPDATE {$table} SET field_type = 'tel', required = 1 WHERE field_name = 'phone'"
        );
    }
    
    /**
     * Verificar integridad de la base de datos
     */
    public static function check_database_integrity() {
        global $wpdb;
        
        $tables_to_check = array(
            'ov_booking_forms',
            'ov_booking_services',
            'ov_booking_schedules',
            'ov_booking_professionals',
            'ov_booking_form_fields',
            'ov_booking_reservations',
            'ov_booking_holidays',
            'ov_booking_breaks',
            'ov_booking_patients',
            'ov_booking_rate_limits',
            'ov_service_professionals',
            'ov_booking_special_schedules',
            'ov_booking_professional_schedules',
            'ov_booking_professional_schedule_exceptions'
        );
        
        $missing_tables = array();
        
        foreach ($tables_to_check as $table) {
            $full_table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'");
            
            if (!$exists) {
                $missing_tables[] = $table;
            }
        }
        
        return $missing_tables;
    }
    
    /**
     * Limpiar campos duplicados (mantener solo el más reciente)
     */
    private static function cleanup_duplicate_fields() {
        global $wpdb;
        $fields_table = $wpdb->prefix . 'ov_booking_form_fields';
        
        // Obtener duplicados (mismo form_id + field_name)
        $sql = "
            SELECT form_id, field_name, COUNT(*) as count, GROUP_CONCAT(id ORDER BY id DESC) as ids
            FROM {$fields_table}
            GROUP BY form_id, field_name
            HAVING count > 1
        ";
        
        $duplicates = $wpdb->get_results($sql);
        
        if ($duplicates) {
            foreach ($duplicates as $dup) {
                // Mantener solo el primero (más reciente), borrar el resto
                $ids = explode(',', $dup->ids);
                $keep_id = array_shift($ids); // Mantener el más reciente
                
                if (!empty($ids)) {
                    $delete_ids = implode(',', array_map('intval', $ids));
                    $wpdb->query("DELETE FROM {$fields_table} WHERE id IN ({$delete_ids})");
                    error_log("OV Booking: Cleaned " . count($ids) . " duplicate fields for form {$dup->form_id}, field {$dup->field_name}");
                }
            }
        }
    }

    /**
     * Crear tabla de relación servicio-profesional (v2.3.0)
     */
    private static function create_service_professionals_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'ov_service_professionals';
        
        // Verificar si la tabla ya existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        if ($table_exists) {
            return; // Ya existe, no hacer nada
        }
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY service_professional (service_id, professional_id),
            KEY service_id (service_id),
            KEY professional_id (professional_id)
        ) $charset_collate;";
        
        dbDelta($sql);
        
        error_log("OV Booking: Created table {$table_name} (v2.3.0)");
    }

    /**
     * Agregar campo max_bookings_per_slot_type (v2.4.0)
     */
    private static function add_max_bookings_per_slot_type_field() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ov_booking_forms';
        
        // Verificar si la columna ya existe
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$table_name} LIKE %s",
                'max_bookings_per_slot_type'
            )
        );
        
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$table_name} 
                 ADD COLUMN max_bookings_per_slot_type VARCHAR(20) DEFAULT 'per_professional' 
                 AFTER max_bookings_per_slot"
            );
            
            error_log("OV Booking: Added column max_bookings_per_slot_type (v2.4.0)");
        }
    }
}
