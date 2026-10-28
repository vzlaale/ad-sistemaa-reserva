# Medición de disponibilidad mensual en clon

El clon `agenda.geneal.cl` permite medir la ruta AJAX real mediante el plugin
temporal `tests/ov-booking-benchmark/`. Su panel está en Herramientas → OV Booking
Audit Probe y requiere un administrador y un nonce. Se activa únicamente en ese
host y registra `SAVEQUERIES` mientras está instalado. Tras la comparación, se
desactiva y elimina. El JSON exportado contiene tiempos, conteos, fechas,
agregados de reservas y `EXPLAIN`, sin datos de pacientes.

Para cada versión y caso, seleccionar el mismo formulario, servicio, mes y modo
de profesional; ejecutar 20 muestras secuenciales. La primera muestra se
conserva por separado. Comparar mediana y p95 de las 19 restantes, además de
`available_dates` y del contexto de reservas antes y después. Si el contexto
cambia, descartar la serie. El `EXPLAIN` de `DATE_ADD` solo aparece cuando la
ruta consulta un servicio de varios días. Si el clon no tiene tal servicio o
historial amplio, declarar ese límite en el resultado.

## Alternativa con WP-CLI

Esta medición debe ejecutarse en un **clon WordPress/MySQL** con la misma base de datos para OV Booking 1.8.3 y 1.8.5. El ensayo local de PHP no mide consultas reales ni latencia del endpoint. Mantener la tarea 02 pendiente de auditoría hasta registrar estos resultados.

1. Seleccionar un formulario activo y un servicio de varios días con reservas que empiecen antes del mes consultado y se solapen con él. Conservar un historial amplio y fijo, junto con los mismos profesionales, horarios, descansos y festivos. Registrar la cantidad total de reservas del formulario, las reservas de varios días y las que cruzan el inicio del mes.
2. En `wp-config.php` **del clon**, definir `SAVEQUERIES` como `true` antes de cargar WordPress. Instalar 1.8.3 y ejecutar `tests/measure-month-availability.php` con `wp eval-file`. Repetir al menos 20 veces para el mismo mes y profesional, guardando cada JSON y la respuesta `available_dates`. Actualizar a 1.8.5 sobre el mismo clon y repetir. Evitar nuevas reservas entre ambas series. Separar la primera medición fría del resto.
3. Configurar para cada ejecución `OV_BENCH_FORM_ID`, `OV_BENCH_SERVICE_ID`, `OV_BENCH_YEAR`, `OV_BENCH_MONTH`, `OV_BENCH_USER_ID` (administrador del clon), `OV_BENCH_OUTPUT` (archivo JSON nuevo en un directorio existente) y, si corresponde, `OV_BENCH_PROFESSIONAL_ID` (`0` para cualquier profesional). El script establece ese usuario solo en WP-CLI para que el límite anónimo no influya en la comparación; mide la misma ruta mensual a partir de su validación.
4. Comparar las fechas devueltas, conteo de consultas SQL y latencia mediana/p95 de ambas versiones. El JSON 1.8.5 incluye el `EXPLAIN` de la consulta con `DATE_ADD` cuando el servicio dura días. Revisar `key`, `type`, `rows` y `Extra` junto con el volumen de historial. Si no aparece `date_add_query`, comprobar `SAVEQUERIES` y el tipo de servicio.
5. Repetir una muestra por cada modo de profesional (sin profesionales, concreto y cualquiera), y verificar los cupos diarios. No inferir mejora o regresión del `DATE_ADD` solo desde el plan: decidir cambios de índice o de esquema a partir de las cifras del clon.

Ejemplo PowerShell, ajustando IDs, ruta del clon y mes:

```powershell
$env:OV_BENCH_FORM_ID = '1'
$env:OV_BENCH_SERVICE_ID = '2'
$env:OV_BENCH_YEAR = '2026'
$env:OV_BENCH_MONTH = '11'
$env:OV_BENCH_USER_ID = '1'
$env:OV_BENCH_PROFESSIONAL_ID = '3'
$env:OV_BENCH_OUTPUT = 'C:\ruta\clon\mediciones\mes-01.json'
wp eval-file 'C:\ruta\clon\wp-content\plugins\ov-booking-system\tests\measure-month-availability.php' --path='C:\ruta\clon'
```

El directorio `tests/` no se incluye en el ZIP instalable. Copiar estos dos archivos de medición al clon por separado o ejecutar el script desde una ruta local legible por WP-CLI. Borrar `SAVEQUERIES` al terminar: registra SQL en memoria y altera ligeramente la latencia medida, de forma similar en ambas versiones.

## Continuación de P2: varios días e historial amplio (2026-10-25)

El ensayo del 21-10-2026 ya midió WordPress/MySQL en `agenda.geneal.cl`: 20 solicitudes por versión y profesional, con la primera muestra separada. Para el servicio de 30 minutos del formulario 3, las consultas medianas bajaron de 320 a 10 (profesional 7) y de 319 a 10 (profesional 8) entre 1.8.3 y 1.8.5, con `available_dates` idéntico. Los JSON están en `dist/ov-bench-month-*.json` y el detalle en `dist/ov-booking-system-1.8.6-evidencia.md`. En ese ensayo `multi_day_reservations=0` y `crossing_month=0`; no prueba la consulta con `DATE_ADD`.

