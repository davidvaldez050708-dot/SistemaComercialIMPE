# Sidebar plegable en escritorio

El botón de navegación del encabezado permite ocultar el menú lateral completo
en escritorio y recuperarlo con otro clic. El contenido ocupa los 270 px
liberados sin navegar ni recargar formularios. Se conserva el desplazamiento del
menú y se emite `resize` para que los gráficos puedan recalcular su espacio.

La preferencia se guarda por identificador de usuario en este navegador y se
sincroniza entre sus pestañas. Si el navegador bloquea el almacenamiento, el
control funciona durante la página actual. Desde 992 px usa el menú fijo;
por debajo conserva el offcanvas de Bootstrap, incluyendo Escape y backdrop.
Al ampliar la ventana se cierra exclusivamente ese offcanvas móvil.

## Alcance de instalación

Tres archivos: dos recursos nuevos (`sidebar_plegable.css` y
`sidebar_plegable.js`) y la integración mínima en `topbar.php`. El paquete parte
de la cabecera exacta de Hostinger, SHA-256
`69eb7bfc3d6730a8d145be49011c780a45bc2429354b280505f93775fb96b4af`.
Conserva sus cambios de Marketing, avisos, CSRF y telefonía. Esas diferencias
preexistentes también aparecen en el diff contra la rama main más antigua.

`sidebar.php`, `global.css` y `responsive_sistema.css` se comprueban como
dependencias de sólo lectura con los hashes suministrados. No se sustituyen.
El motor telefónico, las rutas, los permisos y los datos no se modifican.

El publicador es offline, se detiene ante variantes desconocidas y valida
sintaxis PHP antes de escribir. Instala los recursos antes de la cabecera;
conserva modos, finales de línea y un respaldo privado. Ante un fallo de
escritura revierte lo instalado. Repetir la instalación es idempotente.

## Comprobación e instalación controlada

Descargar `tools/publicar_sidebar_plegable.py` y
`tools/sidebar_plegable_release.json` de un mismo commit revisado, fuera de
`public_html`. Verificar sus hashes antes de ejecutarlos.

```bash
python3 publicar_sidebar_plegable.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-sidebar-plegable" --check

python3 publicar_sidebar_plegable.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-sidebar-plegable" --apply
```

La herramienta imprime la ruta del respaldo. Para restaurar, usar esa ruta
exacta con `--restore`; se detiene si hubo cambios posteriores en los archivos:

```bash
python3 publicar_sidebar_plegable.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --restore "/ruta/privada/impresa/por/el/publicador"
```

## Validación

- `python3 tests/sidebar_plegable_publicador_test.py`: fuentes/manifiesto,
  cabecera original exacta, bloqueo de versiones y dependencias, instalación,
  idempotencia, restauración protegida y rollback en sitios temporales.
- `node tests/sidebar_plegable_browser.cjs`: seis perfiles; vistas reales de
  Usuarios y Roles; tablas y resúmenes; 390–1920 px; persistencia, teclado,
  sincronización, storage bloqueado, scroll del menú, contenido en edición,
  anchos de modales y motor hermano independiente del iframe del CRM.
- El navegador usa únicamente fixtures locales con datos ficticios y bloquea
  peticiones externas. No consulta SQL ni PBX. CI ejecuta Chromium y WebKit.
- Revisar manualmente tras instalar: abrir/cerrar con cada perfil, navegar con
  el menú cerrado, recuperarlo, reducir/ampliar la ventana y comprobar una
  pantalla con tablas y otra con gráficos.

Las capturas de la prueba se guardan en `tmp/sidebar-capturas` y CI las adjunta
como artefactos. No se ha realizado una instalación en Hostinger desde estas
pruebas.
