<?php
/**
 * Clase principal del plugin
 * 
 * @package OV_Booking_System
 */

class OV_Booking_System {

    /**
     * El loader que mantiene todos los hooks del plugin
     */
    protected $loader;

    /**
     * Identificador único del plugin
     */
    protected $plugin_name;

    /**
     * Versión actual del plugin
     */
    protected $version;

    /**
     * Constructor de la clase
     */
    public function __construct() {
        $this->version = OV_BOOKING_VERSION;
        $this->plugin_name = 'ov-booking-system';

        $this->load_dependencies();
        $this->check_and_update(); // EJECUTAR UPDATER AUTOMÁTICAMENTE
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Cargar las dependencias necesarias
     */
    private function load_dependencies() {
        
        // Loader para registrar todos los hooks
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-loader.php';
        
        // Internacionalización
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-i18n.php';
        
        // Base de datos
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-database.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-module-manager.php';
        
        // Modelos
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-form.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-service.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-professional.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-reservation.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-schedule.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-field.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/models/class-ov-patient.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/services/class-ov-message-service.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/services/class-ov-csv-export.php';
        
        // Helpers
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/helpers/class-ov-availability.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/helpers/class-ov-datetime.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/helpers/class-ov-validation.php';
        
        // API
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/api/class-ov-ajax-handler.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/api/class-ov-google-calendar.php';
        
        // Admin
        require_once OV_BOOKING_PLUGIN_DIR . 'admin/class-ov-admin.php';
        
        // Public
        require_once OV_BOOKING_PLUGIN_DIR . 'public/class-ov-public.php';
        require_once OV_BOOKING_PLUGIN_DIR . 'public/class-ov-shortcode.php';
        
        $this->loader = new OV_Loader();
    }

    /**
     * Verificar y ejecutar actualizaciones de base de datos
     */
    private function check_and_update() {
        require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-updater.php';
        OV_Updater::maybe_update();
    }

    /**
     * Definir la configuración de idioma
     */
    private function set_locale() {
        $plugin_i18n = new OV_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Registrar todos los hooks del área de administración
     */
    private function define_admin_hooks() {
        $plugin_admin = new OV_Admin($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_admin_menu');
        $this->loader->add_action('admin_post_ov_booking_export_csv', 'OV_CSV_Export', 'reservations');
    }

    /**
     * Registrar todos los hooks del área pública
     */
    private function define_public_hooks() {
        $plugin_public = new OV_Public($this->get_plugin_name(), $this->get_version());

        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
        
        // Shortcode
        $shortcode = new OV_Shortcode();
        add_shortcode('ov_booking', array($shortcode, 'render_booking_form'));
        
        // AJAX Handlers
        $ajax_handler = new OV_Ajax_Handler();
        $ajax_handler->init();
    }

    /**
     * Ejecutar el loader
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * Obtener el nombre del plugin
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * Obtener la referencia al loader
     */
    public function get_loader() {
        return $this->loader;
    }

    /**
     * Obtener la versión del plugin
     */
    public function get_version() {
        return $this->version;
    }
}
