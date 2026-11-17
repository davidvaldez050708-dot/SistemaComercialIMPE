# Actualización del embudo V3 sin pantalla blanca

Base instalada: commit `d84ce1ff55467573d5ceafa4db33a0479c0f7a8b`, con la variante de telefonía Hostinger ya revisada. La entrega publica únicamente cuatro scripts; no modifica PHP, SQL, reglas comerciales, PBX, permisos ni estilos.

## Cambio

Después de registrar resultados de llamadas, actividades, movimientos y gestiones, la pantalla conserva el documento y el teléfono persistente. Solicita el HTML actualizado al mismo controlador y sustituye sincronizadamente el tablero, sus contadores, el bloque histórico y la nota de Recuperación/Descartados. No ejecuta scripts del HTML recibido ni sustituye los diálogos o el formulario de filtros.

Las tarjetas recién recibidas conservan sus botones y arrastre. Los manejadores de eventos de ventana y los intervalos se registran una sola vez. El aviso de éxito se muestra una vez después de actualizar la vista; si el cambio está guardado pero falla la lectura, se informa de ese estado y se conserva la vista anterior. Las operaciones ya guardadas no se vuelven a enviar.

También se actualizan mediante peticiones los filtros, la paginación y Limpiar del embudo principal. Se sincroniza la URL con el historial del navegador. Una respuesta antigua no puede sustituir una búsqueda más reciente. Se conserva el desplazamiento de las columnas y de la página cuando sigue siendo posible.

No se sustituye el contenido mientras hay una llamada activa, un formulario/modal abierto, un menú desplegado o un arrastre. Las actualizaciones pendientes por guardados/llamadas se retoman al terminar. Las páginas externas, Recuperación/Descartados como vistas independientes, la navegación a expedientes y una recarga explícita del navegador mantienen su navegación normal.

## Pruebas ejecutadas

- `embudo_refresco_sin_parpadeo_browser.cjs`: actividades sucesivas, resultado telefónico, un solo documento, mismo teléfono, botones después de actualizar, desplazamiento, búsqueda, respuestas concurrentes, Limpiar, paginación, Atrás, llamadas activas y modal abierto. Simula errores HTTP y una página de login: conserva el tablero. Comprueba en cada frame que no desaparezcan las tarjetas.
- `seguimiento_acciones_v3_browser.cjs`: programación y reprogramación en seis etapas; conserva la validación contra fecha/hora sin cambio y un solo envío.
- `embudo_toast_llamada_browser.cjs`: un aviso estable después de actualizar el resultado, duración y consumo único.
- `ventas_v3_toast_browser.cjs`: gestión, movimiento, calificación, actividad, conflicto, respuesta inválida, llamada activa y almacenamiento deshabilitado.
- `gestion_comercial_v3_inscripcion_browser.cjs` y `ventas_v3_bandejas_browser.cjs`: regresión de formularios y validaciones existentes.
- Prueba del publicador con `IMPE_PUBLICADOR_MODULO=publicar_embudo_sin_parpadeo_v3_hostinger`: hashes, preservación de telefonía personalizada, publicación idempotente, respaldo, rollback, bloqueo ante cambios desconocidos y reversión por fallo parcial.

Playwright utiliza Chromium/Edge real con respuestas sintéticas. No se ejecutan llamadas reales ni se usa la base de producción durante estas pruebas.

## Publicación

Extraer el commit de la entrega en una carpeta privada fuera de `public_html`. Mantener juntos los módulos Python incluidos.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PUBLICADOR="$ENTREGA/tools/publicar_embudo_sin_parpadeo_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-embudo-sin-parpadeo-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE &&
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

Se conserva el motor de publicación y sus confirmaciones anteriores. Sus mensajes genéricos mencionan Gestión de Potenciales V3. Solo los cuatro archivos listados en el publicador se escriben. CHECK no publica nada ni modifica datos. Una personalización distinta de las bases revisadas bloquea la publicación.

Para revertir únicamente este código, usar el mismo publicador y la ruta exacta `ROLLBACK_BACKUP` de esta publicación:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

No sustituir ese respaldo por uno de una entrega anterior. El rollback no borra resultados, actividades ni prospectos.

## Prueba posterior

Tras publicar, hacer Ctrl+F5 una sola vez para cargar los scripts. En una tarjeta ficticia del asesor 22, registrar una gestión o resultado de llamada. Deben actualizarse los datos y mostrarse un único aviso, manteniendo visible el tablero, la posición y el teléfono. Repetir la acción desde la tarjeta actualizada para comprobar que sus controles siguen operativos; comprobar búsqueda y paginación sin recarga completa.
