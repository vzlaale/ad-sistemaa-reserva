(function ($) {
    'use strict';

    $(function () {
        const cfg = window.ovBenchConfig;
        const status = $('#ov-bench-status');
        const output = $('#ov-bench-output');
        const button = $('#ov-bench-run');
        const download = $('#ov-bench-download');
        let report = null;
        let fixtureState = null;
        let fixtureBusy = false;

        function showFixture(state) {
            fixtureState = state || null;
            $('#ov-bench-fixture-details').text(fixtureState ? JSON.stringify(fixtureState, null, 2) : 'No hay fixture activo.');
        }

        async function fixtureCall(action, extra) {
            const response = await $.ajax({
                url: cfg.ajaxurl, method: 'POST', dataType: 'json',
                data: Object.assign({ action: action, benchmark_nonce: cfg.nonce }, extra || {})
            });
            if (!response.success) throw new Error(errorMessage({ responseJSON: response }));
            return response.data;
        }

        function fixtureError(error) {
            const message = errorMessage(error);
            return message === 'La solicitud falló.' && error.message ? error.message : message;
        }

        fixtureCall('ov_bench_fixture_status').then(showFixture).catch(function (error) {
            $('#ov-bench-fixture-status').text(fixtureError(error));
        });

        $('#ov-bench-fixture-create').on('click', async function () {
            if (fixtureBusy) return;
            fixtureBusy = true;
            try {
                const state = await fixtureCall('ov_bench_fixture_create', {
                    month: $('#ov-bench-month').val(),
                    backup_confirmed: $('#ov-bench-backup').prop('checked') ? '1' : '0'
                });
                showFixture(state);
                $('#ov-bench-fixture-status').text('Fixture creado. Carga el historial antes de medir.');
            } catch (error) {
                $('#ov-bench-fixture-status').text(fixtureError(error));
                try { showFixture(await fixtureCall('ov_bench_fixture_status')); } catch (_) {}
            } finally {
                fixtureBusy = false;
            }
        });

        $('#ov-bench-fixture-seed').on('click', async function () {
            if (fixtureBusy || !fixtureState) return;
            fixtureBusy = true;
            try {
                while (fixtureState.phase !== 'seeded') {
                    showFixture(await fixtureCall('ov_bench_fixture_seed', { run: fixtureState.run }));
                    $('#ov-bench-fixture-status').text('Reservas históricas: ' +
                        (fixtureState.seeded_solo + fixtureState.seeded_pro) + ' de ' +
                        (fixtureState.target_solo + fixtureState.target_pro));
                }
                $('#ov-bench-fixture-status').text('Historial completo. Guarda los IDs y ejecuta las mediciones.');
            } catch (error) {
                $('#ov-bench-fixture-status').text(fixtureError(error));
                try { showFixture(await fixtureCall('ov_bench_fixture_status')); } catch (_) {}
            } finally {
                fixtureBusy = false;
            }
        });

        $('#ov-bench-fixture-cleanup').on('click', async function () {
            if (fixtureBusy || !fixtureState) return;
            if (!window.confirm('¿Eliminar únicamente el fixture sintético identificado como ' + fixtureState.run + '?')) return;
            fixtureBusy = true;
            try {
                let result;
                do {
                    result = await fixtureCall('ov_bench_fixture_cleanup', { run: fixtureState.run });
                    $('#ov-bench-fixture-status').text('Limpieza en lotes: ' + result.deleted + ' reservas en el último lote.');
                } while (result.remaining);
                showFixture(null);
                $('#ov-bench-fixture-status').text('Fixture eliminado.');
            } catch (error) {
                $('#ov-bench-fixture-status').text(fixtureError(error));
                try { showFixture(await fixtureCall('ov_bench_fixture_status')); } catch (_) {}
            } finally {
                fixtureBusy = false;
            }
        });

        $('#ov-bench-fixture-use').on('click', function () {
            if (!fixtureState || !fixtureState.solo_form || !fixtureState.pro_form) return;
            const kind = $('#ov-bench-fixture-case').val();
            const solo = kind === 'solo';
            $('#ov-bench-form').val(solo ? fixtureState.solo_form : fixtureState.pro_form);
            $('#ov-bench-service').val(solo ? fixtureState.solo_service : fixtureState.pro_service);
            $('#ov-bench-professional').val(solo ? '' : (kind === 'any' ? '0' :
                fixtureState.professional_ids[kind === 'pro1' ? 0 : 1]));
            $('#ov-bench-month').val(fixtureState.month);
            $('#ov-bench-date').val(fixtureState.month + '-01');
        });

        $('#ov-bench-route').on('change', function () {
            $('#ov-bench-runs').val($(this).val() === 'day' ? '1' : '20');
        });

        function token() {
            return Array.from(window.crypto.getRandomValues(new Uint8Array(16)))
                .map(value => value.toString(16).padStart(2, '0')).join('');
        }

        function errorMessage(error) {
            return error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message
                ? String(error.responseJSON.data.message) : 'La solicitud falló.';
        }

        function summarize(samples, field) {
            const values = samples.map(sample => Number(sample.metrics[field])).sort((a, b) => a - b);
            if (!values.length) return null;
            const middle = Math.floor(values.length / 2);
            return {
                median: values.length % 2 ? values[middle] : (values[middle - 1] + values[middle]) / 2,
                p95: values[Math.ceil(values.length * 0.95) - 1],
                min: values[0], max: values[values.length - 1]
            };
        }

        async function take(sampleToken) {
            for (let attempt = 0; attempt < 10; attempt++) {
                try {
                    const result = await $.ajax({ url: cfg.ajaxurl, dataType: 'json', data: {
                        action: 'ov_bench_take', benchmark_nonce: cfg.nonce, benchmark_token: sampleToken
                    } });
                    if (result.success) return result.data;
                } catch (error) {
                    if (!error || error.status !== 404) throw error;
                }
                await new Promise(resolve => setTimeout(resolve, 300));
            }
            throw new Error('No se recibieron métricas de la solicitud.');
        }

        async function context(formId, serviceId, month) {
            const response = await $.ajax({ url: cfg.ajaxurl, dataType: 'json', data: {
                action: 'ov_bench_context', benchmark_nonce: cfg.nonce,
                form_id: formId, service_id: serviceId, month: month
            } });
            if (!response.success) throw new Error('No se pudo leer el contexto.');
            return response.data;
        }

        button.on('click', async function () {
            if (!cfg.savequeries) {
                status.text('SAVEQUERIES está desactivado. No se pueden capturar las consultas SQL.');
                return;
            }
            const formId = $('#ov-bench-form').val();
            const serviceId = $('#ov-bench-service').val();
            const month = $('#ov-bench-month').val();
            const professional = $('#ov-bench-professional').val();
            const date = $('#ov-bench-date').val();
            const mode = $('#ov-bench-route').val();
            const runs = Number($('#ov-bench-runs').val());
            if (!/^[1-9]\d*$/.test(formId) || !/^[1-9]\d*$/.test(serviceId) ||
                !/^\d{4}-(0[1-9]|1[0-2])$/.test(month) ||
                (professional !== '' && !/^(0|[1-9]\d*)$/.test(professional)) ||
                (mode === 'day' && (!/^\d{4}-(0[1-9]|1[0-2])-[0-3]\d$/.test(date) || date.slice(0, 7) !== month)) ||
                !Number.isInteger(runs) || runs < 1 || runs > 20) {
                status.text('Revisa IDs, mes, fecha diaria y número de muestras (1 a 20).');
                return;
            }

            button.prop('disabled', true);
            download.prop('disabled', true);
            report = {
                kind: mode, form_id: Number(formId), service_id: Number(serviceId),
                month: month, professional_id: professional === '' ? null : Number(professional),
                date: mode === 'day' ? date : null,
                started_at: new Date().toISOString(), initial_context: null,
                final_context: null, data_changed: null, samples: [], summary: null
            };
            try {
                report.initial_context = await context(formId, serviceId, month);
                for (let index = 0; index < runs; index++) {
                    status.text('Muestra ' + (index + 1) + ' de ' + runs + '...');
                    const sampleToken = token();
                    const request = {
                        action: mode === 'config' ? 'ov_get_form_config' :
                            (mode === 'day' ? 'ov_get_available_slots' : 'ov_get_month_availability'),
                        form_id: formId, benchmark_nonce: cfg.nonce, benchmark_token: sampleToken
                    };
                    if (mode !== 'config') {
                        request.service_id = serviceId;
                        if (mode === 'day') request.date = date;
                        else {
                            request.year = month.slice(0, 4);
                            request.month = String(Number(month.slice(5)));
                        }
                        if (professional !== '') request.professional_id = professional;
                    }
                    const response = await $.ajax({ url: cfg.ajaxurl, dataType: 'json', data: request });
                    if (!response.success) throw new Error(errorMessage({ responseJSON: response }));
                    const metrics = await take(sampleToken);
                    report.samples.push({
                        index: index + 1,
                        metrics: metrics,
                        available_dates: mode === 'month' ? response.data.available_dates : undefined,
                        available_slots: mode === 'day' ? response.data : undefined,
                        working_days: mode === 'config' ? response.data.working_days : undefined,
                        holidays: mode === 'config' ? response.data.holidays : undefined
                    });
                }
                report.final_context = await context(formId, serviceId, month);
                report.data_changed = JSON.stringify(report.initial_context) !== JSON.stringify(report.final_context);
                report.summary = {
                    elapsed_ms: summarize(report.samples.slice(1), 'elapsed_ms'),
                    sql_queries: summarize(report.samples.slice(1), 'sql_queries'),
                    sql_time_ms: summarize(report.samples.slice(1), 'sql_time_ms'),
                    cold_sample_separate: report.samples.length > 1
                };
                status.text(report.data_changed ? 'Cambió el historial: descarta esta serie.' : 'Medición completada.');
            } catch (error) {
                report.error = errorMessage(error) === 'La solicitud falló.' && error.message ? error.message : errorMessage(error);
                status.text('Medición interrumpida: ' + report.error);
            } finally {
                report.finished_at = new Date().toISOString();
                output.val(JSON.stringify(report, null, 2));
                download.prop('disabled', report.samples.length === 0);
                button.prop('disabled', false);
            }
        });

        download.on('click', function () {
            if (!report) return;
            const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'ov-bench-' + report.kind + '-' + (report.samples[0] ? report.samples[0].metrics.plugin_version : 'unknown') + '.json';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        });
    });
})(jQuery);
