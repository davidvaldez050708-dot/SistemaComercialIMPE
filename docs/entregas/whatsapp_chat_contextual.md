# WhatsApp: chat contextual compartido

## Resultado

El botón de WhatsApp del embudo abre una ventana flotante sobre el tablero. El expediente de Seguimiento del analista y «Abrir conversación» de Aliados utilizan el mismo panel. Las conversaciones conservan la clave única cuenta/teléfono del módulo existente. No se abre WhatsApp Web ni se cambia de pantalla; abrir un chat no envía mensajes, no modifica etapas y no completa actividades comerciales.

El panel reutiliza el chat y los endpoints de mensajes existentes mediante una vista de sólo esa conversación. La bandeja central usa la misma autorización. No carga el teléfono, la navegación completa ni el encabezado global dentro del panel. Se conserva el borrador en memoria por contexto al cerrar/reabrir, sin guardarlo en localStorage. Al cerrar se destruye el iframe y se detiene su consulta periódica. Una respuesta de apertura atrasada no vuelve a abrir el panel cerrado.

## Acceso

- `whatsapp.ver`: consulta de conversaciones autorizadas, no acceso a la cartera completa por ser propietario técnico de un canal.
- Prospectos enlazados: acceso por asignación comercial vigente. Al cambiar de asesor, el anterior pierde acceso; se presenta al responsable vigente.
- Seguimientos: el analista requiere territorio vigente, usuarios/roles/estado activos y permiso para consultar seguimientos. No se concede acceso por autoría histórica.
- Aliados: Cuenta Clave requiere asignación vigente y `aliados.ver`. Se conserva la selección de un contacto confirmado y autorizado para WhatsApp. Analista y Cuenta Clave comparten la conversación cuando corresponde al mismo contacto del seguimiento.
- `whatsapp.ver_todas`: consulta global explícita. El permiso histórico `whatsapp.gestionar_conversaciones`, descrito como supervisión, mantiene su alcance de consulta para no retirar los accesos ya concedidos.
- `whatsapp.enviar`: envío dentro del alcance propio. Responder conversaciones ajenas requiere además `whatsapp.responder_todas`. Consultar todo no concede ese permiso. La política vigente del Administrador sigue impidiendo el envío operativo.
- `whatsapp.gestionar_cuentas` sigue separado. Esta entrega no modifica credenciales ni concede permisos a usuarios o roles.

La apertura contextual es un POST con CSRF, con tipo e identificador de origen; el cliente no decide el destinatario, canal ni responsable. Una conversación de otro contexto o responsable no se reclama por conocer su teléfono. El GET histórico `abrirAliado` deja de crear conversaciones y dirige al uso del botón contextual. El panel exige autorización incluso si se solicita su URL directamente.

Las entradas del webhook se enlazan al prospecto sólo con coincidencia exacta y única del teléfono normalizado y asignación vigente. Una entrada desconocida o ambigua queda sin asignación automática y disponible a la consulta global autorizada. No se crea un distribuidor ni una interfaz de transferencia en esta entrega.

## Envío

Se conservan la ventana de atención y la comprobación transversal de No contactar de Cloud API. Las conversaciones comerciales enlazadas en Recuperación o Descartados bloquean la salida. El panel indica configuración pendiente sin ofrecer envío cuando faltan las credenciales. La plantilla inicial de prueba sólo se ofrece y acepta en un canal de tipo PRUEBA; no constituye un catálogo de plantillas de producción.

El cliente evita doble clic y adjunta un identificador de intento a los mensajes de texto. El servidor conserva el resultado durante 15 minutos en la sesión y evita reenviar un intento repetido; un mismo identificador con contenido diferente se rechaza. Esto no equivale a entrega al destinatario ni proporciona deduplicación entre sesiones independientes. No se reenvía automáticamente tras errores de red. Si Meta acepta un mensaje pero falla el historial, se informa expresamente para evitar otro envío.

Un error 401/403 durante la consulta retira el historial visible, deshabilita el envío y detiene la consulta. Los errores conservan el borrador y no se convierten en confirmaciones de envío.

## Entrega

`tools/publicar_whatsapp_contextual_hostinger.py` proporciona CHECK, APPLY, ROLLBACK y una auditoría de hashes sin contenido. Verifica trece archivos y dependencias sensibles de autorización contra bases exactas; ante personalizaciones desconocidas bloquea. No modifica el encabezado, pie, configuración local de WhatsApp ni otras credenciales.

CHECK sólo consulta metadatos. APPLY conserva y verifica el respaldo del código antes de preparar una tabla aditiva `whatsapp_conversacion_prospectos` y dos entradas del catálogo de permisos. No altera mensajes, prospectos, etapas, asignaciones o permisos concedidos. La preparación aditiva puede dejar la tabla creada si el alojamiento interrumpe el DDL: el código anterior se conserva y se puede reintentar después de revisar el error. Un fallo al publicar archivos activa la reversión del código. ROLLBACK preserva los mensajes y la tabla de enlaces; no ejecuta DROP ni revoca permisos concedidos después. Como vuelve al código previo, también vuelve a su política previa de acceso.

