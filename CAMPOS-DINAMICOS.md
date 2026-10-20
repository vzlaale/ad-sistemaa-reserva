# Campos dinámicos — OV Booking 1.8.2

## Campos estándar

| Nombre interno | Tipo recomendado | Requerido | Uso |
|---|---|---:|---|
| `name` | text | Sí | Nombre del cliente |
| `surname` | text | Sí | Apellidos |
| `phone` | tel | Sí | Identificador telefónico canónico |
| `email` | email | Opcional | Correo electrónico |
| `notes` | textarea | Opcional | Notas de la reserva |

Los nombres internos se escriben en minúsculas y sin espacios. `name` y `surname` se
combinan en el nombre visible del cliente. El teléfono se valida también por el nombre
interno `phone`, incluso si una instalación antigua lo tenía configurado como `text`.

## Campos adicionales

Desde **Reservas > Formularios > Editar > Campos Formulario** puedes añadir, ordenar y
marcar como obligatorios campos como `edad`, `ciudad` o `motivo_consulta`. Sus valores
se sanitizan y se guardan dentro de `customer_data`; aparecen en administración y en la
exportación CSV.

No uses nombres reservados del sistema para información con otro significado. No
guardes contraseñas, tokens, datos de tarjeta ni secretos en campos personalizados.
