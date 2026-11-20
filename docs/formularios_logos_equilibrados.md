# Proporción de marcas y separador

El encabezado equilibra la altura visible de Fundación con la marca de SEP y
reduce el espacio vacío a la izquierda del separador. Las cajas usan el ancho
natural de la imagen en vez de repartir el espacio disponible con factores flex.
El logo de SEP tiene márgenes transparentes internos; se compensan mediante CSS,
sin editar las imágenes. En móvil se reduce la altura del grupo completo.

La corrección se limita a `public/css/formularios_hero_institucional.css`.
El publicador `tools/publicar_formularios_logos_equilibrados.py` añade el bloque
`tools/formularios_logos_equilibrados.css` a la versión canónica o al CSS de
producción con SHA-256 `0a370a289c19fabe9235f7aa59a1b05fb807105cd19e53059b269afe1555d0ae`.
Conserva todos los bytes anteriores, incluida la imagen de fondo personalizada.
Las cuatro vistas y sus enlaces existentes siguen usando este encabezado.

Admite `--site`, `--backup-root`, `--check`, `--apply` y `--restore RESPALDO`.
El respaldo queda fuera del sitio público. Una segunda aplicación no duplica
reglas y una versión desconocida bloquea la escritura. No modifica PHP ni SQL.

La prueba de navegador usa las cuatro vistas reales en 1752, 1440, 1024, 390 y
320 px. Con `IMPE_FORM_LOGO_PRODUCTION` apuntando a una carpeta con `hero.css`
y `sep.png` descargados del sitio, mide los límites de los píxeles visibles:
alturas con diferencia menor al 12 % y espacios junto al separador con diferencia
menor a 4 px. También conserva la transparencia y comprueba que no haya
desbordamiento. Los recursos descargados no forman parte de la publicación.

`tests/formularios_logos_equilibrados_publicacion.py` valida ambas bases, la
conservación íntegra del CSS previo, idempotencia, respaldo, restauración y bloqueo
ante cambios posteriores.