Opciones:

```text
--site SITIO --stage ENTREGA --check
--site SITIO --audit
--site SITIO --stage ENTREGA --backup-root PRIVADO --apply --confirm PUBLICAR_WHATSAPP_CONTEXTUAL_IMPE
--site SITIO --backup-dir RESPALDO_EXACTO --rollback --confirm REVERTIR_WHATSAPP_CONTEXTUAL_IMPE
```

Conservar la entrega privada y la ruta `ROLLBACK_BACKUP`. Si CHECK bloquea una personalización, revisar esa variante antes de publicar: no ampliar la lista de hashes sin revisar el archivo.

### Variante de Hostinger auditada

Para las bases compartidas el 18 de noviembre, usar `tools/publicar_whatsapp_contextual_compatible_hostinger.py` con las mismas opciones y confirmaciones. Reconoce las versiones exactas de PermissionHelper, AdminRolePolicy, SupervisionRolePolicy e index.php y las conserva byte por byte. No modifica los permisos de Respaldo, la conexión compartida, el manejador de fallos temporales, las rutas de Agenda/Inscritos ni la protección CSRF de Correo Marketing.

Incluye dos vistas adaptadas, con hashes propios: el tablero parte de la versión de tarjetas realmente instalada, sin exigir el cambio anterior de ubicación de notificaciones; Aliados conserva sus iconos, avance y respuestas de difusión, historial desplazable y parcial de avance. Las únicas modificaciones de esas vistas son el acceso contextual de WhatsApp y la carga de su cliente. La prueba de reversión textual recupera exactamente las vistas originales auditadas.

`tests/fixtures/whatsapp_hostinger_20261118.py` reconstruye las trece bases y compara cada SHA-256 con la auditoría. `tests/publicar_whatsapp_hostinger_compat_test.py` comprueba la publicación, rechazo de variantes desconocidas y reversión contra esas bases con MariaDB sintética. `tests/whatsapp_hostinger_compat_runtime.py` prepara una copia desechable con las políticas y vistas adaptadas, y ejecuta las pruebas de chat/HTTP, permisos, navegador y cinco etapas del embudo. No utiliza credenciales ni datos de producción.

## Verificación y límites

Pruebas realizadas con MariaDB local desechable y datos sintéticos: reutilización de chat, asignaciones/territorio, consentimiento, permisos globales separados, entradas desconocidas y ambiguas, eventos repetidos, bloqueo de salida, CSRF, HTTP real e idempotencia. El transporte Meta de los tests HTTP se sustituye expresamente por un fixture: no se enviaron mensajes reales. Se comprobaron publicación, metadatos, respaldo, idempotencia, reversión y fallo parcial, conservando el contenido de mensajes/asignaciones/enlaces y la configuración local. Regresión de tarjetas y supervisión comercial aprobada.

El navegador real comprobó panel en escritorio y móvil, borrador, doble clic, fallo visible, retirada de acceso y configuración pendiente, usando el HTML renderizado por PHP y el cliente real. Los tests no acreditan una conexión de producción con Meta.

### Ventana flotante compacta

`tools/publicar_whatsapp_chat_compacto_hostinger.py` publica únicamente CSS, cliente contextual y las dos vistas que cargan sus recursos. No consulta SQL ni modifica permisos, controladores, mensajes o configuración de Meta. Mantiene bases exactas, respaldo, APPLY idempotente, ROLLBACK y reversión automática ante fallo parcial. Las confirmaciones son `PUBLICAR_WHATSAPP_CHAT_COMPACTO_IMPE` y `REVERTIR_WHATSAPP_CHAT_COMPACTO_IMPE`.

La ventana mide 460 × 680 px en escritorio, con límites según el viewport y separación de sus bordes. Usa encabezado azul/teal, historial de fondo suave y mensajes de 14 px, en lugar de heredar los 9.5 px del módulo completo. No oscurece ni bloquea el tablero. El marco exterior y el documento del iframe no desbordan: sólo el historial tiene desplazamiento vertical. El editor conserva el desplazamiento propio necesario para borradores largos. Escape funciona también dentro del iframe y conserva el borrador.

Los recursos incorporan su hash en la URL para evitar caché antigua. La advertencia de configuración ocupa una franja discreta y no repite avisos redundantes en el pie. Las pruebas de navegador verifican dimensiones, legibilidad, uso del tablero, un solo scroll con 45 mensajes, pantalla móvil de poca altura, Escape, borrador y los controles existentes. Se revisaron capturas de escritorio y móvil. `tests/publicar_whatsapp_chat_compacto_test.py` prueba publicación y recuperación con una conexión SQL que falla si se intenta utilizar, y verifica que controladores y configuración no cambian.

Todavía es necesario configurar Access Token, Verify Token y App Secret de forma privada, registrar el canal y comprobar el webhook con el número de prueba. La migración del número institucional se hará después de verificar envío y recepción reales. No se implementa aquí un bot, campañas, transferencia de conversaciones, nuevas notificaciones de WhatsApp en la campana ni cambios automáticos de etapa a partir del contenido del chat.
