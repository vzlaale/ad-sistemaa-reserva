<?php
/**
 * Vista: Gestor de Campos del Formulario
 * Similar a la gestión por hoja de cálculo
 * 
 * @package OV_Booking_System
 */

if (!defined('ABSPATH')) exit;

$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    wp_die(__('ID de formulario inválido', 'ov-booking-system'));
}

$form_model = new OV_Form();
$form = $form_model->get($form_id);

if (!$form) {
    wp_die(__('Formulario no encontrado', 'ov-booking-system'));
}

$field_model = new OV_Field();
$fields = $field_model->get_by_form($form_id);

// Guardar cambios
if (isset($_POST['ov_save_fields']) && wp_verify_nonce($_POST['ov_fields_nonce'], 'ov_save_fields')) {
    // Eliminar todos los campos existentes
    foreach ($fields as $field) {
        $field_model->delete($field->id);
    }
    
    // Crear nuevos campos desde el formulario
    if (isset($_POST['fields']) && is_array($_POST['fields'])) {
        foreach ($_POST['fields'] as $order => $field_data) {
            if (empty($field_data['field_name'])) continue;
            
            $field_model->create(array(
                'form_id' => $form_id,
                'field_name' => sanitize_key($field_data['field_name']),
                'field_label' => sanitize_text_field($field_data['field_label']),
                'field_type' => sanitize_text_field($field_data['field_type']),
                'required' => isset($field_data['required']) ? 1 : 0,
                'placeholder' => sanitize_text_field($field_data['placeholder']),
                'display_order' => $order + 1
            ));
        }
    }
    
    echo '<div class="notice notice-success"><p>' . __('Campos actualizados exitosamente', 'ov-booking-system') . '</p></div>';
    
    // Recargar campos
    $fields = $field_model->get_by_form($form_id);
}

// Tipos de campo disponibles
$field_types = array(
    'text' => 'Texto',
    'email' => 'Email',
    'tel' => 'Teléfono',
    'number' => 'Número',
    'textarea' => 'Área de texto',
    'select' => 'Selección',
    'checkbox' => 'Casilla'
);
?>

<div class="wrap ov-booking-admin">
    <h1><?php printf(__('Gestor de Campos - %s', 'ov-booking-system'), esc_html($form->name)); ?></h1>
    
    <p class="description">
        <?php _e('Define qué información quieres solicitar a tus clientes. Puedes agregar, editar y ordenar campos personalizados.', 'ov-booking-system'); ?>
    </p>

    <form method="post" action="" id="ov-fields-manager">
        <?php wp_nonce_field('ov_save_fields', 'ov_fields_nonce'); ?>
        
        <div class="ov-fields-table-wrapper" style="margin: 20px 0; background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px;">
            <table class="wp-list-table widefat fixed striped" id="ov-fields-table">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e('Orden', 'ov-booking-system'); ?></th>
                        <th style="width: 150px;"><?php _e('Nombre Campo', 'ov-booking-system'); ?></th>
                        <th style="width: 200px;"><?php _e('Etiqueta', 'ov-booking-system'); ?></th>
                        <th style="width: 120px;"><?php _e('Tipo', 'ov-booking-system'); ?></th>
                        <th style="width: 100px;"><?php _e('Requerido', 'ov-booking-system'); ?></th>
                        <th><?php _e('Placeholder', 'ov-booking-system'); ?></th>
                        <th style="width: 80px;"><?php _e('Acciones', 'ov-booking-system'); ?></th>
                    </tr>
                </thead>
                <tbody id="ov-fields-body">
                    <?php foreach ($fields as $index => $field): ?>
                    <tr class="ov-field-row" data-index="<?php echo $index; ?>">
                        <td class="ov-drag-handle" style="cursor: move; text-align: center;">
                            <span class="dashicons dashicons-menu"></span>
                            <?php echo $index + 1; ?>
                        </td>
                        <td>
                            <input type="text" name="fields[<?php echo $index; ?>][field_name]" 
                                   value="<?php echo esc_attr($field->field_name); ?>" 
                                   class="regular-text" required
                                   pattern="[a-z_]+" 
                                   title="Solo letras minúsculas y guiones bajos">
                        </td>
                        <td>
                            <input type="text" name="fields[<?php echo $index; ?>][field_label]" 
                                   value="<?php echo esc_attr($field->field_label); ?>" 
                                   class="regular-text" required>
                        </td>
                        <td>
                            <select name="fields[<?php echo $index; ?>][field_type]" class="regular-text">
                                <?php foreach ($field_types as $type => $label): ?>
                                    <option value="<?php echo $type; ?>" <?php selected($field->field_type, $type); ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="fields[<?php echo $index; ?>][required]" 
                                   value="1" <?php checked($field->required, 1); ?>>
                        </td>
                        <td>
                            <input type="text" name="fields[<?php echo $index; ?>][placeholder]" 
                                   value="<?php echo esc_attr($field->placeholder); ?>" 
                                   class="regular-text">
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
            
            <p style="margin-top: 20px;">
                <button type="button" class="button" id="ov-add-field">
                    <span class="dashicons dashicons-plus-alt"></span> <?php _e('Agregar Campo', 'ov-booking-system'); ?>
                </button>
            </p>
        </div>

        <p class="submit">
            <input type="submit" name="ov_save_fields" class="button button-primary button-large" 
                   value="<?php _e('Guardar Campos', 'ov-booking-system'); ?>">
            <a href="<?php echo admin_url('admin.php?page=ov-booking-forms'); ?>" class="button button-large">
                <i class="fas fa-arrow-left"></i> <?php _e('Volver a los Ajustes', 'ov-booking-system'); ?>
            </a>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    let fieldIndex = <?php echo count($fields); ?>;
    
    // Agregar campo
    $('#ov-add-field').on('click', function() {
        const row = `
            <tr class="ov-field-row" data-index="${fieldIndex}">
                <td class="ov-drag-handle" style="cursor: move; text-align: center;">
                    <span class="dashicons dashicons-menu"></span>
                    ${fieldIndex + 1}
                </td>
                <td>
                    <input type="text" name="fields[${fieldIndex}][field_name]" 
                           class="regular-text" required
                           pattern="[a-z_]+" 
                           placeholder="nombre_campo"
                           title="Solo letras minúsculas y guiones bajos">
                </td>
                <td>
                    <input type="text" name="fields[${fieldIndex}][field_label]" 
                           class="regular-text" required
                           placeholder="Etiqueta del campo">
                </td>
                <td>
                    <select name="fields[${fieldIndex}][field_type]" class="regular-text">
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
                           class="regular-text"
                           placeholder="Texto de ejemplo...">
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
        updateOrder();
    });
    
    // Eliminar campo
    $(document).on('click', '.ov-remove-field', function() {
        if (confirm('¿Estás seguro de eliminar este campo?')) {
            $(this).closest('tr').remove();
            updateOrder();
        }
    });
    
    // Actualizar números de orden
    function updateOrder() {
        $('#ov-fields-body tr').each(function(index) {
            $(this).find('.ov-drag-handle').html(`
                <span class="dashicons dashicons-menu"></span>
                ${index + 1}
            `);
        });
    }
    
    // Hacer tabla sortable
    $('#ov-fields-body').sortable({
        handle: '.ov-drag-handle',
        update: function() {
            updateOrder();
        }
    });
});
</script>

<style>
.ov-field-row input[type="text"],
.ov-field-row select {
    width: 100%;
}

.ov-drag-handle {
    cursor: move;
}

.ov-drag-handle:hover {
    background: #f0f0f0;
}

.ui-sortable-helper {
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
</style>
