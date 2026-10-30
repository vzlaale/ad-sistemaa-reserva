# OV Booking System 1.8.8

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

La versión 1.8.2 añade la consulta del RUT en reservas administrativas y corrige el
borrado masivo y la reactivación de reservas canceladas. Consulta [CHANGELOG.md](CHANGELOG.md).
La versión 1.8.3 acota las reservas públicas a seis meses calendario y limita las consultas
mensuales anónimas. El calendario usa la zona horaria configurada del formulario.
La versión 1.8.4 precarga los datos de cada consulta mensual y evita las consultas por horario.
La versión 1.8.5 comparte el límite anónimo entre consultas mensuales y diarias; el calendario
habilita únicamente las fechas que devuelve el servidor, incluidos horarios especiales.
La versión 1.8.6 calcula los días laborables de los profesionales en una consulta de lote,
conservando el campo `working_days` de la configuración pública.
La versión 1.8.7 permite ampliar una reserva activa desde el editor sin crear otra cita.
La versión 1.8.8 emite el evento de actualización también al ampliar y protege las
lecturas públicas de formularios inactivos.
El esquema de base de datos permanece en 1.8.0.

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
La ampliación administrativa emite `ov_booking_reservation_updated` después de guardar.
Cada add-on decide si los campos modificados requieren una acción; WhatsApp 1.0.4
ignora los cambios que solo afectan la duración y el fin de la reserva. Actualiza
ese add-on antes de instalar el núcleo 1.8.8.
