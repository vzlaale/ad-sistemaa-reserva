# Instalación y actualización — OV Booking 1.8.2

## Instalación nueva

1. En WordPress abre **Plugins > Añadir nuevo > Subir plugin**.
2. Selecciona el ZIP que contiene una única carpeta raíz `ov-booking-system/`.
3. Activa el plugin.
4. Configura la zona horaria correcta del formulario.
5. Revisa servicios, horarios, profesionales, límites y campos obligatorios.
6. Publica `[ov_booking id="ID"]` en una página HTTPS.

El teléfono es obligatorio. Se aceptan números internacionales E.164 y, para Chile,
`+56 9 XXXX XXXX`, `569XXXXXXXX` o `9XXXXXXXX`; se guarda como `569XXXXXXXX`.

## Actualización desde 1.7 a 1.8.2

1. Crea un respaldo verificable de base de datos y archivos.
2. Ensaya la actualización sobre un clon de producción.
3. Sustituye el plugin conservando el slug `ov-booking-system`.
4. Instala la versión 1.8.2 y actívala o carga el panel para ejecutar la migración idempotente del esquema 1.8.0.
5. Verifica que `ov_booking_version` sea `1.8.2` y `ov_booking_db_version` sea `1.8.0`.

La migración añade idempotencia, origen de reserva, huella de IP no reversible, índices
de disponibilidad y contadores antiabuso. También asegura la tabla de pacientes. No
elimina reservas ni columnas históricas.

Las versiones 1.8.1 y 1.8.2 corrigen el funcionamiento sin añadir otra migración de esquema.

## Google Calendar

En el editor del formulario, pestaña **Integraciones**, activa **Calendario del cliente**.
Haz una reserva de prueba y comprueba:

- enlace **Añadir a Google Calendar**;
- descarga del archivo `.ics`;
- hora correcta según la zona horaria del formulario;
- duración correcta y evento de día completo cuando corresponda.

## Comprobación antes de producción

- Crear una reserva pública válida.
- Reenviar la misma solicitud y confirmar que devuelve el mismo ID.
- Intentar dos reservas simultáneas sobre el último cupo.
- Confirmar que un visitante no puede buscar pacientes.
- Buscar un paciente desde el panel con un usuario autorizado.
- Crear y editar una reserva administrativa.
- Probar servicios con y sin profesionales.
- Probar Calendar en móvil y escritorio.
- Revisar el log de PHP sin datos personales ni errores SQL.

## Compatibilidad de base de datos

El bloqueo concurrente usa `GET_LOCK()` de MySQL/MariaDB durante una sección crítica
corta. Si el hosting bloquea esa función, la creación devuelve un error seguro y no
continúa sin garantía de cupo. Debe verificarse esta función en staging.
