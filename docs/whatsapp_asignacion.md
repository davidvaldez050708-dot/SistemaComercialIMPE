# Bandeja y responsables de WhatsApp

La entrada de un mensaje conserva la conversación, los permisos y cualquier
contexto o responsable existente. Si el chat aún está libre, se comparan los
formatos del teléfono con prospectos con asignación vigente y seguimientos
institucionales activos. Una coincidencia única permite enlazar el expediente
y registrar al responsable activo que tenga permisos de consulta y envío.
En instituciones se comprueba además el territorio vigente del analista.
Las coincidencias múltiples o sin responsable operativo permanecen para revisión.

El Administrador dispone del acceso **Sin asignar** en Conversaciones. La bandeja
incluye únicamente chats con mensajes, en canales activos, que no tengan dueño
ni enlace previo a un expediente. Tiene búsqueda y paginación de 50 filas.
Los candidatos deben estar activos, con rol activo y permisos de consulta y envío
de WhatsApp. No se puede elegir al Administrador como operador.

La asignación manual exige el rol Administrador y los permisos existentes
`whatsapp.ver`, `whatsapp.gestionar_cuentas` y consulta global. No se concede una
nueva capacidad de asignación por tener únicamente consulta global. La petición
exige POST y CSRF. El servidor vuelve a consultar y bloquear el chat: no se puede
reasignar silenciosamente un chat ya atendido ni cambiar desde aquí una cartera.
Un reintento al mismo responsable es idempotente. Si se reconoce un único
responsable comercial distinto del seleccionado, se rechaza la operación.

El nuevo historial registra chat, responsable anterior y nuevo, actor, origen
manual/automático y fecha, dentro de la misma transacción. Un fallo de auditoría
revierte la asignación. Un fallo de enrutamiento no pierde el mensaje entrante:
éste se conserva sin asignar para revisión.

Los analistas pueden conservar la consulta territorial, pero responder por una
institución requiere ser el responsable y tener territorio vigente. Esta
restricción se aplica tanto a la interfaz de envío como al control del servidor.
Cuenta Clave conserva su alcance de aliados; no se cambian esas asignaciones.

## Instalación y pruebas

`tools/publicar_whatsapp_asignacion.py` y `whatsapp_asignacion_release.json`
forman el paquete. Se ejecutan fuera de `public_html`, con `--site`,
`--backup-root` y `--check` o `--apply`. El instalador admite Python 3.6, verifica
todos los contextos y sintaxis antes de escribir y respalda los archivos
anteriores fuera del sitio público. Conserva personalizaciones fuera de los
bloques afectados y bloquea diferencias dentro de ellos. Se añade únicamente la
tabla `whatsapp_asignaciones_historial`; no se reescriben mensajes, permisos,
canales, carteras ni responsables existentes.

`--restore RUTA_RESPALDO` restaura los archivos de esta entrega si no tienen
cambios posteriores. La restauración conserva el historial y las asignaciones
que ya se hayan realizado, para no perder la trazabilidad.

Validación automatizada con base efímera y registros sintéticos:

- `tests/whatsapp_asignacion_db.php`: búsqueda/paginación, candidatos, permisos,
  asignación, reintentos, rechazo de reasignación y expedientes enlazados,
  reconocimiento de prospectos e instituciones, ambigüedad, auditoría y rollback.
- `tests/whatsapp_contextual_db.php` y `whatsapp_contextual_http.py`: recepción
  completa, aislamiento, consulta vs. envío, POST/CSRF, errores, reconocimiento y
  conservación de mensajes ante un fallo del enrutador. El transporte Meta se
  sustituye por una implementación de prueba: no se envían mensajes reales.
- `tests/whatsapp_asignacion_ui.cjs`: Chrome aislado, formulario, CSRF, doble clic,
  conflicto, contador/estado vacío, escape HTML y ancho móvil.
- `tests/publicar_whatsapp_asignacion_test.py`: sitio ficticio, cambios selectivos,
  conservación de personalizaciones, LF/CRLF, esquema, bloqueo y restauración.

En producción, comprobar un contacto desconocido en Sin asignar, asignarlo a un
operador y verificar su acceso y el rechazo para otro operador. Probar después
un prospecto conocido, una institución y un teléfono ambiguo. La publicación no
realiza envíos a Meta ni activa campañas.
