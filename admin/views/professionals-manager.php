<?php
/**
 * Vista de Gestión de Profesionales
 * Admin > OV Booking > Profesionales
 * 
 * @package OV_Booking_System
 */

// Seguridad
if (!defined('ABSPATH')) {
    exit;
}

// Obtener ID del formulario
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    echo '<div class="notice notice-error"><p>ID de formulario no válido</p></div>';
    return;
}

// Modelos
$professional_model = new OV_Professional();
$service_model = new OV_Service();
$form_model = new OV_Form();

// Obtener datos
$form = $form_model->get($form_id);
$professionals = $professional_model->get_by_form($form_id, false);
$services = $service_model->get_by_form($form_id);

if (!$form) {
    echo '<div class="notice notice-error"><p>Formulario no encontrado</p></div>';
    return;
}
?>

<div class="wrap ov-professionals-manager">
    <h1 class="wp-heading-inline">
        <i class="fas fa-user-tie"></i> Profesionales
        <small style="font-size: 0.6em; color: #666; font-weight: normal;">
            <?php echo esc_html($form->name); ?>
        </small>
    </h1>
    
    <a href="<?php echo admin_url('admin.php?page=ov-booking-forms&action=edit&form_id=' . $form_id); ?>" 
       class="page-title-action ov-btn-back">
        <i class="fas fa-arrow-left"></i> <?php _e('Volver a los Ajustes', 'ov-booking-system'); ?>
    </a>
    
    <button type="button" class="page-title-action button button-primary ov-btn-add" id="ov-add-professional">
        <i class="fas fa-plus"></i> Agregar Profesional
    </button>
    
    <hr class="wp-header-end">
    
    <?php if (empty($professionals)): ?>
        <div class="ov-empty-state" style="text-align: center; padding: 4rem 2rem; background: #f9f9f9; border-radius: 8px; margin-top: 2rem;">
            <i class="fas fa-user-tie" style="font-size: 4rem; color: #ccc; margin-bottom: 1rem;"></i>
            <h2 style="color: #666; margin-bottom: 0.5rem;">No hay profesionales configurados</h2>
            <p style="color: #999; margin-bottom: 2rem;">Agrega profesionales para que los usuarios puedan seleccionar con quién desean su cita</p>
            <button type="button" class="button button-primary button-large" onclick="jQuery('#ov-add-professional').click()">
                <i class="fas fa-plus"></i> Crear Primer Profesional
            </button>
        </div>
    <?php else: ?>
        
        <div class="ov-table-wrapper" style="overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 2rem;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;">Orden</th>
                    <th style="width: 60px;"></th>
                    <th>Nombre</th>
                    <th>Servicios Asignados</th>
                    <th style="width: 180px;">Visibilidad</th>
                    <th style="width: 100px;">Estado</th>
                    <th style="width: 150px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="ov-professionals-list">
                <?php foreach ($professionals as $professional): 
                    $assigned_services = $professional_model->get_assigned_services($professional->id);
                    $service_names = array_map(function($s) { return $s->name; }, $assigned_services);
                ?>
                <tr data-id="<?php echo esc_attr($professional->id); ?>" data-order="<?php echo esc_attr($professional->display_order); ?>">
                    <td class="ov-drag-handle" style="cursor: move; text-align: center;">
                        <i class="fas fa-grip-vertical" style="color: #999;"></i>
                        <span style="display: inline-block; margin-left: 0.5rem; color: #666;">
                            <?php echo esc_html($professional->display_order); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($professional->avatar_url): ?>
                            <img src="<?php echo esc_url($professional->avatar_url); ?>" 
                                 style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #4f46e5); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1.2rem;">
                                <?php echo esc_html(strtoupper(substr($professional->name, 0, 1))); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($professional->name); ?></strong>
                        <?php if ($professional->email): ?>
                            <br><small style="color: #666;">
                                <i class="fas fa-envelope" style="font-size: 0.8em;"></i>
                                <?php echo esc_html($professional->email); ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($service_names)): ?>
                            <span style="color: #999; font-style: italic;">Sin servicios asignados</span>
                        <?php else: ?>
                            <span style="display: inline-block; padding: 0.25rem 0.5rem; background: #f0f0f1; border-radius: 4px; font-size: 0.85em; margin-right: 0.25rem;">
                                <?php echo esc_html(implode(', ', array_slice($service_names, 0, 2))); ?>
                                <?php if (count($service_names) > 2): ?>
                                    <strong>+<?php echo count($service_names) - 2; ?></strong>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($professional->booking_visibility ?? 'public') === 'admin_only'): ?>
                            <span class="ov-status-badge" style="background:#fff3cd;color:#664d03;">
                                <i class="fas fa-lock"></i> Sólo administración
                            </span>
                        <?php else: ?>
                            <span class="ov-status-badge" style="background:#d1ecf1;color:#0c5460;">
                                <i class="fas fa-globe"></i> Público
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($professional->active): ?>
                            <span class="ov-status-badge ov-status-active">
                                <i class="fas fa-check-circle"></i> Activo
                            </span>
                        <?php else: ?>
                            <span class="ov-status-badge ov-status-inactive">
                                <i class="fas fa-times-circle"></i> Inactivo
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small ov-edit-professional" 
                                data-id="<?php echo esc_attr($professional->id); ?>">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                        <button type="button" class="button button-small button-link-delete ov-delete-professional" 
                                data-id="<?php echo esc_attr($professional->id); ?>"
                                data-name="<?php echo esc_attr($professional->name); ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        
    <?php endif; ?>
