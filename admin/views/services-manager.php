<?php
/**
 * Vista: Gestión de Servicios
 * 
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) exit;

$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    echo '<div class="notice notice-error"><p>' . __('ID de formulario no válido', 'ov-booking-system') . '</p></div>';
    return;
}

$form_model = new OV_Form();
$form = $form_model->get($form_id);

if (!$form) {
    echo '<div class="notice notice-error"><p>' . __('Formulario no encontrado', 'ov-booking-system') . '</p></div>';
    return;
}

// Procesar acciones
$service_model = new OV_Service();

if (isset($_POST['ov_save_service']) && wp_verify_nonce($_POST['ov_service_nonce'], 'ov_save_service')) {
    $service_data = array(
        'form_id' => $form_id,
        'name' => sanitize_text_field($_POST['service_name']),
        'description' => sanitize_textarea_field($_POST['service_description']),
        'duration' => intval($_POST['service_duration']),
        'duration_unit' => sanitize_text_field($_POST['duration_unit']),
        'slot_interval' => !empty($_POST['slot_interval']) ? intval($_POST['slot_interval']) : null,
        'buffer_time' => intval($_POST['buffer_time']),
        'price_text' => sanitize_text_field($_POST['price_text']),
        'image_url' => esc_url_raw($_POST['image_url']),
        'active' => isset($_POST['active']) ? 1 : 0,
        'display_order' => intval($_POST['display_order'])
    );

    if (isset($_POST['service_id']) && !empty($_POST['service_id'])) {
        $service_id = absint($_POST['service_id']);
        $existing_service = $service_model->get($service_id);
        if (!$existing_service || (int) $existing_service->form_id !== $form_id) {
            wp_die(esc_html__('El servicio no pertenece a este formulario.', 'ov-booking-system'), '', array('response' => 403));
        }
        $service_model->update($service_id, $service_data);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Servicio actualizado exitosamente.', 'ov-booking-system') . '</p></div>';
    } else {
        $service_model->create($service_data);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Servicio creado exitosamente.', 'ov-booking-system') . '</p></div>';
    }
}

if (isset($_GET['delete_service']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_service_' . $_GET['delete_service'])) {
    $service_id = absint($_GET['delete_service']);
    $existing_service = $service_model->get($service_id);
    if (!$existing_service || (int) $existing_service->form_id !== $form_id) {
        wp_die(esc_html__('El servicio no pertenece a este formulario.', 'ov-booking-system'), '', array('response' => 403));
    }
    $service_model->delete($service_id);
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Servicio eliminado exitosamente.', 'ov-booking-system') . '</p></div>';
}

// Obtener servicios
$services = $service_model->get_by_form($form_id, null, false);

// Modo edición
$edit_service = null;
if (isset($_GET['edit_service'])) {
    $edit_service = $service_model->get(intval($_GET['edit_service']));
    if ($edit_service && (int) $edit_service->form_id !== $form_id) {
        $edit_service = null;
    }
}
?>

<div class="wrap ov-booking-admin">
    <h1 class="wp-heading-inline">
        <?php printf(__('Servicios - %s', 'ov-booking-system'), esc_html($form->name)); ?>
    </h1>
    <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form_id); ?>" class="page-title-action">
        <?php _e('← Volver a los Ajustes', 'ov-booking-system'); ?>
    </a>
    <hr class="wp-header-end">

    <div class="ov-services-manager">
        <div class="ov-services-grid-container">
            <!-- Lista de Servicios -->
            <div class="ov-services-list">
                <h2><?php _e('Servicios Existentes', 'ov-booking-system'); ?></h2>
                
                <?php if (empty($services)): ?>
                    <div class="ov-empty-state-small">
                        <p><?php _e('No hay servicios todavía. Agrega tu primer servicio.', 'ov-booking-system'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="ov-services-items">
                        <?php foreach ($services as $service): ?>
                            <div class="ov-service-item <?php echo $service->active ? 'active' : 'inactive'; ?>">
                                <div class="ov-service-icon">
                                    <?php if ($service->image_url): ?>
                                        <img src="<?php echo esc_url($service->image_url); ?>" alt="">
                                    <?php else: ?>
                                        <span><?php echo substr($service->name, 0, 1); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ov-service-info">
                                    <h3><?php echo esc_html($service->name); ?></h3>
                                    <p class="ov-service-meta">
                                        <span class="ov-duration">
                                            <i class="dashicons dashicons-clock"></i>
                                            <?php echo $service->duration; ?>
                                            <?php 
                                            $units = array('minutes' => 'min', 'hours' => 'hrs', 'days' => 'días');
                                            echo $units[$service->duration_unit];
                                            ?>
                                        </span>
                                        <?php if ($service->price_text): ?>
                                            <span class="ov-price">
                                                <i class="dashicons dashicons-tag"></i>
                                                <?php echo esc_html($service->price_text); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="ov-service-actions">
                                    <a href="?page=ov-booking-services&form_id=<?php echo $form_id; ?>&edit_service=<?php echo $service->id; ?>" 
                                       class="button button-small">
                                        <i class="dashicons dashicons-edit"></i>
                                        <?php _e('Editar', 'ov-booking-system'); ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url('?page=ov-booking-services&form_id=' . $form_id . '&delete_service=' . $service->id, 'delete_service_' . $service->id); ?>" 
                                       class="button button-small button-link-delete"
                                       onclick="return confirm('<?php _e('¿Estás seguro de eliminar este servicio?', 'ov-booking-system'); ?>');">
                                        <i class="dashicons dashicons-trash"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Formulario de Servicio -->
            <div class="ov-service-form-container">
                <h2><?php echo $edit_service ? __('Editar Servicio', 'ov-booking-system') : __('Nuevo Servicio', 'ov-booking-system'); ?></h2>
                
                <form method="post" action="" class="ov-service-form">
                    <?php wp_nonce_field('ov_save_service', 'ov_service_nonce'); ?>
                    <?php if ($edit_service): ?>
                        <input type="hidden" name="service_id" value="<?php echo $edit_service->id; ?>">
                    <?php endif; ?>

                    <div class="ov-form-row">
                        <label for="service_name"><?php _e('Nombre del Servicio', 'ov-booking-system'); ?> *</label>
                        <input type="text" name="service_name" id="service_name" class="regular-text" 
                               value="<?php echo $edit_service ? esc_attr($edit_service->name) : ''; ?>" required>
                    </div>

                    <div class="ov-form-row">
                        <label for="service_description"><?php _e('Descripción', 'ov-booking-system'); ?></label>
                        <textarea name="service_description" id="service_description" rows="3" class="large-text"><?php echo $edit_service ? esc_textarea($edit_service->description) : ''; ?></textarea>
                    </div>

                    <div class="ov-form-row-group">
                        <div class="ov-form-row">
                            <label for="service_duration"><?php _e('Duración', 'ov-booking-system'); ?> *</label>
                            <input type="number" name="service_duration" id="service_duration" 
                                   value="<?php echo $edit_service ? $edit_service->duration : '60'; ?>" 
                                   min="1" required>
                        </div>

                        <div class="ov-form-row">
                            <label for="duration_unit"><?php _e('Unidad', 'ov-booking-system'); ?></label>
                            <select name="duration_unit" id="duration_unit">
                                <option value="minutes" <?php echo ($edit_service && $edit_service->duration_unit == 'minutes') ? 'selected' : ''; ?>>
                                    <?php _e('Minutos', 'ov-booking-system'); ?>
                                </option>
                                <option value="hours" <?php echo ($edit_service && $edit_service->duration_unit == 'hours') ? 'selected' : ''; ?>>
                                    <?php _e('Horas', 'ov-booking-system'); ?>
                                </option>
                                <option value="days" <?php echo ($edit_service && $edit_service->duration_unit == 'days') ? 'selected' : ''; ?>>
                                    <?php _e('Días', 'ov-booking-system'); ?>
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="ov-form-row-group">
                        <div class="ov-form-row">
                            <label for="slot_interval"><?php _e('Intervalo de Slots', 'ov-booking-system'); ?></label>
                            <input type="number" name="slot_interval" id="slot_interval" 
                                   value="<?php echo $edit_service ? $edit_service->slot_interval : ''; ?>" 
                                   min="0" placeholder="<?php _e('Mismo que duración', 'ov-booking-system'); ?>">
                            <p class="description"><?php _e('Minutos entre cada slot disponible. Dejar vacío usa la duración del servicio.', 'ov-booking-system'); ?></p>
                        </div>

                        <div class="ov-form-row">
                            <label for="buffer_time"><?php _e('Tiempo de Buffer', 'ov-booking-system'); ?></label>
                            <input type="number" name="buffer_time" id="buffer_time" 
                                   value="<?php echo $edit_service ? $edit_service->buffer_time : '0'; ?>" 
                                   min="0">
                            <p class="description"><?php _e('Minutos extra de descanso después de cada cita.', 'ov-booking-system'); ?></p>
                        </div>
                    </div>

                    <div class="ov-form-row-group">
                        <div class="ov-form-row">
                            <label for="price_text"><?php _e('Precio (texto)', 'ov-booking-system'); ?></label>
                            <input type="text" name="price_text" id="price_text" 
                                   value="<?php echo $edit_service ? esc_attr($edit_service->price_text) : ''; ?>" 
                                   placeholder="$100">
                        </div>

                        <div class="ov-form-row">
                            <label for="display_order"><?php _e('Orden de Visualización', 'ov-booking-system'); ?></label>
                            <input type="number" name="display_order" id="display_order" 
                                   value="<?php echo $edit_service ? $edit_service->display_order : count($services) + 1; ?>" 
                                   min="0">
                        </div>
                    </div>

                    <div class="ov-form-row">
                        <label for="image_url"><?php _e('URL de Imagen (opcional)', 'ov-booking-system'); ?></label>
                        <input type="url" name="image_url" id="image_url" class="large-text" 
                               value="<?php echo $edit_service ? esc_url($edit_service->image_url) : ''; ?>" 
                               placeholder="https://...">
                    </div>

                    <div class="ov-form-row">
                        <label>
                            <input type="checkbox" name="active" value="1" 
                                   <?php echo (!$edit_service || $edit_service->active) ? 'checked' : ''; ?>>
                            <?php _e('Servicio activo', 'ov-booking-system'); ?>
                        </label>
                    </div>

                    <div class="ov-form-actions">
                        <button type="submit" name="ov_save_service" class="button button-primary button-large">
                            <i class="dashicons dashicons-yes"></i>
                            <?php echo $edit_service ? __('Actualizar Servicio', 'ov-booking-system') : __('Crear Servicio', 'ov-booking-system'); ?>
                        </button>
                        <?php if ($edit_service): ?>
                            <a href="?page=ov-booking-services&form_id=<?php echo $form_id; ?>" class="button button-large">
                                <?php _e('Cancelar', 'ov-booking-system'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
