/**
 * OV Booking System - Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Inicializar color pickers
        if ($.fn.wpColorPicker) {
            $('.ov-color-picker').wpColorPicker();
        }

        // Sistema de tabs
        $('.ov-tab-link').on('click', function(e) {
            e.preventDefault();
            
            const target = $(this).attr('href');
            
            // Activar tab
            $('.ov-tab-link').removeClass('active');
            $(this).addClass('active');
            
            // Mostrar contenido
            $('.ov-tab-content').removeClass('active');
            $(target).addClass('active');
        });

        // Auto-generar slug desde nombre
        $('#form_name').on('blur', function() {
            const name = $(this).val();
            const slugField = $('#form_slug');
            
            if (!slugField.val() || slugField.data('auto')) {
                const slug = name.toLowerCase()
                    .replace(/\s+/g, '-')
                    .replace(/[^\w\-]+/g, '')
                    .replace(/\-\-+/g, '-')
                    .replace(/^-+/, '')
                    .replace(/-+$/, '');
                
                slugField.val(slug).data('auto', true);
            }
        });

        // Marcar slug como manual si se edita
        $('#form_slug').on('input', function() {
            $(this).data('auto', false);
        });
    });

})(jQuery);

/**
 * Cambio de estado de reservaciones
 */
jQuery(document).ready(function($) {
    $('.ov-status-select').on('change', function() {
        const reservationId = $(this).data('reservation-id');
        const newStatus = $(this).val();
        const $select = $(this);
        
        if (confirm(ovBookingAdmin.strings.confirm_status_change)) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ov_update_reservation_status',
                    reservation_id: reservationId,
                    status: newStatus,
                    nonce: ovBookingAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Mostrar mensaje de éxito
                        const notice = $('<div class="notice notice-success is-dismissible"><p>Estado actualizado correctamente</p></div>');
                        $('.wrap > h1').after(notice);
                        setTimeout(() => notice.fadeOut(), 3000);
                    } else {
                        alert('Error al actualizar el estado: ' + response.data.message);
                        // Revertir el select
                        location.reload();
                    }
                },
                error: function(xhr) {
                    const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                    alert(message || 'Error de conexión');
                    location.reload();
                }
            });
        } else {
            // Revertir el select
            location.reload();
        }
    });
});
