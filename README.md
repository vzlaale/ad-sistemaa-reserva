# OV Booking System 1.8.1

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

Google Sheets y su Apps Script fueron retirados en 1.8.0. Una actualización conserva
columnas históricas existentes, pero el plugin ya no las lee, escribe ni sincroniza.

La versión 1.8.1 incorpora las correcciones de seguridad y exportación descritas en
[CHANGELOG.md](CHANGELOG.md). El esquema de base de datos permanece en 1.8.0.

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

## Actualización segura

- Haz copia de la base de datos y de la carpeta del plugin.
- Actualiza reemplazando la carpeta bajo el mismo slug `ov-booking-system`.
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
