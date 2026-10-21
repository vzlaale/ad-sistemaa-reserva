/**
 * OV Booking System - Frontend JavaScript
 * Version: 1.8.5
 * Author: Oscar Viedma
 */


/**
 * Helper global de WhatsApp para OV Booking.
 *
 * Centraliza la apertura de WhatsApp para:
 * - Reservas online
 * - Panel administrativo
 */
window.OVBooking = window.OVBooking || {};

window.OVBooking.openWhatsApp = function(phone, message) {

    if (!phone) {
        console.warn('OV Booking: no se recibió un número de teléfono.');
        return false;
    }

    phone = String(phone).replace(/\D/g, '');

    if (
        phone.length === 9 &&
        phone.charAt(0) === '9'
    ) {
        phone = '56' + phone;
    }

    const encoded = encodeURIComponent(message);

    const isMobile =
        /iPhone|iPad|iPod|Android/i.test(
            navigator.userAgent
        );

    let whatsappUrl;

    if (isMobile) {

        whatsappUrl =
            'whatsapp://send?phone=' +
            phone +
            '&text=' +
            encoded;

    } else {

        whatsappUrl =
            'https://web.whatsapp.com/send?phone=' +
            phone +
            '&text=' +
            encoded;

    }

    if (isMobile) {

        window.location.href = whatsappUrl;

        return true;

    }

    const opened = window.open(
        whatsappUrl,
        '_blank'
    );

    if (!opened) {

        console.warn(
            'OV Booking: WhatsApp no pudo abrirse. Revisa las ventanas emergentes.'
        );

        return false;

    }

    return true;

};

