<?php
/**
 * Funcionalidad del área de administración
 * 
 * @package OV_Booking_System
 */

class OV_Admin {

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // El administrador conserva el acceso completo; OV Manager solo ve OV Booking.
        add_action('admin_menu', array($this, 'restrict_ov_manager_menu'), 9999);
        add_action('admin_init', array($this, 'redirect_ov_manager_dashboard'));
        add_filter('login_redirect', array($this, 'login_redirect_ov_manager'), 10, 3);
        add_filter('show_admin_bar', array($this, 'hide_admin_bar_for_ov_manager'));
    }

    /**
     * Registrar estilos del admin
     */
    public function enqueue_styles($hook) {
        // Cargar solo en páginas de tu plugin
        if (strpos($hook, 'ov-booking') === false && strpos($hook, 'ov-booking-system') === false) {
            return;
        }

        // Admin i18n compatibility layer for older hardcoded templates/data.
        $this->maybe_start_admin_i18n_buffer();

        wp_enqueue_style(
            $this->plugin_name,
            OV_BOOKING_PLUGIN_URL . 'admin/assets/css/ov-admin.css',
            array(),
            $this->version,
            'all'
        );

        wp_enqueue_style('wp-color-picker');

        wp_enqueue_style(
            'fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
            array(),
            '6.4.0'
        );
    }

    /**
     * Registrar scripts del admin
     */
    public function enqueue_scripts($hook) {
        // Cargar solo en páginas de tu plugin
        if (strpos($hook, 'ov-booking') === false && strpos($hook, 'ov-booking-system') === false) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_script(
            $this->plugin_name,
            OV_BOOKING_PLUGIN_URL . 'admin/assets/js/ov-admin.js',
            array('jquery', 'wp-color-picker', 'jquery-ui-sortable'),
            $this->version,
            false
        );

        wp_enqueue_script(
            'ov-booking-public',
            OV_BOOKING_PLUGIN_URL . 'public/assets/js/ov-booking.js',
            array('jquery'),
            $this->version,
            true
        );

        wp_enqueue_script('ov-admin-booking', OV_BOOKING_PLUGIN_URL . 'admin/assets/js/ov-admin-booking.js', array($this->plugin_name), $this->version, true);

        wp_localize_script(
            $this->plugin_name,
            'ovBookingAdmin',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ov_admin_nonce'),
                'patient_search_nonce' => wp_create_nonce('ov_admin_nonce'),
                'bookingStrings' => array(
                    'select' => __('Seleccione...', 'ov-booking-system'),
                    'chooseForm' => __('Seleccione un formulario.', 'ov-booking-system'),
                    'chooseService' => __('Seleccione un servicio.', 'ov-booking-system'),
                    'chooseProfessional' => __('Seleccione un profesional para este servicio.', 'ov-booking-system'),
                    'chooseContext' => __('Seleccione servicio, profesional y fecha para ver las horas disponibles.', 'ov-booking-system'),
                    'chooseTime' => __('Selecciona una hora disponible.', 'ov-booking-system'),
                    'loadingServices' => __('Cargando servicios...', 'ov-booking-system'),
                    'loadingProfessionals' => __('Cargando profesionales...', 'ov-booking-system'),
                    'loadingSlots' => __('Consultando disponibilidad...', 'ov-booking-system'),
                    'noServices' => __('No hay servicios activos en este formulario.', 'ov-booking-system'),
                    'noProfessionals' => __('No hay profesionales activos asignados a este servicio.', 'ov-booking-system'),
                    'noSlots' => __('No hay horas disponibles. Prueba otro día o profesional.', 'ov-booking-system'),
                    'loadError' => __('No se pudieron cargar los datos.', 'ov-booking-system'),
                    'connectionError' => __('Error de conexión. Puedes reintentar.', 'ov-booking-system'),
                    'searchingPatient' => __('Buscando paciente...', 'ov-booking-system'),
                    'patientError' => __('No se pudo consultar el paciente. Puedes completar sus datos manualmente.', 'ov-booking-system'),
                    'newPatient' => __('Paciente nuevo', 'ov-booking-system'),
                    'patientFound' => __('Paciente encontrado', 'ov-booking-system'),
                    'saving' => __('Creando reserva...', 'ov-booking-system'),
                    'create' => __('Crear Reserva', 'ov-booking-system'),
                    'created' => __('Reserva creada exitosamente.', 'ov-booking-system'),
                    'saveError' => __('No se pudo crear la reserva.', 'ov-booking-system'),
                    'uncertainSave' => __('No se pudo confirmar el resultado. Revisa el calendario antes de volver a crear la reserva.', 'ov-booking-system'),
                ),
                'strings' => array(
                    'confirm_delete' => __('¿Estás seguro de eliminar este elemento?', 'ov-booking-system'),
                    'error' => __('Ha ocurrido un error', 'ov-booking-system'),
                    'confirm_status_change' => __('¿Estás seguro de cambiar el estado de esta reserva?', 'ov-booking-system'),
                    'confirm_export_csv' => __('¿Deseas exportar las reservas a CSV? Al aceptar comenzará la descarga del archivo.', 'ov-booking-system'),
                    'confirm_delete_reservation' => __('¿Seguro que quieres borrar esta reserva?', 'ov-booking-system'),
                    'error_save' => __('Error al guardar', 'ov-booking-system'),
                    'error_delete' => __('Error al borrar', 'ov-booking-system'),
                    'error_clear' => __('Error al limpiar', 'ov-booking-system'),
                    'save_changes' => __('Guardar cambios', 'ov-booking-system')
                )
            )
        );
    }

    /**
     * Start an output buffer to translate legacy hardcoded admin strings.
     * This keeps existing user data safe while the admin templates are fully internationalized.
     */
    private function maybe_start_admin_i18n_buffer() {
        if (is_admin() && 0 === strpos(determine_locale(), 'en') && !defined('OV_BOOKING_ADMIN_I18N_BUFFER')) {
            define('OV_BOOKING_ADMIN_I18N_BUFFER', true);
            ob_start(array($this, 'translate_admin_output_en'));
        }
    }

    /**
     * Translate legacy Spanish admin text to English on plugin admin screens.
     */
    public function translate_admin_output_en($html) {
        $map = array(
            'Formularios de Reserva' => 'Booking Forms',
            'Crear Formulario' => 'Create Form',
            'Editar Formulario' => 'Edit Form',
            'Crear Nuevo' => 'Create New',
            'Información General' => 'General Information',
            'Nombre del Formulario' => 'Form Name',
            'Identificador único (solo letras minúsculas y guiones)' => 'Unique identifier (lowercase letters and hyphens only)',
            'Descripción' => 'Description',
            'Número de WhatsApp' => 'WhatsApp Number',
            'Zona Horaria' => 'Time Zone',
            'Anticipación Mínima (horas)' => 'Minimum Advance Notice (hours)',
            'Horas de anticipación requeridas para reservar. 0 = sin límite (permite reservas el mismo día)' => 'Required hours of advance notice for bookings. 0 = no limit (allows same-day bookings)',
            'Prefijo de Reserva' => 'Booking Prefix',
            'Reservas por Horario' => 'Bookings per Slot',
            'Número máximo de reservas permitidas en el mismo horario' => 'Maximum number of bookings allowed in the same time slot',
            'La semana comienza en' => 'Week starts on',
            'Campos Formulario' => 'Form Fields',
            'Campos del Formulario de Información' => 'Information Form Fields',
            'Define qué información solicitar a tus clientes en el paso "Completa tu información". Arrastra para reordenar.' => 'Define what information to request from your customers in the "Complete your information" step. Drag to reorder.',
            'Orden' => 'Order',
            'Nombre' => 'Name',
            'Etiqueta' => 'Label',
            'Tipo' => 'Type',
            'Requerido' => 'Required',
            'Área de texto' => 'Text Area',
            'Texto' => 'Text',
            'Teléfono' => 'Phone',
            'Número' => 'Number',
            'Tu nombre' => 'Your name',
            'Tus apellidos' => 'Your surname',
            'Tu número de teléfono' => 'Your phone number',
            'Tu correo electrónico (opcional)' => 'Your email address (optional)',
            '¿Alguna información adicional que quieras compartir?' => 'Any additional information you would like to share?',
            'Notas adicionales' => 'Additional notes',
            'Agregar Campo' => 'Add Field',
            'Diseño' => 'Design',
            'Personalización de Colores' => 'Color Customization',
            'Color Primario' => 'Primary Color',
            'Color Secundario' => 'Secondary Color',
            'Color de Texto' => 'Text Color',
            'Color de Advertencia' => 'Warning Color',
            'Color de Error' => 'Error Color',
            'Seleccionar Color' => 'Select Color',
            'Guardar Cambios' => 'Save Changes',
            'Guardar cambios' => 'Save Changes',
            'Cancelar' => 'Cancel',
            'Integraciones' => 'Integrations',
            'Estado:' => 'Status:',
            'Configurado' => 'Configured',
            'Servicios que Ofrece' => 'Services Offered',
            'Manage Services' => 'Manage Services',
            'Agregar, editar y ordenar servicios' => 'Add, edit and reorder services',
            'Manage Professionals' => 'Manage Professionals',
            'Agregar y asignar profesionales' => 'Add and assign professionals',
            'Configure Schedules' => 'Configure Schedules',
            'Shortcode para este formulario' => 'Shortcode for this form',
            'Profesionales' => 'Professionals',
            'Agregar Profesional' => 'Add Professional',
            'Editar Profesional' => 'Edit Professional',
            'No hay profesionales configurados' => 'No professionals configured',
            'Agrega profesionales para que los usuarios puedan seleccionar con quién desean su cita' => 'Add professionals so users can choose who they want to book with',
            'Crear Primer Profesional' => 'Create First Professional',
            'Nombre Completo' => 'Full Name',
            'Biografía / Especialidad' => 'Bio / Specialty',
            'Ej: Especialista en cortes modernos con 5 años de experiencia' => 'Example: Specialist in modern cuts with 5 years of experience',
            'URL de Foto (opcional)' => 'Photo URL (optional)',
            'Subir' => 'Upload',
            'Orden de Visualización' => 'Display Order',
            'Menor número aparece primero' => 'Lower number appears first',
            'Activo (visible en el formulario)' => 'Active (visible in the form)',
            'Guardar Profesional' => 'Save Professional',
            'Servicio activo' => 'Active service',
            'Actualizar Servicio' => 'Update Service',
            'Editar Servicio' => 'Edit Service',
            'Nuevo Servicio' => 'New Service',
            'Nombre del Servicio' => 'Service Name',
            'Duración' => 'Duration',
            'Unidad' => 'Unit',
            'Minutos' => 'Minutes',
            'Horas' => 'Hours',
            'Días' => 'Days',
            'Intervalo de Slots' => 'Slot Interval',
            'Tiempo de Buffer' => 'Buffer Time',
            'Mismo que duración' => 'Same as duration',
            'Minutos entre cada slot disponible. Dejar vacío usa la duración del servicio.' => 'Minutes between each available slot. Leave empty to use the service duration.',
            'Minutos extra de descanso después de cada cita.' => 'Extra rest minutes after each booking.',
            'Precio (texto)' => 'Price (text)',
            'URL de Imagen (opcional)' => 'Image URL (optional)',
            'Días Festivos' => 'Holidays',
            'Editar Festivo' => 'Edit Holiday',
            'Descanso' => 'Break',
            'Actualizar' => 'Update',
            'Consejos Rápidos' => 'Quick Tips',
            'Festivos Comunes' => 'Common Holidays',
            'Click para agregar rápidamente:' => 'Click to add quickly:',
            'Agrega los días festivos específicos de tu país o región manualmente.' => 'Add the specific holidays for your country or region manually.',
            'Este día no estará disponible para reservas.' => 'This day will not be available for bookings.',
            'Todas las Reservas' => 'All Bookings',
            'Ajustes' => 'Settings',
            'Reservas' => 'Bookings',
            'Borrar reservas' => 'Delete bookings',
            'Exportar CSV' => 'Export CSV',
            'Buscar por nombre, email o teléfono...' => 'Search by name, email or phone...',
            'Todos los formularios' => 'All forms',
            'Todos los estados' => 'All statuses',
            'Todos los profesionales' => 'All professionals',
            'Fecha creación (reciente)' => 'Creation date (newest)',
            'Fecha creación (antigua)' => 'Creation date (oldest)',
            'Fecha reserva (reciente)' => 'Booking date (newest)',
            'Fecha reserva (antigua)' => 'Booking date (oldest)',
            'Filtrar' => 'Filter',
            'Limpiar' => 'Clear',
            'ID Reserva' => 'Booking ID',
            'Formulario' => 'Form',
            'Cliente' => 'Customer',
            'Servicio' => 'Service',
            'Precio' => 'Price',
            'Profesional' => 'Professional',
            'Fecha' => 'Date',
            'Hora' => 'Time',
            'Notas' => 'Notes',
            'Datos Adicionales' => 'Additional Data',
            'Estado' => 'Status',
            'Creada' => 'Created',
            'Acciones' => 'Actions',
            'Pendiente' => 'Pending',
            'Confirmada' => 'Confirmed',
            'Completada' => 'Completed',
            'Cancelada' => 'Cancelled',
            'Cualquiera' => 'Any',
            'Editar' => 'Edit',
            'Borrar' => 'Delete',
            'Eliminar' => 'Delete',
            'Editar reserva' => 'Edit booking',
            'Formularios' => 'Forms',
            'Apellidos' => 'Surname'
        );

        return strtr($html, $map);
    }

    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        $is_manager = $this->is_ov_manager();

        // Menú principal. Para OV Manager, la puerta de entrada es el Dashboard.
        // El administrador conserva Formularios como página principal del plugin.
        add_menu_page(
            __('OV Booking System', 'ov-booking-system'),
            __('Reservas', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-system',
            $is_manager ? array($this, 'display_dashboard_page') : array($this, 'display_forms_page'),
            'dashicons-calendar-alt',
            30
        );

        if ($is_manager) {
            // Submenú: Dashboard
            add_submenu_page(
                'ov-booking-system',
                __('Dashboard', 'ov-booking-system'),
                __('Dashboard', 'ov-booking-system'),
                OV_Roles::CAPABILITY,
                'ov-booking-system',
                array($this, 'display_dashboard_page')
            );
        }

        // Submenú: Formularios
        add_submenu_page(
            'ov-booking-system',
            __('Formularios de Reserva', 'ov-booking-system'),
            __('Formularios', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-forms',
            array($this, 'display_forms_page')
        );

        // Submenú: Servicios (oculto, acceso desde formularios)
        add_submenu_page(
            null, // Oculto del menú
            __('Gestión de Servicios', 'ov-booking-system'),
            __('Servicios', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-services',
            array($this, 'display_services_page')
        );

        // Submenú: Horarios (oculto, acceso desde formularios)
        add_submenu_page(
            null, // Oculto del menú
            __('Gestión de Horarios', 'ov-booking-system'),
            __('Horarios', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-schedules',
            array($this, 'display_schedules_page')
        );

        // Submenú: Horarios especiales (oculto, acceso desde formularios)
        add_submenu_page(
            null,
            __('Horarios especiales', 'ov-booking-system'),
            __('Horarios especiales', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-special-schedules',
            array($this, 'display_special_schedules_page')
        );

        // Submenú: Días Festivos (oculto, acceso desde formularios)
        add_submenu_page(
            null, // Oculto del menú
            __('Gestión de Días Festivos', 'ov-booking-system'),
            __('Días Festivos', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-holidays',
            array($this, 'display_holidays_page')
        );

        // Submenú: Gestor de Campos (oculto, acceso desde formularios)
        add_submenu_page(
            null, // Oculto del menú
            __('Gestor de Campos', 'ov-booking-system'),
            __('Campos', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-fields',
            array($this, 'display_fields_page')
        );

        // Submenú: Profesionales (oculto, acceso desde formularios)
        add_submenu_page(
            null, // Oculto del menú
            __('Gestión de Profesionales', 'ov-booking-system'),
            __('Profesionales', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-professionals',
            array($this, 'display_professionals_page')
        );

        // Submenú: Reservas
        add_submenu_page(
            'ov-booking-system',
            __('Reservas', 'ov-booking-system'),
            __('Todas las Reservas', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-reservations',
            array($this, 'display_reservations_page')
        );

        // Submenú oculto: Nueva Reserva
        add_submenu_page(
            null,
            __('Nueva Reserva', 'ov-booking-system'),
            __('Nueva Reserva', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-new-reservation',
            array($this, 'display_new_reservation_page')
        );

        // Submenú: Ajustes
        add_submenu_page(
            'ov-booking-system',
            __('Ajustes', 'ov-booking-system'),
            __('Ajustes', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-settings',
            array($this, 'display_settings_page')
        );

        // Módulos y licencias. La pantalla siempre pertenece al núcleo;
        // cada add-on registra su propia funcionalidad y sus propias tablas.
        add_submenu_page(
            'ov-booking-system',
            __('Módulos y licencias', 'ov-booking-system'),
            __('Módulos', 'ov-booking-system'),
            OV_Roles::CAPABILITY,
            'ov-booking-modules',
            array($this, 'display_modules_page')
        );
    }

    /**
     * Ocultar la administración nativa de WordPress para OV Manager.
     *
     * Esto es una capa de interfaz. La seguridad real la proporciona el hecho
     * de que el rol no posee las capacidades de WordPress para administrar esas
     * áreas.
     */
    /**
     * Hide the native WordPress admin bar for OV Manager.
     */
    public function hide_admin_bar_for_ov_manager($show) {
        return is_admin() && $this->is_ov_manager() ? false : $show;
    }

    /**
     * Add account actions outside the Reservas menu for OV Manager.
     */
    private function add_ov_manager_account_menu() {
        add_menu_page(
            __('Mi cuenta', 'ov-booking-system'),
            __('Mi cuenta', 'ov-booking-system'),
            'read',
            'ov-booking-account',
            array($this, 'display_account_page'),
            'dashicons-admin-users',
            80
        );
    }

    /**
     * Render the account actions previously available from the admin bar.
     */
    public function display_account_page() {
        if (!$this->is_ov_manager()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ov-booking-system'));
        }

        $current_user = wp_get_current_user();
        $logout_url = wp_logout_url(wp_login_url());
        ?>
        <div class="wrap ov-booking-admin">
            <h1><?php esc_html_e('Mi cuenta', 'ov-booking-system'); ?></h1>
            <p><?php printf(esc_html__('Hola, %s.', 'ov-booking-system'), esc_html($current_user->display_name ?: $current_user->user_login)); ?></p>
            <p><a href="<?php echo esc_url($logout_url); ?>" class="button button-primary"><?php esc_html_e('Cerrar sesión', 'ov-booking-system'); ?></a></p>
        </div>
        <?php
    }

    /**
     * Ocultar la administraciÃ³n nativa de WordPress para OV Manager.
     *
     * Esto es una capa de interfaz. La seguridad real la proporciona el hecho
     * de que el rol no posee las capacidades de WordPress para administrar esas
     * Ã¡reas.
     */
    public function restrict_ov_manager_menu() {
        if (!$this->is_ov_manager()) {
            return;
        }

        // This is intentionally a top-level menu, independent from Reservas.
        $this->add_ov_manager_account_menu();

        $default_menu_pages = array(
            'index.php',
            'edit.php',
            'upload.php',
            'edit-comments.php',
            'edit.php?post_type=page',
            'themes.php',
            'plugins.php',
            'users.php',
            'tools.php',
            'options-general.php',
            'profile.php',
            'site-editor.php',
        );

        foreach ($default_menu_pages as $menu_page) {
            remove_menu_page($menu_page);
        }

        // OV Manager trabaja las reservas desde el Dashboard.
        // La pantalla antigua se conserva para administración directa, pero no se muestra en el menú.
        remove_submenu_page('ov-booking-system', 'ov-booking-reservations');
    }

    /**
     * Evitar que OV Manager termine en el escritorio de WordPress.
     */
    public function redirect_ov_manager_dashboard() {
        if (!$this->is_ov_manager()) {
            return;
        }

        global $pagenow;

        if ('index.php' !== $pagenow) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=ov-booking-system'));
        exit;
    }

    /**
     * Redirigir al OV Manager al Dashboard después del inicio de sesión.
     */
    public function login_redirect_ov_manager($redirect_to, $requested_redirect_to, $user) {
        if ($user instanceof WP_User && in_array(OV_Roles::ROLE, (array) $user->roles, true)) {
            return admin_url('admin.php?page=ov-booking-system');
        }

        return $redirect_to;
    }

    /**
     * Determinar si el usuario actual utiliza el rol OV Manager.
     */
    private function is_ov_manager() {
        $user = wp_get_current_user();

        return $user instanceof WP_User && in_array(OV_Roles::ROLE, (array) $user->roles, true);
    }

    /**
     * Mostrar Dashboard para OV Manager.
     */
    public function display_dashboard_page() {
        if (!$this->is_ov_manager()) {
            $this->display_forms_page();
            return;
        }

        include OV_BOOKING_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Mostrar página de formularios
     */
    public function display_forms_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

        switch ($action) {
            case 'edit':
                if ($form_id) {
                    include OV_BOOKING_PLUGIN_DIR . 'admin/views/form-editor.php';
                } else {
                    include OV_BOOKING_PLUGIN_DIR . 'admin/views/forms-list.php';
                }
                break;
            case 'new':
                include OV_BOOKING_PLUGIN_DIR . 'admin/views/form-editor.php';
                break;
            default:
                include OV_BOOKING_PLUGIN_DIR . 'admin/views/forms-list.php';
                break;
        }
    }

    /**
     * Mostrar página de servicios
     */
    public function display_services_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/services-manager.php';
    }

    /**
     * Mostrar página de horarios
     */
    public function display_schedules_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/schedules-manager.php';
    }

    /**
     * Mostrar página de horarios especiales
     */
    public function display_special_schedules_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/special-schedules-manager.php';
    }

    /**
     * Mostrar página de días festivos
     */
    public function display_holidays_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/holidays-manager.php';
    }

    /**
     * Mostrar página de gestor de campos
     */
    public function display_fields_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/fields-manager.php';
    }

    /**
     * Mostrar página de gestión de profesionales
     */
    public function display_professionals_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/professionals-manager.php';
    }

    /**
     * Mostrar página de reservas
     */
    public function display_reservations_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/reservations-list.php';
    }

    /**
     * Mostrar página Nueva Reserva.
     */
    public function display_new_reservation_page() {

        include OV_BOOKING_PLUGIN_DIR .
            'admin/views/new-reservation.php';

    }

    /**
     * Mostrar página de ajustes
     */
    public function display_settings_page() {
        include OV_BOOKING_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Mostrar el registro de módulos y la activación de licencias.
     */
    public function display_modules_page() {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'ov-booking-system'), '', array('response' => 403));
        }

        include OV_BOOKING_PLUGIN_DIR . 'admin/views/modules.php';
    }

    /**
     * Mostrar página de diagnóstico
     */
}
