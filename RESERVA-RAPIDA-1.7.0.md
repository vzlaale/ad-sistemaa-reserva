# Reserva rápida administrativa · 1.7.0

## Uso

1. En **Reservas → Calendario**, pulsa el día o **+ Reserva**.
2. Elige una de las horas disponibles. La fecha viene del calendario y el profesional preferido aparece seleccionado.
3. Completa el RUT y los datos del paciente; pulsa **Crear Reserva**.

La reserva se completa dentro del calendario. Al guardar, se vuelve a cargar el mismo mes con los filtros vigentes. Los filtros de estado y búsqueda siguen aplicándose: una reserva pendiente puede quedar fuera de un filtro de confirmadas, por ejemplo.

Formulario, servicio, profesional y fecha permanecen editables. Cambiar cualquiera de ellos invalida la hora anterior y consulta la disponibilidad correspondiente. **Nueva Reserva** comparte este flujo y conserva servicio, profesional y fecha después de guardar para agilizar la siguiente reserva.

## Preselección

Se busca el nombre completo **Claudio Marcelo Medina Toloza**, ignorando mayúsculas, acentos y espacios repetidos. No se asume ningún ID de base de datos. Debe existir un solo registro activo con ese nombre en el formulario, y estar asignado al servicio.

Se prioriza **Medicina general** si está activa y asignada a Medina. En su defecto se elige su único servicio asignado. Si hay varias alternativas sin una preferencia inequívoca, la secretaria elige. Cuando existe un filtro explícito de profesional, este tiene prioridad. Si Medina no está disponible para el servicio seleccionado, se pide elegir un profesional; no se sustituye silenciosamente por otro.

## Compatibilidad y validaciones

- Implementación compatible sintácticamente con PHP 7.4; validada también con PHP CLI 8.5.10. Se mantiene el stack WordPress del plugin, usando su jQuery y sin nuevas dependencias de producción. El diálogo requiere un navegador moderno con soporte de `HTMLDialogElement.showModal()`.
- Reutiliza los endpoints administrativos y el motor de disponibilidad existente: horarios especiales, descansos, festivos, duración, anticipación y cupos.
- Comprueba permisos y nonce, fecha válida/no pasada en la zona horaria del formulario y relación activa formulario–servicio–profesional. Al crear, conserva la comprobación de disponibilidad del servidor.
- Presenta los nombres y mensajes como texto y escapa la salida PHP. No interpreta HTML recibido como nombre del servicio o del paciente.
- Descarta consultas obsoletas y evita envíos repetidos mientras guarda. Si falla el guardado, conserva los datos del paciente. Si la conexión se pierde durante una escritura, pide revisar el calendario antes de repetirla porque el resultado puede ser incierto.
- Consulta horas de un solo día por vez; no dispara una consulta de disponibilidad para cada casilla del mes. Renderizar los botones cuesta O(H), con H horas devueltas. No modifica el coste interno del motor de disponibilidad ni los límites existentes del listado.
- No añade tablas, columnas ni migraciones. La versión del esquema permanece en 1.6.0; la versión del plugin y sus recursos es 1.7.0. No modifica los add-ons ni el formulario público.

## Verificación y alcance

Pruebas locales automatizadas con la vista y el JavaScript reales, modelos/datos ficticios, PHP 8.5.10 y Edge sin interfaz: apertura desde calendario, preselección, ausencia/duplicados de Medina, cambio de profesional, respuestas fuera de orden, día sin cupos, error de red y reintento, conflicto de disponibilidad, guardado y conservación de mes/filtros, pantalla Nueva Reserva y tamaños de escritorio/móvil. También se comprueban permisos, nonce y combinaciones inválidas en PHP.

No se ha ejecutado una prueba integral sobre una instalación real de WordPress con su base de datos ni sobre las integraciones Google. El motor heredado comprueba disponibilidad antes de insertar, pero no ofrece una operación atómica entre ambas acciones; esta versión no garantiza exclusión ante dos escrituras simultáneas desde sesiones distintas.

## Instalación

En WordPress, sube el ZIP 1.7.0 desde **Plugins → Añadir plugin → Subir plugin** y reemplaza la versión instalada del núcleo. El ZIP conserva el nombre de carpeta del paquete 1.6.0 entregado en este proyecto. Comprueba una reserva administrativa en tu entorno de pruebas antes de usar el flujo con pacientes reales.
