<?php
/**
 * Activación del plugin
 * 
 * @package OV_Booking_System
 */

class OV_Activator {

    /**
     * Acciones durante la activación del plugin
     */
    public static function activate() {
        self::create_database_tables();
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-updater.php';
        OV_Updater::maybe_update();
        self::ensure_roles();
        self::set_default_options();
        self::create_default_form();
        
        // Limpiar rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Crear/actualizar el rol de gestión de OV Booking.
     */
    private static function ensure_roles() {
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-roles.php';
        OV_Roles::ensure_roles();
    }

    /**
     * Crear todas las tablas de la base de datos
     */
    private static function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Tabla de formularios
        $table_forms = $wpdb->prefix . 'ov_booking_forms';
        $sql_forms = "CREATE TABLE $table_forms (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description TEXT,
            enable_professionals TINYINT(1) DEFAULT 0,
            allow_any_professional TINYINT(1) DEFAULT 1,
            calendar_config TEXT,
            terms_config TEXT,
            colors_config TEXT,
            timezone VARCHAR(100) DEFAULT 'UTC',
            booking_limit_hours INT DEFAULT 0,
            booking_prefix VARCHAR(4) DEFAULT 'OV',
            max_bookings_per_slot INT DEFAULT 1,
            max_bookings_per_slot_type VARCHAR(20) DEFAULT 'per_professional',
            week_starts_on TINYINT(1) DEFAULT 1,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";
        dbDelta($sql_forms);

        // Tabla de profesionales
        $table_professionals = $wpdb->prefix . 'ov_booking_professionals';
        $sql_professionals = "CREATE TABLE $table_professionals (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            phone VARCHAR(20),
            bio TEXT,
            avatar_url VARCHAR(500),
            calendar_id VARCHAR(255),
            schedule_config TEXT,
            working_days TEXT,
            breaks_config TEXT,
            active TINYINT(1) DEFAULT 1,
            booking_visibility VARCHAR(20) NOT NULL DEFAULT 'public',
            display_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY active (active)
        ) $charset_collate;";
        dbDelta($sql_professionals);

