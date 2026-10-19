# Fase B: almacenamiento comercial

Depende de A. La interfaz operativa completa se incorpora en C; esta etapa prepara propiedad y resultados independientes. No ejecutar migración hasta revisar y respaldar la base de producción.

- Tabla nueva `telefonia_ventas_llamadas`: propietario histórico por PBX + extensión, dirección, número, nombre informado y solicitud comercial exacta. La atribución se captura después del webhook existente, sin duplicar eventos. Una reasignación no transfiere filas antiguas al nuevo titular.
- Tabla existente `telefonia_ventas_resultados`: agregar observación, nombre del prospecto, contacto personal opcional y próxima llamada. No se borra ni transforma el historial anterior. Nuevos resultados definitivos; la unicidad existente impide duplicados y cambios de propietario.
- No se crean instituciones, expedientes ni verificaciones de Vinculación.
- Los controladores y webhooks no ejecutan esta migración. Si falta, la operación comercial informa que requiere instalación; el webhook original continúa guardando sus eventos.

Después de publicar selectivamente los archivos de B, desde la raíz real del CRM en SSH:

```bash
php tools/migrar_telefonia_ventas.php --check
```

El modo check sólo lee estructura. Con copia comprobada de la base, ejecutar explícitamente:

```bash
php tools/migrar_telefonia_ventas.php --apply
php tools/migrar_telefonia_ventas.php --check
```

Las operaciones son aditivas e idempotentes. MySQL realiza commits implícitos de DDL: si se interrumpe, volver a revisar y completar, sin borrar columnas. El rollback del código usa el respaldo del publicador; conservar las columnas y filas nuevas para no perder atenciones. Una restauración integral de base se decide aparte con la copia previa, nunca automáticamente.

La consulta de audio sólo enumera referencias firmadas del tramo exacto de la extensión comercial. Un NOTIFY_RECORD global o perteneciente a 100 no acredita un segmento de 150. Buzón y conexión PBX no son conversación humana verificada. Los estados sin evidencia permanecen pendientes.

Pruebas: base desechable, sintaxis y regresiones de identidad y atención institucional. Las llamadas reales 100 → 150 siguen pendientes de validación después de la instalación de las fases dependientes.

## Fase D: agenda y devoluciones comerciales

Volver a ejecutar `--check` del migrador después de publicar D. Esta fase añade `telefonia_ventas_devolucion_intentos` (solicitud/version y referencia propia por intento), y correo, observación y próxima acción a `telefonia_contactos_personales`. Agrega también `recordatorio_atendido_at` al resultado comercial para separar el cierre posterior de un aviso del resultado definitivo. No modifica expedientes ni contactos existentes.

El resultado comercial continúa siendo la única clasificación del intento. El guardado del resultado, la vinculación opcional de un contacto y el cierre exacto de una devolución son una transacción. Buzón, sin respuesta, ocupado, comunicación interrumpida, otro resultado y seguimiento conservan la gestión pendiente. No hay cierre manual que invente una llamada. Si dos referencias son compatibles con un intento, se informa la ambigüedad y no se elige por teléfono.

Los contactos se editan explícitamente por ID propio. Un número ya guardado no sobrescribe el nombre de otra persona; se pide editar el contacto existente. La programación de agenda no crea resultados ni conversaciones.

## Fase E: avisos y conciliación

La última versión del migrador también prepara la tabla legacy de intenciones `telefonia_ventas_marcaciones` si todavía no existe; no altera sus filas ni las atribuye por número solamente. La recuperación exige una relación única entre intención y evento real, en ambos sentidos. El historial se reconstruye por conjuntos y consulta los eventos mínimos dentro del intervalo seleccionado (30 días por defecto, hasta 90).

Las notificaciones comerciales usan `telefonia_notificaciones`, su lectura existente y los toasts del CRM. Conservan los ocho minutos compartidos. Disponibilidad aceptada, transferencia enviada, respuesta de destino y resultado son estados distintos. Una respuesta PBX tardía retira el aviso falso de pérdida; una devolución automática sólo se cierra con el resultado correspondiente. Nunca se presume retorno automático de Zadarma.

Los recordatorios de agenda/resultados se cierran por usuario y fecha esperada. Ese cierre no crea una llamada ni modifica el resultado definitivo. La agenda y el resultado de la misma persona, ID de contacto y fecha no generan dos recordatorios.

El audio comercial se limita a segmentos NOTIFY_RECORD de su propia extensión y llamada. Si una referencia de audio también aparece atribuida a otra extensión, se conserva como ambigua y no se reproduce como audio propio. Una duración omitida por PBX queda sin confirmar. Se requiere comprobar estos eventos con llamadas reales antes de certificar grabaciones de producción.

Las cinco fases deben revisarse e integrarse en orden, con Actions comprobado. Durante la publicación no debe haber llamadas activas; usar una ventana de mantenimiento para no abrir formularios nuevos antes de completar la migración explícita. El código, los publicadores y las pruebas no conectan ni modifican Zadarma por su cuenta.
