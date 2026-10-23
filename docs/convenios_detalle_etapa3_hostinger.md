# Etapa 3 — Directorio y detalle de Convenios

**Compatibilidad Hostinger (22/10):** el paquete original indicado abajo se detiene ante la variante personalizada de `crear_convocatoria.js`. El manifiesto actualizado de este documento incorpora su contenido público exacto (SHA256 `7095c76746feddda854b9f89684e66d832ffcf2976d08b885f29f3cebc0720e1`) y sólo limita la apertura al botón Ver detalle. Conserva las rutas internacionales y el resto del archivo; no copia la versión completa de GitHub sobre esa variante. Usar el commit de conciliación y el SHA256 del manifiesto entregados en la conversación, no el `REV` histórico de abajo. El publicador y los otros cuatro destinos no cambiaron. Una variante diferente sigue requiriendo `--check`; no forzar conflictos.

Validación de esta conciliación: publicación en réplicas LF/CRLF, idempotencia, restauración exacta y bloqueo sin escrituras ante variantes desconocidas. Comparación en Edge del script publicado contra el conciliado, solo y con la extensión V2: rutas, visibilidad, filtros, clic, Enter/Espacio, notas y programas. Sin acceso autenticado, SQL o escrituras en Hostinger.

PR #124, rama `feat/aliados-programas-registro`. Aplicación: `00aae2ce8489728eb9210acece5bd3228276fcd9`. Paquete selectivo: `85b4326626a8d91a71d3ac7a6cb44325f09be532`. Base: `285bfac993d04f5d5cd5e5cdae6cca3d809372dc`, con las etapas 1 y 2.

Filas compactas, sin hover accionable y con botón uniforme Ver detalle; apertura mediante botón nativo con teclado. Origen y programas usan la misma cuadrícula informativa del modal, notas completas con saltos de línea y estado vacío discreto; desplazamiento independiente del cuerpo, fechas sin medianoche artificial. Los programas llegan como una lista estructurada de la consulta existente; se conserva el texto legado para sus otros consumidores, sin alterar autorizaciones.

Sólo cinco archivos operativos:

- `app/services/AliadoRegistroService.php` (lectura y decoración de programas).
- `app/views/convocatorias/crear_desde_convenio.php`.
- `public/css/aliados_v2_extension.css` (selectores de Convenios).
- `public/javascript/crear_convocatoria.js`.
- `public/javascript/crear_convocatoria_v2_extension.js`.

Sin migraciones ni SQL. No se cambia el editor, destino de Nueva convocatoria, guardado, QR, Compartir, Canales, Prospectos, Handly o Telefonía. No publicar el repositorio completo.

## Descargar y comprobar

En SSH, fuera de `public_html`:

```bash
REV=85b4326626a8d91a71d3ac7a6cb44325f09be532
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/impe-convenios-etapa3-85b43266"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/convenios_detalle_etapa3_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && printf '%s\n' \
 'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
 '10ac937655ccbac6d1d64232c325281a06cd752874afcaa5febbfec35dcf44ab  entrega.json' | sha256sum -c -) &&
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-convenios" --check
```

Revisar el diff y exigir revisión compatible. El publicador concilia personalizaciones no superpuestas y preserva CRLF. Un conflicto detiene toda la entrega antes de escribir; no forzar ni sustituir archivos completos. Una variante desconocida de Hostinger sólo queda validada cuando su `--check` termina correctamente y se revisa el diff. PHP se valida con `php -l`; JavaScript con Node cuando existe, o mediante la integridad del candidato conocido. AST comprobado compatible con Python 3.6; no hubo intérprete 3.6 local para ejecución.

## Aplicar

Sólo después de revisar el resultado compatible:

```bash
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-convenios" --apply
```

Guardar la ruta real mostrada en `Respaldo:`: es privada y externa al sitio. Después repetir `--check`; debe indicar cero archivos pendientes. Recargar sin caché y comprobar directorio y detalle de un aliado de cada origen, filtros, notas, programas, teclado y móvil; confirmar también Canales y Compartir. No ejecutar migraciones para esta etapa ni `git pull` en producción.

## Restaurar

Usar la ruta exacta del respaldo creado por esta entrega:

```bash
RESPALDO="/ruta/exacta/mostrada/en/Respaldo"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-convenios" --restore "$RESPALDO"
```

Restaura bytes originales, sin SQL y sin sobrescribir ediciones posteriores incompatibles. No reutilizar un respaldo de etapas anteriores.

## Validación realizada

- Sintaxis PHP/JavaScript y diff sin errores.
- 120 comprobaciones nuevas Playwright/HTTP con MariaDB aislada y datos sintéticos, en Edge real a 1440, 1366, 1024, 768, 390 y 360 px; 24 capturas antes/después contra la base indicada. Incluye botones/teclado, filtros/grupos, notas completas y seguras, programas con puntos sin divisiones, fechas, scroll y ausencia de errores PHP/JS. El enlace de Nueva convocatoria se compara literalmente con la revisión anterior.
- Regresiones: 65 de base de datos de Aliados, 60 de navegador/HTTP/concurrencia existentes, 89 de Canales, 56 de Compartir en DB/proveedor simulado y 66 de Compartir en navegador/HTTP.
- Cuatro pruebas del publicador: comprobación intacta, aplicación repetida y restauración exacta; personalizaciones/CRLF; conflicto sin escrituras; sintaxis Python 3.6.

Capturas locales: `C:/Users/chill/Documents/Codex/2026-10-16/outputs/convenios-detalle-etapa3/`. En CI se adjuntan en `aliados-registro-*/convenios-detalle`. Los resultados locales no certifican una variante de producción aún no examinada. Codex no desplegó ni ejecutó SQL en Hostinger.