        // Tabla de servicios
        $table_services = $wpdb->prefix . 'ov_booking_services';
        $sql_services = "CREATE TABLE $table_services (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            duration INT NOT NULL,
            duration_unit VARCHAR(10) DEFAULT 'minutes',
            slot_interval INT NULL,
            buffer_time INT DEFAULT 0,
            price DECIMAL(10,2),
            price_text VARCHAR(255),
            image_url VARCHAR(500),
            active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY professional_id (professional_id),
            KEY active (active)
        ) $charset_collate;";
        dbDelta($sql_services);

        // Tabla de relación servicio-profesional (muchos a muchos)
        $table_service_professionals = $wpdb->prefix . 'ov_service_professionals';
        $sql_service_professionals = "CREATE TABLE $table_service_professionals (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY service_professional (service_id, professional_id),
            KEY service_id (service_id),
            KEY professional_id (professional_id)
        ) $charset_collate;";
        dbDelta($sql_service_professionals);

        // Tabla de campos del formulario
        $table_fields = $wpdb->prefix . 'ov_booking_form_fields';
        $sql_fields = "CREATE TABLE $table_fields (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            field_name VARCHAR(100) NOT NULL,
            field_label VARCHAR(255) NOT NULL,
            field_type VARCHAR(50) NOT NULL,
            field_options TEXT,
            required TINYINT(1) DEFAULT 0,
            placeholder VARCHAR(255),
            validation_rules TEXT,
            display_order INT DEFAULT 0,
            active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) $charset_collate;";
        dbDelta($sql_fields);

        // Tabla de reservas
        $table_reservations = $wpdb->prefix . 'ov_booking_reservations';
        $sql_reservations = "CREATE TABLE $table_reservations (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id VARCHAR(50) NULL,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            service_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NULL,
            patient_id BIGINT(20) UNSIGNED NULL,
            booking_date DATE NOT NULL,
            booking_time TIME NOT NULL,
            end_time TIME NOT NULL,
            duration INT NOT NULL,
            duration_unit VARCHAR(10) NOT NULL,
            price VARCHAR(255) NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255),
            customer_phone VARCHAR(50),
            customer_data TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            source VARCHAR(20) NOT NULL DEFAULT 'public',
            calendar_event_id VARCHAR(255),
            whatsapp_sent TINYINT(1) DEFAULT 0,
            request_key CHAR(64) NULL,
            created_ip_hash CHAR(64) NULL,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_id (booking_id),
            UNIQUE KEY request_key (request_key),
            KEY form_id (form_id),
            KEY service_id (service_id),
            KEY professional_id (professional_id),
            KEY patient_id (patient_id),
            KEY booking_date (booking_date),
            KEY status (status),
            KEY availability_lookup (form_id, professional_id, booking_date, status)
        ) $charset_collate;";
        dbDelta($sql_reservations);
        
        // ✅ CRÍTICO: Verificar manualmente que existan columnas esenciales
        // dbDelta() a veces no agrega columnas a tablas existentes
        self::ensure_critical_columns($table_reservations);

        // Tabla de horarios
        $table_schedules = $wpdb->prefix . 'ov_booking_schedules';
        $sql_schedules = "CREATE TABLE $table_schedules (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NULL,
            day_of_week TINYINT(1) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY professional_id (professional_id)
        ) $charset_collate;";
        dbDelta($sql_schedules);

        // Tabla de días festivos
        $table_holidays = $wpdb->prefix . 'ov_booking_holidays';
        $sql_holidays = "CREATE TABLE $table_holidays (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NULL,
            holiday_date DATE NOT NULL,
            description VARCHAR(255),
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY professional_id (professional_id),
            KEY holiday_date (holiday_date)
        ) $charset_collate;";
        dbDelta($sql_holidays);

        // Tabla de horarios especiales por rango de fechas
        $table_special_schedules = $wpdb->prefix . 'ov_booking_special_schedules';
        $sql_special_schedules = "CREATE TABLE $table_special_schedules (
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
        dbDelta($sql_special_schedules);

        // Tabla de descansos
        $table_breaks = $wpdb->prefix . 'ov_booking_breaks';
        $sql_breaks = "CREATE TABLE $table_breaks (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL,
            professional_id BIGINT(20) UNSIGNED NULL,
            break_date DATE NULL,
            day_of_week TINYINT(1) NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            description VARCHAR(255),
            active TINYINT(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY professional_id (professional_id)
        ) $charset_collate;";
        dbDelta($sql_breaks);

        // Tabla normalizada de pacientes. Debe existir también en instalaciones nuevas.
        $table_patients = $wpdb->prefix . 'ov_booking_patients';
        $sql_patients = "CREATE TABLE $table_patients (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(190) NULL,
            rut VARCHAR(20) NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone (phone),
            KEY email (email),
            UNIQUE KEY rut (rut)
        ) $charset_collate;";
        dbDelta($sql_patients);

        // Contadores atómicos para limitar intentos públicos por ventanas de tiempo.
        $table_rate_limits = $wpdb->prefix . 'ov_booking_rate_limits';
        $sql_rate_limits = "CREATE TABLE $table_rate_limits (
            rate_key CHAR(64) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_expires DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rate_key),
            KEY window_expires (window_expires)
        ) $charset_collate;";
        dbDelta($sql_rate_limits);

    }

    /**
     * Establecer opciones por defecto
     */
    private static function set_default_options() {
        add_option('ov_booking_version', OV_BOOKING_VERSION);
        add_option('ov_booking_db_version', OV_Updater::CURRENT_VERSION);
        add_option('ov_booking_activation_date', current_time('mysql'));
    }

    /**
     * Crear formulario de demostración
     */
    private static function create_default_form() {
        global $wpdb;
        
        $table_forms = $wpdb->prefix . 'ov_booking_forms';
        
        // Verificar si ya existe un formulario
        $exists = $wpdb->get_var("SELECT COUNT(*) FROM $table_forms");
        
        if ($exists == 0) {
            // Crear formulario de ejemplo
            $wpdb->insert(
                $table_forms,
                array(
                    'name' => __('Sistema de Reservas Principal', 'ov-booking-system'),
                    'slug' => 'principal',
                    'description' => __('Formulario de reservas predeterminado', 'ov-booking-system'),
                    'enable_professionals' => 0,
                    'colors_config' => json_encode(array(
                        'primary' => '#6c5ce7',
                        'secondary' => '#90A1B9',
                        'success' => '#00b894',
                        'text' => '#2d3436'
                    )),
                    'timezone' => function_exists('wp_timezone_string') && wp_timezone_string() ? wp_timezone_string() : 'UTC',
                    'booking_limit_hours' => 24,
                    'max_bookings_per_slot' => 1,
                    'week_starts_on' => 1,
                    'status' => 'active'
                )
            );
            
            $form_id = $wpdb->insert_id;
            
            // Crear horarios de ejemplo (Lunes a Viernes 9:00 - 18:00)
            $table_schedules = $wpdb->prefix . 'ov_booking_schedules';
            for ($day = 1; $day <= 5; $day++) {
                $wpdb->insert(
                    $table_schedules,
                    array(
                        'form_id' => $form_id,
                        'day_of_week' => $day,
                        'start_time' => '09:00:00',
                        'end_time' => '18:00:00',
                        'active' => 1
                    )
                );
            }
            
            // Crear servicio de ejemplo
            $table_services = $wpdb->prefix . 'ov_booking_services';
            $wpdb->insert(
                $table_services,
                array(
                    'form_id' => $form_id,
                    'name' => __('Consulta General', 'ov-booking-system'),
                    'description' => __('Servicio de consulta general de 60 minutos', 'ov-booking-system'),
                    'duration' => 60,
                    'duration_unit' => 'minutes',
                    'price_text' => '$50',
                    'active' => 1,
                    'display_order' => 1
                )
            );
            
            // Crear campos del formulario por defecto
            $table_fields = $wpdb->prefix . 'ov_booking_form_fields';
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
            
            foreach ($default_fields as $field) {
                $field['form_id'] = $form_id;
                $field['active'] = 1;
                $wpdb->insert($table_fields, $field);
            }
        }
    }
    
    /**
     * Actualizar estructura de base de datos
     * Se ejecuta en cada carga del plugin para agregar columnas faltantes
     */
    public static function upgrade_database() {
        global $wpdb;
        
        $table_reservations = $wpdb->prefix . 'ov_booking_reservations';
        
        // Verificar si existe la columna booking_id
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'booking_id'",
                DB_NAME,
                $table_reservations
            )
        );
        
        // Si no existe, agregarla
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE $table_reservations 
                ADD COLUMN booking_id VARCHAR(50) NULL AFTER id,
                ADD UNIQUE KEY booking_id (booking_id)"
            );
        }
    }
    
    /**
     * Asegurar que existan columnas críticas en la tabla de reservaciones
     * Se ejecuta en cada activación para garantizar compatibilidad
     */
    private static function ensure_critical_columns($table_reservations) {
        global $wpdb;
        
        // Verificar columna booking_id
        $has_booking_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'booking_id'",
                DB_NAME,
                $table_reservations
            )
        );
        
        if (!$has_booking_id) {
            $wpdb->query(
                "ALTER TABLE $table_reservations 
                ADD COLUMN booking_id VARCHAR(50) NULL AFTER id,
                ADD UNIQUE KEY booking_id (booking_id)"
            );
        }
        
        // Verificar columna price
        $has_price = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'price'",
                DB_NAME,
                $table_reservations
            )
        );
        
        if (!$has_price) {
            $wpdb->query(
                "ALTER TABLE $table_reservations 
                ADD COLUMN price VARCHAR(100) NULL AFTER duration_unit"
            );
        } else {
            // Reparar instalaciones donde price quedó como DECIMAL o VARCHAR muy corto.
            $price_column = $wpdb->get_row("SHOW COLUMNS FROM $table_reservations LIKE 'price'");
            if (!empty($price_column) && isset($price_column->Type)) {
                $price_type = strtolower($price_column->Type);
                if (strpos($price_type, 'varchar') === false || preg_match('/varchar\((\d+)\)/', $price_type, $m) && intval($m[1]) < 100) {
                    $wpdb->query("ALTER TABLE $table_reservations MODIFY COLUMN price VARCHAR(100) NULL");
                }
            }
        }
    }
}
