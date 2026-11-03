# Ficha de Prospectos: medidas y transiciones del Panel de trabajo

La ficha instalada medía 620 px y alternaba `hidden` directamente. No tenía
una transición de entrada/salida. Seguimiento usa 460 px en escritorio,
ancho completo hasta 767.98 px y desplazamiento de 300 ms del offcanvas.

Esta corrección conserva la ficha y las rutas autorizadas. Ajusta únicamente
`prospectos_ficha_panel.css` y `prospectos_ficha_panel.js`:

- Misma escala, fondo, márgenes del encabezado/cuerpo y ancho de Seguimiento.
- Despliegue desde la derecha y cierre inverso; el fondo acompaña con opacidad.
- Entrada de contenido de 160 ms en secciones, tablas nuevas y ficha; no se
  interceptan pestañas, filtros, formularios ni navegación para animarlos.
- Escape, X y clic fuera conservan el foco y el scroll previos. El fondo no
  salta cuando se retira su barra de desplazamiento.
- Un cierre aborta la petición y descarta respuestas tardías. La reapertura
  durante un cierre cancela su temporizador y conserva un solo panel.
- Movimiento reducido elimina desplazamientos, entradas y esperas artificiales.
- La animación se habilita después de preparar los manejadores. La combinación
  transitoria de CSS nuevo/script anterior continúa mostrando una ficha estática.

No cambian controladores, permisos, datos, preferencias de horario ni formularios.
No se modifica Seguimiento; su CSS se verifica como referencia de sólo lectura.

## Paquete selectivo

El publicador `tools/publicar_prospectos_panel_visual.py` y el manifiesto
`tools/prospectos_panel_visual_release.json` se descargan del mismo commit.
La revisión parte de `4c7182877f0781a8d62d9ea6cc97757efac616d9` y de los dos
hashes exactos de Hostinger. Verifica, sin sustituirlos, el script de filtros
central y `seguimiento_estabilizacion_visual.css`, también comparados por hash.

```bash
python3 publicar_prospectos_panel_visual.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --check
python3 publicar_prospectos_panel_visual.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" --apply
```

El respaldo queda fuera de `public_html`, en
`$HOME/respaldos-impe-prospectos-panel-visual`. Para revertir, proporcionar la
ruta exacta impresa con `--restore`. La herramienta bloquea versiones desconocidas,
cambios concurrentes y restauraciones que pisarían una modificación posterior;
revierte los recursos si falla una escritura. No ejecuta SQL ni modifica otros
archivos del sitio.

La herramienta anterior de instalación de la ficha permanece anclada a su
revisión original y rechaza estos recursos nuevos. No debe usarse para sustituir
esta actualización. Su fixture toma los originales comprimidos del manifiesto
para seguir comprobando esa entrega histórica sin reutilizar fuentes nuevas.

## Validación

- `tests/prospectos_panel_visual_browser.cjs`: 39 comprobaciones con las vistas
  reales y datos ficticios, sin SQL/PBX; dimensiones frente al CSS real de
  Seguimiento, transición real, foco, scroll, X/Escape/clic exterior, cierre
  durante una respuesta lenta, reapertura rápida, errores de lectura,
  320–1920 px, filtros, secciones y movimiento reducido.
- `tests/publicacion_prospectos_panel_visual_test.py`: bases y payloads exactos,
  dependencias intactas, check sin escrituras al sitio, instalación, conflictos,
  idempotencia, restore protegido y rollback en directorios temporales.
- Regresión de la ficha anterior y suite real `prospectos_central_browser.cjs`;
  sólo se actualiza la espera de cierre para aguardar el final de su transición.
- Almacenamiento, formularios HTTP, territorialización y horario de contacto en
  esquemas ficticios locales/CI. Ninguna prueba utiliza Hostinger.

La CI visual ejecuta Chromium y WebKit. Las capturas están en el artefacto
`prospectos-panel-*`; las locales, en `tmp/prospectos-panel`.
