<?php
/**
 * Vista: Gestión de Horarios
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
$professional_model = new OV_Professional();
$professionals = $professional_model->get_by_form($form_id, true);
$professional_id = isset($_GET['professional_id']) ? absint($_GET['professional_id']) : 0;
$professional = null;
if ($professional_id) {
    foreach ($professionals as $candidate) {
        if ((int) $candidate->id === $professional_id) {
            $professional = $candidate;
            break;
        }
    }
    if (!$professional) {
        $professional_id = 0;
    }
}
$using_professional_schedule = !empty($professionals) && $professional_id > 0;
$schedules_table = $using_professional_schedule
    ? $wpdb->prefix . 'ov_booking_professional_schedules'
    : $wpdb->prefix . 'ov_booking_schedules';

if (isset($_POST['ov_save_schedule']) && wp_verify_nonce($_POST['ov_schedule_nonce'], 'ov_save_schedule')) {
    $start_time = sanitize_text_field($_POST['start_time']);
    $end_time = sanitize_text_field($_POST['end_time']);
    
    // Validar que la hora de fin sea mayor que la de inicio
    $start_minutes = intval(substr($start_time, 0, 2)) * 60 + intval(substr($start_time, 3, 2));
    $end_minutes = intval(substr($end_time, 0, 2)) * 60 + intval(substr($end_time, 3, 2));
    
    if ($end_minutes <= $start_minutes) {
        echo '<div class="notice notice-error is-dismissible"><p>' . __('La hora de fin debe ser posterior a la hora de inicio.', 'ov-booking-system') . '</p></div>';
    } else {
        $schedule_data = array(
            'day_of_week' => intval($_POST['day_of_week']),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'active' => isset($_POST['active']) ? 1 : 0
        );
        if ($using_professional_schedule) {
            $schedule_data['professional_id'] = $professional_id;
        } else {
            $schedule_data['form_id'] = $form_id;
        }

        if (isset($_POST['schedule_id']) && !empty($_POST['schedule_id'])) {
            $where = array('id' => intval($_POST['schedule_id']));
            if ($using_professional_schedule) {
                $where['professional_id'] = $professional_id;
            } else {
                $where['form_id'] = $form_id;
            }
            $wpdb->update($schedules_table, $schedule_data, $where);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Horario actualizado exitosamente.', 'ov-booking-system') . '</p></div>';
        } else {
            $wpdb->insert($schedules_table, $schedule_data);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Horario creado exitosamente.', 'ov-booking-system') . '</p></div>';
        }
    }
}

if (isset($_GET['delete_schedule']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_schedule_' . $_GET['delete_schedule'])) {
    $where = array('id' => intval($_GET['delete_schedule']));
    if ($using_professional_schedule) {
        $where['professional_id'] = $professional_id;
    } else {
        $where['form_id'] = $form_id;
    }
    $wpdb->delete($schedules_table, $where);
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Horario eliminado exitosamente.', 'ov-booking-system') . '</p></div>';
}

// Obtener horarios
$schedules = $using_professional_schedule
    ? $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$schedules_table} WHERE professional_id = %d ORDER BY day_of_week, start_time",
        $professional_id
    ))
    : $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$schedules_table} WHERE form_id = %d AND professional_id IS NULL ORDER BY day_of_week, start_time",
        $form_id
    ));

// Modo edición
$edit_schedule = null;
if (isset($_GET['edit_schedule'])) {
    $edit_schedule = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$schedules_table} WHERE id = %d AND " . ($using_professional_schedule ? 'professional_id = %d' : 'form_id = %d'),
        intval($_GET['edit_schedule']), $using_professional_schedule ? $professional_id : $form_id
    ));
}

$days_of_week = array(
    0 => __('Domingo', 'ov-booking-system'),
    1 => __('Lunes', 'ov-booking-system'),
    2 => __('Martes', 'ov-booking-system'),
    3 => __('Miércoles', 'ov-booking-system'),
    4 => __('Jueves', 'ov-booking-system'),
    5 => __('Viernes', 'ov-booking-system'),
    6 => __('Sábado', 'ov-booking-system')
);
?>

<div class="wrap ov-booking-admin">
    <h1 class="wp-heading-inline">
        <?php printf(__('Horarios - %s', 'ov-booking-system'), esc_html($form->name)); ?>
    </h1>
    <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form_id); ?>" class="page-title-action">
        <?php _e('← Volver a los Ajustes', 'ov-booking-system'); ?>
    </a>
    <hr class="wp-header-end">

    <?php if (!empty($professionals)): ?>
        <form method="get" style="margin: 16px 0;">
            <input type="hidden" name="page" value="ov-booking-schedules">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <label for="ov-professional-select"><strong><?php _e('Profesional', 'ov-booking-system'); ?></strong></label>
            <select id="ov-professional-select" name="professional_id" onchange="this.form.submit()">
                <option value="0"><?php _e('Horario general del formulario (legado)', 'ov-booking-system'); ?></option>
                <?php foreach ($professionals as $prof): ?>
                    <option value="<?php echo esc_attr($prof->id); ?>" <?php selected($professional_id, $prof->id); ?>><?php echo esc_html($prof->name); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (!$using_professional_schedule): ?>
            <div class="notice notice-warning inline"><p><?php _e('Estás viendo el horario general legado. No se copiará automáticamente a los profesionales; selecciona un profesional para administrar su horario individual.', 'ov-booking-system'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ov-schedules-manager">
        <div class="ov-schedules-grid-container">
            <!-- Lista de Horarios -->
            <div class="ov-schedules-list">
                <h2><?php _e('Horarios Configurados', 'ov-booking-system'); ?></h2>
                
                <?php if (empty($schedules)): ?>
                    <div class="ov-empty-state-small">
                        <p><?php _e('No hay horarios configurados. Agrega tu primer horario para empezar a recibir reservas.', 'ov-booking-system'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="ov-schedules-items">
                        <?php 
                        $grouped_schedules = array();
                        foreach ($schedules as $schedule) {
                            $grouped_schedules[$schedule->day_of_week][] = $schedule;
                        }
                        
                        foreach ($days_of_week as $day_num => $day_name): 
                            if (isset($grouped_schedules[$day_num])):
                        ?>
                            <div class="ov-schedule-day-group">
                                <h3><?php echo $day_name; ?></h3>
                                <?php foreach ($grouped_schedules[$day_num] as $schedule): ?>
                                    <div class="ov-schedule-item <?php echo $schedule->active ? 'active' : 'inactive'; ?>">
                                        <div class="ov-schedule-time">
                                            <i class="dashicons dashicons-clock"></i>
                                            <?php echo esc_html($schedule->start_time); ?> - <?php echo esc_html($schedule->end_time); ?>
                                        </div>
                                        <div class="ov-schedule-actions">
                            <a href="?page=ov-booking-schedules&form_id=<?php echo $form_id; ?>&professional_id=<?php echo $professional_id; ?>&edit_schedule=<?php echo $schedule->id; ?>" 
                                               class="button button-small">
                                                <i class="dashicons dashicons-edit"></i>
                                                <?php _e('Editar', 'ov-booking-system'); ?>
                                            </a>
                                            <a href="<?php echo wp_nonce_url('?page=ov-booking-schedules&form_id=' . $form_id . '&professional_id=' . $professional_id . '&delete_schedule=' . $schedule->id, 'delete_schedule_' . $schedule->id); ?>" 
                                               class="button button-small button-link-delete"
                                               onclick="return confirm('<?php _e('¿Estás seguro de eliminar este horario?', 'ov-booking-system'); ?>');">
                                                <i class="dashicons dashicons-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Formulario de Horario -->
            <div class="ov-schedule-form-container">
                <h2><?php echo $edit_schedule ? __('Editar Horario', 'ov-booking-system') : __('Nuevo Horario', 'ov-booking-system'); ?></h2>
                
                <form method="post" action="" class="ov-schedule-form">
                    <?php wp_nonce_field('ov_save_schedule', 'ov_schedule_nonce'); ?>
                    <?php if ($edit_schedule): ?>
                        <input type="hidden" name="schedule_id" value="<?php echo $edit_schedule->id; ?>">
                    <?php endif; ?>

                    <div class="ov-form-row">
                        <label for="day_of_week"><?php _e('Día de la Semana', 'ov-booking-system'); ?> *</label>
                        <select name="day_of_week" id="day_of_week" required>
                            <?php foreach ($days_of_week as $day_num => $day_name): ?>
                                <option value="<?php echo $day_num; ?>" <?php echo ($edit_schedule && $edit_schedule->day_of_week == $day_num) ? 'selected' : ''; ?>>
                                    <?php echo $day_name; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ov-form-row-group">
                        <div class="ov-form-row">
                            <label for="start_time"><?php _e('Hora de Inicio', 'ov-booking-system'); ?> *</label>
                            <input type="time" name="start_time" id="start_time" 
                                   value="<?php echo $edit_schedule ? esc_attr($edit_schedule->start_time) : '09:00'; ?>" required>
                        </div>

                        <div class="ov-form-row">
                            <label for="end_time"><?php _e('Hora de Fin', 'ov-booking-system'); ?> *</label>
                            <input type="time" name="end_time" id="end_time" 
                                   value="<?php echo $edit_schedule ? esc_attr($edit_schedule->end_time) : '18:00'; ?>" required>
                        </div>
                    </div>

                    <div class="ov-form-row">
                        <label>
                            <input type="checkbox" name="active" value="1" 
                                   <?php echo (!$edit_schedule || $edit_schedule->active) ? 'checked' : ''; ?>>
                            <?php _e('Horario activo', 'ov-booking-system'); ?>
                        </label>
                    </div>

                    <div class="ov-form-actions">
                        <button type="submit" name="ov_save_schedule" class="button button-primary button-large">
                            <i class="dashicons dashicons-yes"></i>
                            <?php echo $edit_schedule ? __('Actualizar Horario', 'ov-booking-system') : __('Crear Horario', 'ov-booking-system'); ?>
                        </button>
                        <?php if ($edit_schedule): ?>
                            <a href="?page=ov-booking-schedules&form_id=<?php echo $form_id; ?>&professional_id=<?php echo $professional_id; ?>" class="button button-large">
                                <?php _e('Cancelar', 'ov-booking-system'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="ov-schedule-tips" style="margin-top: 2rem; padding: 1.5rem; background: #f8f9fa; border-radius: 8px;">
                    <h3 style="margin-top: 0;">💡 <?php _e('Consejos', 'ov-booking-system'); ?></h3>
                    <ul style="margin: 0; padding-left: 1.5rem;">
                        <li><?php _e('Puedes crear múltiples horarios para el mismo día (ej: 9-13 y 15-18)', 'ov-booking-system'); ?></li>
                        <li><?php _e('Los horarios inactivos no mostrarán slots disponibles', 'ov-booking-system'); ?></li>
                        <li><?php _e('Los slots se generan según la duración de cada servicio', 'ov-booking-system'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ov-schedules-manager {
    margin-top: 2rem;
}

.ov-schedules-grid-container {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 2rem;
}

.ov-schedules-list,
.ov-schedule-form-container {
    background: white;
    padding: 2rem;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
}

.ov-schedules-list h2,
.ov-schedule-form-container h2 {
    margin-top: 0;
    color: #343a40;
    font-size: 1.3rem;
    margin-bottom: 1.5rem;
}

.ov-schedule-day-group {
    margin-bottom: 1.5rem;
}

.ov-schedule-day-group h3 {
    margin: 0 0 0.75rem 0;
    color: #6c5ce7;
    font-size: 1.1rem;
    font-weight: 700;
}

.ov-schedule-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    border: 2px solid transparent;
    transition: all 0.3s;
}

.ov-schedule-item:hover {
    border-color: #6c5ce7;
    box-shadow: 0 2px 8px rgba(108, 92, 231, 0.1);
}

.ov-schedule-item.inactive {
    opacity: 0.5;
}

.ov-schedule-time {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: #343a40;
}

.ov-schedule-time .dashicons {
    color: #6c5ce7;
}

.ov-schedule-actions {
    display: flex;
    gap: 0.5rem;
}

.ov-schedule-form-container {
    position: sticky;
    top: 32px;
    max-height: calc(100vh - 64px);
    overflow-y: auto;
}

@media (max-width: 1200px) {
    .ov-schedules-grid-container {
        grid-template-columns: 1fr;
    }
    
    .ov-schedule-form-container {
        position: static;
        max-height: none;
    }
}
</style>
