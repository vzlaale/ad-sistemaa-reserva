# Changelog

## 1.0.4

- El listener comprueba cambios relevantes antes de consultar o modificar la cola; una ampliación de duración no invalida botones ni programa mensajes.
- Las transiciones de estado sin snapshot anterior conservan su procesamiento.
- Sin cambios de esquema; actualizar antes de OV Booking System 1.8.8.

## 1.0.3

- Se invalidan los botones al modificar una reserva y se reenvía la plantilla actualizada.
- Los resultados de envío inciertos requieren revisión manual antes de reencolar.
- El worker evita sobrescribir una fila renovada mientras había un envío en curso.
- La generación de tokens falla de forma segura si no hay entropía criptográfica.

## 1.0.2

- Diagnóstico seguro del último POST de Meta, presencia de firma y resultado del botón.
- Lectura alternativa de respuestas Quick Reply mediante texto o título del botón.
- La compatibilidad alternativa conserva la correlación por mensaje original y teléfono.
- Lectura de respaldo de `X-Hub-Signature-256` desde el entorno del servidor.

## 1.0.1

- Se simplifica la pantalla administrativa y la cola técnica queda plegada por defecto.
- Se documenta el resultado operativo de los botones: confirmar cambia a `confirmed`
  y anular cambia a `cancelled`, liberando el horario sin borrar el historial.
- Se amplía la cobertura automática del webhook para confirmar y anular.

## 1.0.0

- Compatibilidad mínima con OV Booking System 1.8.0.
- Outbox persistente, worker asíncrono y reintentos con backoff.
- Envío de plantilla con cinco variables y dos botones Quick Reply.
- Persistencia y seguimiento de `wamid` y estados de entrega.
- Webhook firmado, correlacionado y deduplicado.
- Validación de receptor, remitente, contexto, token, vigencia y estado.
- Transiciones atómicas de confirmación y anulación.
- Pantalla administrativa sin exposición de secretos.
