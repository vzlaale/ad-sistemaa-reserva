/**
 * Ejecutar con Node 22: node tests/public-calendar.js
 * DOM y AJAX aislados: verifica la interacción del calendario sin WordPress.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
    constructor(html = '') {
        this.classes = new Set((html.match(/class="([^"]*)"/) || [,''])[1].split(/\s+/).filter(Boolean));
        this.attributes = {};
        const date = html.match(/data-date="([^"]+)"/);
        if (date) this.attributes['data-date'] = date[1];
        this.children = [];
        this.handlers = {};
        this.value = '';
    }
    addClass(names) { names.split(/\s+/).forEach(name => this.classes.add(name)); return this; }
    removeClass(names) { names.split(/\s+/).forEach(name => this.classes.delete(name)); return this; }
    hasClass(name) { return this.classes.has(name); }
    attr(name) { return this.attributes[name]; }
    append(child) { this.children.push(child); return this; }
    empty() { this.children = []; this.value = ''; return this; }
    text(value) { if (value === undefined) return this.value; this.value = String(value); return this; }
    html(value) { if (value === undefined) return this.value; this.value = String(value); return this; }
    on(event, handler) { this.handlers[event] = handler; return this; }
    off(event) { delete this.handlers[event]; return this; }
    hide() { return this; }
    show() { return this; }
    prop() { return this; }
    find(selector) {
        if (selector === '#ov-calendar-grid .ov-calendar-day.ov-eligible') {
            return new Collection(this.nodes['#ov-calendar-grid'].children.filter(child => child instanceof Element && child.hasClass('ov-eligible')));
        }
        return this.nodes[selector] || (this.nodes[selector] = new Element());
    }
}
class Collection {
    constructor(elements) { this.elements = elements; }
    each(callback) { this.elements.forEach((element, index) => callback(index, element)); return this; }
    addClass(names) { this.elements.forEach(element => element.addClass(names)); return this; }
    removeClass(names) { this.elements.forEach(element => element.removeClass(names)); return this; }
    off(event) { this.elements.forEach(element => element.off(event)); return this; }
}

const root = new Element();
root.nodes = {};
const document = {};
const requests = [];
function $(input, context) {
    if (input === document) return { ready() {} };
    if (context) return context.find(input);
    if (input instanceof Element) return input;
    if (typeof input === 'string' && input.startsWith('<')) return new Element(input);
    throw new Error('Selector no previsto: ' + input);
}
$.ajax = options => {
    if (options.success || options.error) {
        requests.push(options);
        return {};
    }
    const request = {
        options,
        done(callback) { this.onDone = callback; return this; },
        fail(callback) { this.onFail = callback; return this; },
        abort() { if (this.onFail) this.onFail({}, 'abort'); }
    };
    requests.push(request);
    return request;
};
const ovBooking = { ajaxurl: '/admin-ajax.php', strings: { loading: 'Cargando', error: 'Error genérico' }, locale: 'es-ES' };
const context = { window: { OVBooking: {}, ovBooking }, document, jQuery: $, ovBooking, console, setTimeout };
vm.createContext(context);
const source = fs.readFileSync(path.join(__dirname, '..', 'public/assets/js/ov-booking.js'), 'utf8');
vm.runInContext(source.replace('class OVBookingSystem {', 'globalThis.TestBookingSystem = class OVBookingSystem {'), context);
const booking = Object.create(context.TestBookingSystem.prototype);
booking.container = root;
booking.formId = 1;
booking.formConfig = {
    booking_window: { start_date: '2026-10-20', end_date: '2027-03-31', start_month: '2026-10', end_month: '2027-03' },
    working_days: [], holidays: ['2026-10-22'], week_starts_on: 1
};
booking.currentMonth = new Date(2026, 9, 1);
booking.bookingData = { serviceId: 2, professionalId: 3 };
booking.monthRequestId = 0;
booking.monthRequest = null;
booking.resetCreatedBooking = () => {};

function day(date) {
    return root.find('#ov-calendar-grid').children.find(element => element instanceof Element && element.attr('data-date') === date);
}
booking.renderCalendar();
let request = requests.at(-1);
assert.equal(request.options.data.action, 'ov_get_month_availability');
assert.equal(day('2026-10-19').hasClass('ov-disabled'), true);
assert.equal(day('2026-10-20').hasClass('ov-pending'), true);
assert.equal(day('2026-10-21').hasClass('ov-pending'), true, 'Horario especial, fuera de working_days, espera al servidor');
assert.equal(day('2026-10-22').hasClass('ov-pending'), true, 'Festivo general espera al servidor para el profesional concreto');
assert.equal(day('2026-10-21').handlers.click, undefined, 'No se puede seleccionar durante la carga');
request.onDone({ success: true, data: { available_dates: ['2026-10-20', '2026-10-21', '2026-10-22'] } });
assert.equal(day('2026-10-21').hasClass('ov-available'), true);
assert.equal(day('2026-10-22').hasClass('ov-available'), true);
assert.equal(typeof day('2026-10-21').handlers.click, 'function');
assert.equal(day('2026-10-23').hasClass('ov-disabled'), true, 'Un día ausente de available_dates queda deshabilitado');

booking.renderCalendar();
request = requests.at(-1);
request.onFail({ responseJSON: { data: { message: 'Límite de consultas' } } }, 'error');
assert.equal(day('2026-10-21').hasClass('ov-disabled'), true);
assert.equal(day('2026-10-21').handlers.click, undefined);
const status = root.find('#ov-calendar-status');
assert.equal(status.children[0].value, 'Límite de consultas');
status.children[1].handlers.click();
requests.at(-1).onDone({ success: true, data: { available_dates: ['2026-10-21'] } });
assert.equal(day('2026-10-21').hasClass('ov-available'), true, 'Reintento vuelve a habilitar la fecha devuelta');

booking.changeMonth(5);
assert.equal(booking.currentMonth.getMonth(), 2);
assert.equal(day('2027-03-31').hasClass('ov-pending'), true, 'Último día de la ventana espera al servidor');
booking.changeMonth(1);
assert.equal(booking.currentMonth.getMonth(), 2, 'No navega al séptimo mes');

booking.loadTimeSlots('2027-03-31');
requests.at(-1).error({ status: 429, responseJSON: { data: { message: '<b>Espera unos minutos</b>' } } });
assert.equal(root.find('#ov-time-grid').children[0].value, '<b>Espera unos minutos</b>', 'El mensaje diario se inserta como texto');
assert.equal(root.find('#ov-time-grid').value, '', 'El mensaje no se inserta como HTML');
booking.loadTimeSlots('2027-03-31');
requests.at(-1).error({ status: 503, responseJSON: { data: { message: 'Servicio temporalmente no disponible' } } });
assert.equal(root.find('#ov-time-grid').children[0].value, 'Servicio temporalmente no disponible');

console.log('Calendario público y errores diarios: OK');
