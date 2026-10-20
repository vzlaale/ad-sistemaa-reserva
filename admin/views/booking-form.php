<?php
if (!defined('ABSPATH')) exit;

$embedded = !empty($ov_calendar_booking);
$forms = (new OV_Form())->get_all();
$form_ids = array_map('intval', wp_list_pluck($forms, 'id'));
$defaults = array();
$professional_model = new OV_Professional();
// Resolver los IDs reales; no asumir IDs ni elegir entre homónimos.
$medina_by_form = array();
foreach ($professional_model->get_all(true) as $professional) {
    $name = strtolower(trim(preg_replace('/\s+/', ' ', remove_accents($professional->name))));
    if ($name === 'claudio marcelo medina toloza') {
        $medina_by_form[(int) $professional->form_id][] = $professional;
    }
}
foreach ($medina_by_form as $form_id => $matches) {
    if (count($matches) !== 1 || !in_array($form_id, $form_ids, true)) continue;
    $professional = $matches[0];
    $services = array_values(array_filter($professional_model->get_assigned_services($professional->id), function($service) use ($form_id) {
        return !empty($service->active) && (int) $service->form_id === $form_id;
    }));
    $general = array_values(array_filter($services, function($service) {
        return strtolower(trim(remove_accents($service->name))) === 'medicina general';
    }));
    $preferred_service = count($general) === 1 ? $general[0] : (count($services) === 1 ? $services[0] : null);
    $defaults[$form_id] = array('professional' => (int) $professional->id, 'service' => $preferred_service ? (int) $preferred_service->id : 0);
}
$initial_form_id = $embedded ? (int) $ov_calendar_form_id : (isset($_GET['form_id']) ? absint($_GET['form_id']) : 0);
if ($embedded && $ov_calendar_professional_id > 0) {
    $filtered_professional = $professional_model->get($ov_calendar_professional_id);
    if ($filtered_professional && !empty($filtered_professional->active) && in_array((int) $filtered_professional->form_id, $form_ids, true)) {
        if (!$initial_form_id) $initial_form_id = (int) $filtered_professional->form_id;
        if ($initial_form_id === (int) $filtered_professional->form_id) {
            $filtered_services = array_values(array_filter($professional_model->get_assigned_services($filtered_professional->id), function($service) use ($initial_form_id) {
                return !empty($service->active) && (int) $service->form_id === $initial_form_id;
            }));
            $defaults[$initial_form_id] = array('professional' => (int) $filtered_professional->id, 'service' => count($filtered_services) === 1 ? (int) $filtered_services[0]->id : 0);
        }
    }
}
if (!in_array($initial_form_id, $form_ids, true)) {
    $preferred_forms = array_keys($defaults);
    $initial_form_id = count($preferred_forms) === 1 ? $preferred_forms[0] : (count($forms) === 1 ? (int) $forms[0]->id : 0);
}
$initial_date = '';
if (!$embedded && isset($_GET['booking_date']) && is_string($_GET['booking_date'])) {
    $candidate = sanitize_text_field(wp_unslash($_GET['booking_date']));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
        list($year, $month, $day) = array_map('intval', explode('-', $candidate));
        if (checkdate($month, $day, $year)) $initial_date = $candidate;
    }
}
?>
<div class="<?php echo $embedded ? 'ov-quick-booking-content' : 'wrap'; ?>">
    <h1 id="ov-new-booking-title"><?php esc_html_e('Nueva Reserva', 'ov-booking-system'); ?></h1>
    <p><?php esc_html_e('Elige un día y una hora disponible. Luego completa los datos del paciente.', 'ov-booking-system'); ?></p>
    <form id="ov-admin-new-booking" data-defaults="<?php echo esc_attr(wp_json_encode($defaults)); ?>" data-professional="<?php echo $embedded ? absint($ov_calendar_professional_id) : 0; ?>">
        <fieldset id="ov-booking-fields">
        <div class="ov-booking-context">
            <label for="ov-form-id"><?php esc_html_e('Formulario', 'ov-booking-system'); ?>
                <select id="ov-form-id" name="form_id" required>
                    <option value=""><?php esc_html_e('Seleccione...', 'ov-booking-system'); ?></option>
                    <?php foreach ($forms as $form): ?>
                    <option value="<?php echo esc_attr($form->id); ?>" <?php selected($initial_form_id, (int) $form->id); ?>><?php echo esc_html($form->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="ov-service-id"><?php esc_html_e('Servicio', 'ov-booking-system'); ?><select id="ov-service-id" name="service_id" required></select></label>
            <label for="ov-professional-id"><?php esc_html_e('Profesional', 'ov-booking-system'); ?><select id="ov-professional-id" name="professional_id" required></select></label>
            <label for="ov-booking-date"><?php esc_html_e('Fecha', 'ov-booking-system'); ?><input type="date" id="ov-booking-date" required value="<?php echo esc_attr($initial_date); ?>"></label>
        </div>
        <p id="ov-booking-feedback" role="status" aria-live="polite"></p>
        <button type="button" class="button" id="ov-booking-retry" hidden><?php esc_html_e('Reintentar', 'ov-booking-system'); ?></button>
        <div id="ov-booking-slots" class="ov-booking-slots" role="group" aria-label="<?php esc_attr_e('Horas disponibles', 'ov-booking-system'); ?>"></div>
        <input type="hidden" id="ov-booking-time">
        <p id="ov-booking-summary" class="ov-booking-summary" hidden></p>
        <div id="ov-booking-patient" hidden>
            <h2><?php esc_html_e('Datos del paciente', 'ov-booking-system'); ?></h2>
            <div class="ov-booking-context">
                <label for="ov-rut">RUT<input type="text" id="ov-rut" name="rut" required></label>
                <label for="ov-name"><?php esc_html_e('Nombre', 'ov-booking-system'); ?><input type="text" id="ov-name" name="name" required></label>
                <label for="ov-surname"><?php esc_html_e('Apellido', 'ov-booking-system'); ?><input type="text" id="ov-surname" name="surname" required></label>
                <label for="ov-phone"><?php esc_html_e('Teléfono', 'ov-booking-system'); ?><input type="tel" id="ov-phone" name="phone" required></label>
                <label for="ov-email">Email<input type="email" id="ov-email" name="email"></label>
            </div>
            <p id="ov-patient-status" role="status"></p>
            <label for="ov-notes"><?php esc_html_e('Notas', 'ov-booking-system'); ?></label>
            <textarea id="ov-notes" name="notes" rows="3" class="large-text"></textarea>
        </div>
        </fieldset>
        <p class="submit"><button type="submit" class="button button-primary" id="ov-create-booking" disabled><?php esc_html_e('Crear Reserva', 'ov-booking-system'); ?></button></p>
    </form>
</div>
