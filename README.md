# OV Booking System 1.9.4

Plugin de reservas para WordPress, compatible con Divi y otros temas.

## Funciones principales

- Formularios, servicios, profesionales, horarios, excepciones y días festivos.
- Reserva pública paso a paso y reserva rápida desde administración.
- Límites por horario, duración en minutos/horas/días y tiempo de separación.
- Campos personalizados, términos, colores y zona horaria por formulario.
- Pacientes reutilizables únicamente desde el panel autorizado.
- Protección contra solicitudes duplicadas, abuso y carreras de cupo.
- Opción para que el cliente añada la cita a Google Calendar o descargue `.ics`.
- Eventos del núcleo y gestor de módulos para add-ons independientes.
- Contratos internos versionados para integrar Ficha Clínica con pacientes, servicios y profesionales de la agenda.

Google Sheets y su Apps Script fueron retirados en 1.8.0. Una actualización conserva
columnas históricas existentes, pero el plugin ya no las lee, escribe ni sincroniza.

## OV Booking Superusuario

Desde 1.9.4 se registra **OV Booking Superusuario** (`ov_superuser`): administración completa del WordPress del sitio, módulos y acceso clínico. Asignarlo expresamente a la cuenta personal del administrador desde **Usuarios → Añadir nuevo/Editar → Rol**. La actualización no promueve cuentas ni modifica el registro público. Recepción conserva **OV Booking Manager**, sin ajustes técnicos ni gestión de usuarios. Licencias y comprobaciones de seguridad siguen vigentes.

## Cambios desde 1.8.9

- **1.9.3:** licencias locales administradas con fecha de vencimiento, contrato de lectura tras el vencimiento y acceso clínico en producción. Compatible con Ficha Clínica 0.2.1; sin migración de tablas.

- **1.9.0:** integración con el add-on Ficha Clínica mediante contratos internos para
  consultar pacientes y habilitar el enlace desde el detalle de una reserva.
- **1.9.1:** consultas de pacientes y profesionales para el rol OV Booking Manager.
- **1.9.2:** listado de servicios activos y selección de profesionales activos asignados
  a los servicios de cada especialidad, sin duplicados y dentro del mismo formulario.

Las consultas clínicas funcionan también en producción, requieren OV Booking Manager y una licencia que permita lectura. Ficha Clínica se instala como add-on separado; conserva sus propios permisos y datos. El nuevo modo local permite al administrador gestionar licencias desde Módulos, sin servidor adicional. El modo API sigue siendo el predeterminado. Ver [MODULOS-Y-LICENCIAS.md](MODULOS-Y-LICENCIAS.md).

El esquema de base de datos permanece en **1.8.0**. Consulta el historial completo en
[CHANGELOG.md](CHANGELOG.md) y las firmas de integración en
[MODULOS-Y-LICENCIAS.md](MODULOS-Y-LICENCIAS.md).

## Requisitos asumidos

- WordPress 5.8 o posterior.
- PHP 7.4 o posterior.
- MySQL 5.7+ o MariaDB equivalente con soporte para `GET_LOCK()`.
- HTTPS en producción.

## Uso

1. Instala el ZIP desde **Plugins > Añadir nuevo > Subir plugin**.
2. Activa OV Booking System.
3. Configura el formulario, servicios, profesionales y horarios desde **Reservas**.
4. Inserta el shortcode en la página pública:

```text
[ov_booking id="1"]
```

## Calendario del cliente

En **Reservas > Formularios > Editar > Integraciones**, activa la opción de calendario.
Al finalizar, el cliente podrá abrir un evento prellenado en Google Calendar o descargar
un archivo iCalendar compatible con Apple Calendar y Outlook.

Este flujo no accede a la cuenta del cliente ni inserta eventos silenciosamente. Una
sincronización automática requeriría OAuth y consentimiento explícito del titular.

## Ampliar una reserva

En el editor de la reserva, aumenta **Duración total (minutos)** en tramos de 30.
Por ejemplo, para añadir una hora a un chequeo de 30 minutos, introduce **90**.
El panel muestra la hora hasta la que quedará ocupado el horario, incluido el tiempo
de separación del servicio. La ampliación se rechaza si coincide con otra cita,
un descanso o el cierre del profesional. Guarda por separado cualquier cambio de
fecha, hora, estado o datos de contacto. La ampliación no envía un WhatsApp automático.

## Actualización segura

- Haz copia de la base de datos y de la carpeta del plugin.
- Si usas WhatsApp, instala primero el add-on 1.0.5 bajo su carpeta instalada.
- Actualiza reemplazando la carpeta bajo el mismo slug `ov-booking-system`.
- Comprueba `ov_booking_version=1.9.4` y `ov_booking_db_version=1.8.0`.
- No desinstales la versión anterior: la desinstalación no es un procedimiento de migración.
- Prueba en staging la creación pública, la reserva administrativa y dos solicitudes
  simultáneas sobre el último cupo.

Consulta [INSTALACION.md](INSTALACION.md) para la lista de comprobación.

## Extensiones

Los add-ons se instalan como plugins separados y se registran mediante
`ov_booking_register_modules`. Antes de persistir una reserva se aplica el filtro
`ov_booking_before_reservation_create`; después se mantienen los eventos
`ov_booking_reservation_created`, `ov_booking_reservation_updated` y
`ov_booking_reservation_deleted`.
La ampliación administrativa emite `ov_booking_reservation_updated` después de guardar.
Cada add-on decide si los campos modificados requieren una acción; WhatsApp 1.0.4
ignora los cambios que solo afectan la duración y el fin de la reserva. Actualiza
ese add-on antes de actualizar desde un núcleo anterior a 1.8.8 hacia 1.9.3.

Los filtros clínicos `ov_booking_clinical_services_v1` y
`ov_booking_clinical_professionals_v2` permiten relacionar las especialidades del add-on
con los servicios y profesionales de la agenda. Conservan los contratos anteriores;
el add-on debe comprobar la autorización y tratar los resultados `WP_Error`.
