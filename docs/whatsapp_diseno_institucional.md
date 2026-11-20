# Diseño institucional de WhatsApp

El chat del embudo se compacta a 392 × 560 px como máximo en escritorio y se adapta a la pantalla disponible en móvil. Mantiene encabezado azul institucional, acento verde, fondo cálido y burbujas de conversación similares a WhatsApp. Se conserva el envío, los borradores y el acceso por cartera.

La causa del aviso desbordado era una cuadrícula de tres columnas con nuevos elementos hijos que se colocaban en la columna reservada al icono. El aviso y la plantilla ahora quedan dentro del bloque de texto y usan columnas explícitas. La presentación aprobada puede desplegarse para consultar su texto. No se envía al abrirla.

Las etiquetas dejan de usar los estilos globales de Bootstrap `badge` y pasan a clases encapsuladas. Permanecen en una línea, truncan nombres largos y ofrecen el texto completo del responsable al pasar el cursor. Las fichas de la lista tienen una altura estable. Se conserva la estructura general de Conversaciones.

Inicio comercial y Sin asignar comparten encabezados, tarjetas, formularios y estados vacíos propios del diseño institucional. Los enlaces de regreso y los contactos de la cola incluyen `action=index`. Antes omitían la acción y el router intentaba `mostrarLogin`, que no existe en WhatsAppController.

Sin asignar es una cola de revisión para supervisión: muestra conversaciones sin responsable. No cambia las asignaciones de prospectos. En Ventas, asignar un chat manualmente desde esa cola no reemplaza la asignación del prospecto en el embudo; ese alcance se conserva.

## Publicación

El paquete actualiza sólo vistas, estilos y la indicación visual tras validar una plantilla. No cambia permisos, configuración privada, plantillas guardadas, historial ni lógica de envío.

Descargar del mismo commit `tools/publicar_whatsapp_diseno.py` y `tools/whatsapp_diseno_release.json` a una carpeta privada. Ejecutar primero `--check` y después `--apply`:

```bash
python3 publicar_whatsapp_diseno.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/domains/fundacionrededumex.org/impe-private" --check
python3 publicar_whatsapp_diseno.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/domains/fundacionrededumex.org/impe-private" --apply
```

Requiere Python 3.6 o posterior y PHP CLI. Comprueba todos los bloques y la sintaxis antes de escribir. Conserva ajustes ajenos al cambio y genera un respaldo privado. Ante diferencias incompatibles se detiene sin aplicar cambios parciales. Para restaurar, sustituir `--apply` por `--restore RUTA_RESPALDO`; no sobrescribe modificaciones posteriores.

La vista Sin asignar parte de la versión 46742db ya visible en producción. La bandeja admite esa versión combinada con el cambio de Ventas d26d0f6. El paquete no instala nuevos servicios ni métodos de asignación: conserva los que ya están funcionando en el servidor.

## Validación

Pruebas con vistas PHP reales y Chrome aislado: chat pendiente, plantilla disponible y ventana abierta; escritorio, móvil de 390 px y pantalla baja de 460 px; avisos sin expansión ni desbordamiento, burbujas legibles, etiquetas en una línea y fichas estables. Se validan formularios con respuestas simuladas, enlaces de regreso, estado vacío y filas de la cola. No se envía ningún mensaje real.

El publicador se prueba con modificaciones personalizadas, LF/CRLF, repetición, restauración completa y rechazo de conflictos antes de escribir. Los cambios todavía requieren ejecutar la publicación en el servidor y comprobar las vistas con la sesión real.
