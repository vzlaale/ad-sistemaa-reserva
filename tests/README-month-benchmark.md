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
