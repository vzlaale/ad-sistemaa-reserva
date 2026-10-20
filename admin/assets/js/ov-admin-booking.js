/** Reserva administrativa compartida por el calendario y Nueva Reserva. */
jQuery(function ($) {
    'use strict';
    const form = document.getElementById('ov-admin-new-booking');
    if (!form) return;
    const defaults = JSON.parse(form.dataset.defaults || '{}');
    const $form = $('#ov-form-id');
    const $service = $('#ov-service-id');
    const $professional = $('#ov-professional-id');
    const $date = $('#ov-booking-date');
    const $time = $('#ov-booking-time');
    const $slots = $('#ov-booking-slots');
    const $feedback = $('#ov-booking-feedback');
    const $patient = $('#ov-booking-patient');
    const $submit = $('#ov-create-booking');
    const dialog = document.getElementById('ov-quick-booking-dialog');
    const requests = {};
    let generation = 0;
    let saving = false;
    let patientGeneration = 0;
    let retry = null;
    const t = ovBookingAdmin.bookingStrings;

    function message(text, retryAction) {
        $feedback.text(text);
        retry = retryAction || null;
        $('#ov-booking-retry').prop('hidden', !retry);
    }
    function placeholder($select, text) {
        $select.empty().append(new Option(text, '')).prop('disabled', true);
    }
    function clearSlot() {
        $time.val('');
        $slots.empty();
        $('#ov-booking-summary').prop('hidden', true).text('');
        $patient.prop('hidden', true);
        $submit.prop('disabled', true);
    }
    function invalidate() {
        generation++;
        Object.keys(requests).forEach(function (key) {
            if (key !== 'patient' && requests[key]) requests[key].abort();
        });
        clearSlot();
    }
    // Un cambio de contexto invalida también las peticiones de sus dependencias.
    function request(key, data, done, retryAction) {
        const version = generation;
        requests[key] = $.ajax({
            url: ovBookingAdmin.ajaxurl, method: 'POST', dataType: 'json', timeout: 20000,
            data: Object.assign({ nonce: ovBookingAdmin.nonce }, data)
        }).done(function (response) {
            if (version !== generation) return;
            if (!response.success || !Array.isArray(response.data)) {
                message((response.data && response.data.message) || t.loadError, retryAction);
                return;
            }
            done(response.data);
        }).fail(function (_, status) {
            if (version === generation && status !== 'abort') message(t.connectionError, retryAction);
        });
    }
    function options($select, items, preferred) {
        placeholder($select, t.select);
        items.forEach(function (item) { $select.append(new Option(item.name, String(item.id))); });
        $select.prop('disabled', items.length === 0);
        if (items.some(function (item) { return String(item.id) === String(preferred); })) {
            $select.val(String(preferred));
        } else if (items.length === 1 && !preferred) {
            $select.val(String(items[0].id));
        }
    }
    function loadServices() {
        invalidate();
        placeholder($service, t.select);
        placeholder($professional, t.select);
        if (!$form.val()) { message(t.chooseForm); return; }
        message(t.loadingServices);
        request('services', { action: 'ov_admin_get_services', form_id: $form.val() }, function (items) {
            const preferred = (defaults[$form.val()] || {}).service;
            options($service, items, preferred);
            if ($service.val()) loadProfessionals();
            else message(items.length ? t.chooseService : t.noServices);
        }, loadServices);
    }
    function loadProfessionals() {
        invalidate();
        placeholder($professional, t.select);
        if (!$service.val()) { message(t.chooseService); return; }
        message(t.loadingProfessionals);
        request('professionals', { action: 'ov_admin_get_professionals', service_id: $service.val() }, function (items) {
            const preferred = Number(form.dataset.professional) || (defaults[$form.val()] || {}).professional;
            // Si el preferido no atiende este servicio, exigir una elección explícita.
            options($professional, items, preferred);
            if ($professional.val()) loadSlots();
            else message(items.length ? t.chooseProfessional : t.noProfessionals);
        }, loadProfessionals);
    }
    function loadSlots() {
        invalidate();
        if (!$service.val() || !$professional.val() || !$date.val()) {
            message(t.chooseContext); return;
        }
        message(t.loadingSlots);
        request('slots', {
            action: 'ov_admin_get_available_slots', form_id: $form.val(), service_id: $service.val(),
            professional_id: $professional.val(), date: $date.val()
        }, function (items) {
            message(items.length ? t.chooseTime : t.noSlots);
            items.forEach(function (slot) {
                if (!slot.available) return;
                const $button = $('<button>', { type: 'button', class: 'button ov-booking-slot', 'aria-pressed': 'false' }).text(slot.time);
                $button.on('click', function () {
                    if (saving) return;
                    $time.val(slot.time);
                    $slots.find('button').attr('aria-pressed', 'false');
                    $button.attr('aria-pressed', 'true');
                    $('#ov-booking-summary').text([
                        $date.val().split('-').reverse().join('/'), slot.time,
                        $professional.find(':selected').text(), $service.find(':selected').text()
                    ].join(' · ')).prop('hidden', false);
                    $patient.prop('hidden', false);
                    $submit.prop('disabled', false);
                    $('#ov-rut').trigger('focus');
                });
                $slots.append($button);
            });
        }, loadSlots);
    }
    $form.on('change', function () { form.dataset.professional = '0'; loadServices(); });
    $service.on('change', loadProfessionals);
    $professional.on('change', loadSlots);
    $date.on('change', function () {
        // Una fecha puede elegirse mientras aún se cargan servicios/profesionales.
        if ($service.val() && $professional.val()) loadSlots();
        else clearSlot();
    });
    $('#ov-booking-retry').on('click', function () { if (retry) retry(); });

    function clearPatient() {
        patientGeneration++;
        if (requests.patient) requests.patient.abort();
        $('#ov-rut, #ov-name, #ov-surname, #ov-phone, #ov-email, #ov-notes').val('');
        $('#ov-patient-status').text('');
    }
    $('#ov-rut').on('input', function () {
        patientGeneration++;
        if (requests.patient) requests.patient.abort();
        $('#ov-name, #ov-surname, #ov-phone, #ov-email').val('');
        $('#ov-patient-status').text('');
    }).on('change', function () {
        const value = $(this).val().trim();
        const version = ++patientGeneration;
        if (!value) return;
        const previousValues = $('#ov-name, #ov-surname, #ov-phone, #ov-email').map(function () { return this.value; }).get();
        $('#ov-patient-status').text(t.searchingPatient);
        requests.patient = $.ajax({
            url: ovBookingAdmin.ajaxurl, method: 'POST', dataType: 'json', timeout: 15000,
            data: { action: 'ov_search_patient', nonce: ovBookingAdmin.patient_search_nonce, search: value }
        }).done(function (response) {
            if (version !== patientGeneration || saving) return;
            if (!response.success) { $('#ov-patient-status').text(t.patientError); return; }
            if (!response.data.found) { $('#ov-patient-status').text(t.newPatient); return; }
            const patient = response.data.patient;
            if (patient.rut) $('#ov-rut').val(patient.rut);
            ['name', 'surname', 'phone', 'email'].forEach(function (key, index) {
                const input = document.getElementById('ov-' + key);
                if (input.value === previousValues[index]) input.value = patient[key] || '';
            });
            $('#ov-patient-status').text(t.patientFound);
        }).fail(function (_, status) {
            if (version === patientGeneration && status !== 'abort') $('#ov-patient-status').text(t.patientError);
        });
    });

    $(form).on('submit', function (event) {
        event.preventDefault();
        if (saving || !$time.val() || !form.reportValidity()) return;
        saving = true;
        patientGeneration++;
        if (requests.patient) requests.patient.abort();
        const data = {
            action: 'ov_create_booking', nonce: ovBookingAdmin.nonce,
            form_id: $form.val(), service_id: $service.val(), professional_id: $professional.val(),
            date: $date.val(), time: $time.val(), customer_data: {}
        };
        ['name', 'surname', 'rut', 'phone', 'email', 'notes'].forEach(function (key) {
            data.customer_data[key] = $('#ov-' + key).val().trim();
        });
        $('#ov-booking-fields').prop('disabled', true);
        $submit.prop('disabled', true).text(t.saving);
        $.ajax({ url: ovBookingAdmin.ajaxurl, method: 'POST', dataType: 'json', data: data })
            .done(function (response) {
                if (!response.success) {
                    const error = (response.data && response.data.message) || t.saveError;
                    loadSlots();
                    // Mantener los datos escritos aunque sea necesario elegir otra hora.
                    $patient.prop('hidden', false);
                    $('#ov-patient-status').text(error);
                    return;
                }
                clearPatient();
                if (dialog) {
                    $(document).trigger('ov:booking-created', [data, response.data]);
                    dialog.close();
                } else {
                    loadSlots();
                }
                $('#ov-booking-success').remove();
                $('<div>', { id: 'ov-booking-success', class: 'notice notice-success', role: 'status' })
                    .append($('<p>').text(t.created)).insertBefore(dialog || form);
            }).fail(function () {
                // No reintentar automáticamente una escritura cuyo resultado se desconoce.
                message(t.uncertainSave);
                clearSlot();
                $patient.prop('hidden', false);
            }).always(function () {
                saving = false;
                $('#ov-booking-fields').prop('disabled', false);
                $submit.prop('disabled', !$time.val()).text(t.create);
            });
    });
    if (dialog) {
        window.ovBookingOpenNewReservation = function (date) {
            $date.val(date);
            clearPatient();
            clearSlot();
            dialog.showModal();
            if ($service.val() && $professional.val()) loadSlots();
            else loadServices();
        };
        $('#ov-quick-booking-close').on('click', function () { if (!saving) dialog.close(); });
        $(dialog).on('cancel', function (event) { if (saving) event.preventDefault(); });
        $(dialog).on('close', function () { invalidate(); clearPatient(); });
    }
    loadServices();
});
