<?php
/**
 * Template: Contenedor Principal del Formulario de Reserva
 * 
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) exit;

// Aplicar colores personalizados con defaults
$colors = is_array($form->colors_config) ? $form->colors_config : array();

// Defaults para colores que pueden no existir en formularios antiguos
$defaults = [
    'primary' => '#6c5ce7',
    'secondary' => '#90a1b9',
    'success' => '#00b894',
    'text' => '#2d3436',
    'warning' => '#f39c12',
    'error' => '#d63031',
    'whatsapp' => '#25d366',
];

$colors = array_merge($defaults, $colors);
foreach ($defaults as $key => $fallback) {
    $colors[$key] = sanitize_hex_color($colors[$key]) ?: $fallback;
}
$form_id = absint($form_id);

$custom_css = "
<style>
.ov-booking-container-{$form_id},
.ov-modal-overlay.ov-booking-container-{$form_id} {
    --ov-bs-primary-color: {$colors['primary']};
    --ov-bs-secondary-color: {$colors['secondary']};
    --ov-bs-success-color: {$colors['success']};
    --ov-bs-text-color: {$colors['text']};
    --ov-bs-warning-color: {$colors['warning']};
    --ov-bs-error-color: {$colors['error']};
    --ov-bs-whatsapp-color: {$colors['whatsapp']};
}
</style> 
";
echo $custom_css;
?>

<div class="ov-booking-container ov-booking-container-<?php echo $form_id; ?>" data-form-id="<?php echo $form_id; ?>">
    
    <!-- Barra de Progreso -->
    <div class="ov-progress-bar">
        <div class="ov-progress-step ov-active" data-step="1">
            <span class="ov-step-number">1</span>
            <span class="ov-step-label"><?php _e('Servicio', 'ov-booking-system'); ?></span>
        </div>
        <?php if ($form->enable_professionals): ?>
        <div class="ov-progress-step" data-step="2">
            <span class="ov-step-number">2</span>
            <span class="ov-step-label"><?php _e('Profesional', 'ov-booking-system'); ?></span>
        </div>
        <?php endif; ?>
        <div class="ov-progress-step" data-step="<?php echo $form->enable_professionals ? '3' : '2'; ?>">
            <span class="ov-step-number"><?php echo $form->enable_professionals ? '3' : '2'; ?></span>
            <span class="ov-step-label"><?php _e('Fecha y Hora', 'ov-booking-system'); ?></span>
        </div>
        <div class="ov-progress-step" data-step="<?php echo $form->enable_professionals ? '4' : '3'; ?>">
            <span class="ov-step-number"><?php echo $form->enable_professionals ? '4' : '3'; ?></span>
            <span class="ov-step-label"><?php _e('Información', 'ov-booking-system'); ?></span>
        </div>
        <div class="ov-progress-step" data-step="<?php echo $form->enable_professionals ? '5' : '4'; ?>">
            <span class="ov-step-number"><?php echo $form->enable_professionals ? '5' : '4'; ?></span>
            <span class="ov-step-label"><?php _e('Confirmación', 'ov-booking-system'); ?></span>
        </div>
    </div>

    <!-- Contenido de los pasos -->
    <div class="ov-steps-container">
        
        <!-- Paso 1: Selección de Servicio -->
        <div class="ov-step ov-step-services ov-active" data-step="1">
            <h2 class="ov-step-title"><?php _e('Selecciona un servicio', 'ov-booking-system'); ?></h2>
            <div class="ov-services-grid">
                <div class="ov-loader">
                    <div class="ov-spinner"></div>
                    <p><?php _e('Cargando servicios...', 'ov-booking-system'); ?></p>
                </div>
            </div>
        </div>

        <?php if ($form->enable_professionals): ?>
        <!-- Paso 2: Selección de Profesional -->
        <div class="ov-step ov-step-professionals" data-step="2">
            <h2 class="ov-step-title"><?php _e('Selecciona un profesional', 'ov-booking-system'); ?></h2>
            <div class="ov-step-content">
                <div class="ov-professionals-grid">
                    <div class="ov-loader">
                        <div class="ov-spinner"></div>
                        <p><?php _e('Cargando profesionales...', 'ov-booking-system'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Paso: Fecha y Hora -->
        <div class="ov-step ov-step-datetime" data-step="<?php echo $form->enable_professionals ? '3' : '2'; ?>">
            <h2 class="ov-step-title"><?php _e('Selecciona fecha y hora', 'ov-booking-system'); ?></h2>
            
            <div class="ov-calendar-container">
                <div class="ov-calendar-header">
                    <button type="button" class="ov-btn ov-btn-icon" id="ov-prev-month" aria-label="<?php esc_attr_e('Mes anterior', 'ov-booking-system'); ?>">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <h3 id="ov-current-month"></h3>
                    <button type="button" class="ov-btn ov-btn-icon" id="ov-next-month" aria-label="<?php esc_attr_e('Mes siguiente', 'ov-booking-system'); ?>">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="ov-calendar-grid" id="ov-calendar-grid"></div>
                <div class="ov-calendar-status" id="ov-calendar-status" role="status" aria-live="polite"></div>
            </div>

            <div class="ov-time-slots-container" id="ov-time-slots" style="display: none;">
                <h3><?php _e('Horarios disponibles', 'ov-booking-system'); ?></h3>
                <div class="ov-time-grid" id="ov-time-grid"></div>
            </div>
        </div>

        <!-- Paso: Información del Cliente -->
        <div class="ov-step ov-step-form" data-step="<?php echo $form->enable_professionals ? '4' : '3'; ?>">
            <h2 class="ov-step-title"><?php _e('Completa tu información', 'ov-booking-system'); ?></h2>
            <form id="ov-customer-form" class="ov-customer-form"></form>
        </div>

        <!-- Paso: Confirmación -->
        <div class="ov-step ov-step-confirmation" data-step="<?php echo $form->enable_professionals ? '5' : '4'; ?>">
            <h2 class="ov-step-title"><?php _e('Confirma tu reserva', 'ov-booking-system'); ?></h2>
            <div class="ov-confirmation-details" id="ov-confirmation-details"></div>
        </div>
    </div>

    <!-- Botones de Navegación -->
    <div class="ov-navigation-buttons">
        <button type="button" class="ov-btn ov-btn-secondary" id="ov-btn-back" style="display: none;">
            <i class="fas fa-arrow-left"></i>
            <?php _e('Atrás', 'ov-booking-system'); ?>
        </button>
        <button type="button" class="ov-btn ov-btn-primary" id="ov-btn-next">
            <?php _e('Siguiente', 'ov-booking-system'); ?>
            <i class="fas fa-arrow-right"></i>
        </button>
    </div>

    <!-- Mensaje de Error/Éxito -->
    <div class="ov-message" id="ov-message" style="display: none;"></div>
</div>
