# OV Booking WhatsApp 1.0.4

Add-on para **OV Booking System 1.8.1 o posterior**. Envía la plantilla de
confirmación mediante WhatsApp Cloud API y procesa los botones para cambiar la
reserva de `pending` a `confirmed` o `cancelled`.

La versión 1.0.4 debe instalarse antes de OV Booking System 1.8.8. Ignora las
ampliaciones que solo cambian la duración y el fin de la reserva, incluso cuando
el formulario administrativo envía campos sin cambios.

## Credenciales: completar en `wp-config.php`

No escribas secretos dentro del plugin. Agrega estas constantes antes de la línea
`/* That's all, stop editing! Happy publishing. */` de `wp-config.php`:

```php
// ===== OV BOOKING WHATSAPP: COMPLETAR =====
define('OV_BOOKING_WHATSAPP_ACCESS_TOKEN', 'PEGAR_TOKEN_PERMANENTE_AQUI');
define('OV_BOOKING_WHATSAPP_PHONE_NUMBER_ID', 'PEGAR_PHONE_NUMBER_ID_AQUI');
define('OV_BOOKING_WHATSAPP_VERIFY_TOKEN', 'CREAR_UN_TOKEN_LARGO_Y_ALEATORIO_AQUI');
define('OV_BOOKING_WHATSAPP_APP_SECRET', 'PEGAR_APP_SECRET_AQUI');
define('OV_BOOKING_WHATSAPP_API_VERSION', 'PEGAR_VERSION_GRAPH_VIGENTE_AQUI'); // Ejemplo de formato: vNN.N
// ===== FIN OV BOOKING WHATSAPP =====
```

El Access Token debe ser apto para producción y tener permiso para mensajería de
WhatsApp. No compartas el Access Token ni el App Secret por chat, correo o capturas.

## Plantilla esperada

La pantalla **Reservas > WhatsApp** permite configurar el nombre exacto, idioma,
nombre del negocio e índices de botones. El payload enviado contiene:

1. `{{1}}`: primer nombre del cliente.
2. `{{2}}`: nombre del negocio.
3. `{{3}}`: servicio.
4. `{{4}}`: fecha según WordPress.
5. `{{5}}`: hora según WordPress.
6. Botón Quick Reply índice `0`: confirmar.
7. Botón Quick Reply índice `1`: anular.

Los índices se pueden cambiar. La plantilla aprobada debe tener exactamente esas
cinco variables BODY y dos botones Quick Reply en el mismo orden configurado.

## Webhook en Meta

Después de instalar, copia desde **Reservas > WhatsApp** la Callback URL exacta. Su
forma será:

```text
https://TU-DOMINIO/wp-json/ov-booking-whatsapp/v1/webhook
```

- Usa como Verify Token el mismo valor de `OV_BOOKING_WHATSAPP_VERIFY_TOKEN`.
- Suscribe el campo `messages`.
- El proxy/WAF debe aceptar GET y POST, preservar `X-Hub-Signature-256`, no cachear
  la ruta y no mostrar CAPTCHA.

## Flujo y seguridad

- El hook del núcleo solo agrega un trabajo a la tabla outbox; el navegador no espera a Meta.
- WP-Cron procesa hasta diez trabajos y reintenta respuestas de límite de tasa con backoff.
- Los envíos con resultado incierto quedan pendientes de revisión manual para evitar duplicados automáticos.
- Al cambiar datos relevantes de la reserva se revocan los botones anteriores y se prepara un nuevo mensaje.
- Cada envío tiene un token aleatorio de 256 bits y persiste el `wamid` devuelto por Meta.
- El webhook exige firma HMAC SHA-256, Phone Number ID correcto, `context.id`, token,
  teléfono del remitente y teléfono actual de la reserva.
- `message.id` tiene índice único; una entrega repetida devuelve éxito sin repetir efectos.
- Los botones vencen al comenzar la cita y solo permiten la transición desde `pending`.
- Confirmar cambia la reserva a `confirmed`; anular la cambia a `cancelled`, estado
  que el núcleo excluye del cálculo de ocupación para liberar el horario sin borrar
  el historial.
- No se almacenan payloads completos ni teléfonos crudos en las tablas del add-on.
- La auditoría de cola y entregas queda plegada por defecto en la pantalla del add-on.
- El diagnóstico registra únicamente resultados técnicos del último POST y botón;
  nunca guarda firmas, payloads completos, teléfonos ni secretos.
- Los botones aceptan el payload privado correlacionado y, como compatibilidad,
  el texto `CONFIRMAR`/`ANULAR` siempre que coincidan mensaje original y teléfono.

## Puesta en marcha

1. Instala y activa primero OV Booking System 1.8.1.
2. Instala este add-on.
3. Completa las cinco constantes en `wp-config.php`.
4. Configura y verifica el webhook en Meta.
5. En **Reservas > WhatsApp**, comprueba que las cinco constantes figuren configuradas.
6. Verifica nombre/idioma/variables/botones y habilita los envíos.
7. Crea una reserva futura con un teléfono de prueba autorizado.
8. Comprueba envío, entrega, lectura, confirmación, anulación y duplicado del webhook.

WP-Cron depende del tráfico del sitio. En producción se recomienda que el hosting
ejecute `wp-cron.php` periódicamente para asegurar los reintentos.

## Datos al desinstalar

Por defecto se conservan las tablas de auditoría. Para borrarlas al desinstalar,
define previamente:

```php
define('OV_BOOKING_WHATSAPP_REMOVE_DATA', true);
```
