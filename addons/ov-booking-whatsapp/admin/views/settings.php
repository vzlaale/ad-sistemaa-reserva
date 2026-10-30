<?php
/**
 * Estado y configuración no secreta.
 *
 * @package OV_Booking_WhatsApp
 */

if (!defined('ABSPATH')) exit;

$settings = OV_Booking_WhatsApp_Config::get();
$secrets = OV_Booking_WhatsApp_Config::secrets();
$errors = OV_Booking_WhatsApp_Config::validation_errors();
$notice = isset($_GET['ov_whatsapp_notice']) ? sanitize_key(wp_unslash($_GET['ov_whatsapp_notice'])) : '';
$notice_messages = array(
    'saved' => __('Configuración guardada.', 'ov-booking-whatsapp'),
    'scheduled' => __('Procesamiento de cola programado.', 'ov-booking-whatsapp'),
    'requeued' => __('Envío reencolado.', 'ov-booking-whatsapp'),
    'invalid_settings' => __('Revisa la plantilla, idioma, negocio e índices de botones.', 'ov-booking-whatsapp'),
    'requeue_error' => __('El envío no se pudo reencolar.', 'ov-booking-whatsapp'),
);

global $wpdb;
$outbox_table = $wpdb->prefix . 'ov_booking_whatsapp_outbox';
$rows = $wpdb->get_results("SELECT * FROM {$outbox_table} ORDER BY id DESC LIMIT 50");
$counts_raw = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$outbox_table} GROUP BY status");
$counts = array();
foreach ($counts_raw as $count) $counts[$count->status] = (int) $count->total;
$callback_url = rest_url('ov-booking-whatsapp/v1/webhook');
$webhook_health = get_option(OV_Booking_WhatsApp_Webhook::HEALTH_OPTION, array());
$webhook_health = is_array($webhook_health) ? $webhook_health : array();
?>
<div class="wrap">
    <h1><?php esc_html_e('WhatsApp Cloud API', 'ov-booking-whatsapp'); ?></h1>

    <?php if ($notice && isset($notice_messages[$notice])): ?>
        <div class="notice <?php echo in_array($notice, array('invalid_settings', 'requeue_error'), true) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
            <p><?php echo esc_html($notice_messages[$notice]); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="notice notice-warning"><p>
            <?php echo esc_html(sprintf(__('Configuración pendiente: %s.', 'ov-booking-whatsapp'), implode(', ', $errors))); ?>
        </p></div>
    <?php endif; ?>

    <h2><?php esc_html_e('Conexión con Meta', 'ov-booking-whatsapp'); ?></h2>
    <table class="widefat striped" style="max-width:900px">
        <tbody>
        <?php
        $secret_labels = array(
            'access_token' => 'OV_BOOKING_WHATSAPP_ACCESS_TOKEN',
            'phone_number_id' => 'OV_BOOKING_WHATSAPP_PHONE_NUMBER_ID',
            'verify_token' => 'OV_BOOKING_WHATSAPP_VERIFY_TOKEN',
            'app_secret' => 'OV_BOOKING_WHATSAPP_APP_SECRET',
            'api_version' => 'OV_BOOKING_WHATSAPP_API_VERSION',
        );
        foreach ($secret_labels as $key => $label): ?>
            <tr><th scope="row"><code><?php echo esc_html($label); ?></code></th>
                <td><?php echo $secrets[$key] !== '' ? '<span style="color:#008a20">✓ ' . esc_html__('Configurado', 'ov-booking-whatsapp') . '</span>' : '<span style="color:#b32d2e">✗ ' . esc_html__('Pendiente', 'ov-booking-whatsapp') . '</span>'; ?></td></tr>
        <?php endforeach; ?>
        <tr><th scope="row"><?php esc_html_e('Callback URL', 'ov-booking-whatsapp'); ?></th><td><code><?php echo esc_html($callback_url); ?></code></td></tr>
        </tbody>
    </table>
    <p class="description"><?php esc_html_e('Las credenciales nunca se guardan en WordPress ni se muestran en esta pantalla. Deben definirse en wp-config.php.', 'ov-booking-whatsapp'); ?></p>

    <h2><?php esc_html_e('Plantilla aprobada', 'ov-booking-whatsapp'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ov_whatsapp_save_settings">
        <?php wp_nonce_field('ov_whatsapp_save_settings'); ?>
        <table class="form-table" role="presentation">
            <tr><th><label for="ov-wa-template"><?php esc_html_e('Nombre exacto', 'ov-booking-whatsapp'); ?></label></th>
                <td><input id="ov-wa-template" class="regular-text" name="settings[template_name]" value="<?php echo esc_attr($settings['template_name']); ?>" required pattern="[a-z0-9_]+"></td></tr>
            <tr><th><label for="ov-wa-language"><?php esc_html_e('Código de idioma', 'ov-booking-whatsapp'); ?></label></th>
                <td><input id="ov-wa-language" class="regular-text" name="settings[language_code]" value="<?php echo esc_attr($settings['language_code']); ?>" required placeholder="es o es_CL"></td></tr>
            <tr><th><label for="ov-wa-business"><?php esc_html_e('Nombre del negocio', 'ov-booking-whatsapp'); ?></label></th>
                <td><input id="ov-wa-business" class="regular-text" name="settings[business_name]" value="<?php echo esc_attr($settings['business_name']); ?>" required maxlength="190"></td></tr>
            <tr><th><?php esc_html_e('Variables BODY', 'ov-booking-whatsapp'); ?></th>
                <td><code>{{1}} cliente · {{2}} negocio · {{3}} servicio · {{4}} fecha · {{5}} hora</code></td></tr>
            <tr><th><label for="ov-wa-confirm-index"><?php esc_html_e('Índice botón Confirmar', 'ov-booking-whatsapp'); ?></label></th>
                <td><input id="ov-wa-confirm-index" type="number" min="0" max="9" name="settings[confirm_button_index]" value="<?php echo esc_attr($settings['confirm_button_index']); ?>" required></td></tr>
            <tr><th><label for="ov-wa-cancel-index"><?php esc_html_e('Índice botón Anular', 'ov-booking-whatsapp'); ?></label></th>
                <td><input id="ov-wa-cancel-index" type="number" min="0" max="9" name="settings[cancel_button_index]" value="<?php echo esc_attr($settings['cancel_button_index']); ?>" required></td></tr>
            <tr><th><?php esc_html_e('Envíos', 'ov-booking-whatsapp'); ?></th><td>
                <label><input type="checkbox" name="settings[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?>> <?php esc_html_e('Habilitar nuevos envíos y procesamiento de respuestas', 'ov-booking-whatsapp'); ?></label><br>
                <label><input type="checkbox" name="settings[send_admin_bookings]" value="1" <?php checked(!empty($settings['send_admin_bookings'])); ?>> <?php esc_html_e('Enviar también para reservas creadas desde administración', 'ov-booking-whatsapp'); ?></label>
            </td></tr>
        </table>
        <?php submit_button(__('Guardar configuración', 'ov-booking-whatsapp')); ?>
    </form>

    <h2><?php esc_html_e('Respuesta del cliente', 'ov-booking-whatsapp'); ?></h2>
    <p><?php esc_html_e('CONFIRMAR cambia la reserva pendiente a Confirmada. ANULAR cambia la reserva a Cancelada y libera inmediatamente el horario para una nueva reserva.', 'ov-booking-whatsapp'); ?></p>
    <p class="description"><?php esc_html_e('La reserva cancelada se conserva en el historial para mantener trazabilidad; no bloquea cupos ni horarios.', 'ov-booking-whatsapp'); ?></p>
    <p class="description"><strong><?php esc_html_e('Meta:', 'ov-booking-whatsapp'); ?></strong> <?php esc_html_e('el campo messages del webhook debe estar suscrito para recibir las pulsaciones de los botones.', 'ov-booking-whatsapp'); ?></p>

    <details style="max-width:1100px; margin-top:24px;">
        <summary style="cursor:pointer; font-weight:600;"><?php esc_html_e('Diagnóstico técnico de envíos', 'ov-booking-whatsapp'); ?></summary>
        <div style="margin-top:12px;">
            <p class="description"><?php esc_html_e('Un envío incierto pudo haber sido aceptado por Meta. Comprueba el mensaje en WhatsApp antes de reencolarlo para evitar duplicados.', 'ov-booking-whatsapp'); ?></p>
            <table class="widefat striped" style="max-width:900px; margin-bottom:16px;">
                <tbody>
                    <tr><th><?php esc_html_e('Último POST de Meta', 'ov-booking-whatsapp'); ?></th><td><?php echo esc_html(($webhook_health['last_post_at'] ?? '—') . ' · ' . ($webhook_health['last_post_result'] ?? 'sin_datos')); ?></td></tr>
                    <tr><th><?php esc_html_e('Cabecera de firma', 'ov-booking-whatsapp'); ?></th><td><?php echo !empty($webhook_health['signature_header_present']) ? esc_html__('Recibida', 'ov-booking-whatsapp') : esc_html__('Sin datos o ausente', 'ov-booking-whatsapp'); ?></td></tr>
                    <tr><th><?php esc_html_e('Último botón', 'ov-booking-whatsapp'); ?></th><td><?php echo esc_html(($webhook_health['last_button_at'] ?? '—') . ' · ' . ($webhook_health['last_button_result'] ?? 'sin_datos')); ?></td></tr>
                </tbody>
            </table>
            <p>
                <?php if (empty($counts)): ?>
                    <?php esc_html_e('Todavía no hay envíos.', 'ov-booking-whatsapp'); ?>
                <?php else: ?>
                    <?php foreach ($counts as $status => $total): ?>
                        <strong><?php echo esc_html($status); ?>:</strong> <?php echo esc_html(number_format_i18n($total)); ?>&nbsp;&nbsp;
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px">
                <input type="hidden" name="action" value="ov_whatsapp_process_queue">
                <?php wp_nonce_field('ov_whatsapp_process_queue'); ?>
                <?php submit_button(__('Programar procesamiento ahora', 'ov-booking-whatsapp'), 'secondary', 'submit', false); ?>
            </form>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th><?php esc_html_e('Reserva', 'ov-booking-whatsapp'); ?></th><th><?php esc_html_e('Estado', 'ov-booking-whatsapp'); ?></th><th><?php esc_html_e('Intentos', 'ov-booking-whatsapp'); ?></th><th><?php esc_html_e('Último error', 'ov-booking-whatsapp'); ?></th><th><?php esc_html_e('Actualizado', 'ov-booking-whatsapp'); ?></th><th></th></tr></thead>
                <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="7"><?php esc_html_e('Todavía no hay envíos.', 'ov-booking-whatsapp'); ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->id); ?></td>
                        <td><?php echo esc_html($row->reservation_id); ?></td>
                        <td><?php echo esc_html($row->status); ?><?php echo !empty($row->response_action) ? ' · ' . esc_html($row->response_action) : ''; ?></td>
                        <td><?php echo esc_html($row->attempts); ?></td>
                        <td><?php echo esc_html(trim(($row->last_error_code ?? '') . ' ' . ($row->last_error_message ?? ''))); ?></td>
                        <td><?php echo esc_html($row->updated_at); ?></td>
                        <td><?php if (in_array($row->status, array('failed', 'discarded', 'uncertain'), true)): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ov_whatsapp_requeue"><input type="hidden" name="outbox_id" value="<?php echo esc_attr($row->id); ?>">
                                <?php wp_nonce_field('ov_whatsapp_requeue_' . $row->id); ?>
                                <button class="button button-small"><?php esc_html_e('Reencolar', 'ov-booking-whatsapp'); ?></button>
                            </form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</div>
