# Asignación controlada del chat de prueba al asesor

La entrada real Prueba CRM 2 se recibió por el canal `1508758925647776`. La captura
del usuario muestra la conversación sin responsable, y tres prospectos con el
mismo teléfono sin asignación vigente. La pantalla Todos los prospectos permite
consultarlos, pero no completar una asignación manual. Para comprobar la respuesta
del asesor no se requiere alterar esos registros comerciales.

`tools/asignar_chat_prueba_whatsapp.php` opera exclusivamente sobre un único chat
del canal de prueba activo con el mensaje entrante de texto exacto Prueba CRM 2.
Obtiene el asesor del usuario elegido por el operador al registrar el canal y
exige que sea prueba llamadas, con rol Asesor de Ventas y permisos de consulta y
envío activos. No utiliza un ID de asesor supuesto o un teléfono arbitrario.

CHECK es una transacción de solo lectura. APPLY toma un bloqueo exclusivo,
verifica que el chat no esté enlazado a un prospecto, seguimiento u otro asesor,
guarda un respaldo privado del responsable anterior y asigna solo el responsable
de la conversación. No crea ni modifica prospectos, asignaciones comerciales,
etapas, enlaces, mensajes, canales ni permisos. No ejecuta solicitudes a Meta ni
envíos. Se conserva el canal anterior. Una segunda aplicación es idempotente.

```bash
php tools/asignar_chat_prueba_whatsapp.php --site="$SITIO" --check
php tools/asignar_chat_prueba_whatsapp.php --site="$SITIO" \
  --backup-root="$RESPALDOS" --apply
```

La operación se bloquea si hay dos chats con el mismo mensaje de prueba, falta
el usuario del canal, ya existe otro responsable o enlace comercial, el asesor
está inactivo o faltan sus permisos. No concede consulta global al asesor.

Después de APPLY, el usuario prueba llamadas debe abrir Conversaciones y responder
el chat recibido. La interfaz del Administrador conserva consulta sin envío.
Este auxiliar no sustituye una futura bandeja de asignación de entradas desconocidas
ni implementa distribución automática por haber dado de alta un canal.

La prueba `tests/whatsapp_asignar_chat_prueba_db.php` ejecuta MariaDB 10.11 temporal
con datos sintéticos. Comprueba el control de acceso real de WhatsAppContextoService
para dos asesores, conservación del historial y de tres prospectos sin asignar,
respaldo, idempotencia y todos los bloqueos anteriores. No usa datos de producción.
