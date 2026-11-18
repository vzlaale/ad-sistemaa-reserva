# Instalación y actualización — OV Booking 1.9.4

## Actualización desde 1.9.3 con Ficha Clínica 0.2.1 en producción

Respaldar base y carpeta privada. Reemplazar primero el núcleo por 1.9.4 y después Ficha Clínica por 0.2.2. Mantener la configuración de producción, licencia local, almacenamiento y cron existentes. Asignar el nuevo rol **OV Booking Superusuario** a la cuenta personal del administrador desde Usuarios; ninguna cuenta cambia automáticamente. El manager mantiene sus permisos. Ver SUPERUSER.md incluido en el add-on.

## Instalación nueva

1. En WordPress abre **Plugins > Añadir nuevo > Subir plugin**.
2. Selecciona el ZIP que contiene una única carpeta raíz `ov-booking-system/`.
3. Activa el plugin.
4. Configura la zona horaria correcta del formulario.
5. Revisa servicios, horarios, profesionales, límites y campos obligatorios.
6. Publica `[ov_booking id="ID"]` en una página HTTPS.

El teléfono es obligatorio. Se aceptan números internacionales E.164 y, para Chile,
`+56 9 XXXX XXXX`, `569XXXXXXXX` o `9XXXXXXXX`; se guarda como `569XXXXXXXX`.

## Actualización desde 1.8.9 a 1.9.4

1. Crea un respaldo verificable de base de datos y archivos y ensaya en un clon.
2. Si usas WhatsApp, comprueba que el add-on instalado sea 1.0.5.
3. Sube el ZIP 1.9.4 y reemplaza el núcleo conservando la carpeta `ov-booking-system`.
4. Carga el panel y verifica `ov_booking_version=1.9.4` y `ov_booking_db_version=1.8.0`.
5. Comprueba las reservas públicas y administrativas y la edición de datos de contacto.

No se añade una migración de esquema al pasar de 1.8.9 a 1.9.4. No desinstales el
plugin para actualizarlo. Las novedades clínicas requieren el add-on Ficha Clínica;
no es necesario instalarlo para seguir utilizando las reservas.

## Actualización desde versiones anteriores a 1.8.9

1. Crea un respaldo verificable de base de datos y archivos.
2. Ensaya la actualización sobre un clon de producción.
3. Si usas WhatsApp, actualiza primero el add-on a 1.0.5 bajo su carpeta instalada.
4. Sustituye el núcleo conservando el slug `ov-booking-system`.
5. Instala la versión 1.9.4 y actívala o carga el panel para ejecutar la migración idempotente del esquema 1.8.0 cuando corresponda.
6. Verifica que `ov_booking_version` sea `1.9.4` y `ov_booking_db_version` sea `1.8.0`.

La migración añade idempotencia, origen de reserva, huella de IP no reversible, índices
de disponibilidad y contadores antiabuso. También asegura la tabla de pacientes. No
elimina reservas ni columnas históricas.

Las versiones 1.8.1 a 1.9.4 no añaden otra migración de esquema.
Comprueba que existe la tabla `wp_ov_booking_rate_limits` (con el prefijo real del sitio):
el calendario público y los horarios diarios de 1.8.5 la utilizan para limitar consultas anónimas.

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

El esquema del núcleo sigue en 1.8.0, independientemente de la versión 1.9.4 del plugin.

El bloqueo concurrente usa `GET_LOCK()` de MySQL/MariaDB durante una sección crítica
corta. Si el hosting bloquea esa función, la creación devuelve un error seguro y no
continúa sin garantía de cupo. Debe verificarse esta función en staging.

## Integración con Ficha Clínica

La versión 1.9.4 conserva los contratos clínicos de 1.9.0 y 1.9.1 y añade consultas
de servicios y profesionales asignados a esos servicios. El add-on mantiene sus
propias tablas, permisos y licencia.

Las consultas clínicas requieren `ov_manager`, `manage_ov_booking` y el módulo con licencia que permita lectura. Funcionan en producción. El administrador sin rol clínico puede gestionar licencias locales, pero no obtiene acceso a las fichas.

Para actualizar desde 1.9.2/0.2.0 al conjunto 1.9.4/0.2.2, instalar primero el núcleo; conserva compatibilidad con el add-on anterior. Después actualizar el add-on y seguir PRODUCTION.md incluido en él. Cambiar explícitamente a modo local, activar con fecha desde Módulos y finalmente pasar el entorno a producción; retirar el servidor y constantes de pruebas. No modificar las constantes del almacenamiento privado.

En el clon, comprueba la búsqueda de pacientes, el enlace desde una reserva y que
cada especialidad solo permita seleccionar profesionales activos asignados a sus
servicios. Consulta [MODULOS-Y-LICENCIAS.md](MODULOS-Y-LICENCIAS.md) para los contratos.
