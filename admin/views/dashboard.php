<?php
/**
 * Vista: Dashboard de OV Booking para OV Manager.
 *
 * El dashboard es el centro operativo de las reservas. Las acciones de gestión
 * se realizan aquí sin enviar al usuario a "Todas las Reservas".
 *
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
$reservation_model = new OV_Reservation();
$form_model = new OV_Form();
$service_model = new OV_Service();
$professional_model = new OV_Professional();
$message_service = new OV_Message_Service();

$forms = $form_model->get_all();
$reservations = array();

foreach ($forms as $form) {
    $form_reservations = $reservation_model->get_by_form($form->id, null, 1000, true);
    if (!empty($form_reservations)) {
        $reservations = array_merge($reservations, $form_reservations);
    }
}

$now_timestamp = current_time('timestamp');
$today = wp_date('Y-m-d', $now_timestamp);

$today_reservations = array();
$upcoming_reservations = array();
$completed_count = 0;
$pending_count = 0;
$cancelled_count = 0;

foreach ($reservations as $reservation) {
    switch ($reservation->status) {
        case 'completed':
            $completed_count++;
            break;
        case 'pending':
            $pending_count++;
            break;
        case 'cancelled':
            $cancelled_count++;
            break;
    }

    if ($reservation->booking_date === $today && $reservation->status !== 'cancelled') {
        $today_reservations[] = $reservation;
    }

    $booking_datetime = $reservation->booking_date . ' ' . ($reservation->booking_time ?: '00:00:00');
    if (
        $reservation->status !== 'cancelled' &&
        $reservation->status !== 'completed' &&
        strtotime($booking_datetime) >= $now_timestamp
    ) {
        $upcoming_reservations[] = $reservation;
    }
}

usort($today_reservations, function ($a, $b) {
    return strcmp($a->booking_time, $b->booking_time);
});

usort($upcoming_reservations, function ($a, $b) {
    return strtotime($a->booking_date . ' ' . $a->booking_time) <=> strtotime($b->booking_date . ' ' . $b->booking_time);
});

$calendar_events = array();

$status_color = array(
    'confirmed' => '#00b894',
    'completed' => '#6c5ce7',
    'cancelled' => '#6c757d',
    'pending' => '#f39c12',
);

$status_label = array(
    'pending' => __('Pendiente', 'ov-booking-system'),
    'confirmed' => __('Confirmada', 'ov-booking-system'),
    'completed' => __('Completada', 'ov-booking-system'),
    'cancelled' => __('Cancelada', 'ov-booking-system'),
);

$reservation_rut = static function($reservation) {
    $customer_data = $reservation->customer_data ?? null;
    if (is_string($customer_data)) {
        $customer_data = json_decode($customer_data, true);
    }
    if (is_array($customer_data) && isset($customer_data['rut']) && is_scalar($customer_data['rut'])) {
        $rut = trim((string) $customer_data['rut']);
        if ($rut !== '') {
            return $rut;
        }
    }
    return isset($reservation->linked_patient_rut) && is_scalar($reservation->linked_patient_rut)
        ? trim((string) $reservation->linked_patient_rut)
        : '';
};

foreach ($reservations as $reservation) {
    $service = $service_model->get($reservation->service_id);
    $professional = !empty($reservation->professional_id)
        ? $professional_model->get($reservation->professional_id)
        : null;

    $service_name = $service ? $service->name : __('Sin servicio', 'ov-booking-system');
    $professional_name = $professional ? $professional->name : __('Cualquiera', 'ov-booking-system');
    $price = !empty($reservation->price)
        ? $reservation->price
        : ($service && !empty($service->price) ? $service->price : '');

    $whatsapp_phone = preg_replace('/\D+/', '', (string) $reservation->customer_phone);
    if (strlen($whatsapp_phone) === 9 && substr($whatsapp_phone, 0, 1) === '9') {
        $whatsapp_phone = '56' . $whatsapp_phone;
    }

    $booking_info = array(
        'booking_id'        => $reservation->booking_id,
        'service_name'      => $service_name,
        'date'              => $reservation->booking_date,
        'time'              => substr($reservation->booking_time, 0, 5),
        'professional_name' => $professional ? $professional->name : null,
        'customer_name'     => $reservation->customer_name,
        'customer_email'    => $reservation->customer_email,
        'customer_phone'    => $reservation->customer_phone,
        'price'             => $price,
        'notes'             => $reservation->notes,
    );

    $whatsapp_message = $message_service->booking_confirmation($booking_info);
    $reminder_message = $message_service->booking_reminder(array(
        'customer_name'     => $reservation->customer_name,
        'date'              => date_i18n(get_option('date_format'), strtotime($reservation->booking_date)),
        'time'              => substr($reservation->booking_time, 0, 5),
        'service_name'      => $service_name,
        'professional_name' => $professional_name,
    ));

    $is_all_day = ($reservation->duration_unit === 'days');

    $event = array(
        'id' => (string) $reservation->id,
        'title' => trim($reservation->customer_name . ' — ' . $service_name),
        'allDay' => $is_all_day,
        'backgroundColor' => $status_color[$reservation->status] ?? '#f39c12',
        'extendedProps' => array(
            'id' => (string) $reservation->id,
            'bookingId' => (string) $reservation->booking_id,
            'customerName' => $reservation->customer_name,
            'customerRut' => $reservation_rut($reservation),
            'customerEmail' => $reservation->customer_email,
            'customerPhone' => $reservation->customer_phone,
            'serviceName' => $service_name,
            'professionalName' => $professional_name,
            'professionalId' => (string) intval($reservation->professional_id),
            'formId' => (string) intval($reservation->form_id),
            'formName' => (function() use ($forms, $reservation) { foreach ($forms as $dashboard_form) { if ((int) $dashboard_form->id === (int) $reservation->form_id) return $dashboard_form->name; } return ''; })(),
            'status' => $reservation->status,
            'notes' => $reservation->notes,
            'price' => $price,
            'date' => $reservation->booking_date,
            'time' => substr($reservation->booking_time, 0, 5),
            'phoneForWhatsapp' => $whatsapp_phone,
            'confirmationMessage' => $whatsapp_message,
            'reminderMessage' => $reminder_message,
        ),
    );

    if ($is_all_day) {
        $event['start'] = $reservation->booking_date;
        $event['end'] = date('Y-m-d', strtotime($reservation->booking_date . ' +' . max(1, intval($reservation->duration)) . ' days'));
    } else {
        $event['start'] = $reservation->booking_date . 'T' . $reservation->booking_time;
        $event['end'] = $reservation->booking_date . 'T' . $reservation->end_time;
    }

    $calendar_events[] = $event;
}

$new_reservation_url = admin_url('admin.php?page=ov-booking-new-reservation');
$export_url = add_query_arg(array('action' => 'ov_booking_export_csv', '_wpnonce' => wp_create_nonce('ov_booking_export_csv')), admin_url('admin-post.php'));
?>

<div class="wrap ov-booking-admin ov-dashboard">
    <div class="ov-dashboard-header">
        <div>
            <h1><?php printf(esc_html__('¡Hola, %s!', 'ov-booking-system'), esc_html($current_user->display_name ?: $current_user->user_login)); ?></h1>
            <p><?php printf(esc_html__('Última actualización: %s', 'ov-booking-system'), esc_html(wp_date(get_option('date_format') . ', ' . get_option('time_format'), $now_timestamp))); ?></p>
        </div>
        <a href="<?php echo esc_url($new_reservation_url); ?>" class="button button-primary ov-dashboard-new-booking">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php _e('Nueva reserva', 'ov-booking-system'); ?>
        </a>
    </div>

    <div class="ov-dashboard-stats">
        <div class="ov-dashboard-stat ov-stat-today"><span class="dashicons dashicons-calendar-alt"></span><div><strong><?php echo esc_html(count($today_reservations)); ?></strong><span><?php _e('Hoy', 'ov-booking-system'); ?></span></div></div>
        <div class="ov-dashboard-stat ov-stat-upcoming"><span class="dashicons dashicons-calendar"></span><div><strong><?php echo esc_html(count($upcoming_reservations)); ?></strong><span><?php _e('Próximas', 'ov-booking-system'); ?></span></div></div>
        <div class="ov-dashboard-stat ov-stat-completed"><span class="dashicons dashicons-yes-alt"></span><div><strong><?php echo esc_html($completed_count); ?></strong><span><?php _e('Completadas', 'ov-booking-system'); ?></span></div></div>
        <div class="ov-dashboard-stat ov-stat-pending"><span class="dashicons dashicons-clock"></span><div><strong><?php echo esc_html($pending_count); ?></strong><span><?php _e('Por confirmar', 'ov-booking-system'); ?></span></div></div>
        <div class="ov-dashboard-stat ov-stat-cancelled"><span class="dashicons dashicons-dismiss"></span><div><strong><?php echo esc_html($cancelled_count); ?></strong><span><?php _e('Canceladas', 'ov-booking-system'); ?></span></div></div>
    </div>

    <section class="ov-dashboard-panel ov-dashboard-today">
        <div class="ov-dashboard-panel-header">
            <h2><?php _e('Agenda de Hoy', 'ov-booking-system'); ?></h2>
            <button type="button" class="button button-secondary ov-scroll-calendar"><?php _e('Ver en calendario', 'ov-booking-system'); ?></button>
        </div>

        <?php if (empty($today_reservations)): ?>
            <div class="ov-dashboard-empty"><span class="dashicons dashicons-calendar-alt"></span><p><?php _e('No hay reservas para hoy', 'ov-booking-system'); ?></p></div>
        <?php else: ?>
            <div class="ov-today-list">
                <?php foreach ($today_reservations as $reservation):
                    $service = $service_model->get($reservation->service_id);
                    $professional = !empty($reservation->professional_id) ? $professional_model->get($reservation->professional_id) : null;
                    $status_class = 'status-' . sanitize_html_class($reservation->status);
                ?>
                    <button type="button" class="ov-today-item ov-dashboard-reservation-trigger" data-reservation-id="<?php echo esc_attr($reservation->id); ?>">
                        <span class="ov-today-time"><?php echo esc_html(substr($reservation->booking_time, 0, 5)); ?></span>
                        <span class="ov-today-main"><strong><?php echo esc_html($reservation->customer_name); ?></strong><small><?php echo esc_html($service ? $service->name : __('Sin servicio', 'ov-booking-system')); ?></small></span>
                        <span class="ov-today-professional"><?php echo esc_html($professional ? $professional->name : __('Cualquiera', 'ov-booking-system')); ?></span>
                        <span class="ov-dashboard-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label[$reservation->status] ?? ucfirst($reservation->status)); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="ov-dashboard-panel ov-dashboard-calendar-panel" id="ov-dashboard-calendar-section">
        <div class="ov-dashboard-panel-header">
            <div>
                <h2><?php _e('Próximas Reservas', 'ov-booking-system'); ?></h2>
                <p><?php _e('Gestiona tus reservas directamente desde el calendario.', 'ov-booking-system'); ?></p>
            </div>
            <div class="ov-dashboard-calendar-actions">
                <button type="button" class="button" id="ov-dashboard-calendar-today"><?php _e('Hoy', 'ov-booking-system'); ?></button>
                <button type="button" class="button" id="ov-dashboard-calendar-week"><?php _e('Esta semana', 'ov-booking-system'); ?></button>
                <button type="button" class="button button-secondary" id="ov-dashboard-calendar-all"><?php _e('Todas', 'ov-booking-system'); ?></button>
                <button type="button" class="button button-secondary" id="ov-dashboard-calendar-fullscreen"><span class="dashicons dashicons-editor-expand"></span> <?php _e('Expandir', 'ov-booking-system'); ?></button>
            </div>
        </div>

        <div class="ov-dashboard-reservation-tools">
            <div class="ov-dashboard-filter-search">
                <span class="dashicons dashicons-search"></span>
                <input type="search" id="ov-dashboard-reservation-search" placeholder="<?php esc_attr_e('Buscar por cliente, teléfono o ID...', 'ov-booking-system'); ?>">
            </div>
            <select id="ov-dashboard-filter-status">
                <option value=""><?php _e('Todos los estados', 'ov-booking-system'); ?></option>
                <?php foreach ($status_label as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select>
            <select id="ov-dashboard-filter-form">
                <option value=""><?php _e('Todos los formularios', 'ov-booking-system'); ?></option>
                <?php foreach ($forms as $form): ?><option value="<?php echo esc_attr($form->id); ?>"><?php echo esc_html($form->name); ?></option><?php endforeach; ?>
            </select>
            <select id="ov-dashboard-filter-professional">
                <option value=""><?php _e('Todos los profesionales', 'ov-booking-system'); ?></option>
                <?php $dashboard_professionals = $professional_model->get_all(); foreach ($dashboard_professionals as $professional): ?><option value="<?php echo esc_attr($professional->id); ?>"><?php echo esc_html($professional->name); ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="button" id="ov-dashboard-clear-filters"><?php _e('Limpiar', 'ov-booking-system'); ?></button>
            <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary" id="ov-dashboard-export-csv"><span class="dashicons dashicons-download"></span> <?php _e('Exportar CSV', 'ov-booking-system'); ?></a>
        </div>

        <div class="ov-dashboard-calendar-summary" id="ov-dashboard-calendar-summary"></div>
        <div id="ov-dashboard-calendar"></div>
    </section>
</div>

<div id="ov-dashboard-reservation-modal" class="ov-dashboard-modal" aria-hidden="true">
    <div class="ov-dashboard-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ov-dashboard-modal-title">
        <div class="ov-dashboard-modal-header">
            <div><span class="ov-dashboard-modal-kicker"><?php _e('Reserva', 'ov-booking-system'); ?></span><h2 id="ov-dashboard-modal-title"></h2></div>
            <button type="button" class="button-link ov-dashboard-modal-close" aria-label="<?php esc_attr_e('Cerrar', 'ov-booking-system'); ?>">&times;</button>
        </div>
        <div class="ov-dashboard-modal-body">
            <div class="ov-dashboard-reservation-meta" id="ov-dashboard-reservation-meta"></div>
            <input type="hidden" id="ov-dashboard-edit-id">
            <div class="ov-dashboard-edit-grid">
                <label><?php _e('Cliente', 'ov-booking-system'); ?><input type="text" id="ov-dashboard-edit-name"></label>
                <label><?php esc_html_e('RUT', 'ov-booking-system'); ?><input type="text" id="ov-dashboard-edit-rut" placeholder="<?php esc_attr_e('No registrado', 'ov-booking-system'); ?>" readonly></label>
                <label><?php _e('Teléfono', 'ov-booking-system'); ?><input type="text" id="ov-dashboard-edit-phone"></label>
                <label><?php _e('Email', 'ov-booking-system'); ?><input type="email" id="ov-dashboard-edit-email"></label>
                <label><?php _e('Estado', 'ov-booking-system'); ?><select id="ov-dashboard-edit-status"><?php foreach ($status_label as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                <label><?php _e('Fecha', 'ov-booking-system'); ?><input type="date" id="ov-dashboard-edit-date"></label>
                <label><?php _e('Hora', 'ov-booking-system'); ?><input type="time" id="ov-dashboard-edit-time"></label>
                <label class="ov-dashboard-edit-full"><?php _e('Notas', 'ov-booking-system'); ?><textarea id="ov-dashboard-edit-notes" rows="2"></textarea></label>
            </div>
        </div>
        <div class="ov-dashboard-modal-footer">
            <div class="ov-dashboard-modal-danger"><button type="button" class="button button-link-delete" id="ov-dashboard-delete-reservation"><?php _e('Borrar reserva', 'ov-booking-system'); ?></button></div>
            <div class="ov-dashboard-modal-actions">
                <button type="button" class="button ov-dashboard-modal-close"><?php _e('Cerrar', 'ov-booking-system'); ?></button>
                <button type="button" class="button" id="ov-dashboard-whatsapp-reminder">🔔 <?php _e('Recordatorio', 'ov-booking-system'); ?></button>
                <button type="button" class="button" id="ov-dashboard-whatsapp-confirmation"><?php _e('WhatsApp', 'ov-booking-system'); ?></button>
                <button type="button" class="button button-primary" id="ov-dashboard-save-reservation"><?php _e('Guardar cambios', 'ov-booking-system'); ?></button>
            </div>
        </div>
    </div>
</div>

<style>
.ov-dashboard-reservation-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:14px 0 8px}.ov-dashboard-filter-search{display:flex;align-items:center;gap:6px;min-width:260px;flex:1}.ov-dashboard-filter-search .dashicons{color:#8c8f94}.ov-dashboard-filter-search input,.ov-dashboard-reservation-tools select{min-height:36px;border:1px solid #dcdcde;border-radius:6px;background:#fff}.ov-dashboard-filter-search input{width:100%;padding:0 10px}.ov-dashboard-reservation-tools select{padding:0 30px 0 9px}.ov-dashboard-calendar-summary{font-size:12px;color:#646970;margin:4px 0 10px}.ov-dashboard-calendar-actions{display:flex;gap:8px;flex-wrap:wrap}.ov-dashboard-calendar-actions .is-active{box-shadow:inset 0 0 0 1px currentColor}.ov-dashboard-calendar-panel.is-fullscreen{position:fixed;z-index:100000;inset:32px 0 0 0;margin:0;border-radius:0;overflow:auto;background:#fff}.ov-dashboard-calendar-panel.is-fullscreen #ov-dashboard-calendar{min-height:calc(100vh - 180px)}.ov-dashboard-calendar-panel.is-fullscreen #ov-dashboard-calendar-fullscreen .dashicons:before{content:"\f158"}.ov-dashboard .ov-calendar-add{background:transparent;border:0;border-radius:4px;color:#2271b1;cursor:pointer;float:right;font-size:11px;line-height:24px;margin:0;padding:0 5px}.ov-dashboard .ov-calendar-add:hover,.ov-dashboard .ov-calendar-add:focus{background:#e7f3ff;color:#135e96;outline:1px solid #72aee6}.ov-dashboard .ov-calendar-event{clear:both}.ov-dashboard-reservation-trigger{width:100%;border:0;background:transparent;text-align:left;font:inherit;cursor:pointer}.ov-dashboard-reservation-trigger:hover{background:#f6f7f7}.ov-dashboard-modal{display:none;position:fixed;z-index:100001;inset:0;background:rgba(0,0,0,.48);padding:5vh 20px;overflow:auto}.ov-dashboard-modal.is-open{display:flex;align-items:flex-start;justify-content:center}.ov-dashboard-modal-dialog{width:min(720px,100%);background:#fff;border-radius:14px;box-shadow:0 20px 70px rgba(0,0,0,.28);overflow:hidden}.ov-dashboard-modal-header{display:flex;justify-content:space-between;gap:20px;padding:22px 24px;border-bottom:1px solid #eee}.ov-dashboard-modal-kicker{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#8c8f94}.ov-dashboard-modal-header h2{margin:3px 0 0;font-size:22px}.ov-dashboard-modal-close{font-size:28px;text-decoration:none;line-height:1}.ov-dashboard-modal-body{padding:22px 24px}.ov-dashboard-reservation-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}.ov-dashboard-meta-card{background:#f6f7f7;border-radius:8px;padding:10px 12px}.ov-dashboard-meta-card small{display:block;color:#8c8f94;font-size:11px}.ov-dashboard-meta-card strong{display:block;margin-top:3px}.ov-dashboard-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.ov-dashboard-edit-grid label{font-weight:600;font-size:12px}.ov-dashboard-edit-grid input,.ov-dashboard-edit-grid select,.ov-dashboard-edit-grid textarea{display:block;width:100%;box-sizing:border-box;margin-top:5px}.ov-dashboard-edit-full{grid-column:1/-1}.ov-dashboard-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 24px;background:#f8f9fa;border-top:1px solid #eee}.ov-dashboard-modal-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}@media(max-width:782px){.ov-dashboard-reservation-meta,.ov-dashboard-edit-grid{grid-template-columns:1fr}.ov-dashboard-edit-full{grid-column:auto}.ov-dashboard-modal{padding:20px 10px}.ov-dashboard-modal-footer{align-items:stretch;flex-direction:column}.ov-dashboard-modal-actions{justify-content:stretch}.ov-dashboard-modal-actions .button{flex:1}.ov-dashboard-calendar-actions{flex-wrap:wrap}}
/* Modal compacto: la cabecera y las acciones permanecen visibles en ventanas bajas. */
.ov-dashboard-modal { box-sizing: border-box; padding: 16px; }
.ov-dashboard-modal.is-open { align-items: center; }
.ov-dashboard-modal-dialog {
    display: flex;
    flex-direction: column;
    width: min(920px, 100%);
    max-height: calc(100vh - 32px);
}
.ov-dashboard-modal-header,
.ov-dashboard-modal-footer { flex: 0 0 auto; }
.ov-dashboard-modal-header { padding: 14px 20px; }
.ov-dashboard-modal-body {
    min-height: 0;
    overflow-y: auto;
    padding: 16px 20px;
}
.ov-dashboard-reservation-meta { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.ov-dashboard-meta-card { min-width: 0; padding: 8px 10px; }
.ov-dashboard-meta-card strong { overflow-wrap: anywhere; }
.ov-dashboard-edit-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 14px; }
.ov-dashboard-edit-grid label { min-width: 0; }
.ov-dashboard-edit-full { grid-column: span 2; }
.ov-dashboard-edit-grid textarea { min-height: 52px; resize: vertical; }
.ov-dashboard-modal-footer { padding: 12px 20px; }
@media (max-width: 780px) {
    .ov-dashboard-edit-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ov-dashboard-edit-full { grid-column: 1 / -1; }
    .ov-dashboard-modal-footer { flex-direction: row; flex-wrap: wrap; align-items: center; }
}
@media (max-width: 600px) {
    .ov-dashboard-modal { padding: 8px; }
    .ov-dashboard-modal-dialog { max-height: calc(100vh - 16px); }
    .ov-dashboard-reservation-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ov-dashboard-edit-grid { grid-template-columns: 1fr; }
    .ov-dashboard-edit-full { grid-column: auto; }
    .ov-dashboard-modal-header,
    .ov-dashboard-modal-body,
    .ov-dashboard-modal-footer { padding-left: 12px; padding-right: 12px; }
    .ov-dashboard-modal-footer { flex-direction: column; align-items: stretch; }
}
</style>