El plugin temporal `tests/ov-booking-benchmark/` versión 0.2.0 añade un fixture sintético y la ruta diaria. Requiere WordPress 5.8+, PHP 7.4+, OV Booking activo y acceso de administrador; solo carga en `agenda.geneal.cl`. El paquete listo está en `dist/ov-booking-audit-probe-clone-0.2.0.zip` (SHA-256 `D5090AB1F2F265A06A7599801E3D81B34D8B5B9F8928651344BB505B83D1C9AB`), con carpeta raíz `ov-booking-benchmark/`. Subirlo desde **Plugins > Añadir nuevo > Subir plugin**. La versión 0.2.0 no modifica el esquema del núcleo. Sus escrituras exigen capacidad `manage_options`, nonce y un identificador aleatorio del fixture.

1. **Antes de instalar o cambiar el núcleo:** respaldar base de datos y archivos del clon, registrar versiones instaladas y desactivar temporalmente integraciones salientes como WhatsApp. Conservar el ZIP de la versión inicialmente activa para restaurarla. Usar `dist/ov-booking-system-1.8.3-baseline.zip` y `dist/ov-booking-system-1.8.7-p2-current.zip` (SHA-256 `1D9E7EC49AA2826F78DAFA7D99C926CCFEE07A083359BB3E0DD91ECAD42C3325`). Este último contiene los 57 archivos del núcleo actual; el ZIP 1.8.7 anterior en `dist/` difiere en diez archivos. Ambos declaran WordPress 5.8+ y PHP 7.4+, con esquema 1.8.0. Reemplazar el plugin bajo el mismo slug; **no desinstalar el núcleo**.
2. Con 1.8.7 activo, instalar y activar el diagnóstico 0.2.0. En **Herramientas → OV Booking Audit Probe**, elegir un mes futuro dentro de la ventana pública (por ejemplo, noviembre de 2026 si la prueba se ejecuta en octubre), confirmar el respaldo, pulsar **Crear fixture** y después **Cargar historial**. La carga se puede reanudar. El diagnóstico crea dos formularios aislados, servicios de tres días, dos profesionales públicos en uno de ellos, reservas que cruzan el mes y 12 000 reservas históricas sintéticas en lotes de 150. Usa `booking_id` con prefijo aleatorio `P2<run>` y no emite hooks de creación ni envía datos a WhatsApp.
3. Guardar los IDs y el identificador `run` mostrados. Para cada versión, seleccionar en **Caso** «Sin profesional», «Profesional 1», «Profesional 2» y «Cualquier profesional»; pulsar **Cargar IDs del caso** y ejecutar 20 muestras mensuales secuenciales. Descargar cada JSON. Mantener intactos los datos del fixture al cambiar entre 1.8.3 y 1.8.7. La primera muestra queda separada de la mediana y p95 de las 19 restantes. Debe haber un `date_add_explain` en ambas versiones; si falta, detenerse y revisar el servicio/fixture.
4. En cada modo, seleccionar **Horarios diarios** y comparar por lo menos el día 1 (reserva que cruza el mes), los días 5, 10 y 20 (reservas dentro del mes), y el día 25 (control libre). Descargar una muestra por fecha y versión. Los JSON deben tener iguales `available_dates` y `available_slots` para el mismo caso, incluidos `capacity`, `used` y `remaining`.
5. Comparar pares de archivos con `tests/compare-p2-benchmarks.ps1`. Para meses de varios días usar `-RequireDateAdd`. El script falla si cambia el contexto, difieren fechas/cupos o no bajan las consultas medianas. Revisar además `key`, `type`, `rows` y `Extra` de `date_add_explain`; registrar volumen de reservas, latencia mediana/p95 y cualquier regresión. Un plan `EXPLAIN` por sí solo no demuestra una mejora.
6. Con 1.8.7 activo, pulsar **Eliminar fixture** y esperar «Fixture eliminado». Comprobar que no queden formularios ni reservas `P2<run>`. Restaurar la versión inicial del núcleo y las integraciones salientes, desactivar y borrar el diagnóstico, y verificar `ov_booking_db_version=1.8.0`. Conservar los JSON y el respaldo. Si la limpieza detecta reservas ajenas en los formularios sintéticos o falla una consulta, se detiene sin borrar las entidades: resolver con el respaldo y el identificador `run`.

Ejemplo de comparación de un par:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/compare-p2-benchmarks.ps1 `
  -Baseline dist/p2-month-1.8.3-solo.json `
  -Current dist/p2-month-1.8.7-solo.json -RequireDateAdd
```

Cuando estén las 24 mediciones de cada versión sobre **el mismo fixture**, `tests/audit-p2-evidence.ps1` valida el conjunto completo. Recibe cuatro rutas mensuales por versión y una carpeta con veinte JSON diarios por versión. Comprueba versión, mes, IDs, historial, fechas, horarios, cupos y mejora de consultas; devuelve los planes `EXPLAIN` para revisión humana y señala inconsistencias entre `capacity`, `used` y `remaining`. Un resultado `PARITY_AND_SQL_PASS_EXPLAIN_REVIEW_REQUIRED` no cierra P2 por sí solo: aún hay que evaluar el costo de `DATE_ADD`.

La tarea 02 permanece «En curso» hasta ejecutar ese ensayo y adjuntar los resultados. El instrumento preparado localmente no constituye evidencia de su ejecución en el clon.
