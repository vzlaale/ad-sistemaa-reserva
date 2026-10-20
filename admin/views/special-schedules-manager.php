<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can(OV_Roles::CAPABILITY)) wp_die(__('No tienes permisos para realizar esta acción', 'ov-booking-system'));

global $wpdb;
$form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
if (!$form_id) wp_die(__('Formulario no válido', 'ov-booking-system'));

$table = $wpdb->prefix . 'ov_booking_special_schedules';
$form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ov_booking_forms WHERE id=%d", $form_id));
if (!$form) wp_die(__('Formulario no encontrado', 'ov-booking-system'));
$professional_model = new OV_Professional();
$professionals = $professional_model->get_by_form($form_id, true);
$professional_id = isset($_GET['professional_id']) ? absint($_GET['professional_id']) : 0;
$professional = null;
foreach ($professionals as $candidate) {
    if ((int) $candidate->id === $professional_id) {
        $professional = $candidate;
        break;
    }
}
$using_professional_exceptions = (bool) $professional;
if ($using_professional_exceptions) {
    $table = $wpdb->prefix . 'ov_booking_professional_schedule_exceptions';
}

$message = '';
if (isset($_POST['ov_save_special_schedule']) && check_admin_referer('ov_save_special_schedule', 'ov_special_nonce')) {
    $id = isset($_POST['special_id']) ? absint($_POST['special_id']) : 0;
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $start_date = sanitize_text_field($_POST['start_date'] ?? '');
    $end_date = sanitize_text_field($_POST['end_date'] ?? '');
    $days = isset($_POST['days_of_week']) ? array_map('absint', (array) $_POST['days_of_week']) : array();
    $closed = !empty($_POST['closed_all_day']) ? 1 : 0;
    $start_time = $closed ? null : sanitize_text_field($_POST['start_time'] ?? '');
    $end_time = $closed ? null : sanitize_text_field($_POST['end_time'] ?? '');
    $active = !empty($_POST['active']) ? 1 : 0;

    if (!$name || !$start_date || !$end_date || empty($days) || (!$closed && (!$start_time || !$end_time))) {
        $message = '<div class="notice notice-error"><p>' . esc_html__('Completa todos los campos requeridos.', 'ov-booking-system') . '</p></div>';
    } elseif ($end_date < $start_date) {
        $message = '<div class="notice notice-error"><p>' . esc_html__('La fecha final debe ser igual o posterior a la fecha inicial.', 'ov-booking-system') . '</p></div>';
    } elseif (!$closed && $end_time <= $start_time) {
        $message = '<div class="notice notice-error"><p>' . esc_html__('La hora final debe ser posterior a la hora inicial.', 'ov-booking-system') . '</p></div>';
    } else {
        $data = array(
            'name' => $name,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'days_of_week' => implode(',', array_unique($days)),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'closed_all_day' => $closed,
            'active' => $active,
        );
        if ($using_professional_exceptions) {
            $data['professional_id'] = $professional_id;
        } else {
            $data['form_id'] = $form_id;
            $data['professional_id'] = null;
        }
        $where_scope = array('id' => $id);
        $where_scope[$using_professional_exceptions ? 'professional_id' : 'form_id'] = $using_professional_exceptions ? $professional_id : $form_id;
        if ($id) {
            $wpdb->update($table, $data, $where_scope);
            $message = '<div class="notice notice-success"><p>' . esc_html__('Horario especial actualizado.', 'ov-booking-system') . '</p></div>';
        } else {
            $wpdb->insert($table, $data);
            $message = '<div class="notice notice-success"><p>' . esc_html__('Horario especial creado.', 'ov-booking-system') . '</p></div>';
        }
    }
}

if (isset($_GET['delete_special'])) {
    $id = absint($_GET['delete_special']);
    check_admin_referer('delete_special_' . $id);
    $where_scope = array('id' => $id);
    $where_scope[$using_professional_exceptions ? 'professional_id' : 'form_id'] = $using_professional_exceptions ? $professional_id : $form_id;
    $wpdb->delete($table, $where_scope);
    $message = '<div class="notice notice-success"><p>' . esc_html__('Horario especial eliminado.', 'ov-booking-system') . '</p></div>';
}

