# Módulos y licencias

## Modelo comercial

OV Booking Core es obligatorio. Cada negocio tiene una instalación independiente de WordPress y los módulos adicionales se entregan como add-ons separados.

La activación se guarda localmente por instalación, pero una licencia solo se considera válida cuando el servidor de licencias responde `valid: true`.

Para configurar el servidor de licencias en producción, define en `wp-config.php`:

```php
define('OV_BOOKING_LICENSE_API_URL', 'https://licencias.example.com/wp-json/ov-licenses/v1/validate');
```

El núcleo envía una petición JSON `POST` con:

- `action`: `activate`.
- `license_key`.
- `module`.
- `site_url`.
- `instance_id`.
- `plugin_version`.

La respuesta correcta debe ser JSON con esta forma mínima:

```json
{
  "valid": true,
  "expires_at": "2027-12-31 23:59:59",
  "message": "Licencia válida"
}
```

`expires_at` puede omitirse para una licencia sin vencimiento.

## Registrar un add-on

El add-on debe engancharse al evento de descubrimiento:

```php
add_action('ov_booking_register_modules', function () {
    OV_Module_Manager::register(array(
        'key' => 'customers',
        'name' => __('Clientes', 'ov-booking-customers'),
        'version' => '1.0.0',
        'requires' => array('core'),
        'requires_license' => true,
        'on_enable' => array('OV_Customers_Installer', 'install'),
    ));
});
```

El add-on también debe comprobar `OV_Module_Manager::current_user_can('customers')` antes de mostrar pantallas o procesar solicitudes.

Los módulos con `requires_license=false`, como WhatsApp 1.0.3, aparecen como instalados en el gestor. Su funcionamiento se controla desde los ajustes del propio add-on; la pantalla Módulos no ofrece desactivarlos. Al integrar ambos controles en una versión futura del add-on, se deberá probar la transición con instalaciones ya activas antes de cambiar esta regla.

## Eventos del núcleo

Eventos disponibles para integrar módulos:

- `ov_booking_reservation_created`: recibe `$reservation_id` y `$reservation_data`.
- `ov_booking_reservation_updated`: recibe `$reservation_id` y `$changed_data`.
- `ov_booking_reservation_deleted`: recibe `$reservation_id`.
- `ov_booking_customer_created`: recibe `$customer_id` y `$customer_data`.
- `ov_booking_customer_resolved`: recibe `$customer_id` y `$customer_data`.
- `ov_booking_modules_registered`: recibe el registro completo de módulos.

Los add-ons deben mantener sus tablas y migraciones en su propio plugin. El núcleo no debe conocer las tablas internas de Finanzas, Inventario o Reportes.

## Reglas de seguridad

### Contratos para ficha clínica desde 1.9.0

- `ov_booking_module_enabled_v1` recibe `(bool, string $module)` y devuelve el estado local del módulo.
- `ov_booking_clinical_candidate_v1` recibe `(null, array $query)` después de `plugins_loaded`. Requiere sesión y licencia activa. Acepta una sola clave: `rut` normalizado (requiere `ov_clinical_identify`) o `reservation_id` entero (misma capacidad o acceso aprobado por el add-on). Devuelve `{patient_id:int, name:string, rut:string}` o `WP_Error` 400, 403, 404 o 409. Las reservas sin RUT propio coincidente requieren revisión. El consumidor verifica el dígito.
- `ov_booking_clinical_reservation_access_v1` recibe `(false, int $patient_id)`. El add-on comprueba la asignación del usuario actual. Se invoca solo desde la consulta por reserva.
- `ov_booking_reservation_clinical_url_v1` recibe `(string $url, int $reservation_id, int $patient_id)` en el dashboard. El add-on devuelve una URL administrativa autorizada o la cadena vacía. La pantalla de destino vuelve a autorizar la solicitud.

### Contratos para ficha clínica desde 1.9.1

- `ov_booking_clinical_manager_v1` devuelve un booleano. Comprueba que la cuenta activa tenga el rol `ov_manager` y la capacidad `manage_ov_booking`.
- `ov_booking_clinical_candidate_v2` conserva los argumentos y el resultado de la versión 1. Autoriza al OV Booking Manager con licencia activa en un entorno de pruebas y aplica las mismas comprobaciones de identidad.
- `ov_booking_clinical_professionals_v1` recibe `(null, ?int $id)` con la misma autorización que la consulta de pacientes v2. Con `null` lista profesionales activos como pares `{id:int, name:string}`. Con un ID devuelve un par o `WP_Error` si el profesional no está disponible.

### Reglas generales

- No activar módulos solo ocultando menús.
- Comprobar capacidades y licencia en cada página, AJAX y endpoint REST.
- Usar nonces para formularios administrativos.
- Sanitizar entradas y escapar salidas.
- No registrar claves de licencia en logs.
- No almacenar información financiera o sensible en campos genéricos de reservas.
