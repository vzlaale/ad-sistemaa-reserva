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
            $manager->add_cap('read');
            $manager->add_cap(self::CAPABILITY);
        }

        // El administrador sigue siendo el super usuario del sistema.
        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->add_cap(self::CAPABILITY);
        }
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
