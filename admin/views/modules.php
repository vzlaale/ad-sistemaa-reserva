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
$local_licenses = OV_Module_Manager::license_mode() === 'local';
$manage_licenses = current_user_can($local_licenses ? 'manage_options' : OV_Roles::CAPABILITY);
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

    <p><?php echo esc_html($local_licenses
        ? __('Las licencias se administran en esta instalación. Solo una cuenta administradora puede activarlas, renovarlas o desactivarlas.', 'ov-booking-system')
        : __('Activa únicamente los módulos contratados para este negocio. La licencia se valida contra tu servidor de licencias.', 'ov-booking-system')); ?></p>

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
                    <?php elseif (OV_Module_Manager::is_readable($key)) : ?>
                        <?php esc_html_e('Solo lectura', 'ov-booking-system'); ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-minus"></span>
                        <?php esc_html_e('Inactivo', 'ov-booking-system'); ?>
                    <?php endif; ?>
                    <?php if (!empty($entitlement['expires_at'])) : ?>
                        <br><small><?php echo esc_html(sprintf(__('Vence: %s', 'ov-booking-system'), get_date_from_gmt($entitlement['expires_at'], 'd/m/Y H:i'))); ?></small>
                    <?php endif; ?>
                    <?php if ($local_licenses && !empty($entitlement['managed_by'])) : $actor = get_userdata((int) $entitlement['managed_by']); ?>
                        <br><small><?php echo esc_html(sprintf(__('Última gestión: %s', 'ov-booking-system'), $actor ? $actor->display_name : '#' . (int) $entitlement['managed_by'])); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($key === 'core') : ?>
                        <em><?php esc_html_e('Incluido', 'ov-booking-system'); ?></em>
                    <?php elseif (!$module['requires_license']) : ?>
                        <em><?php esc_html_e('Configurar desde los ajustes del complemento', 'ov-booking-system'); ?></em>
                    <?php elseif (!$manage_licenses) : ?>
                        <em><?php esc_html_e('Administrado por la cuenta administradora', 'ov-booking-system'); ?></em>
                    <?php elseif ($local_licenses) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ov_booking_activate_module">
                            <input type="hidden" name="module_key" value="<?php echo esc_attr($key); ?>">
                            <?php wp_nonce_field('ov_booking_module_activate'); ?>
                            <label><?php esc_html_e('Vencimiento', 'ov-booking-system'); ?>
                                <input type="date" name="expires_on" required min="<?php echo esc_attr(wp_date('Y-m-d')); ?>" value="<?php echo esc_attr(($entitlement['provider'] ?? '') === 'local' && !empty($entitlement['expires_at']) ? get_date_from_gmt($entitlement['expires_at'], 'Y-m-d') : ''); ?>">
                            </label>
                            <?php submit_button(!empty($entitlement['activated_once']) && ($entitlement['provider'] ?? '') === 'local' ? __('Renovar', 'ov-booking-system') : __('Activar', 'ov-booking-system'), 'primary', 'submit', false); ?>
                        </form>
                        <?php if (($entitlement['provider'] ?? '') === 'local' && ($entitlement['status'] ?? '') === 'active') : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ov_booking_deactivate_module">
                                <input type="hidden" name="module_key" value="<?php echo esc_attr($key); ?>">
                                <?php wp_nonce_field('ov_booking_module_deactivate'); ?>
                                <?php submit_button(__('Desactivar', 'ov-booking-system'), 'secondary', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
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
