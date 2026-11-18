<?php
/**
 * Roles y capacidades del plugin.
 *
 * @package OV_Booking_System
 */

class OV_Roles {

    /**
     * Rol utilizado por los usuarios que gestionan OV Booking.
     */
    const ROLE = 'ov_manager';

    const SUPERUSER_ROLE = 'ov_superuser';
    const SUPERUSER_CAPABILITY = 'manage_ov_booking_system';

    /**
     * Capacidad que permite administrar todas las funciones de OV Booking.
     */
    const CAPABILITY = 'manage_ov_booking';

    /**
     * Crear/actualizar el rol y asegurar la capacidad del administrador.
     *
     * Se ejecuta de forma idempotente para que las instalaciones existentes
     * reciban el rol aunque el plugin ya estuviera instalado anteriormente.
     */
    public static function ensure_roles() {
        $manager = get_role(self::ROLE);

        if (!$manager) {
            $manager = add_role(
                self::ROLE,
                // Se ejecuta durante la carga del plugin, antes de init.
                // Usar texto plano evita forzar la carga temprana del idioma.
                'OV Booking Manager',
                array(
                    'read' => true,
                    self::CAPABILITY => true,
                )
            );
        } else {
            if (!$manager->has_cap('read')) {
                $manager->add_cap('read');
            }
            if (!$manager->has_cap(self::CAPABILITY)) {
                $manager->add_cap(self::CAPABILITY);
            }
        }

        // El administrador conserva sus permisos técnicos, sin acceso clínico implícito.
        $administrator = get_role('administrator');
        if ($administrator) {
            if (!$administrator->has_cap(self::CAPABILITY)) {
                $administrator->add_cap(self::CAPABILITY);
            }
            self::ensure_superuser_role($administrator);
        }
    }

    /** Register a site administrator role; never assign it or alter public registration. */
    private static function ensure_superuser_role($administrator) {
        $capabilities = $administrator->capabilities;
        $capabilities['read'] = true;
        $capabilities['manage_options'] = true;
        $capabilities[self::CAPABILITY] = true;
        $capabilities[self::SUPERUSER_CAPABILITY] = true;
        $superuser = get_role(self::SUPERUSER_ROLE);
        if (!$superuser) {
            add_role(self::SUPERUSER_ROLE, 'OV Booking Superusuario', $capabilities);
            return;
        }
        // Only persist actual changes: this check also runs on ordinary requests.
        foreach ($capabilities as $capability => $grant) {
            if (!array_key_exists($capability, $superuser->capabilities)
                || $superuser->capabilities[$capability] !== (bool) $grant) {
                $superuser->add_cap($capability, (bool) $grant);
            }
        }
    }

    public static function is_superuser($user = null) {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        return $user->exists() && in_array(self::SUPERUSER_ROLE, (array) $user->roles, true)
            && user_can($user, self::SUPERUSER_CAPABILITY)
            && user_can($user, self::CAPABILITY) && user_can($user, 'manage_options');
    }

    /**
     * Eliminar el rol y la capacidad agregada al administrador.
     *
     * Se utiliza únicamente durante la desinstalación definitiva del plugin.
     */
    public static function remove_roles() {
        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->remove_cap(self::CAPABILITY);
        }

        remove_role(self::ROLE);
    }
}