</div>

<!-- Modal para Crear/Editar Profesional -->
<div id="ov-professional-modal" class="ov-modal" style="display: none;">
    <div class="ov-modal-content" style="max-width: 700px;">
        <div class="ov-modal-header">
            <h2 id="ov-modal-title">Agregar Profesional</h2>
            <button type="button" class="ov-modal-close">&times;</button>
        </div>
        
        <form id="ov-professional-form">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <input type="hidden" name="professional_id" id="ov-professional-id" value="">
            
            <div class="ov-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                <!-- Nombre -->
                <div class="ov-form-group" style="grid-column: 1 / -1;">
                    <label for="ov-prof-name">
                        Nombre Completo <span style="color: #dc3232;">*</span>
                    </label>
                    <input type="text" id="ov-prof-name" name="name" required style="max-width: 100%;">
                </div>

                <!-- Visibilidad pública -->
                <div class="ov-form-group" style="grid-column: 1 / -1;">
                    <label for="ov-prof-visibility">
                        <i class="fas fa-eye"></i> Visibilidad de reservas
                    </label>
                    <select id="ov-prof-visibility" name="booking_visibility" style="width:100%;">
                        <option value="public">Visible para reservas públicas</option>
                        <option value="admin_only">Sólo reservas administrativas</option>
                    </select>
                    <p class="description">Los profesionales administrativos no aparecerán en el formulario público, pero seguirán disponibles en “Nueva Reserva”.</p>
                </div>
                
                <!-- Email -->
                <div class="ov-form-group">
                    <label for="ov-prof-email">
                        <i class="fas fa-envelope"></i> Email
                    </label>
                    <input type="email" id="ov-prof-email" name="email" style="width: 100%;">
                </div>
                
                <!-- Teléfono -->
                <div class="ov-form-group">
                    <label for="ov-prof-phone">
                        <i class="fas fa-phone"></i> Teléfono
                    </label>
                    <input type="text" id="ov-prof-phone" name="phone" style="width: 100%;">
                </div>
                
                <!-- Biografía -->
                <div class="ov-form-group" style="grid-column: 1 / -1;">
                    <label for="ov-prof-bio">
                        <i class="fas fa-align-left"></i> Biografía / Especialidad
                    </label>
                    <textarea id="ov-prof-bio" name="bio" rows="3" style="width: 100%;" 
                              placeholder="Ej: Especialista en cortes modernos con 5 años de experiencia"></textarea>
                </div>
                
                <!-- Avatar URL -->
                <div class="ov-form-group" style="grid-column: 1 / -1;">
                    <label for="ov-prof-avatar">
                        <i class="fas fa-image"></i> URL de Foto (opcional)
                    </label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="url" id="ov-prof-avatar" name="avatar_url" style="flex: 1;" 
                               placeholder="https://example.com/foto.jpg">
                        <button type="button" class="button" id="ov-upload-avatar">
                            <i class="fas fa-upload"></i> Subir
                        </button>
                    </div>
                    <div id="ov-avatar-preview" style="margin-top: 0.5rem; display: none;">
                        <img src="" style="max-width: 100px; max-height: 100px; border-radius: 8px;">
                    </div>
                </div>
            </div>
            
            <hr style="margin: 1.5rem 0;">
            
            <!-- Servicios Asignados -->
            <div class="ov-form-group">
                <label style="font-weight: 600; font-size: 1.1em; margin-bottom: 1rem; display: block;">
                    <i class="fas fa-briefcase"></i> Servicios que Ofrece
                </label>
                
                <?php if (empty($services)): ?>
                    <p style="color: #999; font-style: italic;">
                        No hay servicios creados. <a href="?page=ov-booking&tab=services&form_id=<?php echo $form_id; ?>">Crear servicios primero</a>
                    </p>
                <?php else: ?>
                    <div id="ov-services-checkboxes" style="display: grid; gap: 0.75rem;">
                        <?php foreach ($services as $service): ?>
                            <label style="display: flex; align-items: center; padding: 0.75rem; background: #f9f9f9; border-radius: 6px; cursor: pointer; transition: all 0.2s;">
                                <input type="checkbox" name="services[]" value="<?php echo esc_attr($service->id); ?>" 
                                       style="margin-right: 0.75rem; width: 18px; height: 18px;">
                                <div>
                                    <strong><?php echo esc_html($service->name); ?></strong>
                                    <span style="color: #666; font-size: 0.9em; margin-left: 0.5rem;">
                                        (<?php echo esc_html($service->duration . ' ' . $service->duration_unit); ?>)
                                    </span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <hr style="margin: 1.5rem 0;">
            
            <!-- Orden y Estado -->
            <div class="ov-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div class="ov-form-group">
                    <label for="ov-prof-order">
                        <i class="fas fa-sort"></i> Orden de Visualización
                    </label>
                    <input type="number" id="ov-prof-order" name="display_order" class="small-text" value="0" min="0">
                    <p class="description">Menor número aparece primero</p>
                </div>
                
                <div class="ov-form-group">
                    <label>
                        <i class="fas fa-toggle-on"></i> Estado
                    </label>
                    <label style="display: flex; align-items: center; margin-top: 0.5rem;">
                        <input type="checkbox" name="active" id="ov-prof-active" checked style="margin-right: 0.5rem;">
                        <span>Activo (según la visibilidad configurada)</span>
                    </label>
                </div>
            </div>
            
            <div class="ov-modal-footer" style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #ddd; display: flex; gap: 1rem; justify-content: flex-end;">
                <button type="button" class="button button-large ov-modal-close">Cancelar</button>
                <button type="submit" class="button button-primary button-large">
                    <i class="fas fa-save"></i> Guardar Profesional
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.ov-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.85em;
    font-weight: 500;
}

