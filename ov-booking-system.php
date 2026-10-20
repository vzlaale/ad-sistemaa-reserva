<?php
/**
 * Plugin Name: OV Booking System
 * Plugin URI: https://ovdivi.com/ov-booking-system
 * Description: Sistema profesional de reservas con soporte para calendarios de clientes. Creado para Divi y compatible con cualquier tema de WordPress.
 * Version: 1.8.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Oscar Viedma
 * Author URI: https://ovdivi.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: ov-booking-system
 * Domain Path: /languages
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('WPINC')) {
    die;
}

/**
 * Información del plugin
 */
define('OV_BOOKING_VERSION', '1.8.1');
define('OV_BOOKING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OV_BOOKING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OV_BOOKING_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Código que se ejecuta durante la activación del plugin.
 */
function activate_ov_booking_system() {
    require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-activator.php';
    OV_Activator::activate();
}

/**
 * Código que se ejecuta durante la desactivación del plugin.
 */
function deactivate_ov_booking_system() {
    require_once OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-deactivator.php';
    OV_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_ov_booking_system');
register_deactivation_hook(__FILE__, 'deactivate_ov_booking_system');

/**
 * La clase principal del plugin.
 */
require OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-roles.php';
OV_Roles::ensure_roles();

require OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-module-manager.php';
OV_Module_Manager::boot();

require OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-booking-system.php';

/**
 * Sistema de actualización
 */
require OV_BOOKING_PLUGIN_DIR . 'includes/class-ov-updater.php';

/**
 * Comienza la ejecución del plugin.
 */
function run_ov_booking_system() {
    $plugin = new OV_Booking_System();
    $plugin->run();
}
run_ov_booking_system();
