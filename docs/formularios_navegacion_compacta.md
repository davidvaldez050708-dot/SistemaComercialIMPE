# Navegación compacta de Formularios

La entrada conserva exactamente el diseño original de Registro e Inscripción,
incluidas sus ilustraciones y descripciones. El catálogo conserva las tarjetas
originales de los programas y sus enlaces; sólo cambia sus botones a ancho natural,
con altura de 36 px en escritorio y separación de 6 px. En móvil las acciones se acomodan en renglones con objetivos
táctiles de al menos 44 px. No cambia la captura, las rutas ni los permisos.

La publicación modifica únicamente `public/css/formularios.css`: añade el bloque
revisado de `tools/formularios_navegacion_compacta.css`, conservando todos los bytes
del CSS base. Si está instalado el diseño compacto V1, retira únicamente su sufijo
exacto conocido y lo sustituye por V2; el respaldo conserva la versión previa completa. Admite la versión canónica y la versión de producción auditada
por el usuario. Los dos archivos PHP se verifican, pero no se escriben.

Desde una entrega extraída fuera del sitio:

```bash
python3 tools/publicar_formularios_navegacion.py --site "$SITIO" --check
python3 tools/publicar_formularios_navegacion.py --site "$SITIO" --backup-root "$HOME/respaldos-formularios-diseno" --apply
python3 tools/publicar_formularios_navegacion.py --site "$SITIO" --check
```

`--check` no escribe. `--apply` hace respaldo privado, verifica su integridad y
reemplaza el CSS de manera atómica. Repetirlo no agrega el bloque de nuevo. Acepta tanto la base original como V1 instalada.
Los hashes desconocidos bloquean la publicación. No se ejecuta PHP ni SQL.

Para revertir, usar la ruta exacta que imprimió `RESPALDO`:

```bash
python3 tools/publicar_formularios_navegacion.py --site "$SITIO" --restore "/ruta/privada/formularios-..."
```

La restauración rechaza cambios posteriores del CSS.

Validación: `tests/formularios_navegacion_publicacion.py` comprueba instalación,
idempotencia, integridad del respaldo, restauración y bloqueos. El navegador
`tests/formularios_navegacion_browser.cjs` renderiza las vistas PHP reales en
1440, 1024, 768, 390 y 320 px; comprueba que la entrada sea idéntica a la original, separación de botones,
ausencia de desbordamiento, enlaces y visibilidad por permiso de compartir.
También compara los estilos y controles de captura antes y después.

El navegador requiere Playwright, Bootstrap, PHP y Chromium/Edge. Acepta las rutas
por `PLAYWRIGHT_MODULE_PATH`, `BOOTSTRAP_MODULE_PATH`, `PHP_BINARY` y `CHROME_PATH`.
`IMPE_FORMS_ASSETS` opcional permite cargar copias de Manrope e iconos de Bootstrap
para las capturas de revisión; no se publican esas copias.
