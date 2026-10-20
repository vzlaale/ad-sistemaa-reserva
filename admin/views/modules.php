<?php
/**
 * Vista: Módulos y licencias.
 *
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) {
    exit;
}

$modules = OV_Module_Manager::get_modules();
$notice = isset($_GET['ov_module_notice']) ? sanitize_key(wp_unslash($_GET['ov_module_notice'])) : '';
$message = isset($_GET['ov_module_message']) ? sanitize_text_field(wp_unslash($_GET['ov_module_message'])) : '';
?>
<div class="wrap ov-booking-admin">
    <h1><?php esc_html_e('Módulos de OV Booking', 'ov-booking-system'); ?></h1>

    <?php if ($notice === 'success') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('El módulo se actualizó correctamente.', 'ov-booking-system'); ?></p></div>
    <?php elseif ($notice === 'error' && $message !== '') : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>

    <p><?php esc_html_e('Activa únicamente los módulos contratados para este negocio. La licencia se valida contra tu servidor de licencias.', 'ov-booking-system'); ?></p>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Módulo', 'ov-booking-system'); ?></th>
                <th><?php esc_html_e('Versión', 'ov-booking-system'); ?></th>
                <th><?php esc_html_e('Estado', 'ov-booking-system'); ?></th>
                <th><?php esc_html_e('Acción', 'ov-booking-system'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($modules as $module) :
            $key = $module['key'];
            $enabled = OV_Module_Manager::is_enabled($key);
            $entitlement = OV_Module_Manager::get_entitlement($key);
            ?>
            <tr>
                <td><strong><?php echo esc_html($module['name']); ?></strong><br><code><?php echo esc_html($key); ?></code></td>
                <td><?php echo esc_html($module['version']); ?></td>
                <td>
                    <?php if ($key !== 'core' && !$module['requires_license']) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color:#168a42"></span>
                        <?php esc_html_e('Instalado', 'ov-booking-system'); ?>
                    <?php elseif ($enabled) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color:#168a42"></span>
                        <?php esc_html_e('Activo', 'ov-booking-system'); ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-minus"></span>
                        <?php esc_html_e('Inactivo', 'ov-booking-system'); ?>
                    <?php endif; ?>
                    <?php if (!empty($entitlement['expires_at'])) : ?>
                        <br><small><?php echo esc_html(sprintf(__('Vence: %s', 'ov-booking-system'), $entitlement['expires_at'])); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($key === 'core') : ?>
                        <em><?php esc_html_e('Incluido', 'ov-booking-system'); ?></em>
                    <?php elseif (!$module['requires_license']) : ?>
                        <em><?php esc_html_e('Configurar desde los ajustes del complemento', 'ov-booking-system'); ?></em>
                    <?php elseif ($enabled) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ov_booking_deactivate_module">
                            <input type="hidden" name="module_key" value="<?php echo esc_attr($key); ?>">
                            <?php wp_nonce_field('ov_booking_module_deactivate'); ?>
                            <?php submit_button(__('Desactivar', 'ov-booking-system'), 'secondary', 'submit', false); ?>
                        </form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ov_booking_activate_module">
                            <input type="hidden" name="module_key" value="<?php echo esc_attr($key); ?>">
                            <input type="text" name="license_key" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Clave de licencia', 'ov-booking-system'); ?>">
                            <?php wp_nonce_field('ov_booking_module_activate'); ?>
                            <?php submit_button(__('Activar', 'ov-booking-system'), 'primary', 'submit', false); ?>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