<script>
jQuery(function($) {
    const calendarElement = document.getElementById('ov-dashboard-calendar');
    if (!calendarElement) return;

    const allEvents = <?php echo wp_json_encode($calendar_events); ?>;
    const locale = '<?php echo esc_js(str_replace('_', '-', determine_locale())); ?>';
    const firstDay = <?php echo intval(get_option('start_of_week')); ?>;
    const todayKey = '<?php echo esc_js($today); ?>';
    const newReservationUrl = <?php echo wp_json_encode($new_reservation_url); ?>;
    let currentMonth = new Date(todayKey + 'T12:00:00');
    currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);
    let filteredEvents = allEvents.slice();
    let dateFilter = 'all';
    let activeEvent = null;

    function dateKey(date) { return [date.getFullYear(), String(date.getMonth()+1).padStart(2,'0'), String(date.getDate()).padStart(2,'0')].join('-'); }
    function eventDates(event) {
        const start = new Date(event.start.slice(0,10) + 'T12:00:00');
        if (!event.allDay) return [dateKey(start)];
        const end = new Date(event.end.slice(0,10) + 'T12:00:00');
        const dates = [];
        while (start < end) { dates.push(dateKey(start)); start.setDate(start.getDate()+1); }
        return dates;
    }
    function esc(value){ return $('<div>').text(value || '').html(); }
    function getEvent(id){ return allEvents.find(e => String(e.id) === String(id)); }

    function openNewReservation(date){
        const url = new URL(newReservationUrl);
        const selectedFormId = $('#ov-dashboard-filter-form').val();
        url.searchParams.set('booking_date', date);
        if(selectedFormId){ url.searchParams.set('form_id', selectedFormId); }
        window.location.href = url.toString();
    }

    function getWeekRange(){
        const today = new Date(todayKey + 'T12:00:00');
        const day = today.getDay();
        const diff = (day - firstDay + 7) % 7;
        const start = new Date(today);
        start.setDate(today.getDate() - diff);
        const end = new Date(start);
        end.setDate(start.getDate() + 6);
        return { start: dateKey(start), end: dateKey(end) };
    }

    function eventMatchesDateFilter(event){
        if(dateFilter === 'all') return true;
        const dates = eventDates(event);
        if(dateFilter === 'today') return dates.indexOf(todayKey) !== -1;
        if(dateFilter === 'week'){
            const range = getWeekRange();
            return dates.some(function(key){ return key >= range.start && key <= range.end; });
        }
        return true;
    }

    function updateDateFilterButtons(){
        $('#ov-dashboard-calendar-today').toggleClass('is-active', dateFilter === 'today');
        $('#ov-dashboard-calendar-week').toggleClass('is-active', dateFilter === 'week');
        $('#ov-dashboard-calendar-all').toggleClass('is-active', dateFilter === 'all');
    }

    function applyFilters(){
        const search = ($('#ov-dashboard-reservation-search').val() || '').toLowerCase().trim();
        const status = $('#ov-dashboard-filter-status').val();
        const form = $('#ov-dashboard-filter-form').val();
        const professional = $('#ov-dashboard-filter-professional').val();
        filteredEvents = allEvents.filter(function(event){
            const p = event.extendedProps || {};
            const haystack = [p.customerName,p.customerEmail,p.customerPhone,p.bookingId,p.serviceName].join(' ').toLowerCase();
            return eventMatchesDateFilter(event)
                && (!search || haystack.indexOf(search) !== -1)
                && (!status || p.status === status)
                && (!form || String(p.formId) === String(form))
                && (!professional || String(p.professionalId) === String(professional));
        });
        updateDateFilterButtons();
        updateExportUrl();
        renderCalendar();
    }

    function updateExportUrl(){
        const url = new URL(<?php echo wp_json_encode($export_url); ?>);
        const form = $('#ov-dashboard-filter-form').val();
        const status = $('#ov-dashboard-filter-status').val();
        const professional = $('#ov-dashboard-filter-professional').val();
        const search = $('#ov-dashboard-reservation-search').val() || '';
        if(form) url.searchParams.set('form_filter',form);
        if(status) url.searchParams.set('status_filter',status);
        if(professional) url.searchParams.set('professional_filter',professional);
        if(search) url.searchParams.set('search',search);
        $('#ov-dashboard-export-csv').attr('href',url.toString());
    }

    function openModal(event){
        activeEvent = event;
        const p = event.extendedProps || {};
        $('#ov-dashboard-edit-id').val(p.id);
        $('#ov-dashboard-modal-title').text(p.customerName || '<?php echo esc_js(__('Reserva', 'ov-booking-system')); ?>');
        $('#ov-dashboard-reservation-meta').html(
            '<div class="ov-dashboard-meta-card"><small>Servicio</small><strong>'+esc(p.serviceName)+'</strong></div>'+
            '<div class="ov-dashboard-meta-card"><small>Profesional</small><strong>'+esc(p.professionalName)+'</strong></div>'+
            '<div class="ov-dashboard-meta-card"><small>Reserva</small><strong>#'+esc(p.bookingId || p.id)+'</strong></div>'+
            '<div class="ov-dashboard-meta-card"><small>Precio</small><strong>'+esc(p.price || '—')+'</strong></div>'
        );
        $('#ov-dashboard-edit-name').val(p.customerName || '');
        $('#ov-dashboard-edit-rut').val(p.customerRut || '');
        $('#ov-dashboard-edit-phone').val(p.customerPhone || '');
        $('#ov-dashboard-edit-email').val(p.customerEmail || '');
        $('#ov-dashboard-edit-status').val(p.status || 'pending');
        $('#ov-dashboard-edit-date').val(p.date || '');
        $('#ov-dashboard-edit-time').val(p.time || '');
        $('#ov-dashboard-edit-notes').val(p.notes || '');
        $('#ov-dashboard-whatsapp-confirmation').toggle(!!p.phoneForWhatsapp);
        $('#ov-dashboard-whatsapp-reminder').toggle(!!p.phoneForWhatsapp);
        $('#ov-dashboard-reservation-modal').addClass('is-open').attr('aria-hidden','false');
    }

    function closeModal(){ $('#ov-dashboard-reservation-modal').removeClass('is-open').attr('aria-hidden','true'); activeEvent=null; }

    function renderCalendar(){
        calendarElement.replaceChildren();
        const header = document.createElement('div'); header.className='ov-calendar-header';
        const previous = $('<button type="button" class="button">&lsaquo;</button>').on('click',function(){currentMonth.setMonth(currentMonth.getMonth()-1);renderCalendar();})[0];
        const next = $('<button type="button" class="button">&rsaquo;</button>').on('click',function(){currentMonth.setMonth(currentMonth.getMonth()+1);renderCalendar();})[0];
        const title=document.createElement('h2'); title.className='ov-calendar-title'; title.textContent=currentMonth.toLocaleDateString(locale,{month:'long',year:'numeric'});
        const navigation=document.createElement('div'); navigation.append(previous,next); header.append(navigation,title); calendarElement.append(header);

        const grid=document.createElement('div'); grid.className='ov-calendar-grid';
        const weekdayBase=new Date(2024,0,7+firstDay);
        for(let i=0;i<7;i++){const weekday=document.createElement('div');weekday.className='ov-calendar-weekday';weekday.textContent=new Date(weekdayBase.getFullYear(),weekdayBase.getMonth(),weekdayBase.getDate()+i).toLocaleDateString(locale,{weekday:'short'});grid.append(weekday);}
        const firstOfMonth=new Date(currentMonth.getFullYear(),currentMonth.getMonth(),1); const offset=(firstOfMonth.getDay()-firstDay+7)%7; const gridStart=new Date(firstOfMonth); gridStart.setDate(gridStart.getDate()-offset);
        const eventsByDate={}; filteredEvents.forEach(function(event){eventDates(event).forEach(function(key){(eventsByDate[key]=eventsByDate[key]||[]).push(event);});});
        for(let i=0;i<42;i++){
            const date=new Date(gridStart); date.setDate(gridStart.getDate()+i); const key=dateKey(date); const day=document.createElement('div');
            day.className='ov-calendar-day'+(date.getMonth()!==currentMonth.getMonth()?' is-outside':'')+(key===todayKey?' is-today':'');
            const number=document.createElement('span'); number.className='ov-calendar-date'; number.textContent=date.getDate(); day.append(number);
            const addButton=document.createElement('button'); addButton.type='button'; addButton.className='ov-calendar-add'; addButton.textContent='+ <?php echo esc_js(__('Reserva', 'ov-booking-system')); ?>'; addButton.setAttribute('aria-label','<?php echo esc_js(__('Añadir reserva', 'ov-booking-system')); ?> '+key); addButton.addEventListener('click',function(){openNewReservation(key);}); day.append(addButton);
            day.addEventListener('click',function(clickEvent){if(clickEvent.target===day||clickEvent.target===number){openNewReservation(key);}});
            (eventsByDate[key]||[]).forEach(function(event){const button=document.createElement('button');button.type='button';button.className='ov-calendar-event';button.style.backgroundColor=event.backgroundColor;button.textContent=(event.allDay?'':event.start.slice(11,16)+' · ')+event.title;button.addEventListener('click',function(){openModal(event);});day.append(button);});
            grid.append(day);
        }
        calendarElement.append(grid);
        $('#ov-dashboard-calendar-summary').text(filteredEvents.length+' <?php echo esc_js(__('reservas visibles', 'ov-booking-system')); ?>');
    }

    $('.ov-dashboard-reservation-trigger').on('click',function(){ const event=getEvent($(this).data('reservation-id')); if(event) openModal(event); });
    $('.ov-scroll-calendar').on('click',function(){$('#ov-dashboard-calendar-section')[0].scrollIntoView({behavior:'smooth',block:'start'});});
    $('#ov-dashboard-calendar-today').on('click',function(){
        dateFilter='today';
        currentMonth=new Date(todayKey+'T12:00:00');
        currentMonth=new Date(currentMonth.getFullYear(),currentMonth.getMonth(),1);
        applyFilters();
    });
    $('#ov-dashboard-calendar-week').on('click',function(){
        dateFilter='week';
        const range=getWeekRange();
        currentMonth=new Date(range.start+'T12:00:00');
        currentMonth=new Date(currentMonth.getFullYear(),currentMonth.getMonth(),1);
        applyFilters();
    });
    $('#ov-dashboard-calendar-all').on('click',function(){
        dateFilter='all';
        applyFilters();
    });
    $('#ov-dashboard-calendar-fullscreen').on('click',function(){const panel=$('.ov-dashboard-calendar-panel');panel.toggleClass('is-fullscreen');$(this).contents().filter(function(){return this.nodeType===3;}).last().replaceWith(panel.hasClass('is-fullscreen')?' <?php echo esc_js(__('Cerrar', 'ov-booking-system')); ?>':' <?php echo esc_js(__('Expandir', 'ov-booking-system')); ?>');});
    $('#ov-dashboard-reservation-search,#ov-dashboard-filter-status,#ov-dashboard-filter-form,#ov-dashboard-filter-professional').on('input change',applyFilters);
    $('#ov-dashboard-clear-filters').on('click',function(){
        $('#ov-dashboard-reservation-search').val('');
        $('#ov-dashboard-filter-status,#ov-dashboard-filter-form,#ov-dashboard-filter-professional').val('');
        dateFilter='all';
        applyFilters();
    });
    $('.ov-dashboard-modal-close').on('click',closeModal);
    $('#ov-dashboard-reservation-modal').on('click',function(e){if(e.target===this)closeModal();});
    $(document).on('keydown',function(e){if(e.key==='Escape')closeModal();});

    $('#ov-dashboard-save-reservation').on('click',function(){
        const $btn=$(this).prop('disabled',true).text('<?php echo esc_js(__('Guardando...', 'ov-booking-system')); ?>');
        $.post(ajaxurl,{action:'ov_update_reservation',nonce:ovBookingAdmin.nonce,reservation_id:$('#ov-dashboard-edit-id').val(),customer_name:$('#ov-dashboard-edit-name').val(),customer_email:$('#ov-dashboard-edit-email').val(),customer_phone:$('#ov-dashboard-edit-phone').val(),booking_date:$('#ov-dashboard-edit-date').val(),booking_time:$('#ov-dashboard-edit-time').val(),status:$('#ov-dashboard-edit-status').val(),notes:$('#ov-dashboard-edit-notes').val()}).done(function(r){if(r.success){location.reload();}else{alert((r.data&&r.data.message)||ovBookingAdmin.strings.error_save);}}).fail(function(){alert(ovBookingAdmin.strings.error_save);}).always(function(){$btn.prop('disabled',false).text('<?php echo esc_js(__('Guardar cambios', 'ov-booking-system')); ?>');});
    });

    $('#ov-dashboard-delete-reservation').on('click',function(){
        if(!activeEvent || !confirm(ovBookingAdmin.strings.confirm_delete_reservation)) return;
        $.post(ajaxurl,{action:'ov_delete_reservation',nonce:ovBookingAdmin.nonce,reservation_id:activeEvent.id}).done(function(r){if(r.success){location.reload();}else{alert((r.data&&r.data.message)||ovBookingAdmin.strings.error_delete);}}).fail(function(){alert(ovBookingAdmin.strings.error_delete);});
    });

    $('#ov-dashboard-whatsapp-confirmation').on('click',function(){if(activeEvent){window.OVBooking.openWhatsApp(activeEvent.extendedProps.phoneForWhatsapp,activeEvent.extendedProps.confirmationMessage);}});
    $('#ov-dashboard-whatsapp-reminder').on('click',function(){if(activeEvent){window.OVBooking.openWhatsApp(activeEvent.extendedProps.phoneForWhatsapp,activeEvent.extendedProps.reminderMessage);}});

    renderCalendar();
});
</script>
<?php