.ov-status-active {
    background: #d1f4e0;
    color: #0f5132;
}

.ov-status-inactive {
    background: #f8d7da;
    color: #842029;
}

.ov-drag-handle:hover {
    background: #f0f0f1;
}

#ov-services-checkboxes label:hover {
    background: #e8e8e9 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.ov-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #1d2327;
}

.ov-form-group .description {
    margin: 0.25rem 0 0;
    font-size: 0.9em;
    color: #646970;
}

/* ===== MODAL ESTILOS MEJORADOS ===== */
.ov-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
    padding: 20px;
}

.ov-modal-content {
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    max-height: 90vh;
    overflow-y: auto;
    width: 100%;
}

.ov-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid #ddd;
    background: #f9f9f9;
}

.ov-modal-header h2 {
    margin: 0;
    font-size: 1.3em;
    color: #23282d;
}

.ov-modal-close {
    width: 32px;
    height: 32px;
    border: none;
    background: #e0e0e0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
    color: #666;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.ov-modal-close:hover {
    background: #d0d0d0;
    color: #333;
}

#ov-professional-form {
    padding: 24px;
}

.ov-modal-footer {
    margin-top: 0;
    padding: 16px 24px;
    border-top: 1px solid #ddd;
    background: #f9f9f9;
}

.ov-modal-footer .button {
    min-width: 100px;
    padding: 8px 20px !important;
}

.ov-modal-footer .button-large {
    min-width: 140px !important;
    padding: 10px 24px !important;
    height: auto !important;
    line-height: normal !important;
}

.ov-modal-footer .button-primary {
    background: #2271b1 !important;
    border-color: #2271b1 !important;
    color: #fff !important;
}

.ov-modal-footer .button-primary:hover {
    background: #135e96 !important;
    border-color: #135e96 !important;
}

/* Botón guardar profesional */
#ov-save-professional {
    background: #2271b1 !important;
    border-color: #2271b1 !important;
    color: #fff !important;
}

#ov-save-professional:hover {
    background: #135e96 !important;
    border-color: #135e96 !important;
}

/* Botón de subir mejorado */
#ov-upload-avatar {
    padding: 8px 16px !important;
    height: auto !important;
    line-height: normal !important;
}

#ov-avatar-preview {
    margin-top: 12px;
    padding: 12px;
    background: #f9f9f9;
    border-radius: 4px;
    text-align: center;
}

#ov-avatar-preview img {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Campos del formulario */
#ov-professional-form input[type="text"],
#ov-professional-form input[type="email"],
#ov-professional-form input[type="url"],
#ov-professional-form input[type="number"],
#ov-professional-form textarea {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

