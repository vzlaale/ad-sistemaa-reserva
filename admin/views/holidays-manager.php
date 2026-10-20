<?php
/**
 * Vista: Gestión de Días Festivos
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
global $wpdb;
$holidays_table = $wpdb->prefix . 'ov_booking_holidays';

if (isset($_POST['ov_save_holiday']) && wp_verify_nonce($_POST['ov_holiday_nonce'], 'ov_save_holiday')) {
    $holiday_data = array(
        'form_id' => $form_id,
        'holiday_date' => sanitize_text_field($_POST['holiday_date']),
        'description' => sanitize_text_field($_POST['description'])
    );

    if (isset($_POST['holiday_id']) && !empty($_POST['holiday_id'])) {
        $wpdb->update($holidays_table, $holiday_data, array('id' => absint($_POST['holiday_id']), 'form_id' => $form_id));
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Día festivo actualizado.', 'ov-booking-system') . '</p></div>';
    } else {
        $wpdb->insert($holidays_table, $holiday_data);
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Día festivo agregado.', 'ov-booking-system') . '</p></div>';
    }
}

if (isset($_GET['delete_holiday']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_holiday_' . $_GET['delete_holiday'])) {
    $wpdb->delete($holidays_table, array('id' => absint($_GET['delete_holiday']), 'form_id' => $form_id));
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Día festivo eliminado.', 'ov-booking-system') . '</p></div>';
}

// Obtener festivos
$holidays = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$holidays_table} WHERE form_id = %d ORDER BY holiday_date",
    $form_id
));

// Modo edición
$edit_holiday = null;
if (isset($_GET['edit_holiday'])) {
    $edit_holiday = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$holidays_table} WHERE id = %d AND form_id = %d",
        absint($_GET['edit_holiday']), $form_id
    ));
}
?>

<div class="wrap ov-booking-admin">
    <h1 class="wp-heading-inline">
        <?php printf(__('Días Festivos - %s', 'ov-booking-system'), esc_html($form->name)); ?>
    </h1>
    <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form_id); ?>" class="page-title-action">
        <?php _e('← Volver a los Ajustes', 'ov-booking-system'); ?>
    </a>
    <hr class="wp-header-end">

    <div class="ov-holidays-manager">
        <div class="ov-holidays-grid-container">
            <!-- Lista de Festivos -->
            <div class="ov-holidays-list">
                <h2><?php _e('Días Festivos Configurados', 'ov-booking-system'); ?></h2>
                
                <?php if (empty($holidays)): ?>
                    <div class="ov-empty-state-small">
                        <p><?php _e('No hay días festivos configurados. Las reservas estarán disponibles todos los días según tus horarios.', 'ov-booking-system'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="ov-holidays-items">
                        <?php foreach ($holidays as $holiday): ?>
                            <div class="ov-holiday-item">
                                <div class="ov-holiday-date">
                                    <i class="dashicons dashicons-calendar-alt"></i>
                                    <strong><?php echo date_i18n('l, j \d\e F \d\e Y', strtotime($holiday->holiday_date)); ?></strong>
                                </div>
                                <?php if ($holiday->description): ?>
                                    <div class="ov-holiday-description">
                                        <?php echo esc_html($holiday->description); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ov-holiday-actions">
                                    <a href="?page=ov-booking-holidays&form_id=<?php echo $form_id; ?>&edit_holiday=<?php echo $holiday->id; ?>" 
                                       class="button button-small">
                                        <i class="dashicons dashicons-edit"></i>
                                        <?php _e('Editar', 'ov-booking-system'); ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url('?page=ov-booking-holidays&form_id=' . $form_id . '&delete_holiday=' . $holiday->id, 'delete_holiday_' . $holiday->id); ?>" 
                                       class="button button-small button-link-delete"
                                       onclick="return confirm('<?php _e('¿Eliminar este día festivo?', 'ov-booking-system'); ?>');">
                                        <i class="dashicons dashicons-trash"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Formulario de Festivo -->
            <div class="ov-holiday-form-container">
                <h2><?php echo $edit_holiday ? __('Editar Día Festivo', 'ov-booking-system') : __('Nuevo Día Festivo', 'ov-booking-system'); ?></h2>
                
                <form method="post" action="" class="ov-holiday-form">
                    <?php wp_nonce_field('ov_save_holiday', 'ov_holiday_nonce'); ?>
                    <?php if ($edit_holiday): ?>
                        <input type="hidden" name="holiday_id" value="<?php echo $edit_holiday->id; ?>">
                    <?php endif; ?>

                    <div class="ov-form-row">
                        <label for="holiday_date"><?php _e('Fecha', 'ov-booking-system'); ?> *</label>
                        <input type="date" name="holiday_date" id="holiday_date" 
                               value="<?php echo $edit_holiday ? esc_attr($edit_holiday->holiday_date) : ''; ?>" 
                               min="<?php echo date('Y-m-d'); ?>" required>
                        <p class="description"><?php _e('Este día no estará disponible para reservas.', 'ov-booking-system'); ?></p>
                    </div>

                    <div class="ov-form-row">
                        <label for="description"><?php _e('Descripción (opcional)', 'ov-booking-system'); ?></label>
                        <input type="text" name="description" id="description" class="regular-text"
                               value="<?php echo $edit_holiday ? esc_attr($edit_holiday->description) : ''; ?>" 
                               placeholder="<?php _e('Ej: Año Nuevo, Navidad, etc.', 'ov-booking-system'); ?>">
                    </div>

                    <div class="ov-form-actions">
                        <button type="submit" name="ov_save_holiday" class="button button-primary button-large">
                            <i class="dashicons dashicons-yes"></i>
                            <?php echo $edit_holiday ? __('Actualizar', 'ov-booking-system') : __('Agregar Día Festivo', 'ov-booking-system'); ?>
                        </button>
                        <?php if ($edit_holiday): ?>
                            <a href="?page=ov-booking-holidays&form_id=<?php echo $form_id; ?>" class="button button-large">
                                <?php _e('Cancelar', 'ov-booking-system'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="ov-holiday-tips" style="margin-top: 2rem; padding: 1.5rem; background: #fff3cd; border-radius: 8px;">
                    <h3 style="margin-top: 0;">💡 <?php _e('Consejos Rápidos', 'ov-booking-system'); ?></h3>
                    <ul style="margin: 0; padding-left: 1.5rem;">
                        <li><?php _e('Agrega días festivos con anticipación', 'ov-booking-system'); ?></li>
                        <li><?php _e('Los clientes no podrán reservar en estas fechas', 'ov-booking-system'); ?></li>
                        <li><?php _e('Puedes agregar vacaciones, cierres temporales, etc.', 'ov-booking-system'); ?></li>
                    </ul>
                </div>

                <div class="ov-preset-holidays" style="margin-top: 1.5rem;">
                    <h3><?php _e('Festivos Comunes', 'ov-booking-system'); ?></h3>
                    <p style="font-size: 0.9rem; color: #666;">
                        <?php _e('Click para agregar rápidamente:', 'ov-booking-system'); ?>
                    </p>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem;">
                        <?php
                        $current_year = date('Y');
                        $common_holidays = array(
                            "$current_year-01-01" => __("Año Nuevo", 'ov-booking-system'),
                            "$current_year-05-01" => __("Día del Trabajo", 'ov-booking-system'),
                            "$current_year-12-24" => __("Nochebuena", 'ov-booking-system'),
                            "$current_year-12-25" => __("Navidad", 'ov-booking-system'),
                            "$current_year-12-31" => __("Fin de Año", 'ov-booking-system')
                        );
                        
                        foreach ($common_holidays as $date => $desc):
                            // Verificar si ya existe
                            $exists = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM {$holidays_table} WHERE form_id = %d AND holiday_date = %s",
                                $form_id, $date
                            ));
                        ?>
                            <button type="button" class="button button-small" 
                                    onclick="document.getElementById('holiday_date').value='<?php echo $date; ?>'; document.getElementById('description').value='<?php echo $desc; ?>';"
                                    <?php echo $exists ? 'disabled' : ''; ?>>
                                <?php echo $desc; ?>
                                <?php echo $exists ? '✓' : ''; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p style="font-size: 0.85rem; color: #999; margin-top: 1rem;">
                        <?php _e('Agrega los días festivos específicos de tu país o región manualmente.', 'ov-booking-system'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ov-holidays-manager {
    margin-top: 2rem;
}

.ov-holidays-grid-container {
    display: grid;
    grid-template-columns: 1fr 450px;
    gap: 2rem;
}

.ov-holidays-list,
.ov-holiday-form-container {
    background: white;
    padding: 2rem;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
}

.ov-holidays-list h2,
.ov-holiday-form-container h2 {
    margin-top: 0;
    color: #343a40;
    font-size: 1.3rem;
    margin-bottom: 1.5rem;
}

.ov-holiday-item {
    padding: 1.5rem;
    background: #fff3cd;
    border-radius: 8px;
    margin-bottom: 1rem;
    border: 2px solid #ffc107;
}

.ov-holiday-date {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    color: #343a40;
    margin-bottom: 0.5rem;
}

.ov-holiday-date .dashicons {
    color: #ffc107;
    font-size: 20px;
}

.ov-holiday-description {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 1rem;
    font-style: italic;
}

.ov-holiday-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}

.ov-holiday-form-container {
    position: sticky;
    top: 32px;
    max-height: calc(100vh - 64px);
    overflow-y: auto;
}

@media (max-width: 1200px) {
    .ov-holidays-grid-container {
        grid-template-columns: 1fr;
    }
    
    .ov-holiday-form-container {
        position: static;
        max-height: none;
    }
}
</style>
