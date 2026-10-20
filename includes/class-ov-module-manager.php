<?php
/**
 * Registro, permisos y licenciamiento de módulos de OV Booking.
 *
 * Los add-ons se registran en el hook `ov_booking_register_modules` y no
 * necesitan modificar el plugin principal para agregar funcionalidad.
 *
 * @package OV_Booking_System
 */

class OV_Module_Manager {

    const ENTITLEMENTS_OPTION = 'ov_booking_module_entitlements';
    const INSTANCE_ID_OPTION = 'ov_booking_instance_id';

    private static $modules = array();
    private static $booted = false;
    private static $discovery_complete = false;

    /**
     * Iniciar el gestor una sola vez.
     */
    public static function boot() {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        self::register(array(
            'key' => 'core',
            // El registro sucede antes de init. Mantener esta etiqueta sin
            // traducir evita la carga temprana del text domain en WP 6.7+.
            'name' => 'Núcleo de reservas',
            'version' => defined('OV_BOOKING_VERSION') ? OV_BOOKING_VERSION : '0.0.0',
            'requires_license' => false,
            'capability' => OV_Roles::CAPABILITY,
        ));

        // Los add-ons se registran antes de que se construyan los menús.
        add_action('plugins_loaded', array(__CLASS__, 'discover_modules'), 20);
        add_action('admin_post_ov_booking_activate_module', array(__CLASS__, 'handle_activate_request'));
        add_action('admin_post_ov_booking_deactivate_module', array(__CLASS__, 'handle_deactivate_request'));
    }

    /**
     * Registrar un add-on.
     *
     * @param array $module key, name, version y opcionalmente requires,
     *                      capability, requires_license, on_enable y on_disable.
     * @return true|WP_Error
     */
    public static function register($module) {
        if (!is_array($module)) {
            return new WP_Error('invalid_module', __('La definición del módulo no es válida.', 'ov-booking-system'));
        }

        $key = isset($module['key']) ? sanitize_key($module['key']) : '';
        $name = isset($module['name']) ? sanitize_text_field($module['name']) : '';
        $version = isset($module['version']) ? sanitize_text_field($module['version']) : '';

        if ($key === '') {
            return new WP_Error('invalid_module_key', __('El módulo necesita una clave válida.', 'ov-booking-system'));
        }

        if (isset(self::$modules[$key])) {
            return new WP_Error('duplicate_module', __('El módulo ya está registrado.', 'ov-booking-system'));
        }

        if ($name === '' || $version === '') {
            return new WP_Error('invalid_module_definition', __('El módulo necesita nombre y versión.', 'ov-booking-system'));
        }

        $defaults = array(
            'key' => $key,
            'name' => $name,
            'version' => $version,
            'requires' => array('core'),
            'requires_license' => true,
            'capability' => 'manage_ov_booking_' . $key,
            'on_enable' => null,
            'on_disable' => null,
        );

        $definition = wp_parse_args($module, $defaults);
        $definition['key'] = $key;
        $definition['requires'] = array_values(array_filter(array_map('sanitize_key', (array) $definition['requires'])));
        $definition['requires_license'] = (bool) $definition['requires_license'];
        $definition['capability'] = sanitize_key($definition['capability']);

        self::$modules[$key] = $definition;
        self::ensure_capability($definition['capability']);

        return true;
    }

    /**
     * Ejecutar el descubrimiento de add-ons activos.
     */
    public static function discover_modules() {
        if (self::$discovery_complete) {
            return;
        }

        do_action('ov_booking_register_modules', __CLASS__);
        self::$discovery_complete = true;

        foreach (self::$modules as $module) {
            self::ensure_capability($module['capability']);
        }

        do_action('ov_booking_modules_registered', self::$modules);
    }

    public static function get_modules() {
        return self::$modules;
    }

    public static function get_module($key) {
        $key = sanitize_key($key);
        return isset(self::$modules[$key]) ? self::$modules[$key] : null;
    }

