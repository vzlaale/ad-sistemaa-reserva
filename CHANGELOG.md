# Changelog

## 1.8.3 - En pruebas, pendiente de publicación

- La disponibilidad y la reserva públicas aceptan el mes actual y los cinco siguientes según la zona horaria del formulario.
- Las consultas mensuales y diarias validan IDs, fechas y relaciones públicas antes de calcular cupos.
- La disponibilidad mensual limita a 60 consultas anónimas por IP cada 10 minutos mediante la tabla antiabuso existente.
- El calendario bloquea meses fuera del horizonte y solo habilita días tras recibir disponibilidad; muestra un reintento ante errores.
- Sin cambios de esquema: `ov_booking_db_version` permanece en `1.8.0`.

## 1.8.2 - En pruebas, pendiente de publicación

- El borrado masivo previsualiza y verifica las reservas que coinciden con todos los filtros del listado; recorre los ID con memoria acotada y los elimina en lotes.
- El evento de eliminación se emite solo cuando una reserva fue realmente borrada.
- La reactivación de reservas canceladas comprueba disponibilidad dentro del bloqueo del formulario en ambas rutas de edición administrativa.
- Módulos sin licencia ya no muestran una acción de desactivación que no afecta su funcionamiento; sus ajustes siguen controlando el add-on.
- Se añade `.gitignore` para excluir paquetes generados y archivos locales.
- El editor de «Todas las Reservas» muestra el RUT guardado en la reserva o en su ficha vinculada, tanto desde el listado como desde el calendario; las acciones del modal permanecen visibles en pantallas bajas.

## 1.8.1 - Correcciones de seguridad y exportación

- La opción `ov_booking_version` y los metadatos de documentación reflejan 1.8.1; la versión del esquema permanece en 1.8.0.
- La reserva pública ya no modifica ni vincula fichas de pacientes sin verificar identidad.
- La aceptación obligatoria de términos se valida también en el servidor.
- El listado de reservas pagina en SQL y el CSV se exporta por lotes con celdas seguras.
- La edición de servicios y festivos comprueba el formulario propietario.
- El borrado masivo emite el evento de eliminación para cada reserva.
- Los mensajes públicos se insertan como texto cuando no requieren HTML.

## 1.8.0 - Núcleo endurecido para producción

- Retirados Google Sheets, el Apps Script, su configuración y sus sincronizaciones.
- Reemplazado el stub de Google Calendar por acciones reales para el calendario del cliente.
- Eliminada la búsqueda pública de pacientes; queda protegida por sesión, capacidad y nonce administrativo.
- Teléfono obligatorio, validado y canonicalizado en servidor.
- Idempotencia por intento público y límites atómicos por IP/teléfono.
- Bloqueo MySQL alrededor de disponibilidad e inserción para impedir sobreventa concurrente.
- IDs públicos aleatorios criptográficamente fuertes.
- Estados validados en el modelo y transición condicional atómica disponible para add-ons.
- El flujo público confirma dentro del sistema y ya no depende de abrir WhatsApp.
- La edición administrativa revalida teléfono, email y disponibilidad al mover una cita.
- Esquema de instalaciones nuevas y migraciones actualizado a 1.8.0.
- Eliminados logs administrativos que podían contener datos personales de campos.

## 1.7.0 - Reserva rápida desde el calendario

- El día del calendario abre un diálogo con horas disponibles y datos del paciente, sin navegar a Nueva Reserva.
- Preselección de Claudio Marcelo Medina Toloza activo por nombre completo, sin IDs fijos y sin elegir entre registros duplicados. Medicina general se selecciona si está asignada; los casos ambiguos requieren selección.
- Se respeta el profesional filtrado y se permite cambiar formulario, servicio, profesional y fecha.
- Nueva Reserva comparte el mismo selector de horas. Se conserva la búsqueda de paciente por RUT.
- Después de guardar se actualiza el calendario conservando mes y filtros; eventos ordenados por hora.
- Cancelación de consultas obsoletas, errores recuperables, bloqueo durante el guardado y conservación de datos del paciente si falla la reserva.
- Validación administrativa de fecha, formulario activo y asignación del profesional al servicio en el servidor, además de la validación existente de disponibilidad y permisos.
- Sin migraciones de base de datos ni cambios en las pantallas públicas o los add-ons.

## 1.4.0 - Visibilidad de profesionales

### Added
- Configuración por profesional: público o sólo reservas administrativas.
- Profesionales administrativos disponibles únicamente desde el panel de WordPress.

### Changed
- El frontend excluye profesionales administrativos y servicios sin profesionales públicos.
- La validación del servidor rechaza IDs de profesionales administrativos en reservas públicas.
- Las asignaciones antiguas mediante `services.professional_id` se normalizan en la tabla relacional sin duplicar relaciones.

## 1.1.9.2 - Internationalization hotfix

### Fixed
- Actualizada la versión visible del plugin a **1.1.9.2**.
- Traducidos los textos restantes del panel de Google Calendar.
- Unificada la terminología de Google Sheets, Google Calendar y Google Apps Script.

## 1.1.9.1 - Corrección de navegación

