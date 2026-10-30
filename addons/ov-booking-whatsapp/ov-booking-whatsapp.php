<?php
/**
 * Plugin Name: OV Booking WhatsApp
 * Description: Confirmación y anulación de reservas mediante WhatsApp Cloud API para OV Booking System.
 * Version: 1.0.4
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Oscar Viedma
 * License: GPL-2.0+
 * Text Domain: ov-booking-whatsapp
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OV_BOOKING_WHATSAPP_VERSION', '1.0.4');
define('OV_BOOKING_WHATSAPP_MIN_CORE_VERSION', '1.8.1');
define('OV_BOOKING_WHATSAPP_DIR', plugin_dir_path(__FILE__));
define('OV_BOOKING_WHATSAPP_URL', plugin_dir_url(__FILE__));

require_once OV_BOOKING_WHATSAPP_DIR . 'includes/class-ov-whatsapp-config.php';
require_once OV_BOOKING_WHATSAPP_DIR . 'includes/class-ov-whatsapp-installer.php';
require_once OV_BOOKING_WHATSAPP_DIR . 'includes/class-ov-whatsapp-api.php';
require_once OV_BOOKING_WHATSAPP_DIR . 'includes/class-ov-whatsapp-outbox.php';
require_once OV_BOOKING_WHATSAPP_DIR . 'includes/class-ov-whatsapp-webhook.php';
require_once OV_BOOKING_WHATSAPP_DIR . 'includes/class-ov-whatsapp-module.php';

register_activation_hook(__FILE__, array('OV_Booking_WhatsApp_Installer', 'activate'));
register_deactivation_hook(__FILE__, array('OV_Booking_WhatsApp_Installer', 'deactivate'));

add_filter('cron_schedules', array('OV_Booking_WhatsApp_Installer', 'add_cron_interval'));
add_action('ov_booking_register_modules', array('OV_Booking_WhatsApp_Module', 'register'));
add_action('plugins_loaded', array('OV_Booking_WhatsApp_Module', 'boot'), 30);
