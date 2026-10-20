<?php
/**
 * Vista: Editor de Formulario
 * 
 * @package OV_Booking_System
 */

// Si se accede directamente, abortar
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can(OV_Roles::CAPABILITY)) {
    wp_die(esc_html__('No tienes permisos para editar formularios.', 'ov-booking-system'));
}

$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
$is_new = $form_id === 0;

$form_model = new OV_Form();
$form = $is_new ? null : $form_model->get($form_id);

// Procesar guardado
if (isset($_POST['ov_save_form'], $_POST['ov_form_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ov_form_nonce'])), 'ov_save_form')) {
    $form_data = array(
        'name' => sanitize_text_field($_POST['form_name']),
        'slug' => sanitize_title($_POST['form_slug']),
        'description' => sanitize_textarea_field($_POST['form_description']),
        'timezone' => sanitize_text_field($_POST['timezone']),
        'booking_limit_hours' => intval($_POST['booking_limit_hours']),
        'booking_prefix' => !empty($_POST['booking_prefix']) ? strtoupper(sanitize_text_field($_POST['booking_prefix'])) : 'OV',
        'max_bookings_per_slot' => intval($_POST['max_bookings_per_slot']),
        'max_bookings_per_slot_type' => isset($_POST['max_bookings_per_slot_type']) ? sanitize_text_field($_POST['max_bookings_per_slot_type']) : 'per_professional',
        'week_starts_on' => intval($_POST['week_starts_on']),
        'enable_professionals' => isset($_POST['enable_professionals']) ? 1 : 0,
        'allow_any_professional' => isset($_POST['allow_any_professional']) ? intval($_POST['allow_any_professional']) : 1,
        'colors_config' => array(
            'primary' => sanitize_hex_color($_POST['color_primary']),
            'secondary' => sanitize_hex_color($_POST['color_secondary']),
            'success' => sanitize_hex_color($_POST['color_success']),
            'text' => sanitize_hex_color($_POST['color_text']),
            'warning' => sanitize_hex_color($_POST['color_warning'] ?? '#f39c12'),
            'error' => sanitize_hex_color($_POST['color_error'] ?? '#d63031')
        ),
        'calendar_config' => array(
            'client_add_enabled' => isset($_POST['calendar_client_add']) ? 1 : 0
        ),
        'terms_config' => array(
            'url' => !empty($_POST['terms_url']) ? esc_url_raw($_POST['terms_url']) : '',
            'text' => !empty($_POST['terms_text']) ? wp_kses_post($_POST['terms_text']) : '',
            'required' => isset($_POST['terms_required'])
        )
    );

    if ($is_new) {
        $form_id = $form_model->create($form_data);
        if ($form_id) {
            echo '<div class="notice notice-success"><p>' . __('Formulario creado exitosamente.', 'ov-booking-system') . '</p></div>';
            $form = $form_model->get($form_id);
            $is_new = false;
        }
    } else {
        $form_model->update($form_id, $form_data);
        
        // ✅ Guardar campos del formulario
        if (isset($_POST['fields']) && is_array($_POST['fields'])) {
            $field_model = new OV_Field();
            
            // Obtener IDs actuales
            $existing_fields = $field_model->get_by_form($form_id);
            $existing_ids = array_map(function($f) { return $f->id; }, $existing_fields);
            $keep_ids = array();
            
            // Actualizar/crear campos
            foreach ($_POST['fields'] as $order => $field_data) {
                if (empty($field_data['field_name'])) continue;
                
                $field_info = array(
                    'form_id' => $form_id,
                    'field_name' => sanitize_key($field_data['field_name']),
                    'field_label' => sanitize_text_field($field_data['field_label']),
                    'field_type' => sanitize_text_field($field_data['field_type']),
                    'required' => isset($field_data['required']) ? 1 : 0,
                    'placeholder' => sanitize_text_field($field_data['placeholder'] ?? ''),
                    'display_order' => $order + 1
                );
                
                $field_id = isset($field_data['field_id']) ? intval($field_data['field_id']) : 0;
                
                if ($field_id > 0 && in_array($field_id, $existing_ids)) {
                    // Actualizar existente
                    $field_model->update($field_id, $field_info);
                    $keep_ids[] = $field_id;
                } else {
                    // Crear nuevo
                    $new_id = $field_model->create($field_info);
                    if ($new_id) {
                        $keep_ids[] = $new_id;
                    }
                }
            }
            
            // Eliminar campos que ya no están
            foreach ($existing_ids as $old_id) {
                if (!in_array($old_id, $keep_ids)) {
                    $field_model->delete($old_id);
                }
            }
            
        }
        
        // ✅ FORZAR RECARGA PARA MOSTRAR CAMPOS ACTUALIZADOS
        $form = $form_model->get($form_id);
        
        // Detectar desde qué tab se guardó
        $current_tab = isset($_POST['current_tab']) ? sanitize_text_field($_POST['current_tab']) : 'general';
        
        // Patrón POST/Redirect/GET: evita reenvíos y no interpola datos en JavaScript.
        $redirect_url = add_query_arg(array(
            'page' => 'ov-booking-forms',
            'action' => 'edit',
            'form_id' => (int) $form_id,
            'tab' => sanitize_key($current_tab),
            'saved' => 1,
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }
}

// Valores por defecto
$defaults = array(
    'name' => '',
    'slug' => '',
    'description' => '',
    'timezone' => function_exists('wp_timezone_string') && wp_timezone_string() ? wp_timezone_string() : 'UTC',
    'booking_limit_hours' => 24,
    'booking_prefix' => 'OV',
    'max_bookings_per_slot' => 1,
    'max_bookings_per_slot_type' => 'per_professional',
    'week_starts_on' => 1,
    'enable_professionals' => 0,
    'allow_any_professional' => 1,
    'colors_config' => array(
        'primary' => '#6c5ce7',
        'secondary' => '#90A1B9',
        'success' => '#00b894',
        'text' => '#2d3436',
        'warning' => '#f39c12',
        'error' => '#d63031'
    ),
    'calendar_config' => array(
        'client_add_enabled' => 1
    ),
    'terms_config' => array(
        'url' => '',
        'text' => '',
        'required' => false
    )
);

if ($form) {
    foreach ($defaults as $key => $value) {
        if (isset($form->$key)) {
            // Para arrays, hacer merge para no perder keys nuevos
            if (is_array($value) && is_array($form->$key)) {
                $defaults[$key] = array_merge($value, $form->$key);
            } else {
                $defaults[$key] = $form->$key;
            }
        }
    }
}

$datetime_helper = new OV_DateTime();
$timezones = $datetime_helper->get_timezones();
?>

<div class="wrap ov-booking-admin">
    <h1><?php echo $is_new ? __('Crear Formulario', 'ov-booking-system') : __('Editar Formulario', 'ov-booking-system'); ?></h1>
    
    <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Formulario y campos actualizados exitosamente.', 'ov-booking-system'); ?></p>
        </div>
    <?php endif; ?>
    
    <?php 
    // ✅ Detectar tab activo ANTES de usar
    $active_tab = isset($_GET['tab']) && !empty($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general'; 
    ?>
    
    <form method="post" action="">
        <?php wp_nonce_field('ov_save_form', 'ov_form_nonce'); ?>
        <input type="hidden" name="current_tab" id="ov-current-tab" value="<?php echo $active_tab; ?>">
        
        <div class="ov-form-editor">
            <div class="ov-tabs">
                <a href="#general" class="ov-tab-link <?php echo $active_tab === 'general' ? 'active' : ''; ?>"><?php _e('General', 'ov-booking-system'); ?></a>
                <a href="#fields" class="ov-tab-link <?php echo $active_tab === 'fields' ? 'active' : ''; ?>"><?php _e('Campos Formulario', 'ov-booking-system'); ?></a>
                <a href="#integrations" class="ov-tab-link <?php echo $active_tab === 'integrations' ? 'active' : ''; ?>"><?php _e('Integraciones', 'ov-booking-system'); ?></a>
                <a href="#design" class="ov-tab-link <?php echo $active_tab === 'design' ? 'active' : ''; ?>"><?php _e('Diseño', 'ov-booking-system'); ?></a>
            </div>

            <!-- Tab: General -->
            <div id="general" class="ov-tab-content <?php echo $active_tab === 'general' ? 'active' : ''; ?>">
                <h2><?php _e('Información General', 'ov-booking-system'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="form_name"><?php _e('Nombre del Formulario', 'ov-booking-system'); ?> *</label></th>
                        <td>
                            <input type="text" name="form_name" id="form_name" class="regular-text" 
                                   value="<?php echo esc_attr($defaults['name']); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_slug"><?php _e('Slug', 'ov-booking-system'); ?> *</label></th>
                        <td>
                            <input type="text" name="form_slug" id="form_slug" class="regular-text" 
                                   value="<?php echo esc_attr($defaults['slug']); ?>" required>
                            <p class="description"><?php _e('Identificador único (solo letras minúsculas y guiones)', 'ov-booking-system'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_description"><?php _e('Descripción', 'ov-booking-system'); ?></label></th>
                        <td>
                            <textarea name="form_description" id="form_description" class="large-text" rows="3"><?php echo esc_textarea($defaults['description']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="timezone"><?php _e('Zona horaria del negocio', 'ov-booking-system'); ?></label></th>
                        <td>
                            <select name="timezone" id="timezone" class="regular-text">
                                <?php foreach ($timezones as $tz => $label): ?>
                                    <option value="<?php echo esc_attr($tz); ?>" <?php selected($defaults['timezone'], $tz); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Esta zona horaria se usará para calcular disponibilidad, registrar la fecha de creación y generar eventos de calendario. Si no existe, se usará la zona horaria de WordPress.', 'ov-booking-system'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="booking_limit_hours"><?php _e('Anticipación Mínima (horas)', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="number" name="booking_limit_hours" id="booking_limit_hours" 
                                   value="<?php echo esc_attr($defaults['booking_limit_hours']); ?>" min="0" max="720">
                            <p class="description"><?php _e('Horas de anticipación requeridas para reservar. 0 = sin límite (permite reservas el mismo día)', 'ov-booking-system'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="booking_prefix"><?php _e('Prefijo de Reserva', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="booking_prefix" id="booking_prefix" 
                                   value="<?php echo esc_attr($defaults['booking_prefix']); ?>" 
                                   maxlength="4" style="width: 80px; text-transform: uppercase;">
                            <p class="description"><?php _e('Prefijo para IDs de reserva (ej: OV, AB, XY). Máximo 4 caracteres.', 'ov-booking-system'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="max_bookings_per_slot"><?php _e('Reservas por Horario', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="number" name="max_bookings_per_slot" id="max_bookings_per_slot" 
                                   value="<?php echo esc_attr($defaults['max_bookings_per_slot']); ?>" min="1" max="100">
                            <p class="description"><?php _e('Número máximo de reservas permitidas en el mismo horario', 'ov-booking-system'); ?></p>
                        </td>
                    </tr>
                    <tr id="ov-slot-type-row" style="<?php echo $defaults['enable_professionals'] ? '' : 'display: none;'; ?>">
                        <th><label><?php _e('Tipo de Límite', 'ov-booking-system'); ?></label></th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="radio" name="max_bookings_per_slot_type" value="per_professional" 
                                           <?php checked($defaults['max_bookings_per_slot_type'] ?? 'per_professional', 'per_professional'); ?>>
                                    <strong><?php _e('Por Profesional', 'ov-booking-system'); ?></strong>
                                    <span style="color: #666; font-size: 0.9em;">
                                        - <?php _e('Cada profesional puede tener su propia reserva en el mismo horario', 'ov-booking-system'); ?>
                                    </span>
                                </label>
                                <label style="display: block;">
                                    <input type="radio" name="max_bookings_per_slot_type" value="global" 
                                           <?php checked($defaults['max_bookings_per_slot_type'] ?? 'per_professional', 'global'); ?>>
                                    <strong><?php _e('Global (Sala Compartida)', 'ov-booking-system'); ?></strong>
                                    <span style="color: #666; font-size: 0.9em;">
                                        - <?php _e('Solo se permite el total de reservas sin importar el profesional', 'ov-booking-system'); ?>
                                    </span>
                                </label>
                            </fieldset>
                            <p class="description" style="margin-top: 10px;">
                                <strong><?php _e('Por Profesional:', 'ov-booking-system'); ?></strong> 
                                <?php _e('Ideal para barberías, salones de belleza (cada profesional trabaja independientemente)', 'ov-booking-system'); ?>
                                <br>
                                <strong><?php _e('Global:', 'ov-booking-system'); ?></strong> 
                                <?php _e('Ideal para consultorios médicos, salas compartidas (solo 1 persona en la sala)', 'ov-booking-system'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="week_starts_on"><?php _e('La semana comienza en', 'ov-booking-system'); ?></label></th>
                        <td>
                            <select name="week_starts_on" id="week_starts_on">
                                <option value="0" <?php selected($defaults['week_starts_on'], 0); ?>><?php _e('Domingo', 'ov-booking-system'); ?></option>
                                <option value="1" <?php selected($defaults['week_starts_on'], 1); ?>><?php _e('Lunes', 'ov-booking-system'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="enable_professionals"><?php _e('Habilitar Profesionales', 'ov-booking-system'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_professionals" id="enable_professionals" 
                                       value="1" <?php checked($defaults['enable_professionals'], 1); ?>>
                                <?php _e('Permitir selección de profesional en el formulario', 'ov-booking-system'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr id="ov-any-professional-row" style="<?php echo $defaults['enable_professionals'] ? '' : 'display: none;'; ?>">
                        <th><label for="allow_any_professional"><?php _e('"Cualquier profesional"', 'ov-booking-system'); ?></label></th>
                        <td>
                            <!-- Hidden para enviar 0 cuando el checkbox está desmarcado -->
                            <input type="hidden" name="allow_any_professional" value="0">
                            <label>
                                <input type="checkbox" name="allow_any_professional" id="allow_any_professional" 
                                       value="1" <?php checked($defaults['allow_any_professional'], 1); ?>>
                                <?php _e('Mostrar la opción "Cualquier profesional disponible"', 'ov-booking-system'); ?>
                            </label>
                            <p class="description"><?php _e('Si está desactivado, el cliente deberá elegir un profesional específico.', 'ov-booking-system'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tab: Horarios -->
            <div id="schedule" class="ov-tab-content">
                <h2><?php _e('Configuración de Horarios', 'ov-booking-system'); ?></h2>
                <p><?php _e('Configura los horarios desde la base de datos o próximamente desde aquí.', 'ov-booking-system'); ?></p>
            </div>

            <!-- Tab: Servicios -->
            <div id="services" class="ov-tab-content">
                <h2><?php _e('Servicios', 'ov-booking-system'); ?></h2>
                <p><?php _e('Gestión de servicios próximamente.', 'ov-booking-system'); ?></p>
            </div>

            <!-- Tab: Campos Formulario -->
            <div id="fields" class="ov-tab-content <?php echo $active_tab === 'fields' ? 'active' : ''; ?>">
                <h2><?php _e('Campos del Formulario de Información', 'ov-booking-system'); ?></h2>
                <p class="description">
                    <?php _e('Define qué información solicitar a tus clientes en el paso "Completa tu información". Arrastra para reordenar.', 'ov-booking-system'); ?>
                </p>
                
                <?php if (!$is_new && $form): 
                    $field_model = new OV_Field();
                    $fields = $field_model->get_by_form($form->id, false); // ✅ Sin filtro active
                ?>
                <div class="ov-fields-manager" style="margin-top: 20px;">
                    <div class="ov-table-wrapper">
                    <table class="wp-list-table widefat fixed striped" id="ov-fields-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><?php _e('Orden', 'ov-booking-system'); ?></th>
                                <th style="width: 150px;"><?php _e('Nombre', 'ov-booking-system'); ?></th>
                                <th style="width: 200px;"><?php _e('Etiqueta', 'ov-booking-system'); ?></th>
                                <th style="width: 120px;"><?php _e('Tipo', 'ov-booking-system'); ?></th>
                                <th style="width: 80px; text-align: center;"><?php _e('Requerido', 'ov-booking-system'); ?></th>
                                <th><?php _e('Placeholder', 'ov-booking-system'); ?></th>
                                <th style="width: 80px;"><?php _e('Acciones', 'ov-booking-system'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="ov-fields-body">
                            <?php foreach ($fields as $index => $field): ?>
                            <tr class="ov-field-row">
                                <td style="text-align: center; cursor: move;" class="ov-drag-handle">
                                    <span class="dashicons dashicons-menu"></span> <?php echo $index + 1; ?>
                                </td>
                                <td>
                                    <input type="text" name="fields[<?php echo $index; ?>][field_name]" 
                                           value="<?php echo esc_attr($field->field_name); ?>" 
                                           style="width: 100%;" required>
                                    <input type="hidden" name="fields[<?php echo $index; ?>][field_id]" 
                                           value="<?php echo $field->id; ?>">
                                </td>
                                <td>
                                    <input type="text" name="fields[<?php echo $index; ?>][field_label]" 
                                           value="<?php echo esc_attr($field->field_label); ?>" 
                                           style="width: 100%;" required>
                                </td>
                                <td>
                                    <select name="fields[<?php echo $index; ?>][field_type]" style="width: 100%;">
                                        <option value="text" <?php selected($field->field_type, 'text'); ?>>Texto</option>
                                        <option value="email" <?php selected($field->field_type, 'email'); ?>>Email</option>
                                        <option value="tel" <?php selected($field->field_type, 'tel'); ?>>Teléfono</option>
                                        <option value="number" <?php selected($field->field_type, 'number'); ?>>Número</option>
                                        <option value="textarea" <?php selected($field->field_type, 'textarea'); ?>>Área de texto</option>
                                    </select>
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="fields[<?php echo $index; ?>][required]" 
                                           value="1" <?php checked($field->required, 1); ?>>
                                </td>
                                <td>
                                    <input type="text" name="fields[<?php echo $index; ?>][placeholder]" 
                                           value="<?php echo esc_attr($field->placeholder); ?>" 
                                           style="width: 100%;">
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="button ov-remove-field" style="color: #d63031;">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    
                    <p style="margin-top: 15px;">
                        <button type="button" class="button" id="ov-add-field-btn">
                            <span class="dashicons dashicons-plus-alt"></span> <?php _e('Agregar Campo', 'ov-booking-system'); ?>
                        </button>
                    </p>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        let fieldIndex = <?php echo count($fields); ?>;
                        
                        $('#ov-add-field-btn').on('click', function() {
                            const row = `
                                <tr class="ov-field-row">
                                    <td style="text-align: center; cursor: move;" class="ov-drag-handle">
                                        <span class="dashicons dashicons-menu"></span> ${fieldIndex + 1}
                                    </td>
                                    <td>
                                        <input type="text" name="fields[${fieldIndex}][field_name]" 
                                               style="width: 100%;" required placeholder="nombre_campo">
                                        <input type="hidden" name="fields[${fieldIndex}][field_id]" value="0">
                                    </td>
                                    <td>
                                        <input type="text" name="fields[${fieldIndex}][field_label]" 
                                               style="width: 100%;" required placeholder="Etiqueta">
                                    </td>
                                    <td>
                                        <select name="fields[${fieldIndex}][field_type]" style="width: 100%;">
                                            <option value="text">Texto</option>
                                            <option value="email">Email</option>
                                            <option value="tel">Teléfono</option>
                                            <option value="number">Número</option>
                                            <option value="textarea">Área de texto</option>
                                        </select>
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="fields[${fieldIndex}][required]" value="1">
                                    </td>
                                    <td>
                                        <input type="text" name="fields[${fieldIndex}][placeholder]" 
                                               style="width: 100%;" placeholder="Texto de ejemplo...">
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="button" class="button ov-remove-field" style="color: #d63031;">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            `;
                            $('#ov-fields-body').append(row);
                            fieldIndex++;
                            updateFieldOrder();
                        });
                        
                        $(document).on('click', '.ov-remove-field', function() {
                            if (confirm('¿Eliminar este campo?')) {
                                $(this).closest('tr').remove();
                                updateFieldOrder();
                            }
                        });
                        
                        function updateFieldOrder() {
                            $('#ov-fields-body tr').each(function(index) {
                                const $row = $(this);
                                
                                // Actualizar número visual
                                $row.find('.ov-drag-handle').html(`
                                    <span class="dashicons dashicons-menu"></span> ${index + 1}
                                `);
                                
                                // ✅ Actualizar name attributes de todos los inputs
                                $row.find('input, select, textarea').each(function() {
                                    const $input = $(this);
                                    const name = $input.attr('name');
                                    if (name && name.startsWith('fields[')) {
                                        // Reemplazar el índice: fields[X][...] -> fields[index][...]
                                        const newName = name.replace(/fields\[\d+\]/, `fields[${index}]`);
                                        $input.attr('name', newName);
                                    }
                                });
                            });
                        }
                        
                        $('#ov-fields-body').sortable({
                            handle: '.ov-drag-handle',
                            update: function() {
                                updateFieldOrder();
                            }
                        });
                    });
                    </script>
                </div>
                <?php else: ?>
                    <p><?php _e('Guarda el formulario primero para gestionar los campos.', 'ov-booking-system'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Tab: Integraciones -->
            <div id="integrations" class="ov-tab-content <?php echo $active_tab === 'integrations' ? 'active' : ''; ?>">
                <h2><?php _e('Integraciones', 'ov-booking-system'); ?></h2>
                
                <div class="ov-integration-section">
                    <h3><?php _e('Google Calendar', 'ov-booking-system'); ?></h3>
                    <p class="description">
                        <?php _e('Permite que el cliente añada la cita a Google Calendar o descargue un archivo compatible con Apple Calendar y Outlook.', 'ov-booking-system'); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="calendar_client_add"><?php _e('Calendario del cliente', 'ov-booking-system'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="calendar_client_add" id="calendar_client_add" value="1" <?php checked(!empty($defaults['calendar_config']['client_add_enabled'])); ?>>
                                    <?php _e('Mostrar opciones «Añadir a Google Calendar» y «Descargar .ics» al finalizar la reserva.', 'ov-booking-system'); ?>
                                </label>
                                <p class="description"><?php _e('No requiere OAuth ni acceso a la cuenta del cliente: él decide si guarda la cita.', 'ov-booking-system'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Términos y Condiciones -->
                <div class="ov-integration-section" style="margin-top: 2rem;">
                    <h3><?php _e('Términos y Condiciones', 'ov-booking-system'); ?></h3>
                    <p class="description">
                        <?php _e('Configura los términos y condiciones que los usuarios deben aceptar antes de confirmar su reserva.', 'ov-booking-system'); ?>
                    </p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="terms_url"><?php _e('URL de Términos', 'ov-booking-system'); ?></label></th>
                            <td>
                                <input type="url" name="terms_url" id="terms_url" class="large-text" 
                                       value="<?php echo isset($defaults['terms_config']['url']) ? esc_attr($defaults['terms_config']['url']) : ''; ?>" 
                                       placeholder="https://tusitio.com/terminos-y-condiciones">
                                <p class="description">
                                    <?php _e('URL donde están publicados tus términos y condiciones (opcional).', 'ov-booking-system'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="terms_text"><?php _e('Texto de Términos (HTML)', 'ov-booking-system'); ?></label></th>
                            <td>
                                <textarea name="terms_text" id="terms_text" rows="12" class="large-text"><?php echo isset($defaults['terms_config']['text']) ? esc_textarea($defaults['terms_config']['text']) : ''; ?></textarea>
                                <p class="description">
                                    <?php _e('Escribe aquí tus términos y condiciones en HTML. Se mostrarán en un modal si no proporcionas una URL.', 'ov-booking-system'); ?>
                                </p>
                                <button type="button" class="button" id="ov-load-terms-example" style="margin-top: 0.5rem;">
                                    <i class="dashicons dashicons-editor-paste-text" style="vertical-align: middle;"></i>
                                    <?php _e('Cargar Ejemplo', 'ov-booking-system'); ?>
                                </button>
                                <script>
                                document.getElementById('ov-load-terms-example').addEventListener('click', function() {
                                    const termsExample = `<p>Al utilizar nuestro calendario de reservas, usted acepta las siguientes condiciones de servicio:</p>

<h2>1. Proceso de Reserva</h2>
<p>La reserva se registra cuando usted revisa los datos y pulsa el botón de confirmación. El sistema mostrará un identificador único como comprobante.</p>

<div style="background: #e8f4fd; border-left: 4px solid #0073aa; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
    <strong>Importante:</strong> Seleccionar una fecha y una hora no bloquea el cupo. La disponibilidad se valida nuevamente al confirmar la reserva.
</div>

<h2>2. Disponibilidad del Calendario</h2>
<p>En caso de solicitudes simultáneas para el mismo horario, el cupo se asignará a la primera reserva que el sistema logre registrar.</p>

<h2>3. Política de Cancelación y Cambios</h2>
<p>Las solicitudes de modificación o cancelación deben realizarse por los canales de contacto informados por el negocio con al menos <strong>24 horas</strong> de antelación. Las cancelaciones fuera de este plazo podrían estar sujetas a las condiciones comerciales publicadas.</p>

<h2>4. Datos de Contacto</h2>
<p>Al confirmar la reserva, usted declara que los datos proporcionados son correctos y autoriza su uso para gestionar la cita y enviar comunicaciones relacionadas con ella.</p>

<h2>5. Responsabilidad del Usuario</h2>
<p>Es responsabilidad del cliente asegurarse de que el número de teléfono y los datos proporcionados sean correctos para poder recibir la confirmación de la reserva.</p>

<h2>6. Privacidad de Datos</h2>
<p>Los datos personales proporcionados serán utilizados exclusivamente para la gestión de su reserva y no serán compartidos con terceros sin su consentimiento expreso.</p>

<footer style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; color: #666; font-size: 0.9em;">
    <p>&copy; ${new Date().getFullYear()} [Nombre de tu Negocio] | Última actualización: ${new Date().toLocaleDateString('es-ES', {day: 'numeric', month: 'long', year: 'numeric'})}</p>
</footer>`;
                                    
                                    if (confirm('¿Deseas cargar el ejemplo de términos y condiciones? Esto sobrescribirá el contenido actual.')) {
                                        document.getElementById('terms_text').value = termsExample;
                                    }
                                });
                                </script>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="terms_required"><?php _e('Aceptación Requerida', 'ov-booking-system'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="terms_required" id="terms_required" value="1" 
                                           <?php checked(isset($defaults['terms_config']['required']) && $defaults['terms_config']['required']); ?>>
                                    <?php _e('Los usuarios deben aceptar los términos para completar la reserva', 'ov-booking-system'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Tab: Diseño -->
            <div id="design" class="ov-tab-content <?php echo $active_tab === 'design' ? 'active' : ''; ?>">
                <h2><?php _e('Personalización de Colores', 'ov-booking-system'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="color_primary"><?php _e('Color Primario', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="color_primary" id="color_primary" class="ov-color-picker" 
                                   value="<?php echo esc_attr($defaults['colors_config']['primary']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="color_secondary"><?php _e('Color Secundario', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="color_secondary" id="color_secondary" class="ov-color-picker" 
                                   value="<?php echo esc_attr($defaults['colors_config']['secondary']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="color_success"><?php _e('Color de Éxito', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="color_success" id="color_success" class="ov-color-picker" 
                                   value="<?php echo esc_attr($defaults['colors_config']['success']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="color_text"><?php _e('Color de Texto', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="color_text" id="color_text" class="ov-color-picker" 
                                   value="<?php echo esc_attr($defaults['colors_config']['text']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="color_warning"><?php _e('Color de Advertencia', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="color_warning" id="color_warning" class="ov-color-picker" 
                                   value="<?php echo esc_attr($defaults['colors_config']['warning'] ?? '#f39c12'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="color_error"><?php _e('Color de Error', 'ov-booking-system'); ?></label></th>
                        <td>
                            <input type="text" name="color_error" id="color_error" class="ov-color-picker" 
                                   value="<?php echo esc_attr($defaults['colors_config']['error'] ?? '#d63031'); ?>">
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <p class="submit">
            <input type="submit" name="ov_save_form" class="button button-primary button-large" 
                   value="<?php echo $is_new ? __('Crear Formulario', 'ov-booking-system') : __('Guardar Cambios', 'ov-booking-system'); ?>">
            <a href="<?php echo admin_url('admin.php?page=ov-booking-forms'); ?>" class="button button-large">
                <?php _e('Cancelar', 'ov-booking-system'); ?>
            </a>
        </p>
    </form>
    
    <script>
    jQuery(document).ready(function($) {
        // Actualizar campo oculto cuando se cambia de tab
        $('.ov-tab-link').on('click', function() {
            const tab = $(this).attr('href').substring(1); // Quita el #
            $('#ov-current-tab').val(tab);
        });

        // Mostrar/ocultar "Tipo de Límite" según "Habilitar Profesionales"
        $('#enable_professionals').on('change', function() {
            if ($(this).is(':checked')) {
                $('#ov-slot-type-row').slideDown();
                $('#ov-any-professional-row').slideDown();
            } else {
                $('#ov-slot-type-row').slideUp();
                $('#ov-any-professional-row').slideUp();
            }
        });
    });
    </script>

    <?php if (!$is_new): ?>
        <div class="ov-quick-actions" style="margin-top: 2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
            <a href="<?php echo admin_url('admin.php?page=ov-booking-services&form_id=' . $form_id); ?>" class="button button-large" style="height: auto; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-align: center;">
                <span class="dashicons dashicons-admin-tools" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <strong><?php _e('Gestionar Servicios', 'ov-booking-system'); ?></strong>
                <small style="color: #666;"><?php _e('Agregar, editar y ordenar servicios', 'ov-booking-system'); ?></small>
            </a>
            <a href="<?php echo admin_url('admin.php?page=ov-booking-professionals&form_id=' . $form_id); ?>" class="button button-large" style="height: auto; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-align: center;">
                <span class="dashicons dashicons-groups" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <strong><?php _e('Gestionar Profesionales', 'ov-booking-system'); ?></strong>
                <small style="color: #666;"><?php _e('Agregar y asignar profesionales', 'ov-booking-system'); ?></small>
            </a>
            <a href="<?php echo admin_url('admin.php?page=ov-booking-schedules&form_id=' . $form_id); ?>" class="button button-large" style="height: auto; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-align: center;">
                <span class="dashicons dashicons-calendar-alt" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <strong><?php _e('Configurar Horarios', 'ov-booking-system'); ?></strong>
                <small style="color: #666;"><?php _e('Días y horas disponibles', 'ov-booking-system'); ?></small>
            </a>
            <a href="<?php echo admin_url('admin.php?page=ov-booking-special-schedules&form_id=' . $form_id); ?>" class="button button-large" style="height: auto; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-align: center;">
                <span class="dashicons dashicons-calendar" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <strong><?php _e('Horarios especiales', 'ov-booking-system'); ?></strong>
                <small style="color: #666;"><?php _e('Horarios temporales por rango de fechas', 'ov-booking-system'); ?></small>
            </a>
            <a href="<?php echo admin_url('admin.php?page=ov-booking-holidays&form_id=' . $form_id); ?>" class="button button-large" style="height: auto; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; text-align: center;">
                <span class="dashicons dashicons-warning" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <strong><?php _e('Días Festivos', 'ov-booking-system'); ?></strong>
                <small style="color: #666;"><?php _e('Fechas no disponibles', 'ov-booking-system'); ?></small>
            </a>
        </div>
        
        <div class="ov-shortcode-box">
            <h3><?php _e('Shortcode para este formulario', 'ov-booking-system'); ?></h3>
            <input type="text" readonly value='[ov_booking id="<?php echo $form_id; ?>"]' 
                   onclick="this.select();" class="large-text">
            <p class="description"><?php _e('Copia este shortcode y pégalo en cualquier página o entrada.', 'ov-booking-system'); ?></p>
        </div>
    <?php endif; ?>
</div>