### Fixed
- Agregado el botón **← Volver a los Ajustes** en el panel de Horarios especiales.
- La navegación regresa directamente a la configuración del formulario correspondiente.
- Alineada la cabecera del panel con Servicios, Horarios y Días festivos.

## 1.1.9 - Horarios especiales

### Added
- Horarios temporales por rango de fechas para cada formulario.
- Selección de días de la semana dentro del periodo especial.
- Varias franjas especiales para horarios partidos.
- Opción para cerrar días completos durante un periodo.

### Changed
- Los horarios especiales sustituyen al horario semanal normal únicamente en las fechas y días configurados.
- Migración automática y segura de la nueva tabla, sin borrar configuraciones existentes.

## 1.1.8
- Corregida la disponibilidad de servicios por días cuando el formulario permite varios cupos por horario.
- Agregado indicador de cupos restantes en los horarios del frontend.
- La disponibilidad mensual ya no queda desactualizada por cache después de crear reservas.

## 1.1.7 - Day booking blocking fix

- Corrección del motor de disponibilidad para servicios configurados en días.
- Ahora una reserva de 2 días bloquea correctamente el día inicial y el día siguiente.
- La unidad Días se trata como bloqueo de días completos, no como bloqueo parcial por hora.

## 1.1.6 - Form timezone as source of truth

- La zona horaria configurada en cada formulario ahora se usa como zona oficial del negocio.
- La fecha de creación enviada a Google Sheets se genera con la zona horaria del formulario.
- El ID de reserva se genera usando la zona horaria del formulario.
- El Apps Script incluido ahora recibe `timezone` desde WordPress y la prioriza sobre la propiedad global `TIMEZONE`.
- WordPress se mantiene como fallback si el formulario no tiene zona horaria configurada.


## 1.1.5 - Created At timezone fix

- Corrige la fecha de creación enviada a Google Sheets para evitar una conversión doble de zona horaria.
- Google Sheets ahora recibe la misma hora local que se muestra en el panel de WordPress.

# Changelog

## 1.6.0 - 2026-09-05

- Añadida creación de reservas desde los calendarios del administrador y del Dashboard.
- La fecha elegida se valida y se precarga en la pantalla Nueva Reserva.
- Añadidas acciones de WhatsApp y Recordatorio al modal del calendario administrativo.
- Conservada la validación de disponibilidad existente antes de elegir la hora.

## 1.1.4 - Notes persistence fix

- Fixed: the customer notes field is now saved into the reservations `notes` column when creating a booking from the frontend.
- The notes continue to be available in `customer_data`, WhatsApp, Google Sheets, CSV export and the reservations table.

## 1.1.1 - Traducciones del panel admin

- Ampliado el diccionario interno de traducciones para el panel de WordPress.
- Corregidos textos mixtos español/inglés en formularios, reservas, filtros, ajustes, horarios, servicios y días festivos.
- Internacionalizados mensajes JavaScript del panel de reservas: exportar CSV, borrar reservas, limpiar reservas y cambio de estado.
- La versión mantiene las mejoras de la 1.1.0 para frontend, calendario y WhatsApp.


## 1.1.0 - Internacionalización

- Agregado sistema de idioma automático basado en el idioma configurado en WordPress.
- Agregadas traducciones internas para español, inglés, italiano y portugués.
- Internacionalizados los textos principales del frontend, calendario, confirmación, alertas y mensaje de WhatsApp.
- El calendario ahora usa `Intl`/locale del navegador con el idioma de WordPress para meses, días y formato de fecha.
- Agregado fallback de traducciones para textos PHP del panel que usan el text domain `ov-booking-system`.
- Se mantiene compatibilidad con Loco Translate y archivos de idioma dentro de `/languages`.

# Changelog

## 1.0.5 - Versión estable

### Added
- Columna **Notas** en el listado de reservas de WordPress y en la exportación CSV.
- Columna **Estado** en el Apps Script de Google Sheets.
- Edición de reservas desde el panel de WordPress.
- Borrado individual de reservas desde el panel de WordPress.
- Borrado masivo de reservas desde el panel de WordPress con filtros de formulario/estado.
- Migraciones seguras para ampliar columnas sin eliminar datos existentes.

### Changed
- El campo `price` de reservas ahora acepta texto descriptivo largo.
- `price_text` de servicios acepta textos descriptivos más extensos.
- La reserva se crea únicamente al confirmar vía WhatsApp.
- La hora de creación visible usa el formato y zona horaria configurados en WordPress.
- Google Sheets actualiza una reserva existente por ID en lugar de duplicarla cuando cambia el estado.

### Fixed
- Error al crear reservas cuando el campo Precio contenía texto descriptivo.
- Error de flujo donde la reserva se registraba antes de confirmar por WhatsApp.
- Lógica global de disponibilidad: un mismo profesional ya no puede recibir dos reservas solapadas aunque existan varios cupos por horario.
- Reservas por día: se muestran como bloque diario y bloquean el día completo.
- Botón de borrar reservas del panel.

### Notes
- Actualizar reemplazando el plugin desde WordPress. No desinstalar la versión anterior antes de actualizar.
- Nota histórica: esa integración fue retirada por completo en 1.8.0.
