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
            'readonly_on_expiry' => false,
            'capability' => 'manage_ov_booking_' . $key,
            'on_enable' => null,
            'on_disable' => null,
        );

        $definition = wp_parse_args($module, $defaults);
        $definition['key'] = $key;
        $definition['requires'] = array_values(array_filter(array_map('sanitize_key', (array) $definition['requires'])));
        $definition['requires_license'] = (bool) $definition['requires_license'];
        $definition['readonly_on_expiry'] = (bool) $definition['readonly_on_expiry'];
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

        if (!self::entitlement_matches($entitlement) || ($entitlement['status'] ?? '') !== 'active') {
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

    public static function license_mode() {
        $mode = defined('OV_BOOKING_LICENSE_MODE') ? OV_BOOKING_LICENSE_MODE : 'api';
        return in_array($mode, array('local', 'api'), true) ? $mode : '';
    }

    private static function entitlement_matches($entitlement) {
        if (self::license_mode() === 'local') {
            return ($entitlement['provider'] ?? '') === 'local'
                && ($entitlement['instance_id'] ?? '') === get_option(self::INSTANCE_ID_OPTION, '')
                && !empty($entitlement['instance_id'])
                && ($entitlement['site_url'] ?? '') === home_url('/');
        }
        if (self::license_mode() !== 'api' || ($entitlement['provider'] ?? 'api') !== 'api') {
            return false;
        }
        // Never promote the published staging keys into production entitlements.
        return wp_get_environment_type() !== 'production'
            || strpos((string) ($entitlement['license_key'] ?? ''), 'TEST-') !== 0;
    }

    public static function is_readable($key) {
        if (self::is_enabled($key)) {
            return true;
        }
        $module = self::get_module($key);
        $entitlement = self::get_entitlement($key);
        return $module && $module['readonly_on_expiry'] && self::entitlement_matches($entitlement)
            && !empty($entitlement['activated_once'])
            && in_array($entitlement['status'] ?? '', array('active', 'inactive'), true);
    }

    /** Local licenses are administrative grants, never guessed or copied test keys. */
    public static function activate_local($key, $expires_on) {
        if (self::license_mode() !== 'local' || !current_user_can('manage_options')) {
            return new WP_Error('forbidden', __('No tienes permisos para administrar licencias locales.', 'ov-booking-system'));
        }
        $module = self::get_module($key);
        if (!$module || !$module['requires_license']) {
            return new WP_Error('invalid_module', __('Selecciona un módulo que requiera licencia.', 'ov-booking-system'));
        }
        $date = is_string($expires_on) ? DateTimeImmutable::createFromFormat('!Y-m-d', $expires_on, wp_timezone()) : false;
        if (!$date || $date->format('Y-m-d') !== $expires_on || $date->setTime(23, 59, 59)->getTimestamp() <= time()) {
            return new WP_Error('invalid_expiration', __('Selecciona una fecha de vencimiento válida, hoy o posterior.', 'ov-booking-system'));
        }
        return self::set_enabled($key, array(
            'status' => 'active', 'provider' => 'local', 'instance_id' => self::get_instance_id(),
            'site_url' => home_url('/'), 'expires_at' => $date->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'checked_at' => current_time('mysql', true), 'managed_by' => get_current_user_id(),
        ));
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
        if (self::license_mode() !== 'api') {
            return new WP_Error('local_license_mode', __('Administra esta licencia local desde Módulos con una cuenta administradora.', 'ov-booking-system'));
        }
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
        if (wp_get_environment_type() === 'production' && strpos($license_key, 'TEST-') === 0) {
            return new WP_Error('test_license', __('Las claves de prueba no son válidas en producción.', 'ov-booking-system'));
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
            'provider' => 'api',
            'license_key' => $license_key,
            'expires_at' => !empty($payload['expires_at']) ? sanitize_text_field($payload['expires_at']) : '',
            'checked_at' => current_time('mysql', true),
        );

        return self::set_enabled($key, $entitlement);
    }

    public static function deactivate($key) {
        if (!current_user_can(self::license_mode() === 'local' ? 'manage_options' : OV_Roles::CAPABILITY)) {
            return new WP_Error('forbidden', __('No tienes permisos para modificar esta licencia.', 'ov-booking-system'));
        }
        $module = self::get_module($key);
        if (!$module) {
            return new WP_Error('unknown_module', __('El módulo no está registrado.', 'ov-booking-system'));
        }
        if (!$module['requires_license']) {
            return new WP_Error('module_managed_elsewhere', __('Este módulo se configura desde sus propios ajustes.', 'ov-booking-system'));
        }

        $result = self::set_enabled($key, array('status' => 'inactive', 'managed_by' => get_current_user_id(), 'checked_at' => current_time('mysql', true)));
        if (!is_wp_error($result)) {
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

        global $wpdb;
        $lock = 'ov_license_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix), 0, 48);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            return new WP_Error('license_busy', __('Otra solicitud está actualizando licencias. Reintenta.', 'ov-booking-system'));
        }
        try {
            wp_cache_delete(self::ENTITLEMENTS_OPTION, 'options');
            $original = (array) get_option(self::ENTITLEMENTS_OPTION, array());
            $previous = $original[$key] ?? array();
            if (($entitlement['status'] ?? '') === 'inactive') {
                $entitlement = array_merge($previous, $entitlement);
            }
            $entitlements = $original;
            $entitlements[$key] = $entitlement;
            if (!update_option(self::ENTITLEMENTS_OPTION, $entitlements, false) && get_option(self::ENTITLEMENTS_OPTION) !== $entitlements) {
                return new WP_Error('license_storage', __('No se pudo guardar la licencia.', 'ov-booking-system'));
            }
            if (($entitlement['status'] ?? '') === 'active' && is_callable($module['on_enable'])) {
                $callback_result = call_user_func($module['on_enable']);
                if (is_wp_error($callback_result)) {
                    update_option(self::ENTITLEMENTS_OPTION, $original, false);
                    return $callback_result;
                }
            }
            if (($entitlement['status'] ?? '') === 'active') {
                $entitlement['activated_once'] = !empty($previous['activated_once']) || empty($entitlement['expires_at'])
                    || strtotime($entitlement['expires_at']) > time();
                $entitlement['activated_at'] = current_time('mysql', true);
                $entitlements[$key] = $entitlement;
                if (!update_option(self::ENTITLEMENTS_OPTION, $entitlements, false) && get_option(self::ENTITLEMENTS_OPTION) !== $entitlements) {
                    update_option(self::ENTITLEMENTS_OPTION, $original, false);
                    return new WP_Error('license_storage', __('No se pudo confirmar la licencia.', 'ov-booking-system'));
                }
            }
            return true;
        } catch (Throwable $error) {
            if (isset($original)) {
                update_option(self::ENTITLEMENTS_OPTION, $original, false);
            }
            return new WP_Error('license_storage', __('No se pudo completar el cambio de licencia. Reintenta.', 'ov-booking-system'));
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function ensure_capability($capability) {
        if ($capability === '') {
            return;
        }

        $roles = array('administrator', OV_Roles::ROLE, OV_Roles::SUPERUSER_ROLE);
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role && !$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }

    private static function get_instance_id() {
        $instance_id = get_option(self::INSTANCE_ID_OPTION, '');
        if (!$instance_id) {
            $instance_id = wp_generate_uuid4();
            add_option(self::INSTANCE_ID_OPTION, $instance_id, '', false);
            wp_cache_delete(self::INSTANCE_ID_OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            $instance_id = get_option(self::INSTANCE_ID_OPTION);
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
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Método no permitido.', 'ov-booking-system'), '', array('response' => 405));
        }
        if (!current_user_can(self::license_mode() === 'local' ? 'manage_options' : OV_Roles::CAPABILITY)) {
            wp_die(esc_html__('No tienes permisos para modificar módulos.', 'ov-booking-system'), '', array('response' => 403));
        }

        check_admin_referer('ov_booking_module_' . $action);

        $key = isset($_POST['module_key']) ? sanitize_key(wp_unslash($_POST['module_key'])) : '';
        if ($action === 'activate' && self::license_mode() === 'local') {
            $result = self::activate_local($key, isset($_POST['expires_on']) ? wp_unslash($_POST['expires_on']) : '');
        } else {
            $result = $action === 'activate'
                ? self::activate($key, isset($_POST['license_key']) ? wp_unslash($_POST['license_key']) : '')
                : self::deactivate($key);
        }

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