(function($) {
    'use strict';

    class OVBookingSystem {
        constructor(container) {
            this.container = $(container);
            this.formId = this.container.data('form-id');
            this.currentStep = 1;
            this.totalSteps = this.container.find('.ov-progress-step').length;
            this.bookingData = {
                formId: this.formId,
                serviceId: null,
                professionalId: null,
                date: null,
                time: null,
                customerData: {},
                createdBooking: null,
                bookingSignature: null,
                requestId: null,
                requestSignature: null
            };
            this.formConfig = null;
            this.currentMonth = new Date();
            this.selectedDate = null;
            this.monthRequest = null;
            this.monthRequestId = 0;
            
            this.init();
        }

        t(key, fallback) {
            if (window.ovBooking && ovBooking.strings && ovBooking.strings[key]) {
                return ovBooking.strings[key];
            }
            return fallback || key;
        }

        escapeHtml(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        locale() {
            return (window.ovBooking && ovBooking.locale) ? ovBooking.locale : 'es-ES';
        }

        init() {
            this.loadFormConfig();
            this.attachEventListeners();
        }

        attachEventListeners() {
            // Navegación
            $('#ov-btn-next', this.container).on('click', () => this.nextStep());
            $('#ov-btn-back', this.container).on('click', () => this.prevStep());
            
            // Calendario
            $('#ov-prev-month', this.container).on('click', () => this.changeMonth(-1));
            $('#ov-next-month', this.container).on('click', () => this.changeMonth(1));

        }

        loadFormConfig() {
            $.ajax({
                url: ovBooking.ajaxurl,
                type: 'GET',
                data: {
                    action: 'ov_get_form_config',
                    form_id: this.formId
                },
                success: (response) => {
                    if (response && response.success && response.data && response.data.booking_window) {
                        this.formConfig = response.data;
                        const start = this.formConfig.booking_window.start_date.split('-').map(Number);
                        this.currentMonth = new Date(start[0], start[1] - 1, 1);
                        this.loadServices();
                    } else {
                        this.showError(this.t('error', 'No fue posible cargar el formulario.'));
                    }
                },
                error: () => this.showError(this.t('error', 'No fue posible cargar el formulario.'))
            });
        }

        loadServices() {
            const grid = this.container.find('.ov-services-grid');
            grid.html('<div class="ov-loader"><div class="ov-spinner"></div><p>' + ovBooking.strings.loading + '</p></div>');

            const ajaxData = {
                action: 'ov_get_services',
                form_id: this.formId
            };
            
            if (this.bookingData.professionalId) {
                ajaxData.professional_id = this.bookingData.professionalId;
            }

            $.ajax({
                url: ovBooking.ajaxurl,
                type: 'GET',
                data: ajaxData,
                success: (response) => {
                    if (response.success) {
                        this.renderServices(response.data);
                    } else {
                        this.showError(response.data.message);
                    }
                },
                error: () => {
                    this.showError(ovBooking.strings.error);
                }
            });
        }

        renderServices(services) {
            const grid = this.container.find('.ov-services-grid');
            grid.empty();

            if (services.length === 0) {
                grid.html('<p>' + this.t('no_services_available', 'No hay servicios disponibles') + '</p>');
                return;
            }

            services.forEach(service => {
                const card = $('<div class="ov-service-card"></div>').attr('data-service-id', Number(service.id));
                const icon = $('<div class="ov-service-icon"></div>');
                if (service.image_url && this.isSafeImageUrl(service.image_url)) {
                    icon.append($('<img>').attr({ src: service.image_url, alt: String(service.name || '') }));
                } else {
                    icon.text(String(service.name || '').charAt(0));
                }
                const info = $('<div class="ov-service-info"></div>')
                    .append($('<div class="ov-service-name"></div>').text(String(service.name || '')));
                if (service.description) {
                    info.append($('<div class="ov-service-description"></div>').text(String(service.description)));
                }
                info.append(
                    $('<span class="ov-service-duration"></span>')
                        .append('<i class="far fa-clock" aria-hidden="true"></i> ')
                        .append(document.createTextNode(String(service.duration) + ' ' + this.getDurationLabel(service.duration_unit)))
                );
                const meta = $('<div class="ov-service-meta"></div>');
                if (service.price) meta.append($('<span class="ov-service-price"></span>').text(String(service.price)));
                card.append(icon, info, meta);

                card.on('click', () => this.selectService(service.id, card));
                grid.append(card);
            });
        }

        getDurationLabel(unit) {
            const labels = {
                'minutes': this.t('minutes_short', 'min'),
                'hours': this.t('hours_short', 'hrs'),
                'days': this.t('days_short', 'días')
            };
            return labels[unit] || 'min';
        }

        isSafeImageUrl(value) {
            try {
                const url = new URL(String(value), window.location.href);
                return url.protocol === 'https:' || url.protocol === 'http:';
            } catch (e) {
                return false;
            }
        }

        selectService(serviceId, card) {
            this.container.find('.ov-service-card').removeClass('ov-selected');
            card.addClass('ov-selected');
            this.bookingData.serviceId = serviceId;
            this.resetCreatedBooking();
            
            // Guardar precio del servicio
            const priceElement = card.find('.ov-service-price');
            if (priceElement.length) {
                this.bookingData.servicePrice = priceElement.text().trim();
            }
            
            // Auto-avanzar después de medio segundo
            setTimeout(() => this.nextStep(), 300);
        }

        loadProfessionals() {
            const grid = this.container.find('.ov-professionals-grid');
            grid.html('<div class="ov-loader"><div class="ov-spinner"></div><p>' + ovBooking.strings.loading + '</p></div>');

            $.ajax({
                url: ovBooking.ajaxurl,
                type: 'GET',
                data: {
                    action: 'ov_get_professionals_by_service',
                    service_id: this.bookingData.serviceId
                },
                success: (response) => {
                    if (response.success) {
                        this.renderProfessionals(response.data.professionals);
                    } else {
                        this.showError(response.data.message);
                    }
                },
                error: () => {
                    this.showError(ovBooking.strings.error);
                }
            });
        }

        renderProfessionals(professionals) {
            const grid = this.container.find('.ov-professionals-grid');
            grid.empty();

            if (professionals.length === 0) {
                grid.html(`
                    <div style="text-align: center; padding: 3rem; color: #666;">
                        <p style="font-size: 1.1rem; margin: 0;">${this.t('no_professionals_available', 'No hay profesionales disponibles')}</p>
                    </div>
                `);
                return;
            }

            // Opción "Cualquier profesional" (configurable)
            const allowAny = !(this.formConfig && this.formConfig.enable_professionals) ? false
                : (typeof this.formConfig.allow_any_professional === 'undefined' ? true : !!this.formConfig.allow_any_professional);

            if (allowAny) {
                const anyProfCard = $(`
                    <div class="ov-professional-card ov-any-professional ov-selected" data-professional-id="0">
                        <div class="ov-professional-avatar-placeholder">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="ov-professional-content">
                            <div class="ov-professional-name">${this.t('any_professional_name', 'Cualquier profesional disponible')}</div>
                            <div class="ov-professional-bio">${this.t('any_professional_bio', 'Primera hora disponible con cualquiera de nuestros profesionales')}</div>
                        </div>
                    </div>
                `);

                anyProfCard.on('click', () => this.selectProfessional(0, anyProfCard));
                grid.append(anyProfCard);

                // Pre-seleccionar "Cualquier profesional"
                this.bookingData.professionalId = 0;
            this.resetCreatedBooking();
            } else {
                // Requerir selección explícita
                this.bookingData.professionalId = null;
            this.resetCreatedBooking();
            }

            // Profesionales específicos
            professionals.forEach(prof => {
                const card = $('<div class="ov-professional-card"></div>').attr('data-professional-id', Number(prof.id));
                if (prof.avatar_url && this.isSafeImageUrl(prof.avatar_url)) {
                    card.append($('<img class="ov-professional-avatar">').attr({ src: prof.avatar_url, alt: String(prof.name || '') }));
                } else {
                    card.append($('<div class="ov-professional-avatar-placeholder"></div>').text(String(prof.name || '').charAt(0).toUpperCase()));
                }
                const content = $('<div class="ov-professional-content"></div>')
                    .append($('<div class="ov-professional-name"></div>').text(String(prof.name || '')));
                if (prof.bio) content.append($('<div class="ov-professional-bio"></div>').text(String(prof.bio)));
                card.append(content);

                card.on('click', () => this.selectProfessional(prof.id, card));
                grid.append(card);
            });

            // Si no hay "cualquier profesional", no preseleccionar ninguno
        }

        selectProfessional(professionalId, card) {
            this.container.find('.ov-professional-card').removeClass('ov-selected');
            card.addClass('ov-selected');
            this.bookingData.professionalId = professionalId;
            this.resetCreatedBooking();
            
            // Auto-avanzar después de medio segundo
            setTimeout(() => this.nextStep(), 300);
        }

        renderCalendar() {
            if (!this.formConfig || !this.formConfig.booking_window) {
                return;
            }
            this.selectedDate = null;
            this.bookingData.date = null;
            this.bookingData.time = null;
            this.resetCreatedBooking();
            this.container.find('#ov-time-slots').hide();
            const year = this.currentMonth.getFullYear();
            const month = this.currentMonth.getMonth();
            const windowStart = this.formConfig.booking_window.start_date;
            const windowEnd = this.formConfig.booking_window.end_date;
            const monthLabel = this.currentMonth.toLocaleDateString(this.locale(), { month: 'long', year: 'numeric' });

            $('#ov-current-month', this.container).text(monthLabel.charAt(0).toUpperCase() + monthLabel.slice(1));
            this.updateMonthNavigation();

            const grid = this.container.find('#ov-calendar-grid');
            grid.empty();
            this.container.find('#ov-calendar-status').empty();

            // Días de la semana
            const weekStart = (this.formConfig?.week_starts_on ?? 1);
            const dayNames = [];
            for (let i = 0; i < 7; i++) {
                const dayDate = new Date(2024, 0, 7 + i); // Domingo 7 enero 2024
                dayNames.push(dayDate.toLocaleDateString(this.locale(), { weekday: 'short' }));
            }
            const orderedDays = [...dayNames.slice(weekStart), ...dayNames.slice(0, weekStart)];

            orderedDays.forEach(day => {
                grid.append(`<div class="ov-calendar-day ov-weekday-label">${day}</div>`);
            });

            // Días del mes
            const firstDay = new Date(year, month, 1);
            let startingDay = firstDay.getDay() - weekStart;
            if (startingDay < 0) startingDay += 7;

            // Días vacíos del inicio
            for (let i = 0; i < startingDay; i++) {
                grid.append('<div class="ov-calendar-day ov-disabled"></div>');
            }

            // Días del mes
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            for (let day = 1; day <= daysInMonth; day++) {
                const currentDate = new Date(year, month, day);
                const dateStr = this.formatDate(currentDate);

                const dayElement = $(`<div class="ov-calendar-day" data-date="${dateStr}">${day}</div>`);

                // Verificar si es hoy
                if (dateStr === windowStart) {
                    dayElement.addClass('ov-today');
                }

                // La respuesta mensual decide la disponibilidad; aquí solo se acota la ventana.
                if (dateStr < windowStart || dateStr > windowEnd) {
                    dayElement.addClass('ov-disabled');
                } else {
                    dayElement.addClass('ov-eligible ov-pending');
                }

                grid.append(dayElement);
            }

            this.fetchMonthAvailability(year, month);

        }

        selectDate(dateObj, dateStr, element) {
            this.container.find('.ov-calendar-day').removeClass('ov-selected');
            element.addClass('ov-selected');
            
            this.selectedDate = dateObj;
            this.bookingData.date = dateStr;
            this.resetCreatedBooking();
            
            this.loadTimeSlots(dateStr);
        }

        fetchMonthAvailability(year, month) {
            const requestId = ++this.monthRequestId;
            if (this.monthRequest) {
                this.monthRequest.abort();
            }
            const days = this.container.find('#ov-calendar-grid .ov-calendar-day.ov-eligible');
            days.removeClass('ov-available ov-disabled').addClass('ov-pending').off('click');
            const status = this.container.find('#ov-calendar-status');
            status.empty().text(this.t('loading', 'Consultando disponibilidad...'));

            if (!this.bookingData || !this.bookingData.serviceId) {
                this.showMonthAvailabilityError(this.t('error', 'No fue posible consultar la disponibilidad.'), year, month);
                return;
            }
            const ajaxData = {
                action: 'ov_get_month_availability',
                form_id: this.formId,
                service_id: this.bookingData.serviceId,
                year: year,
                month: month + 1
            };
            if (this.bookingData.professionalId !== null && typeof this.bookingData.professionalId !== 'undefined') {
                ajaxData.professional_id = this.bookingData.professionalId;
            }

            this.monthRequest = $.ajax({ url: ovBooking.ajaxurl, type: 'GET', data: ajaxData })
                .done((response) => {
                    if (requestId !== this.monthRequestId) return;
                    if (!response || !response.success || !response.data || !Array.isArray(response.data.available_dates)) {
                        const message = response && response.data && response.data.message;
                        this.showMonthAvailabilityError(message || this.t('error', 'No fue posible consultar la disponibilidad.'), year, month);
                        return;
                    }
                    const availableDates = new Set(response.data.available_dates);
                    days.each((_, element) => {
                        const day = $(element);
                        const dateStr = day.attr('data-date');
                        day.removeClass('ov-pending');
                        if (availableDates.has(dateStr)) {
                            day.addClass('ov-available');
                            day.on('click', () => this.selectDate(new Date(year, month, Number(dateStr.slice(-2))), dateStr, day));
                        } else {
                            day.addClass('ov-disabled');
                        }
                    });
                    status.empty();
                })
                .fail((xhr, textStatus) => {
                    if (requestId !== this.monthRequestId || textStatus === 'abort') return;
                    const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                    this.showMonthAvailabilityError(message || this.t('error', 'No fue posible consultar la disponibilidad.'), year, month);
                });
        }

        showMonthAvailabilityError(message, year, month) {
            this.container.find('#ov-calendar-grid .ov-calendar-day.ov-eligible')
                .removeClass('ov-available ov-pending').addClass('ov-disabled').off('click');
            const status = this.container.find('#ov-calendar-status');
            status.empty().append($('<span>').text(String(message)));
            status.append($('<button type="button" class="ov-btn ov-btn-secondary">')
                .text(this.t('retry', 'Reintentar'))
                .on('click', () => this.fetchMonthAvailability(year, month)));
        }

        loadTimeSlots(date) {
            const container = this.container.find('#ov-time-slots');
            const grid = this.container.find('#ov-time-grid');
            
            container.show();
            grid.html('<div class="ov-loader"><div class="ov-spinner"></div><p>' + ovBooking.strings.loading + '</p></div>');

            // Preparar datos - incluir professional_id si está definido (incluso si es 0)
            const ajaxData = {
                action: 'ov_get_available_slots',
                form_id: this.formId,
                service_id: this.bookingData.serviceId,
                date: date
            };
            
            if (typeof this.bookingData.professionalId !== 'undefined') {
                ajaxData.professional_id = this.bookingData.professionalId;
            }

            $.ajax({
                url: ovBooking.ajaxurl,
                type: 'GET',
                data: ajaxData,
                success: (response) => {
                    if (response && response.success) {
                        this.renderTimeSlots(response.data);
                    } else {
                        const message = response && response.data && response.data.message;
                        grid.empty().append($('<p>').text(message || ovBooking.strings.error));
                    }
                },
                error: (xhr) => {
                    const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                    grid.empty().append($('<p>').text(message || ovBooking.strings.error));
                }
            });
        }

        renderTimeSlots(slots) {
          const grid = this.container.find('#ov-time-grid');
          grid.empty().removeClass('ov-no-slots');

          // Filtrar solo los slots realmente disponibles
          const availableSlots = (slots || []).filter(s => s && s.available);

          // Si no hay ninguno disponible, mostrar mensaje
          if (availableSlots.length === 0) {
            grid.addClass('ov-no-slots');
            grid.html('<div class="ov-no-slots-message">' + ovBooking.strings.no_slots_available + '</div>');
            return;
          }

          availableSlots.forEach(slot => {
            const remaining = (typeof slot.remaining !== 'undefined' && slot.remaining !== null) ? parseInt(slot.remaining, 10) : null;
            const capacity = (typeof slot.capacity !== 'undefined' && slot.capacity !== null) ? parseInt(slot.capacity, 10) : null;
            const showRemaining = remaining !== null && capacity !== null && capacity > 1;
            const remainingLabel = showRemaining
              ? `${remaining} ${remaining === 1 ? this.t('remaining_one', 'cupo disponible') : this.t('remaining_spots', 'cupos disponibles')}`
              : '';
            const titleAttr = showRemaining ? ` title="${remainingLabel}"` : '';
            const badgeHtml = showRemaining ? `<span class="ov-slot-remaining">${remainingLabel}</span>` : '';

            const slotElement = $(`
              <div class="ov-time-slot" data-time="${slot.time}"${titleAttr}>
                <span class="ov-time-slot-main"><i class="far fa-clock"></i> ${slot.time}</span>
                ${badgeHtml}
              </div>
            `);

            slotElement.on('click', () => this.selectTime(slot.time, slotElement));
            grid.append(slotElement);
          });
        }

        selectTime(time, element) {
            this.container.find('.ov-time-slot').removeClass('ov-selected');
            element.addClass('ov-selected');
            this.bookingData.time = time;
            this.resetCreatedBooking();
            
            // Auto-avanzar después de medio segundo
            setTimeout(() => this.nextStep(), 300);
        }

        loadFormFields() {
            const form = this.container.find('#ov-customer-form');
            form.html('<div class="ov-loader"><div class="ov-spinner"></div><p>' + ovBooking.strings.loading + '</p></div>');

            $.ajax({
                url: ovBooking.ajaxurl,
                type: 'GET',
                data: {
                    action: 'ov_get_form_fields',
                    form_id: this.formId
                },
                success: (response) => {
                    if (response.success) {
                        this.renderFormFields(response.data);
                    }
                },
                error: () => {
                    this.showError(ovBooking.strings.error);
                }
            });
        }

        renderFormFields(fields) {
            const form = this.container.find('#ov-customer-form');
            form.empty();

            fields.forEach(field => {
                const group = $('<div class="ov-form-group"></div>');
                const safeName = String(field.name || '').replace(/[^a-zA-Z0-9_-]/g, '');
                if (!safeName) return;
                const inputId = 'ov-field-' + safeName;
                const label = $('<label></label>')
                    .attr('for', inputId)
                    .text(String(field.label || safeName) + (field.required ? ' *' : ''));
                group.append(label);

                let input;
                if (field.type === 'textarea') {
                    input = $('<textarea></textarea>');
                } else {
                    const allowedTypes = ['text', 'email', 'tel', 'number', 'date', 'url'];
                    const type = allowedTypes.includes(field.type) ? field.type : 'text';
                    input = $('<input>').attr('type', type);
                    if (type === 'tel') input.attr('inputmode', 'tel');
                }

                input.attr({ id: inputId, name: safeName, placeholder: String(field.placeholder || '') });
                if (field.required) input.prop('required', true);

                group.append(input);
                form.append(group);
            });

            // Campo señuelo fuera de pantalla. Los usuarios y lectores de pantalla
            // no lo recorren; bots que completan todos los campos quedan bloqueados.
            form.append(
                $('<div class="ov-booking-honeypot" aria-hidden="true"></div>').append(
                    $('<label></label>').text('Website').append(
                        $('<input type="text" name="website" class="ov-honeypot-field" tabindex="-1" autocomplete="off">')
                    )
                )
            );
        }

        collectFormData() {
            const form = this.container.find('#ov-customer-form');
            const data = {};
            let isValid = true;
            const missingFields = [];

            form.find('input, textarea, select').each(function() {
                const field = $(this);
                if (field.hasClass('ov-honeypot-field')) return;
                const name = field.attr('name');
                const value = field.val().trim();
                const label = field.closest('.ov-form-group').find('label').first().text().replace('*', '').trim();

                // Remover clases de error previas
                field.closest('.ov-form-group').removeClass('ov-error')
                     .find('.ov-error-message').remove();

                // Validar campos requeridos ÚNICAMENTE (sin validar formato)
                if (field.prop('required') && !value) {
                    field.closest('.ov-form-group').addClass('ov-error')
                         .append('<div class="ov-error-message">' + this.t('required_field', 'Este campo es requerido') + '</div>');
                    isValid = false;
                    missingFields.push(label || name);
                }

                data[name] = value;
            });

            if (!isValid) {
            }

            this.bookingData.customerData = data;
            this.resetCreatedBooking();
            return isValid;
        }
        resetCreatedBooking() {
            this.bookingData.createdBooking = null;
            this.bookingData.bookingSignature = null;
            this.bookingData.requestId = null;
            this.bookingData.requestSignature = null;
        }

        getBookingSignature() {
            // Firma estable para evitar duplicados si el usuario vuelve atrás y cambia algo
            const payload = {
                formId: this.bookingData.formId,
                serviceId: this.bookingData.serviceId,
                professionalId: (typeof this.bookingData.professionalId !== 'undefined') ? this.bookingData.professionalId : null,
                date: this.bookingData.date,
                time: this.bookingData.time,
                customerData: this.bookingData.customerData || {}
            };
            try {
                return JSON.stringify(payload);
            } catch (e) {
                return String(Date.now());
            }
        }

        getRequestId(signature) {
            if (this.bookingData.requestId && this.bookingData.requestSignature === signature) {
                return this.bookingData.requestId;
            }

            let id;
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                id = window.crypto.randomUUID();
            } else if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                const bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);
                id = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
            } else {
                id = String(Date.now()) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
            }

            this.bookingData.requestId = id;
            this.bookingData.requestSignature = signature;
            return id;
        }

        showConfirmation() {
            const details = this.container.find('#ov-confirmation-details');
            details.empty();
            const safe = (value) => this.escapeHtml(value);


            // Obtener datos del servicio
            const serviceCard = this.container.find(`.ov-service-card[data-service-id="${this.bookingData.serviceId}"]`);
            const serviceName = serviceCard.find('.ov-service-name').text();
            const servicePrice = serviceCard.find('.ov-service-price').text();
            const serviceDuration = serviceCard.find('.ov-service-duration').text().trim();
            
            // Obtener nombre del profesional si existe
            let professionalName = null;
            if (this.formConfig && this.formConfig.enable_professionals && typeof this.bookingData.professionalId !== 'undefined') {
                if (this.bookingData.professionalId === 0) {
                    professionalName = this.t('any_professional_name', 'Cualquier profesional disponible');
                } else {
                    const profCard = this.container.find(`.ov-professional-card[data-professional-id="${this.bookingData.professionalId}"]`);
                    professionalName = profCard.find('.ov-professional-name').text();
                }
            }
            
            // Formatear fecha
            const dateObj = new Date(this.bookingData.date + 'T12:00:00');
            const dateFormatted = dateObj.toLocaleDateString(this.locale(), { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });

            // UN SOLO CONTENEDOR - ELIMINAR ov-confirmation-card
            let html = `
                <div class="ov-confirmation-section">
                    <h3><i class="fas fa-calendar-check"></i> ${this.t('booking_details', 'Detalles de la Reserva')}</h3>
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-star"></i> ${this.t('service', 'Servicio')}</span>
                        <span class="ov-detail-value">${safe(serviceName)}</span>
                    </div>
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="far fa-calendar"></i> ${this.t('date', 'Fecha')}</span>
                        <span class="ov-detail-value">${safe(dateFormatted)}</span>
                    </div>
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="far fa-clock"></i> ${this.t('time', 'Hora')}</span>
                        <span class="ov-detail-value">${safe(this.bookingData.time)}</span>
                    </div>
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-hourglass-half"></i> ${this.t('duration', 'Duración')}</span>
                        <span class="ov-detail-value">${safe(serviceDuration)}</span>
                    </div>
                    ${professionalName ? `
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-user-tie"></i> ${this.t('professional', 'Profesional')}</span>
                        <span class="ov-detail-value">${safe(professionalName)}</span>
                    </div>
                    ` : ''}
                </div>
                
                ${servicePrice ? `
                <div class="ov-price-highlight">
                    <span class="ov-detail-label"><i class="fas fa-dollar-sign"></i> ${this.t('total_price', 'Precio Total')}</span>
                    <span class="ov-detail-value">${safe(servicePrice)}</span>
                </div>
                ` : ''}
                
                <div class="ov-confirmation-section">
                    <h3><i class="fas fa-user"></i> ${this.t('contact_info', 'Información de Contacto')}</h3>
            `;

            // Datos del cliente
            if (this.bookingData.customerData.name) {
                let fullName = this.bookingData.customerData.name;
                if (this.bookingData.customerData.surname) {
                    fullName += ' ' + this.bookingData.customerData.surname;
                }
                html += `
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-user"></i> ${this.t('name', 'Nombre')}</span>
                        <span class="ov-detail-value">${safe(fullName)}</span>
                    </div>
                `;
            }

            if (this.bookingData.customerData.rut) {
                html += `
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-id-card"></i> ${this.t('rut', 'RUT')}</span>
                        <span class="ov-detail-value">${safe(this.bookingData.customerData.rut)}</span>
                    </div>
                `;
            }

            if (this.bookingData.customerData.email) {
                html += `
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-envelope"></i> ${this.t('email', 'Email')}</span>
                        <span class="ov-detail-value">${safe(this.bookingData.customerData.email)}</span>
                    </div>
                `;
            }

            if (this.bookingData.customerData.phone) {
                html += `
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-phone"></i> ${this.t('phone', 'Teléfono')}</span>
                        <span class="ov-detail-value">${safe(this.bookingData.customerData.phone)}</span>
                    </div>
                `;
            }

            if (this.bookingData.customerData.notes) {
                html += `
                    <div class="ov-detail-row">
                        <span class="ov-detail-label"><i class="fas fa-sticky-note"></i> ${this.t('notes', 'Notas')}</span>
                        <span class="ov-detail-value">${safe(this.bookingData.customerData.notes)}</span>
                    </div>
                `;
            }

            html += `</div>`;

            // TÉRMINOS - Parsear JSON si es string
            let termsConfig = null;
            if (this.formConfig && this.formConfig.terms_config) {
                if (typeof this.formConfig.terms_config === 'string') {
                    try {
                        termsConfig = JSON.parse(this.formConfig.terms_config);
                    } catch (e) {
                        console.error('Error parsing terms_config:', e);
                    }
                } else {
                    termsConfig = this.formConfig.terms_config;
                }
            }


            const hasTerms = termsConfig && (termsConfig.url || termsConfig.text);
            
            if (hasTerms) {
                const isRequired = termsConfig.required === true || termsConfig.required === '1' || termsConfig.required === 1 || termsConfig.required === 'on';
                
                
                html += `
                    <div class="ov-terms-acceptance">
                        <label class="ov-checkbox-label">
                            <input type="checkbox" id="ov-accept-terms" ${isRequired ? 'required' : ''}>
                            <span>
                                ${this.t('i_accept', 'Acepto')} 
                                <a href="#" id="ov-terms-link">${this.t('terms_link', 'términos y condiciones')}</a>
                                ${isRequired ? ' <span style="color: #d63031; font-weight: bold;">*</span>' : ''}
                            </span>
                        </label>
                    </div>
                `;
            }

            const termsRequired = hasTerms && (termsConfig.required === true || termsConfig.required === '1' || termsConfig.required === 1 || termsConfig.required === 'on');
            html += `
                <button type="button" class="ov-btn ov-btn-primary" id="ov-confirm-booking" ${termsRequired ? 'disabled' : ''}>
                    <i class="fas fa-check"></i>
                    ${this.t('confirm_booking', 'Confirmar reserva')}
                </button>
            `;

            details.html(html);

            // Event listeners
            const termsCheckbox = $('#ov-accept-terms', this.container);
            const confirmButton = $('#ov-confirm-booking', this.container);
            
            if (termsCheckbox.length && confirmButton.length) {
                termsCheckbox.on('change', function() {
                    confirmButton.prop('disabled', !$(this).is(':checked'));
                });
            }

            if (hasTerms) {
              details.off('click', '#ov-terms-link').on('click', '#ov-terms-link', (e) => {
                e.preventDefault();

                if (termsConfig.text) {
                  window.ovShowModal(
                    this.t('terms_and_conditions', 'Términos y Condiciones'),
                    `<div class="ov-terms-content" style="max-height: 400px; overflow-y: auto; line-height: 1.6;">
                      ${termsConfig.text}
                    </div>`,
                    `<button class="ov-btn ov-btn-secondary" onclick="ovCloseModal()">${this.t('close', 'Cerrar')}</button>`,
                    this.container
                  );
                  return;
                }

                if (termsConfig.url) {
                  window.open(termsConfig.url, '_blank', 'noopener,noreferrer');
                }
              });
            }

            details.off('click', '#ov-confirm-booking').on('click', '#ov-confirm-booking', () => {
                this.confirmBooking();
            });
        }

        confirmBooking() {
            const btn = $('#ov-confirm-booking', this.container);

            if (btn.length && btn.prop('disabled')) {
                return;
            }

            const termsCheckbox = $('#ov-accept-terms', this.container);
            if (termsCheckbox.length && termsCheckbox.prop('required') && !termsCheckbox.is(':checked')) {
                window.ovShowAlert(this.t('accept_terms', 'Debes aceptar los términos y condiciones.'), 'warning');
                return;
            }

            const originalHtml = btn.length ? btn.html() : '';
            if (btn.length) {
                btn.prop('disabled', true).addClass('ov-loading').html('<i class="fas fa-spinner fa-spin"></i> ' + this.t('creating_booking', 'Creando reserva...'));
            }
            const signature = this.getBookingSignature();
            
            const ajaxData = {
                action: 'ov_create_booking',
                nonce: ovBooking.nonce,
                form_id: this.bookingData.formId,
                service_id: this.bookingData.serviceId,
                date: this.bookingData.date,
                time: this.bookingData.time,
                customer_data: this.bookingData.customerData,
                request_id: this.getRequestId(signature),
                terms_accepted: termsCheckbox.length && termsCheckbox.is(':checked') ? '1' : '0',
                website: this.container.find('.ov-honeypot-field').val() || ''
            };

            if (typeof this.bookingData.professionalId !== 'undefined') {
                ajaxData.professional_id = this.bookingData.professionalId;
            }

            $.ajax({
                url: ovBooking.ajaxurl,
                type: 'POST',
                data: ajaxData,

                success: (response) => {

                    if (response.success && response.data.booking) {

                        const booking = response.data.booking;

                        this.bookingData.createdBooking = booking;
                        this.bookingData.bookingSignature = signature;

                        this.showSuccessModal(booking);

                    } else {

                        window.ovShowAlert(
                            (response.data && response.data.message) ||
                            this.t('create_booking_error', 'Error al crear la reserva'),
                            'error'
                        );

                    }

                },

                error: () => {

                    window.ovShowAlert(
                        this.t('connection_error', 'Error de conexión. Por favor intenta de nuevo.'),
                        'error'
                    );

                },

                complete: () => {

                    if (btn.length) {

                        btn.prop(
                            'disabled',
                            termsCheckbox.length
                                ? !termsCheckbox.is(':checked')
                                : false
                        )
                        .removeClass('ov-loading')
                        .html(originalHtml);

                    }

                }

            });
        }

        showSuccessModal(booking) {
            const dateObj = new Date(booking.date + 'T12:00:00');
            const formattedDate = dateObj.toLocaleDateString(this.locale(), {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });

            const safe = (value) => this.escapeHtml(value);
            const calendar = booking.calendar || null;
            const calendarActions = calendar ? `
                <div class="ov-calendar-actions">
                    <a class="ov-btn ov-btn-secondary" href="${safe(calendar.google_url)}" target="_blank" rel="noopener noreferrer">
                        ${this.t('add_google_calendar', 'Añadir a Google Calendar')}
                    </a>
                    <button type="button" class="ov-btn ov-btn-secondary" id="ov-download-calendar">
                        ${this.t('download_calendar', 'Descargar calendario (.ics)')}
                    </button>
                </div>
            ` : '';

            const body = `
                <div class="ov-success-content">
                    <div class="ov-success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2>${this.t('success_text', 'Tu reserva ha sido registrada exitosamente')}</h2>
                    <div class="ov-success-details">
                        <p><strong>ID:</strong> ${safe(booking.booking_id)}</p>
                        <p><strong>${this.t('service', 'Servicio')}:</strong> ${safe(booking.service_name)}</p>
                        ${booking.professional_name ? `<p><strong>${this.t('professional', 'Profesional')}:</strong> ${safe(booking.professional_name)}</p>` : ''}
                        <p><strong>${this.t('date', 'Fecha')}:</strong> ${formattedDate}</p>
                        <p><strong>${this.t('time', 'Hora')}:</strong> ${safe(booking.time)}</p>
                    </div>
                    ${calendarActions}
                </div>
            `;

            const footer = `
                <button class="ov-btn ov-btn-primary ov-btn-success" onclick="location.reload()">
                    ${(window.ovBooking && ovBooking.strings && ovBooking.strings.accept) ? ovBooking.strings.accept : 'Aceptar'}
                </button>
            `;

            window.ovShowModal('', body, footer);

            $(document).off('click.ovBookingCalendar', '#ov-download-calendar');
            $(document).on('click.ovBookingCalendar', '#ov-download-calendar', () => {
                this.downloadCalendarEvent(calendar);
            });
        }

        downloadCalendarEvent(calendar) {
            if (!calendar) return;
            const escapeIcs = (value) => String(value || '')
                .replace(/\\/g, '\\\\')
                .replace(/\r?\n/g, '\\n')
                .replace(/,/g, '\\,')
                .replace(/;/g, '\\;');
            const createdId = this.bookingData.createdBooking && this.bookingData.createdBooking.booking_id;
            const uid = escapeIcs(createdId || calendar.filename || 'ov-booking') + '@ov-booking';
            const now = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
            const startLine = calendar.all_day
                ? 'DTSTART;VALUE=DATE:' + calendar.start_date
                : 'DTSTART:' + calendar.start_utc;
            const endLine = calendar.all_day
                ? 'DTEND;VALUE=DATE:' + calendar.end_date
                : 'DTEND:' + calendar.end_utc;
            const content = [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//OV Booking//Calendar//ES',
                'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
                'UID:' + uid, 'DTSTAMP:' + now,
                startLine, endLine,
                'SUMMARY:' + escapeIcs(calendar.title),
                'DESCRIPTION:' + escapeIcs(calendar.details),
                'END:VEVENT', 'END:VCALENDAR', ''
            ].join('\r\n');
            const blob = new Blob([content], { type: 'text/calendar;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = calendar.filename || 'reserva.ics';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        }


        sendWhatsApp() {
            // Esta función ya no se usa, se maneja desde el modal
        }

        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        updateMonthNavigation() {
            const window = this.formConfig.booking_window;
            const shownMonth = `${this.currentMonth.getFullYear()}-${String(this.currentMonth.getMonth() + 1).padStart(2, '0')}`;
            $('#ov-prev-month', this.container).prop('disabled', shownMonth <= window.start_month);
            $('#ov-next-month', this.container).prop('disabled', shownMonth >= window.end_month);
        }

        changeMonth(direction) {
            if (!this.formConfig || !this.formConfig.booking_window) return;
            const next = new Date(this.currentMonth.getFullYear(), this.currentMonth.getMonth() + direction, 1);
            const nextMonth = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
            const window = this.formConfig.booking_window;
            if (nextMonth < window.start_month || nextMonth > window.end_month) return;
            this.currentMonth = next;
            this.renderCalendar();
        }

        nextStep() {
            // Validaciones por paso CON MODAL
            if (this.currentStep === 1 && !this.bookingData.serviceId) {
                window.ovShowAlert(this.t('select_service', 'Por favor selecciona un servicio'), 'warning', this.container);
                return;
            }

            if (this.formConfig && this.formConfig.enable_professionals) {
                // Validar que professionalId esté definido (puede ser 0 = cualquier profesional)
                if (this.currentStep === 2 && (typeof this.bookingData.professionalId === 'undefined' || this.bookingData.professionalId === null)) {
                    window.ovShowAlert(this.t('select_professional', 'Por favor selecciona un profesional'), 'warning', this.container);
                    return;
                }
            }

            const dateTimeStep = this.formConfig && this.formConfig.enable_professionals ? 3 : 2;
            if (this.currentStep === dateTimeStep) {
                if (!this.bookingData.date) {
                    window.ovShowAlert(this.t('select_date', 'Por favor selecciona una fecha'), 'warning', this.container);
                    return;
                }
                if (!this.bookingData.time) {
                    window.ovShowAlert(this.t('select_time', 'Por favor selecciona un horario'), 'warning', this.container);
                    return;
                }
            }

            const formStep = this.formConfig && this.formConfig.enable_professionals ? 4 : 3;
            if (this.currentStep === formStep) {
                if (!this.collectFormData()) {
                    window.ovShowAlert(this.t('complete_fields', 'Por favor completa todos los campos requeridos'), 'warning', this.container);
                    return;
                }
            }

            if (this.currentStep >= this.totalSteps) return;

            // Marcar paso actual como completado
            this.container.find(`.ov-progress-step[data-step="${this.currentStep}"]`)
                .removeClass('ov-active').addClass('ov-completed');

            // Avanzar paso
            this.currentStep++;

            // Activar nuevo paso
            this.container.find(`.ov-progress-step[data-step="${this.currentStep}"]`)
                .addClass('ov-active');

            // Mostrar/ocultar pasos
            this.container.find('.ov-step').removeClass('ov-active');
            this.container.find(`.ov-step[data-step="${this.currentStep}"]`).addClass('ov-active');

            // Mostrar botón atrás
            if (this.currentStep > 1) {
                $('#ov-btn-back', this.container).show();
            }

            // Ocultar botón siguiente en último paso
            if (this.currentStep === this.totalSteps) {
                $('#ov-btn-next', this.container).hide();
            }

            // Cargar contenido del paso
            this.loadStepContent();

            // Scroll al inicio
            this.container[0].scrollIntoView({ behavior: 'smooth' });
        }

        prevStep() {
            if (this.currentStep <= 1) return;

            // Desactivar paso actual
            this.container.find(`.ov-progress-step[data-step="${this.currentStep}"]`)
                .removeClass('ov-active');

            // Retroceder paso
            this.currentStep--;

            // Activar paso anterior y quitar completado
            this.container.find(`.ov-progress-step[data-step="${this.currentStep}"]`)
                .addClass('ov-active').removeClass('ov-completed');

            // Mostrar/ocultar pasos
            this.container.find('.ov-step').removeClass('ov-active');
            this.container.find(`.ov-step[data-step="${this.currentStep}"]`).addClass('ov-active');

            // Restaurar selección visual del paso profesionales
            if (this.currentStep === 2 && typeof this.bookingData.professionalId !== 'undefined') {
                setTimeout(() => {
                    this.container.find('.ov-professional-card').removeClass('ov-selected');
                    this.container.find(`.ov-professional-card[data-professional-id="${this.bookingData.professionalId}"]`).addClass('ov-selected');
                }, 100);
            }

            // Ocultar botón atrás en primer paso
            if (this.currentStep === 1) {
                $('#ov-btn-back', this.container).hide();
            }

            // Mostrar botón siguiente
            $('#ov-btn-next', this.container).show();

            // Scroll al inicio
            this.container[0].scrollIntoView({ behavior: 'smooth' });
        }

        loadStepContent() {
            switch(this.currentStep) {
                case 2:
                    if (this.formConfig && this.formConfig.enable_professionals) {
                        this.loadProfessionals();
                    } else {
                        this.renderCalendar();
                    }
                    break;
                case 3:
                    if (this.formConfig && this.formConfig.enable_professionals) {
                        this.renderCalendar();
                    } else {
                        this.loadFormFields();
                    }
                    break;
                case 4:
                    if (this.formConfig && this.formConfig.enable_professionals) {
                        this.loadFormFields();
                    } else {
                        this.showConfirmation();
                    }
                    break;
                case 5:
                    this.showConfirmation();
                    break;
            }
        }

        showError(message) {
            const msgBox = this.container.find('#ov-message');
            msgBox.removeClass('ov-success').addClass('ov-error')
                  .text(message).fadeIn();
            
            setTimeout(() => msgBox.fadeOut(), 5000);
        }

        showSuccess(message) {
            const msgBox = this.container.find('#ov-message');
            msgBox.removeClass('ov-error').addClass('ov-success')
                  .text(message).fadeIn();
        }
    }

    /**
     * Sistema de Modales Global
     */
    function initGlobalModals() {
        if (!$('#ov-modal-overlay').length) {
            $('body').append(`
                <div id="ov-modal-overlay" class="ov-modal-overlay" style="display: none;">
                    <div class="ov-modal">
                        <div class="ov-modal-header">
                            <h3 class="ov-modal-title"></h3>
                            <button class="ov-modal-close">&times;</button>
                        </div>
                        <div class="ov-modal-body"></div>
                        <div class="ov-modal-footer"></div>
                    </div>
                </div>
            `);

            $(document).on('click', '#ov-modal-overlay, .ov-modal-close', function(e) {
                if ($(e.target).is('#ov-modal-overlay') || $(e.target).is('.ov-modal-close')) {
                    window.ovCloseModal();
                }
            });
        }
    }

    window.ovShowModal = function(title, body, footer, containerEl) {
      if (!title && !body && !footer) return;

      const $modal = $('#ov-modal-overlay');

      // Limpia cualquier clase ov-booking-container-XXXX previa para evitar acumulación
      const currentClasses = ($modal.attr('class') || '').split(/\s+/);
      currentClasses.forEach(cls => {
        if (/^ov-booking-container-\d+$/.test(cls)) $modal.removeClass(cls);
      });

      // Usa el contenedor real si viene, si no cae al first() como fallback
      const $container = containerEl ? $(containerEl) : $('.ov-booking-container').first();
      if ($container.length) {
        const match = ($container.attr('class') || '').match(/ov-booking-container-\d+/);
        if (match) $modal.addClass(match[0]);
      }

      $modal.find('.ov-modal-title').html(title);
      $modal.find('.ov-modal-body').html(body);
      $modal.find('.ov-modal-footer').html(footer);
      $modal.addClass('ov-show');
    };

    window.ovCloseModal = function() {
        $('#ov-modal-overlay').removeClass('ov-show');
    };

    window.ovShowAlert = function(message, type, containerEl) {
      type = type || 'info';

      const icons = {
        success: '<i class="fas fa-check-circle"></i>',
        error: '<i class="fas fa-times-circle"></i>',
        warning: '<i class="fas fa-exclamation-triangle"></i>',
        info: '<i class="fas fa-info-circle"></i>'
      };

      const icon = icons[type] || icons.info;

      const body = `
        <div class="ov-alert-content ov-alert-${type}">
          <div class="ov-alert-icon">
            ${icon}
          </div>
          <p class="ov-alert-message">${$('<div>').text(message == null ? '' : String(message)).html()}</p>
        </div>
      `;

      const footer = `
        <button class="ov-btn ov-btn-alert ov-btn-alert-${type}" onclick="ovCloseModal()">
          ${(window.ovBooking && ovBooking.strings && ovBooking.strings.accept) ? ovBooking.strings.accept : 'Aceptar'}
        </button>
      `;

      window.ovShowModal('', body, footer, containerEl);
    };

    // Inicializar cuando el documento esté listo
    $(document).ready(function() {
        initGlobalModals();
        
        $('.ov-booking-container').each(function() {
            new OVBookingSystem(this);
        });
    });

})(jQuery);