#ov-professional-form .ov-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

#ov-professional-form .ov-form-group input,
#ov-professional-form .ov-form-group textarea {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

#ov-professional-form .ov-form-group input:focus,
#ov-professional-form .ov-form-group textarea:focus {
    border-color: #2271b1;
    outline: none;
    box-shadow: 0 0 0 1px #2271b1;
}
</style>

<script>
jQuery(document).ready(function($) {
    const formId = <?php echo $form_id; ?>;
    
    // ===== MODAL =====
    function openModal(title = 'Agregar Profesional', professionalId = null) {
        $('#ov-modal-title').text(title);
        $('#ov-professional-id').val(professionalId || '');
        
        if (!professionalId) {
            $('#ov-professional-form')[0].reset();
            $('#ov-services-checkboxes input[type="checkbox"]').prop('checked', false);
            $('#ov-avatar-preview').hide();
        }
        
        $('#ov-professional-modal').fadeIn(200);
    }
    
    function closeModal() {
        $('#ov-professional-modal').fadeOut(200);
    }
    
    $('#ov-add-professional').click(() => openModal());
    $('.ov-modal-close').click(closeModal);
    
    // Cerrar al hacer clic fuera
    $('#ov-professional-modal').click(function(e) {
        if ($(e.target).is('#ov-professional-modal')) {
            closeModal();
        }
    });
    
    // ===== CARGAR PROFESIONAL PARA EDITAR =====
    $('.ov-edit-professional').click(function() {
        const profId = $(this).data('id');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ov_get_professional',
                professional_id: profId,
                nonce: '<?php echo wp_create_nonce('ov_professional_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    const prof = response.data;
                    
                    $('#ov-prof-name').val(prof.name);
                    $('#ov-prof-email').val(prof.email || '');
                    $('#ov-prof-phone').val(prof.phone || '');
                    $('#ov-prof-bio').val(prof.bio || '');
                    $('#ov-prof-avatar').val(prof.avatar_url || '');
                    $('#ov-prof-order').val(prof.display_order || 0);
                    $('#ov-prof-active').prop('checked', prof.active == 1);
                    $('#ov-prof-visibility').val(prof.booking_visibility === 'admin_only' ? 'admin_only' : 'public');
                    
                    // Avatar preview
                    if (prof.avatar_url) {
                        $('#ov-avatar-preview img').attr('src', prof.avatar_url);
                        $('#ov-avatar-preview').show();
                    }
                    
                    // Checkear servicios asignados
                    $('#ov-services-checkboxes input[type="checkbox"]').prop('checked', false);
                    if (prof.assigned_services && prof.assigned_services.length > 0) {
                        prof.assigned_services.forEach(serviceId => {
                            $(`input[name="services[]"][value="${serviceId}"]`).prop('checked', true);
                        });
                    }
                    
                    openModal('Editar Profesional', profId);
                } else {
                    alert('Error al cargar profesional: ' + response.data);
                }
            }
        });
    });
    
    // ===== GUARDAR PROFESIONAL =====
    $('#ov-professional-form').submit(function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'ov_save_professional');
        formData.append('nonce', '<?php echo wp_create_nonce('ov_professional_nonce'); ?>');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert('✅ Profesional guardado correctamente');
                    location.reload();
                } else {
                    alert('❌ Error: ' + response.data);
                }
            }
        });
    });
    
    // ===== ELIMINAR PROFESIONAL =====
    $('.ov-delete-professional').click(function() {
        const profId = $(this).data('id');
        const profName = $(this).data('name');
        
        if (!confirm(`¿Eliminar profesional "${profName}"?\n\nEsta acción no se puede deshacer.`)) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ov_delete_professional',
                professional_id: profId,
                nonce: '<?php echo wp_create_nonce('ov_professional_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ Profesional eliminado');
                    location.reload();
                } else {
                    alert('❌ Error: ' + response.data);
                }
            }
        });
    });
    
    // ===== UPLOAD AVATAR =====
    $('#ov-upload-avatar').click(function() {
        const mediaUploader = wp.media({
            title: 'Seleccionar Foto',
            button: { text: 'Usar esta foto' },
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#ov-prof-avatar').val(attachment.url);
            $('#ov-avatar-preview img').attr('src', attachment.url);
            $('#ov-avatar-preview').show();
        });
        
        mediaUploader.open();
    });
    
    // Preview avatar al cambiar URL
    $('#ov-prof-avatar').on('input', function() {
        const url = $(this).val();
        if (url) {
            $('#ov-avatar-preview img').attr('src', url);
            $('#ov-avatar-preview').show();
        } else {
            $('#ov-avatar-preview').hide();
        }
    });
});
</script>