    /**
     * Comprobar si un módulo está habilitado en esta instalación.
     */
    public static function is_enabled($key) {
        $key = sanitize_key($key);
        $module = self::get_module($key);

        if (!$module) {
            return false;
        }

        if ($key === 'core' || !$module['requires_license']) {
            return true;
        }

        $entitlements = get_option(self::ENTITLEMENTS_OPTION, array());
        $entitlement = isset($entitlements[$key]) && is_array($entitlements[$key])
            ? $entitlements[$key]
            : array();

        if (($entitlement['status'] ?? '') !== 'active') {
            return false;
        }

        if (!empty($entitlement['expires_at']) && strtotime($entitlement['expires_at']) <= time()) {
            return false;
        }

        foreach ($module['requires'] as $dependency) {
            if ($dependency !== $key && !self::is_enabled($dependency)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Comprobar acceso completo: capacidad de WordPress y licencia activa.
     */
    public static function current_user_can($key) {
        $module = self::get_module($key);

        if (!$module || !self::is_enabled($key)) {
            return false;
        }

        return current_user_can(OV_Roles::CAPABILITY)
            && current_user_can($module['capability']);
    }

    public static function get_entitlement($key) {
        $entitlements = get_option(self::ENTITLEMENTS_OPTION, array());
        return isset($entitlements[$key]) && is_array($entitlements[$key])
            ? $entitlements[$key]
            : array('status' => 'inactive');
    }

    /**
     * Activar una licencia consultando el servidor de licencias.
     * Nunca se considera válida una licencia solo por el texto introducido en
     * el navegador: el servidor debe devolver {"valid": true}.
     */
    public static function activate($key, $license_key) {
        $module = self::get_module($key);
        $license_key = sanitize_text_field($license_key);

        if (!$module) {
            return new WP_Error('unknown_module', __('El módulo no está registrado.', 'ov-booking-system'));
        }

        if (!current_user_can(OV_Roles::CAPABILITY)) {
            return new WP_Error('forbidden', __('No tienes permisos para activar módulos.', 'ov-booking-system'));
        }

        if (!$module['requires_license']) {
            return self::set_enabled($key, array('status' => 'active'));
        }

        if ($license_key === '') {
            return new WP_Error('empty_license', __('Introduce una licencia válida.', 'ov-booking-system'));
        }

        $api_url = self::get_license_api_url();
        if ($api_url === '') {
            return new WP_Error(
                'license_api_not_configured',
                __('El servidor de licencias aún no está configurado.', 'ov-booking-system')
            );
        }

        $response = wp_remote_post($api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
            ),
            'body' => wp_json_encode(array(
                'action' => 'activate',
                'license_key' => $license_key,
                'module' => $key,
                'site_url' => home_url('/'),
                'instance_id' => self::get_instance_id(),
                'plugin_version' => defined('OV_BOOKING_VERSION') ? OV_BOOKING_VERSION : '',
            )),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('license_request_failed', __('No fue posible contactar al servidor de licencias.', 'ov-booking-system'));
        }

        $code = wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($payload) || empty($payload['valid'])) {
            $message = is_array($payload) && !empty($payload['message'])
                ? sanitize_text_field($payload['message'])
                : __('La licencia no es válida para este módulo o instalación.', 'ov-booking-system');
            return new WP_Error('invalid_license', $message);
        }

        $entitlement = array(
            'status' => 'active',
            'license_key' => $license_key,
            'expires_at' => !empty($payload['expires_at']) ? sanitize_text_field($payload['expires_at']) : '',
            'checked_at' => current_time('mysql', true),
        );

        return self::set_enabled($key, $entitlement);
    }

    public static function deactivate($key) {
        if (!self::get_module($key)) {
            return new WP_Error('unknown_module', __('El módulo no está registrado.', 'ov-booking-system'));
        }

        $result = self::set_enabled($key, array('status' => 'inactive'));
        if (!is_wp_error($result)) {
            $module = self::get_module($key);
            if (is_callable($module['on_disable'])) {
                call_user_func($module['on_disable']);
            }
        }

        return $result;
    }

    private static function set_enabled($key, $entitlement) {
        $module = self::get_module($key);
        if (!$module) {
            return new WP_Error('unknown_module', __('El módulo no está registrado.', 'ov-booking-system'));
        }

        foreach ($module['requires'] as $dependency) {
            if ($dependency !== $key && !self::is_enabled($dependency)) {
                return new WP_Error('missing_dependency', sprintf(
                    __('El módulo requiere que esté activo: %s.', 'ov-booking-system'),
                    $dependency
                ));
            }
        }

        $entitlements = get_option(self::ENTITLEMENTS_OPTION, array());
        $entitlements[$key] = $entitlement;
        update_option(self::ENTITLEMENTS_OPTION, $entitlements, false);

        if (($entitlement['status'] ?? '') === 'active' && is_callable($module['on_enable'])) {
            $callback_result = call_user_func($module['on_enable']);
            if (is_wp_error($callback_result)) {
                $entitlements[$key]['status'] = 'inactive';
                update_option(self::ENTITLEMENTS_OPTION, $entitlements, false);
                return $callback_result;
            }
        }

        return true;
    }

    private static function ensure_capability($capability) {
        if ($capability === '') {
            return;
        }

        $roles = array('administrator', OV_Roles::ROLE);
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap($capability);
            }
        }
    }

    private static function get_instance_id() {
        $instance_id = get_option(self::INSTANCE_ID_OPTION, '');
        if (!$instance_id) {
            $instance_id = wp_generate_uuid4();
            add_option(self::INSTANCE_ID_OPTION, $instance_id, '', false);
        }

        return $instance_id;
    }

    private static function get_license_api_url() {
        $configured = defined('OV_BOOKING_LICENSE_API_URL') ? OV_BOOKING_LICENSE_API_URL : '';
        return esc_url_raw((string) apply_filters('ov_booking_license_api_url', $configured));
    }

    public static function handle_activate_request() {
        self::handle_request('activate');
    }

    public static function handle_deactivate_request() {
        self::handle_request('deactivate');
    }

    private static function handle_request($action) {
        if (!current_user_can(OV_Roles::CAPABILITY)) {
            wp_die(esc_html__('No tienes permisos para modificar módulos.', 'ov-booking-system'), '', array('response' => 403));
        }

        check_admin_referer('ov_booking_module_' . $action);

        $key = isset($_POST['module_key']) ? sanitize_key(wp_unslash($_POST['module_key'])) : '';
        $result = $action === 'activate'
            ? self::activate($key, isset($_POST['license_key']) ? wp_unslash($_POST['license_key']) : '')
            : self::deactivate($key);

        $args = array(
            'page' => 'ov-booking-modules',
            'ov_module_notice' => is_wp_error($result) ? 'error' : 'success',
        );
        if (is_wp_error($result)) {
            $args['ov_module_message'] = $result->get_error_message();
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
