<?php
/**
 * Bootstrap, hooks y administración del add-on.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) {
    exit;
}

class OV_Booking_WhatsApp_Module {

    public static function register() {
        if (!class_exists('OV_Module_Manager')) return;
        OV_Module_Manager::register(array(
            'key' => 'whatsapp',
            'name' => 'WhatsApp Cloud API',
            'version' => OV_BOOKING_WHATSAPP_VERSION,
            'requires' => array('core'),
            'requires_license' => false,
            'on_enable' => array('OV_Booking_WhatsApp_Installer', 'install'),
        ));
    }

    public static function boot() {
        if (!class_exists('OV_Module_Manager') || !defined('OV_BOOKING_VERSION')) {
            add_action('admin_notices', array(__CLASS__, 'missing_core_notice'));
            return;
        }
        if (version_compare(OV_BOOKING_VERSION, OV_BOOKING_WHATSAPP_MIN_CORE_VERSION, '<')) {
            add_action('admin_notices', array(__CLASS__, 'old_core_notice'));
            return;
        }

        OV_Booking_WhatsApp_Installer::maybe_update();
        OV_Booking_WhatsApp_Installer::schedule_worker();
        add_action('rest_api_init', array('OV_Booking_WhatsApp_Webhook', 'register_routes'));
        add_action('ov_booking_reservation_created', array('OV_Booking_WhatsApp_Outbox', 'enqueue_reservation'), 10, 2);
        add_action('ov_booking_reservation_deleted', array('OV_Booking_WhatsApp_Outbox', 'enqueue_deleted'), 10, 1);
        add_action('ov_booking_reservation_updated', array('OV_Booking_WhatsApp_Outbox', 'reservation_updated'), 10, 3);
        add_action('ov_booking_whatsapp_process_queue', array('OV_Booking_WhatsApp_Outbox', 'process_queue'), 10, 1);
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 40);
        add_action('admin_post_ov_whatsapp_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_ov_whatsapp_process_queue', array(__CLASS__, 'schedule_queue'));
        add_action('admin_post_ov_whatsapp_requeue', array(__CLASS__, 'requeue'));
    }

    public static function admin_menu() {
        if (!OV_Module_Manager::is_enabled('whatsapp')) return;
        add_submenu_page(
            'ov-booking-system',
            __('WhatsApp Cloud API', 'ov-booking-whatsapp'),
            __('WhatsApp', 'ov-booking-whatsapp'),
            OV_Roles::CAPABILITY,
            'ov-booking-whatsapp',
            array(__CLASS__, 'render_admin')
        );
    }

    public static function render_admin() {
        self::guard();
        include OV_BOOKING_WHATSAPP_DIR . 'admin/views/settings.php';
    }

    public static function save_settings() {
        self::guard();
        check_admin_referer('ov_whatsapp_save_settings');
        $settings = OV_Booking_WhatsApp_Config::sanitize(wp_unslash($_POST['settings'] ?? array()));
        if ($settings['template_name'] === '' || $settings['language_code'] === '' || $settings['business_name'] === '' ||
            $settings['confirm_button_index'] === $settings['cancel_button_index']) {
            self::redirect('invalid_settings');
        }
        update_option(OV_Booking_WhatsApp_Config::OPTION, $settings, false);
        if (!empty($settings['enabled'])) OV_Booking_WhatsApp_Outbox::schedule_immediate_worker();
        self::redirect('saved');
    }

    public static function schedule_queue() {
        self::guard();
        check_admin_referer('ov_whatsapp_process_queue');
        OV_Booking_WhatsApp_Outbox::schedule_immediate_worker();
        self::redirect('scheduled');
    }

    public static function requeue() {
        self::guard();
        $id = isset($_POST['outbox_id']) ? absint($_POST['outbox_id']) : 0;
        check_admin_referer('ov_whatsapp_requeue_' . $id);
        $result = OV_Booking_WhatsApp_Outbox::requeue($id);
        self::redirect(is_wp_error($result) ? 'requeue_error' : 'requeued');
    }

    private static function guard() {
        if (!OV_Module_Manager::current_user_can('whatsapp')) {
            wp_die(esc_html__('No tienes permisos para administrar WhatsApp.', 'ov-booking-whatsapp'), '', array('response' => 403));
        }
    }

    private static function redirect($notice) {
        wp_safe_redirect(add_query_arg(array('page' => 'ov-booking-whatsapp', 'ov_whatsapp_notice' => sanitize_key($notice)), admin_url('admin.php')));
        exit;
    }

    public static function missing_core_notice() {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('OV Booking WhatsApp requiere OV Booking System 1.8.1 o posterior.', 'ov-booking-whatsapp') . '</p></div>';
        }
    }

    public static function old_core_notice() {
        self::missing_core_notice();
    }
}
