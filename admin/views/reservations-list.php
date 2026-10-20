<?php
/**
 * Vista: Lista de Reservas
 * 
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) exit;

$reservation_model = new OV_Reservation();
$form_model = new OV_Form();
$service_model = new OV_Service();
$professional_model = new OV_Professional();

// Filtros
$form_filter = isset($_GET['form_filter']) ? intval($_GET['form_filter']) : 0;
$status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
$professional_filter = isset($_GET['professional_filter']) ? intval($_GET['professional_filter']) : 0;
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$order_by = isset($_GET['order_by']) ? sanitize_text_field($_GET['order_by']) : 'created_desc';
$per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 20;
$allowed_per_page = array(20, 50, 100);
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 20;
}
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$view = isset($_GET['view']) && $_GET['view'] === 'calendar' ? 'calendar' : 'list';

// El listado pagina en SQL; el calendario conserva su carga de eventos.
$all_forms = $form_model->get_all();
$reservations = array();
$paged_reservations = array();
$total_reservations = 0;
$total_pages = 1;
$offset = 0;
if ($view === 'list') {
    $page_data = $reservation_model->get_admin_page(array(
        'form_id' => $form_filter,
        'status' => $status_filter,
        'professional_id' => $professional_filter,
        'search' => $search,
        'order_by' => $order_by,
    ), $per_page, $current_page);
    $paged_reservations = $page_data['rows'];
    $reservations = $paged_reservations;
    $total_reservations = $page_data['total'];
    $total_pages = $page_data['pages'];
    $current_page = $page_data['page'];
    $offset = ($current_page - 1) * $per_page;
} else {
    if ($form_filter) {
        $reservations = $reservation_model->get_by_form($form_filter, $status_filter ?: null, 1000);
    } else {
        foreach ($all_forms as $form) {
            $reservations = array_merge($reservations, $reservation_model->get_by_form($form->id, $status_filter ?: null, 1000));
        }
    }
    if ($professional_filter !== 0) {
        $reservations = array_filter($reservations, function($res) use ($professional_filter) {
            return $professional_filter === -1
                ? empty($res->professional_id)
                : (int) $res->professional_id === $professional_filter;
        });
    }
    if ($search !== '') {
        $reservations = array_filter($reservations, function($res) use ($search) {
            return stripos((string) $res->customer_name, $search) !== false
                || stripos((string) $res->customer_email, $search) !== false
                || stripos((string) $res->customer_phone, $search) !== false
                || stripos((string) $res->booking_id, $search) !== false;
        });
    }
    $total_reservations = count($reservations);
}
$pagination_base_args = array(
    'page' => 'ov-booking-reservations',
    'search' => $search,
    'form_filter' => $form_filter,
    'status_filter' => $status_filter,
    'professional_filter' => $professional_filter,
    'order_by' => $order_by,
    'per_page' => $per_page,
);
$pagination_base_args = array_filter($pagination_base_args, function($value) {
    return $value !== '' && $value !== null;
});

function ov_booking_reservations_page_url($page_number, $args) {
    $args['paged'] = max(1, intval($page_number));
    return esc_url(add_query_arg($args, admin_url('admin.php')));
}

$view_base_args = array_filter(array(
    'page' => 'ov-booking-reservations',
    'search' => $search,
    'form_filter' => $form_filter,
    'status_filter' => $status_filter,
    'professional_filter' => $professional_filter,
    'order_by' => $order_by,
    'per_page' => $per_page,
), function($value) {
    return $value !== '' && $value !== null;
});

$calendar_events = array();
$calendar_initial_date = '';
if ($view === 'calendar') {
    $today = current_time('Y-m-d');
    $latest_past_date = '';
    $message_service = new OV_Message_Service();

    foreach ($reservations as $reservation) {
        $service = $service_model->get($reservation->service_id);
        $professional = !empty($reservation->professional_id)
            ? $professional_model->get($reservation->professional_id)
            : null;

        $is_all_day = ($reservation->duration_unit === 'days');
        $whatsapp_phone = preg_replace('/\D+/', '', (string) $reservation->customer_phone);
        if (strlen($whatsapp_phone) === 9 && substr($whatsapp_phone, 0, 1) === '9') {
            $whatsapp_phone = '56' . $whatsapp_phone;
        }

        $booking_info = array(
            'booking_id'        => $reservation->booking_id,
            'service_name'      => $service ? $service->name : '',
            'date'              => $reservation->booking_date,
            'time'              => substr($reservation->booking_time, 0, 5),
            'professional_name' => $professional ? $professional->name : null,
            'customer_name'     => $reservation->customer_name,
            'customer_email'    => $reservation->customer_email,
            'customer_phone'    => $reservation->customer_phone,
            'price'             => !empty($reservation->price)
                ? $reservation->price
                : ($service && !empty($service->price) ? $service->price : ''),
            'notes'             => $reservation->notes,
        );

        $whatsapp_message = $message_service->booking_confirmation($booking_info);
        $reminder_message = $message_service->booking_reminder(array(
            'customer_name'     => $reservation->customer_name,
            'date'              => date_i18n(get_option('date_format'), strtotime($reservation->booking_date)),
            'time'              => substr($reservation->booking_time, 0, 5),
            'service_name'      => $service ? $service->name : '',
            'professional_name' => $professional ? $professional->name : '',
        ));

        $event = array(
        'id' => (string) $reservation->id,
        'title' => trim($reservation->customer_name . ' — ' . ($service ? $service->name : __('Sin servicio', 'ov-booking-system'))),
        'allDay' => $is_all_day,
        'backgroundColor' => array(
            'confirmed' => '#00b894',
            'completed' => '#6c5ce7',
            'cancelled' => '#6c757d',
        )[$reservation->status] ?? '#f39c12',
        'borderColor' => 'transparent',
        'extendedProps' => array(
            'customerName' => $reservation->customer_name,
            'customerEmail' => $reservation->customer_email,
            'customerPhone' => $reservation->customer_phone,
            'serviceName' => $service ? $service->name : '',
            'professionalName' => $professional ? $professional->name : __('Cualquiera', 'ov-booking-system'),
            'status' => $reservation->status,
            'notes' => $reservation->notes,
            'whatsappPhone' => $whatsapp_phone,
            'whatsappMessage' => $whatsapp_message,
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

        if ($reservation->booking_date >= $today) {
            if (empty($calendar_initial_date) || $reservation->booking_date < $calendar_initial_date) {
                $calendar_initial_date = $reservation->booking_date;
            }
        } elseif (empty($latest_past_date) || $reservation->booking_date > $latest_past_date) {
            $latest_past_date = $reservation->booking_date;
        }
    }

    if (empty($calendar_initial_date)) {
        $calendar_initial_date = $latest_past_date;
    }
    if (isset($_GET['calendar_date']) && is_string($_GET['calendar_date'])) {
        $candidate = sanitize_text_field(wp_unslash($_GET['calendar_date']));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            list($year, $month, $day) = array_map('intval', explode('-', $candidate));
            if (checkdate($month, $day, $year)) $calendar_initial_date = $candidate;
        }
    }
}
?>

<div class="wrap ov-booking-admin">
    <h1>
        <?php _e('Reservas', 'ov-booking-system'); ?>

        <a href="<?php echo admin_url('admin.php?page=ov-booking-new-reservation'); ?>"
        class="page-title-action">
            <?php _e('Nueva Reserva', 'ov-booking-system'); ?>
        </a>

        <a href="#" id="ov-export-csv-btn"
        data-url="<?php echo esc_url(add_query_arg(array('action' => 'ov_booking_export_csv', '_wpnonce' => wp_create_nonce('ov_booking_export_csv'), 'form_filter' => $form_filter, 'status_filter' => $status_filter, 'professional_filter' => $professional_filter, 'search' => $search), admin_url('admin-post.php'))); ?>"
        class="button button-secondary"
        style="margin-left:10px;">
            <span class="dashicons dashicons-download"></span>
            <?php _e('Exportar CSV', 'ov-booking-system'); ?>
        </a>

        <button type="button"
                id="ov-clear-reservations-btn"
                class="button button-link-delete"
                style="margin-left:10px;">
            <?php _e('Borrar reservas', 'ov-booking-system'); ?>
        </button>
    </h1>
    
    <script>
    jQuery(document).ready(function($) {
        $('#ov-export-csv-btn').on('click', function(e) {
            e.preventDefault();
            const url = $(this).data('url');
            
            if (confirm(ovBookingAdmin.strings.confirm_export_csv)) {
                window.location.href = url;
            }
        });
    });
    </script>

    <div class="ov-filters" style="margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <form method="get" action="" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; flex: 1;">
            <input type="hidden" name="page" value="ov-booking-reservations">
            <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
            
            <input type="text" name="search" placeholder="<?php _e('Buscar por nombre, email o teléfono...', 'ov-booking-system'); ?>" 
                   value="<?php echo isset($_GET['search']) ? esc_attr($_GET['search']) : ''; ?>" 
                   style="min-width: 250px;">
            
            <select name="form_filter">
                <option value="0"><?php _e('Todos los formularios', 'ov-booking-system'); ?></option>
                <?php foreach ($all_forms as $form): ?>
                    <option value="<?php echo $form->id; ?>" <?php selected($form_filter, $form->id); ?>>
                        <?php echo esc_html($form->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <select name="status_filter">
                <option value=""><?php _e('Todos los estados', 'ov-booking-system'); ?></option>
                <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('Pendiente', 'ov-booking-system'); ?></option>
                <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>><?php _e('Confirmada', 'ov-booking-system'); ?></option>
                <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php _e('Completada', 'ov-booking-system'); ?></option>
                <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>><?php _e('Cancelada', 'ov-booking-system'); ?></option>
            </select>
            
            <?php
            // Filtro de profesionales
            $all_professionals = $professional_model->get_all();
            $professional_filter = isset($_GET['professional_filter']) ? intval($_GET['professional_filter']) : 0;
            if (!empty($all_professionals)):
            ?>
            <select name="professional_filter">
                <option value="0"><?php _e('Todos los profesionales', 'ov-booking-system'); ?></option>
                <option value="-1" <?php selected($professional_filter, -1); ?>><?php _e('Sin profesional (Cualquiera)', 'ov-booking-system'); ?></option>
                <?php foreach ($all_professionals as $prof): ?>
                    <option value="<?php echo $prof->id; ?>" <?php selected($professional_filter, $prof->id); ?>>
                        <?php echo esc_html($prof->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            
            <select name="order_by">
                <option value="created_desc" <?php selected(isset($_GET['order_by']) ? $_GET['order_by'] : 'created_desc', 'created_desc'); ?>><?php _e('Fecha creación (reciente)', 'ov-booking-system'); ?></option>
                <option value="created_asc" <?php selected(isset($_GET['order_by']) ? $_GET['order_by'] : '', 'created_asc'); ?>><?php _e('Fecha creación (antigua)', 'ov-booking-system'); ?></option>
                <option value="booking_desc" <?php selected(isset($_GET['order_by']) ? $_GET['order_by'] : '', 'booking_desc'); ?>><?php _e('Fecha reserva (reciente)', 'ov-booking-system'); ?></option>
                <option value="booking_asc" <?php selected(isset($_GET['order_by']) ? $_GET['order_by'] : '', 'booking_asc'); ?>><?php _e('Fecha reserva (antigua)', 'ov-booking-system'); ?></option>
            </select>
            
            <select name="per_page" aria-label="<?php esc_attr_e('Reservas por página', 'ov-booking-system'); ?>">
                <option value="20" <?php selected($per_page, 20); ?>>20 <?php _e('por página', 'ov-booking-system'); ?></option>
                <option value="50" <?php selected($per_page, 50); ?>>50 <?php _e('por página', 'ov-booking-system'); ?></option>
                <option value="100" <?php selected($per_page, 100); ?>>100 <?php _e('por página', 'ov-booking-system'); ?></option>
            </select>
            <input type="hidden" name="paged" value="1">
            
            <input type="submit" class="button" value="<?php _e('Filtrar', 'ov-booking-system'); ?>">
            <a href="<?php echo admin_url('admin.php?page=ov-booking-reservations'); ?>" class="button"><?php _e('Limpiar', 'ov-booking-system'); ?></a>
        </form>
    </div>

    <p class="ov-reservation-view-switch" style="margin: 16px 0;">
        <a class="button <?php echo $view === 'list' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($view_base_args, array('view' => 'list')), admin_url('admin.php'))); ?>">
            <?php _e('Lista', 'ov-booking-system'); ?>
        </a>
        <a class="button <?php echo $view === 'calendar' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($view_base_args, array('view' => 'calendar')), admin_url('admin.php'))); ?>">
            <?php _e('Calendario', 'ov-booking-system'); ?>
        </a>
    </p>

    <?php if ($view === 'calendar'): ?>
        <div id="ov-reservations-calendar"></div>
        <dialog id="ov-quick-booking-dialog" aria-labelledby="ov-new-booking-title">
            <button type="button" class="button" id="ov-quick-booking-close"><?php esc_html_e('Cerrar', 'ov-booking-system'); ?></button>
            <?php
            // Aislar las variables de la vista compartida del listado de reservas.
            (function($ov_calendar_form_id, $ov_calendar_professional_id) {
                $ov_calendar_booking = true;
                require __DIR__ . '/booking-form.php';
            })($form_filter, max(0, $professional_filter));
            ?>
        </dialog>
    <?php elseif (empty($reservations)): ?>
        <p><?php _e('No hay reservas para mostrar.', 'ov-booking-system'); ?></p>
    <?php else: ?>
        <div class="ov-reservations-pagination ov-reservations-pagination-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin:12px 0; flex-wrap:wrap;">
            <span>
                <?php printf(
                    esc_html__('Mostrando %1$d-%2$d de %3$d reservas', 'ov-booking-system'),
                    $total_reservations ? $offset + 1 : 0,
                    min($offset + $per_page, $total_reservations),
                    $total_reservations
                ); ?>
            </span>
            <?php if ($total_pages > 1): ?>
                <span class="ov-pagination-links" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <a class="button <?php echo $current_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $current_page > 1 ? ov_booking_reservations_page_url($current_page - 1, $pagination_base_args) : '#'; ?>"><?php _e('Anterior', 'ov-booking-system'); ?></a>
                    <span><?php printf(esc_html__('Página %1$d de %2$d', 'ov-booking-system'), $current_page, $total_pages); ?></span>
                    <a class="button <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $current_page < $total_pages ? ov_booking_reservations_page_url($current_page + 1, $pagination_base_args) : '#'; ?>"><?php _e('Siguiente', 'ov-booking-system'); ?></a>
                </span>
            <?php endif; ?>
        </div>
        <div class="ov-table-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Cliente', 'ov-booking-system'); ?></th>
                    <th><?php _e('Servicio', 'ov-booking-system'); ?></th>
                    <th><?php _e('Precio', 'ov-booking-system'); ?></th>
                    <th><?php _e('Profesional', 'ov-booking-system'); ?></th>
                    <th><?php _e('Fecha', 'ov-booking-system'); ?></th>
                    <th><?php _e('Hora', 'ov-booking-system'); ?></th>
                    <th><?php _e('Teléfono', 'ov-booking-system'); ?></th>
                    <th><?php _e('Notas', 'ov-booking-system'); ?></th>
                    <th><?php _e('Datos Adicionales', 'ov-booking-system'); ?></th>
                    <th><?php _e('Estado', 'ov-booking-system'); ?></th>
                    <th><?php _e('Acciones', 'ov-booking-system'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paged_reservations as $reservation): 
                    $service = $service_model->get($reservation->service_id);
                    $res_form = $form_model->get($reservation->form_id);
                    
                    // Obtener profesional si existe
                    $professional = null;
                    if ($reservation->professional_id) {
                        $professional = $professional_model->get($reservation->professional_id);
                    }
                ?>
                    <tr>
                        
                        <td>
                            <strong><?php echo esc_html($reservation->customer_name); ?></strong>

                            <?php
                            $rut = '';

                            if (!empty($reservation->customer_data)) {
                                $customer_data = is_string($reservation->customer_data)
                                    ? json_decode($reservation->customer_data, true)
                                    : $reservation->customer_data;

                                if (is_array($customer_data) && isset($customer_data['rut']) && is_scalar($customer_data['rut'])) {
                                    $rut = trim((string) $customer_data['rut']);
                                }
                            }
                            if ($rut === '' && isset($reservation->linked_patient_rut) && is_scalar($reservation->linked_patient_rut)) {
                                $rut = trim((string) $reservation->linked_patient_rut);
                            }
                            ?>

                            <?php if (!empty($rut)): ?>
                                <br>
                                <small style="color: #555;">
                                    RUT: <?php echo esc_html($rut); ?>
                                </small>
                            <?php endif; ?>

                            <?php if ($reservation->customer_email): ?>
                                <br>
                                <small><?php echo esc_html($reservation->customer_email); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $service ? esc_html($service->name) : '-'; ?></td>
                        <td>
                            <?php 
                            if (!empty($reservation->price)) {
                                echo esc_html($reservation->price);
                            } elseif ($service && !empty($service->price)) {
                                echo esc_html($service->price);
                            } else {
                                echo '<em style="color: #999;">-</em>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if ($professional) {
                                echo esc_html($professional->name);
                            } else {
                                echo '<em style="color: #999;">' . __('Cualquiera', 'ov-booking-system') . '</em>';
                            }
                            ?>
                        </td>
                        <td><?php echo date_i18n(get_option('date_format'), strtotime($reservation->booking_date)); ?></td>
                        <td><?php echo esc_html($reservation->booking_time); ?></td>
                        <td><?php echo esc_html($reservation->customer_phone); ?></td>
                        <td style="max-width: 180px; font-size: 11px;">
                            <?php echo !empty($reservation->notes) ? nl2br(esc_html($reservation->notes)) : '<em style="color: #999;">-</em>'; ?>
                        </td>
                        <td style="max-width: 200px; font-size: 11px;">
                            <?php 
                            if ($reservation->customer_data) {
                                $customer_data = is_string($reservation->customer_data) 
                                    ? json_decode($reservation->customer_data, true) 
                                    : $reservation->customer_data;
                                
                                // Campos estándar que NO se muestran aquí
                                $standard_fields = array('name', 'surname', 'rut', 'email', 'phone', 'notes');
                                
                                // Filtrar solo campos adicionales
                                $additional = array();
                                foreach ($customer_data as $key => $value) {
                                    if (!in_array($key, $standard_fields) && !empty($value)) {
                                        $additional[$key] = $value;
                                    }
                                }
                                
                                if (!empty($additional)) {
                                    echo '<ul style="margin: 0; padding-left: 15px;">';
                                    foreach ($additional as $key => $value) {
                                        echo '<li><strong>' . esc_html(ucfirst($key)) . ':</strong> ' . esc_html($value) . '</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo '<em style="color: #999;">-</em>';
                                }
                            } else {
                                echo '<em style="color: #999;">-</em>';
                            }
                            ?>
                        </td>
                        <td>
                            <select class="ov-status-select" data-reservation-id="<?php echo $reservation->id; ?>" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #ddd;">
                                <?php 
                                $statuses = array(
                                    'pending' => __('Pendiente', 'ov-booking-system'),
                                    'confirmed' => __('Confirmada', 'ov-booking-system'),
                                    'completed' => __('Completada', 'ov-booking-system'),
                                    'cancelled' => __('Cancelada', 'ov-booking-system')
                                );
                                foreach ($statuses as $status_key => $status_label) {
                                    $selected = ($reservation->status === $status_key) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($status_key) . '" ' . $selected . '>' . esc_html($status_label) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                        
                        <td>

                            <!-- Editar -->
                            <button
                                type="button"
                                class="button button-small ov-edit-reservation"
                                data-id="<?php echo esc_attr($reservation->id); ?>"
                                data-name="<?php echo esc_attr($reservation->customer_name); ?>"
                                data-rut="<?php echo esc_attr($rut); ?>"
                                data-email="<?php echo esc_attr($reservation->customer_email); ?>"
                                data-phone="<?php echo esc_attr($reservation->customer_phone); ?>"
                                data-date="<?php echo esc_attr($reservation->booking_date); ?>"
                                data-time="<?php echo esc_attr(substr($reservation->booking_time, 0, 5)); ?>"
                                data-status="<?php echo esc_attr($reservation->status); ?>"
                                data-notes="<?php echo esc_attr($reservation->notes); ?>"
                            >
                                <?php _e('Editar', 'ov-booking-system'); ?>
                            </button>


                            <?php
                            /**
                             * Preparar WhatsApp
                             */

                            $whatsapp_phone = preg_replace('/\D+/', '', $reservation->customer_phone);

                            // Si viene como número chileno local: 9XXXXXXXX
                            if (
                                strlen($whatsapp_phone) === 9 &&
                                substr($whatsapp_phone, 0, 1) === '9'
                            ) {
                                $whatsapp_phone = '56' . $whatsapp_phone;
                            }

                            /**
                             * Servicio de mensajes
                             */
                            $message_service = new OV_Message_Service();


                            /**
                             * ==========================================
                             * MENSAJE DE CONFIRMACIÓN
                             * ==========================================
                             */

                            $professional_display = $professional
                                ? $professional->name
                                : null;

                            $booking_info = array(
                                'booking_id'        => $reservation->booking_id,
                                'service_name'      => $service ? $service->name : '',
                                'date'              => $reservation->booking_date,
                                'time'              => substr($reservation->booking_time, 0, 5),
                                'professional_name' => $professional_display,
                                'customer_name'     => $reservation->customer_name,
                                'customer_email'    => $reservation->customer_email,
                                'customer_phone'    => $reservation->customer_phone,
                                'price'             => !empty($reservation->price)
                                    ? $reservation->price
                                    : ($service && !empty($service->price)
                                        ? $service->price
                                        : ''),
                                'notes'             => $reservation->notes
                            );

                            $whatsapp_message = $message_service->booking_confirmation($booking_info);

                            if (!empty($whatsapp_phone)): ?>

                            <!-- WhatsApp: confirmación -->
                            <button
                                type="button"
                                class="button button-small ov-whatsapp-reservation"
                                data-phone="<?php echo esc_attr($whatsapp_phone); ?>"
                                data-message="<?php echo esc_attr($whatsapp_message); ?>"
                                style="margin-top:4px; color:#128C7E; border-color:#128C7E;"
                            >
                                <?php _e('WhatsApp', 'ov-booking-system'); ?>
                            </button>

                        <?php endif; ?>


                            <?php
                            /**
                             * ==========================================
                             * MENSAJE DE RECORDATORIO
                             * ==========================================
                             */

                            if (!empty($reservation->customer_phone)):

                                $professional_name = $professional
                                    ? $professional->name
                                    : '';

                                $reminder_message = $message_service->booking_reminder(array(
                                    'customer_name'     => $reservation->customer_name,
                                    'date'              => date_i18n(
                                        get_option('date_format'),
                                        strtotime($reservation->booking_date)
                                    ),
                                    'time'              => substr($reservation->booking_time, 0, 5),
                                    'service_name'      => $service ? $service->name : '',
                                    'professional_name' => $professional_name
                                ));
                                ?>

                                <!-- WhatsApp: recordatorio -->
                                <button
                                    type="button"
                                    class="button button-small ov-whatsapp-reminder"
                                    data-phone="<?php echo esc_attr($reservation->customer_phone); ?>"
                                    data-message="<?php echo esc_attr($reminder_message); ?>"
                                    style="margin-top:4px; color:#128C7E; border-color:#128C7E;"
                                >
                                    🔔 Recordatorio
                                </button>

                            <?php endif; ?>


                            <!-- Borrar -->
                            <button
                                type="button"
                                class="button button-small button-link-delete ov-delete-reservation"
                                data-id="<?php echo esc_attr($reservation->id); ?>"
                                style="margin-top:4px;"
                            >
                                <?php _e('Borrar', 'ov-booking-system'); ?>
                            </button>

                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <div class="ov-reservations-pagination ov-reservations-pagination-bottom" style="display:flex; justify-content:flex-end; align-items:center; gap:8px; margin:16px 0; flex-wrap:wrap;">
                <a class="button <?php echo $current_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $current_page > 1 ? ov_booking_reservations_page_url($current_page - 1, $pagination_base_args) : '#'; ?>"><?php _e('Anterior', 'ov-booking-system'); ?></a>
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                if ($start_page > 1) {
                    echo '<a class="button" href="' . ov_booking_reservations_page_url(1, $pagination_base_args) . '">1</a>';
                    if ($start_page > 2) echo '<span>…</span>';
                }
                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i === $current_page) {
                        echo '<span class="button button-primary" aria-current="page">' . esc_html($i) . '</span>';
                    } else {
                        echo '<a class="button" href="' . ov_booking_reservations_page_url($i, $pagination_base_args) . '">' . esc_html($i) . '</a>';
                    }
                }
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) echo '<span>…</span>';
                    echo '<a class="button" href="' . ov_booking_reservations_page_url($total_pages, $pagination_base_args) . '">' . esc_html($total_pages) . '</a>';
                }
                ?>
                <a class="button <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $current_page < $total_pages ? ov_booking_reservations_page_url($current_page + 1, $pagination_base_args) : '#'; ?>"><?php _e('Siguiente', 'ov-booking-system'); ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <style>
        .ov-reservations-pagination .button.disabled { opacity: .45; pointer-events: none; cursor: default; }
        .ov-table-wrapper { overflow-x: auto; }
        .ov-table-wrapper table { min-width: 1320px; }
        #ov-reservations-calendar { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; max-width: 1400px; }
        .ov-calendar-header { align-items: center; display: flex; gap: 10px; justify-content: space-between; margin-bottom: 16px; }
        .ov-calendar-title { font-size: 1.25em; font-weight: 600; margin: 0; text-transform: capitalize; }
        .ov-calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(120px, 1fr)); overflow-x: auto; }
        .ov-calendar-weekday, .ov-calendar-day { border-right: 1px solid #e2e4e7; border-top: 1px solid #e2e4e7; min-width: 120px; }
        .ov-calendar-weekday { background: #f6f7f7; font-size: 12px; font-weight: 600; padding: 8px; text-align: center; }
        .ov-calendar-day { min-height: 120px; padding: 7px; position: relative; }
        .ov-calendar-day:nth-child(7n) { border-right: 0; }
        .ov-calendar-day.is-outside { background: #f9f9f9; color: #8c8f94; }
        .ov-calendar-day.is-today .ov-calendar-date { background: #2271b1; border-radius: 50%; color: #fff; display: inline-flex; height: 24px; align-items: center; justify-content: center; width: 24px; }
        .ov-calendar-date { display: inline-block; font-size: 12px; font-weight: 600; margin-bottom: 5px; }
        .ov-calendar-add { background: transparent; border: 0; border-radius: 4px; color: #2271b1; cursor: pointer; float: right; font-size: 11px; line-height: 24px; margin: 0; padding: 0 5px; }
        .ov-calendar-add:hover, .ov-calendar-add:focus { background: #e7f3ff; color: #135e96; outline: 1px solid #72aee6; }
        .ov-calendar-event { border: 0; border-radius: 3px; clear: both; color: #fff; cursor: pointer; display: block; font-size: 11px; line-height: 1.35; margin: 3px 0; overflow: hidden; padding: 4px 5px; text-align: left; text-overflow: ellipsis; white-space: nowrap; width: 100%; }
        .ov-edit-modal-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; margin-bottom: 0; }
        .ov-edit-modal-actions-primary, .ov-edit-modal-actions-messaging { display: flex; flex-wrap: wrap; gap: 8px; }
        .ov-whatsapp-modal-action { color: #128c7e !important; border-color: #128c7e !important; }
        @media (max-width: 782px) { .ov-calendar-grid { grid-template-columns: repeat(7, minmax(100px, 1fr)); } }
    </style>

    <div id="ov-edit-reservation-modal" style="display:none; position:fixed; z-index:100000; inset:0; background:rgba(0,0,0,.45);">
        <div style="background:#fff; max-width:560px; margin:8vh auto; padding:24px; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <h2 style="margin-top:0;"><?php _e('Editar reserva', 'ov-booking-system'); ?></h2>
            <input type="hidden" id="ov-edit-reservation-id">
            <p><label><?php _e('Cliente', 'ov-booking-system'); ?><br><input type="text" id="ov-edit-customer-name" class="regular-text" style="width:100%;"></label></p>
            <p><label><?php esc_html_e('RUT', 'ov-booking-system'); ?><br><input type="text" id="ov-edit-customer-rut" class="regular-text" style="width:100%;" placeholder="<?php esc_attr_e('No registrado', 'ov-booking-system'); ?>" readonly></label></p>
            <p><label><?php _e('Email', 'ov-booking-system'); ?><br><input type="email" id="ov-edit-customer-email" class="regular-text" style="width:100%;"></label></p>
            <p><label><?php _e('Teléfono', 'ov-booking-system'); ?><br><input type="text" id="ov-edit-customer-phone" class="regular-text" style="width:100%;"></label></p>
            <div style="display:flex; gap:12px;">
                <p style="flex:1;"><label><?php _e('Fecha', 'ov-booking-system'); ?><br><input type="date" id="ov-edit-booking-date" style="width:100%;"></label></p>
                <p style="flex:1;"><label><?php _e('Hora', 'ov-booking-system'); ?><br><input type="time" id="ov-edit-booking-time" style="width:100%;"></label></p>
            </div>
            <p><label><?php _e('Estado', 'ov-booking-system'); ?><br>
                <select id="ov-edit-status" style="width:100%;">
                    <option value="pending"><?php _e('Pendiente', 'ov-booking-system'); ?></option>
                    <option value="confirmed"><?php _e('Confirmada', 'ov-booking-system'); ?></option>
                    <option value="completed"><?php _e('Completada', 'ov-booking-system'); ?></option>
                    <option value="cancelled"><?php _e('Cancelada', 'ov-booking-system'); ?></option>
                </select>
            </label></p>
            <p><label><?php _e('Notas', 'ov-booking-system'); ?><br><textarea id="ov-edit-notes" rows="4" style="width:100%;"></textarea></label></p>
            <p class="ov-edit-modal-actions">
                <span class="ov-edit-modal-actions-messaging">
                    <button type="button" class="button ov-whatsapp-modal-action" id="ov-edit-whatsapp" hidden><?php _e('WhatsApp', 'ov-booking-system'); ?></button>
                    <button type="button" class="button ov-whatsapp-modal-action" id="ov-edit-reminder" hidden>🔔 <?php _e('Recordatorio', 'ov-booking-system'); ?></button>
                </span>
                <span class="ov-edit-modal-actions-primary">
                    <button type="button" class="button" id="ov-edit-cancel"><?php _e('Cancelar', 'ov-booking-system'); ?></button>
                    <button type="button" class="button button-primary" id="ov-edit-save"><?php _e('Guardar cambios', 'ov-booking-system'); ?></button>
                </span>
            </p>
        </div>
    </div>

<script>
    jQuery(function($){

        window.ovBookingOpenReservationEditor = function(element) {
            const data = element.dataset ? element.dataset : $(element).data();
            const $modal = $('#ov-edit-reservation-modal');
            $('#ov-edit-reservation-id').val(data.id);
            $('#ov-edit-customer-name').val(data.name);
            $('#ov-edit-customer-rut').val(data.rut || '');
            $('#ov-edit-customer-email').val(data.email);
            $('#ov-edit-customer-phone').val(data.phone);
            $('#ov-edit-booking-date').val(data.date);
            $('#ov-edit-booking-time').val(data.time);
            $('#ov-edit-status').val(data.status);
            $('#ov-edit-notes').val(data.notes);
            $modal.data({
                whatsappPhone: data.whatsappPhone || '',
                whatsappMessage: data.whatsappMessage || '',
                reminderMessage: data.reminderMessage || ''
            });
            $('#ov-edit-whatsapp').prop('hidden', !(data.whatsappPhone && data.whatsappMessage));
            $('#ov-edit-reminder').prop('hidden', !(data.whatsappPhone && data.reminderMessage));
            $modal.fadeIn(120);
        };
        $('.ov-edit-reservation').on('click', function(){
            window.ovBookingOpenReservationEditor(this);
        });
        $('#ov-edit-cancel, #ov-edit-reservation-modal').on('click', function(e){
            if (e.target.id === 'ov-edit-reservation-modal' || e.target.id === 'ov-edit-cancel') $('#ov-edit-reservation-modal').fadeOut(120);
        });
        $('#ov-edit-save').on('click', function(){
            const $btn = $(this).prop('disabled', true).text('Guardando...');
            $.post(ajaxurl, {
                action: 'ov_update_reservation',
                nonce: ovBookingAdmin.nonce,
                reservation_id: $('#ov-edit-reservation-id').val(),
                customer_name: $('#ov-edit-customer-name').val(),
                customer_email: $('#ov-edit-customer-email').val(),
                customer_phone: $('#ov-edit-customer-phone').val(),
                booking_date: $('#ov-edit-booking-date').val(),
                booking_time: $('#ov-edit-booking-time').val(),
                status: $('#ov-edit-status').val(),
                notes: $('#ov-edit-notes').val()
            }).done(function(r){
                if (r.success) location.reload();
                else alert((r.data && r.data.message) || ovBookingAdmin.strings.error_save);
            }).always(function(){ $btn.prop('disabled', false).text(ovBookingAdmin.strings.save_changes); });
        });
        $('.ov-delete-reservation').on('click', function(){
            if (!confirm(ovBookingAdmin.strings.confirm_delete_reservation)) return;
            $.post(ajaxurl, { action:'ov_delete_reservation', nonce:ovBookingAdmin.nonce, reservation_id:$(this).data('id') })
             .done(function(r){ if (r.success) location.reload(); else alert((r.data && r.data.message) || ovBookingAdmin.strings.error_delete); });
        });
        $('#ov-clear-reservations-btn').on('click', function(){
            if (!confirm(ovBookingAdmin.strings.confirm_clear_reservations)) return;
            $.post(ajaxurl, { action:'ov_clear_reservations', nonce:ovBookingAdmin.nonce, form_id:'<?php echo esc_js($form_filter); ?>', status:'<?php echo esc_js($status_filter); ?>' })
             .done(function(r){ if (r.success) location.reload(); else alert((r.data && r.data.message) || ovBookingAdmin.strings.error_clear); });
        });


        /**
         * WhatsApp: confirmación
         */
        $('.ov-whatsapp-reservation').on('click', function(){

            const button = $(this);

            window.OVBooking.openWhatsApp(
                button.data('phone'),
                button.data('message')
            );

        });

        /**
         * WhatsApp: recordatorio
         */
        $('.ov-whatsapp-reminder').on('click', function(){

            const button = $(this);

            window.OVBooking.openWhatsApp(
                button.data('phone'),
                button.data('message')
            );

        });

        $('#ov-edit-whatsapp, #ov-edit-reminder').on('click', function() {
            const modalData = $('#ov-edit-reservation-modal').data();
            const message = this.id === 'ov-edit-reminder'
                ? modalData.reminderMessage
                : modalData.whatsappMessage;

            if (modalData.whatsappPhone && message && window.OVBooking && typeof window.OVBooking.openWhatsApp === 'function') {
                window.OVBooking.openWhatsApp(modalData.whatsappPhone, message);
            }
        });

    });
</script>

<?php if ($view === 'calendar'): ?>
<script>
    jQuery(function($) {
        const calendarElement = document.getElementById('ov-reservations-calendar');
        if (!calendarElement) return;

        const events = <?php echo wp_json_encode($calendar_events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const locale = '<?php echo esc_js(str_replace('_', '-', determine_locale())); ?>';
        const firstDay = <?php echo intval(get_option('start_of_week')); ?>;
        const initialDate = '<?php echo esc_js($calendar_initial_date); ?>';
        const today = new Date();
        let currentMonth = initialDate ? new Date(initialDate + 'T12:00:00') : new Date();
        currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);

        function dateKey(date) {
            return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
        }

        function eventDates(event) {
            const start = new Date(event.start.slice(0, 10) + 'T12:00:00');
            if (!event.allDay) return [dateKey(start)];

            const end = new Date(event.end.slice(0, 10) + 'T12:00:00');
            const dates = [];
            while (start < end) {
                dates.push(dateKey(start));
                start.setDate(start.getDate() + 1);
            }
            return dates;
        }

        function openEditor(event) {
            window.ovBookingOpenReservationEditor({
                dataset: {
                    id: event.id,
                    name: event.extendedProps.customerName,
                    email: event.extendedProps.customerEmail,
                    phone: event.extendedProps.customerPhone,
                    date: event.start.slice(0, 10),
                    time: event.allDay ? '' : event.start.slice(11, 16),
                    status: event.extendedProps.status,
                    notes: event.extendedProps.notes,
                    whatsappPhone: event.extendedProps.whatsappPhone,
                    whatsappMessage: event.extendedProps.whatsappMessage,
                    reminderMessage: event.extendedProps.reminderMessage
                }
            });
        }

        function openNewReservation(date) {
            if (window.ovBookingOpenNewReservation) window.ovBookingOpenNewReservation(date);
        }

        $(document).on('ov:booking-created', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('calendar_date', dateKey(currentMonth));
            window.location.assign(url.toString());
        });

        function renderCalendar() {
            calendarElement.replaceChildren();
            const header = document.createElement('div');
            header.className = 'ov-calendar-header';
            const previous = $('<button type="button" class="button">&lsaquo;</button>').on('click', function() { currentMonth.setMonth(currentMonth.getMonth() - 1); renderCalendar(); })[0];
            const next = $('<button type="button" class="button">&rsaquo;</button>').on('click', function() { currentMonth.setMonth(currentMonth.getMonth() + 1); renderCalendar(); })[0];
            const title = document.createElement('h2');
            title.className = 'ov-calendar-title';
            title.textContent = currentMonth.toLocaleDateString(locale, { month: 'long', year: 'numeric' });
            const navigation = document.createElement('div');
            navigation.append(previous, next);
            header.append(navigation, title);
            calendarElement.append(header);

            const grid = document.createElement('div');
            grid.className = 'ov-calendar-grid';
            const weekdayBase = new Date(2024, 0, 7 + firstDay);
            for (let i = 0; i < 7; i++) {
                const weekday = document.createElement('div');
                weekday.className = 'ov-calendar-weekday';
                weekday.textContent = new Date(weekdayBase.getFullYear(), weekdayBase.getMonth(), weekdayBase.getDate() + i).toLocaleDateString(locale, { weekday: 'short' });
                grid.append(weekday);
            }

            const firstOfMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);
            const offset = (firstOfMonth.getDay() - firstDay + 7) % 7;
            const gridStart = new Date(firstOfMonth);
            gridStart.setDate(gridStart.getDate() - offset);
            const eventsByDate = {};
            events.forEach(function(event) {
                eventDates(event).forEach(function(key) {
                    (eventsByDate[key] = eventsByDate[key] || []).push(event);
                });
            });

            for (let i = 0; i < 42; i++) {
                const date = new Date(gridStart);
                date.setDate(gridStart.getDate() + i);
                const key = dateKey(date);
                const day = document.createElement('div');
                day.className = 'ov-calendar-day' + (date.getMonth() !== currentMonth.getMonth() ? ' is-outside' : '') + (key === dateKey(today) ? ' is-today' : '');
                const number = document.createElement('span');
                number.className = 'ov-calendar-date';
                number.textContent = date.getDate();
                day.append(number);
                const addButton = document.createElement('button');
                addButton.type = 'button';
                addButton.className = 'ov-calendar-add';
                addButton.textContent = '+ <?php echo esc_js(__('Reserva', 'ov-booking-system')); ?>';
                addButton.setAttribute('aria-label', '<?php echo esc_js(__('Añadir reserva', 'ov-booking-system')); ?> ' + key);
                addButton.addEventListener('click', function() { openNewReservation(key); });
                day.append(addButton);
                day.addEventListener('click', function(clickEvent) {
                    if (clickEvent.target === day || clickEvent.target === number) {
                        openNewReservation(key);
                    }
                });
                (eventsByDate[key] || []).sort(function(a, b) { return a.start.localeCompare(b.start); }).forEach(function(event) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ov-calendar-event';
                    button.style.backgroundColor = event.backgroundColor;
                    button.textContent = (event.allDay ? '' : event.start.slice(11, 16) + ' · ') + event.title;
                    button.addEventListener('click', function() { openEditor(event); });
                    day.append(button);
                });
                grid.append(day);
            }
            calendarElement.append(grid);
        }

        renderCalendar();
    });
</script>
<?php endif; ?>

</div>
