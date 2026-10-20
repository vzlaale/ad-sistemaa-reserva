<?php
/**
 * Vista: Lista de Formularios
 * 
 * @package OV_Booking_System
 */

// Si se accede directamente, abortar
if (!defined('ABSPATH')) {
    exit;
}

// Procesar acciones
if (isset($_GET['action']) && isset($_GET['form_id'])) {
    $form_id = intval($_GET['form_id']);
    $form_model = new OV_Form();
    
    switch ($_GET['action']) {
        case 'delete':
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_form_' . $form_id)) {
                $form_model->delete($form_id);
                echo '<div class="notice notice-success"><p>' . __('Formulario eliminado exitosamente.', 'ov-booking-system') . '</p></div>';
            }
            break;
        case 'duplicate':
            if (wp_verify_nonce($_GET['_wpnonce'], 'duplicate_form_' . $form_id)) {
                $new_id = $form_model->duplicate($form_id);
                if ($new_id) {
                    echo '<div class="notice notice-success"><p>' . __('Formulario duplicado exitosamente.', 'ov-booking-system') . '</p></div>';
                }
            }
            break;
    }
}

// Obtener formularios
$form_model = new OV_Form();
$forms = $form_model->get_all();
?>

<div class="wrap ov-booking-admin">
    <h1 class="wp-heading-inline">
        <?php _e('Formularios de Reserva', 'ov-booking-system'); ?>
    </h1>
    <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=new'); ?>" class="page-title-action">
        <?php _e('Crear Nuevo', 'ov-booking-system'); ?>
    </a>
    <hr class="wp-header-end">

    <?php if (empty($forms)): ?>
        <div class="ov-empty-state">
            <div class="ov-empty-state-icon">
                <span class="dashicons dashicons-calendar-alt"></span>
            </div>
            <h2><?php _e('No hay formularios todavía', 'ov-booking-system'); ?></h2>
            <p><?php _e('Crea tu primer formulario de reservas para empezar a recibir citas.', 'ov-booking-system'); ?></p>
            <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=new'); ?>" class="button button-primary button-large">
                <?php _e('Crear Primer Formulario', 'ov-booking-system'); ?>
            </a>
        </div>
    <?php else: ?>
        <div class="ov-table-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Nombre', 'ov-booking-system'); ?></th>
                    <th><?php _e('Shortcode', 'ov-booking-system'); ?></th>
                    <th><?php _e('Servicios', 'ov-booking-system'); ?></th>
                    <th><?php _e('Reservas', 'ov-booking-system'); ?></th>
                    <th><?php _e('Estado', 'ov-booking-system'); ?></th>
                    <th><?php _e('Fecha', 'ov-booking-system'); ?></th>
                    <th><?php _e('Acciones', 'ov-booking-system'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): 
                    $service_model = new OV_Service();
                    $services_count = count($service_model->get_by_form($form->id));
                    
                    $reservation_model = new OV_Reservation();
                    $reservations_count = count($reservation_model->get_by_form($form->id));
                ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form->id); ?>">
                                    <?php echo esc_html($form->name); ?>
                                </a>
                            </strong>
                            <?php if ($form->description): ?>
                                <br><small class="description"><?php echo esc_html($form->description); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" readonly value='[ov_booking id="<?php echo $form->id; ?>"]' 
                                   onclick="this.select();" style="width: 180px;">
                        </td>
                        <td><?php echo $services_count; ?></td>
                        <td><?php echo $reservations_count; ?></td>
                        <td>
                            <?php if ($form->status === 'active'): ?>
                                <span class="ov-status ov-status-active"><?php _e('Activo', 'ov-booking-system'); ?></span>
                            <?php else: ?>
                                <span class="ov-status ov-status-inactive"><?php _e('Inactivo', 'ov-booking-system'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date_i18n(get_option('date_format'), strtotime($form->created_at)); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form->id); ?>" 
                               class="button button-small">
                                <?php _e('Editar', 'ov-booking-system'); ?>
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ov-booking-forms&action=duplicate&form_id=' . $form->id), 'duplicate_form_' . $form->id); ?>" 
                               class="button button-small">
                                <?php _e('Duplicar', 'ov-booking-system'); ?>
                            </a>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ov-booking-forms&action=delete&form_id=' . $form->id), 'delete_form_' . $form->id); ?>" 
                               class="button button-small button-link-delete"
                               onclick="return confirm('<?php _e('¿Estás seguro de eliminar este formulario? Esta acción no se puede deshacer.', 'ov-booking-system'); ?>');">
                                <?php _e('Eliminar', 'ov-booking-system'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