$edit = null;
if (isset($_GET['edit_special'])) {
    $edit = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id=%d AND " . ($using_professional_exceptions ? 'professional_id=%d' : 'form_id=%d'),
        absint($_GET['edit_special']), $using_professional_exceptions ? $professional_id : $form_id
    ));
}
$rows = $using_professional_exceptions
    ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE professional_id=%d ORDER BY start_date DESC, start_time ASC", $professional_id))
    : $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE form_id=%d AND professional_id IS NULL ORDER BY start_date DESC, start_time ASC", $form_id));
$day_names = array(0=>__('Domingo','ov-booking-system'),1=>__('Lunes','ov-booking-system'),2=>__('Martes','ov-booking-system'),3=>__('Miércoles','ov-booking-system'),4=>__('Jueves','ov-booking-system'),5=>__('Viernes','ov-booking-system'),6=>__('Sábado','ov-booking-system'));
$selected_days = $edit ? array_map('intval', explode(',', $edit->days_of_week)) : array(1,2,3,4,5);
?>
<div class="wrap ov-booking-admin">
    <h1 class="wp-heading-inline"><?php printf(esc_html__('Horarios especiales — %s', 'ov-booking-system'), esc_html($form->name)); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form_id)); ?>" class="page-title-action">
        <?php esc_html_e('← Volver a los Ajustes', 'ov-booking-system'); ?>
    </a>
    <hr class="wp-header-end">
    <?php if (!empty($professionals)): ?>
        <form method="get" style="margin:16px 0;">
            <input type="hidden" name="page" value="ov-booking-special-schedules">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <label for="ov-special-professional"><strong><?php _e('Profesional', 'ov-booking-system'); ?></strong></label>
            <select id="ov-special-professional" name="professional_id" onchange="this.form.submit()">
                <option value="0"><?php _e('Excepciones generales del formulario (legado)', 'ov-booking-system'); ?></option>
                <?php foreach ($professionals as $prof): ?>
                    <option value="<?php echo esc_attr($prof->id); ?>" <?php selected($professional_id, $prof->id); ?>><?php echo esc_html($prof->name); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if (!$using_professional_exceptions): ?>
            <div class="notice notice-warning inline"><p><?php _e('Las excepciones generales no se copiarán a los profesionales.', 'ov-booking-system'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>
    <p><?php esc_html_e('Crea horarios temporales que sustituyen al horario semanal normal dentro de un rango de fechas. Puedes crear varias franjas para el mismo periodo.', 'ov-booking-system'); ?></p>
    <?php echo wp_kses_post($message); ?>

    <div style="display:grid;grid-template-columns:minmax(0,1.3fr) minmax(320px,.7fr);gap:24px;align-items:start;">
        <div class="postbox" style="padding:18px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Reglas configuradas', 'ov-booking-system'); ?></h2>
            <?php if (!$rows): ?>
                <p><?php esc_html_e('No hay horarios especiales configurados.', 'ov-booking-system'); ?></p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="widefat striped" style="min-width:760px;">
                    <thead><tr><th><?php esc_html_e('Nombre','ov-booking-system'); ?></th><th><?php esc_html_e('Periodo','ov-booking-system'); ?></th><th><?php esc_html_e('Días','ov-booking-system'); ?></th><th><?php esc_html_e('Horario','ov-booking-system'); ?></th><th><?php esc_html_e('Estado','ov-booking-system'); ?></th><th><?php esc_html_e('Acciones','ov-booking-system'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): $days=array_map('intval',explode(',',$row->days_of_week)); ?>
                        <tr>
                            <td><strong><?php echo esc_html($row->name); ?></strong></td>
                            <td><?php echo esc_html($row->start_date . ' → ' . $row->end_date); ?></td>
                            <td><?php echo esc_html(implode(', ', array_map(fn($d)=>$day_names[$d] ?? $d, $days))); ?></td>
                            <td><?php echo $row->closed_all_day ? esc_html__('Cerrado todo el día','ov-booking-system') : esc_html(substr($row->start_time,0,5).' - '.substr($row->end_time,0,5)); ?></td>
                            <td><?php echo $row->active ? esc_html__('Activo','ov-booking-system') : esc_html__('Inactivo','ov-booking-system'); ?></td>
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=ov-booking-special-schedules&form_id='.$form_id.'&professional_id='.$professional_id.'&edit_special='.$row->id)); ?>"><?php esc_html_e('Editar','ov-booking-system'); ?></a> | <a onclick="return confirm('<?php echo esc_js(__('¿Eliminar este horario especial?','ov-booking-system')); ?>')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ov-booking-special-schedules&form_id='.$form_id.'&professional_id='.$professional_id.'&delete_special='.$row->id),'delete_special_'.$row->id)); ?>"><?php esc_html_e('Eliminar','ov-booking-system'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="postbox" style="padding:18px;position:sticky;top:40px;">
            <h2 style="margin-top:0;"><?php echo $edit ? esc_html__('Editar horario especial','ov-booking-system') : esc_html__('Nuevo horario especial','ov-booking-system'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('ov_save_special_schedule','ov_special_nonce'); ?>
                <?php if ($edit): ?><input type="hidden" name="special_id" value="<?php echo absint($edit->id); ?>"><?php endif; ?>
                <p><label><strong><?php esc_html_e('Nombre','ov-booking-system'); ?></strong><br><input class="widefat" type="text" name="name" required value="<?php echo esc_attr($edit->name ?? 'Horario de verano'); ?>"></label></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <p><label><strong><?php esc_html_e('Desde','ov-booking-system'); ?></strong><br><input class="widefat" type="date" name="start_date" required value="<?php echo esc_attr($edit->start_date ?? ''); ?>"></label></p>
                    <p><label><strong><?php esc_html_e('Hasta','ov-booking-system'); ?></strong><br><input class="widefat" type="date" name="end_date" required value="<?php echo esc_attr($edit->end_date ?? ''); ?>"></label></p>
                </div>
                <p><strong><?php esc_html_e('Días de la semana','ov-booking-system'); ?></strong></p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;">
                    <?php foreach($day_names as $n=>$label): ?><label><input type="checkbox" name="days_of_week[]" value="<?php echo $n; ?>" <?php checked(in_array($n,$selected_days,true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?>
                </div>
                <p><label><input id="ov-closed-special" type="checkbox" name="closed_all_day" value="1" <?php checked(!empty($edit->closed_all_day)); ?>> <?php esc_html_e('Cerrar todo el día','ov-booking-system'); ?></label></p>
                <div id="ov-special-hours" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <p><label><strong><?php esc_html_e('Hora inicial','ov-booking-system'); ?></strong><br><input class="widefat" type="time" name="start_time" value="<?php echo esc_attr(isset($edit->start_time)?substr($edit->start_time,0,5):'09:00'); ?>"></label></p>
                    <p><label><strong><?php esc_html_e('Hora final','ov-booking-system'); ?></strong><br><input class="widefat" type="time" name="end_time" value="<?php echo esc_attr(isset($edit->end_time)?substr($edit->end_time,0,5):'14:00'); ?>"></label></p>
                </div>
                <p><label><input type="checkbox" name="active" value="1" <?php checked(!isset($edit->active) || $edit->active); ?>> <?php esc_html_e('Activo','ov-booking-system'); ?></label></p>
                <p><button class="button button-primary" type="submit" name="ov_save_special_schedule"><?php echo $edit ? esc_html__('Actualizar','ov-booking-system') : esc_html__('Crear horario especial','ov-booking-system'); ?></button> <?php if($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ov-booking-special-schedules&form_id='.$form_id.'&professional_id='.$professional_id)); ?>"><?php esc_html_e('Cancelar','ov-booking-system'); ?></a><?php endif; ?></p>
                <p class="description"><?php esc_html_e('Durante este periodo, estas franjas sustituyen el horario semanal normal. Para un horario partido, crea dos reglas con las mismas fechas y días.', 'ov-booking-system'); ?></p>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
 const c=document.getElementById('ov-closed-special'), h=document.getElementById('ov-special-hours');
 function toggle(){ h.style.display=c.checked?'none':'grid'; }
 c.addEventListener('change',toggle); toggle();
});
</script>
