# Descarga de PDF sin pestañas externas

Entrega: `2b12de7ad14c1e0a081e10f7fb945c3b533f1c4d`.

Los enlaces de Reportes de actividad telefónica y Desempeño abrían una pestaña por tener `target="_blank"`. El navegador cerraba esa pestaña al recibir la respuesta adjunta. Se sustituye por una petición de descarga desde el módulo y una URL temporal del archivo recibido. El enlace cancela su navegación para conservar la pantalla y el contexto telefónico. Sólo descarga respuestas PDF válidas; muestra un aviso ante errores o sesión caducada y permite reintentar. El nombre del archivo sigue siendo el enviado por PHP, incluidos nombres UTF-8.

La publicación actualiza dos vistas e incorpora un archivo JavaScript compartido de descarga. No cambia los generadores de PDF, permisos, registros ni archivos de telefonía.

## Instalar

Requiere las versiones recientes de Reportes y Desempeño, incluido el retiro del filtro territorial de Asesores (`c959c06c`). Un archivo desconocido bloquea la publicación antes de escribir; el respaldo conserva los archivos exactos.

Pegar el bloque completo en SSH:

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-pdf-directo-2b12de7a"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/2b12de7ad14c1e0a081e10f7fb945c3b533f1c4d"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_pdf_descarga_directa.py" -o publicar_pdf_descarga_directa.py
curl -fsSL "$BASE/tools/pdf_descarga_directa_release.json" -o pdf_descarga_directa_release.json
printf '%s\n' \
'472dbd752fd3c528f0465a227a34ddb3e57fd45081f1d910a1d7a61eebfb2b0b  publicar_pdf_descarga_directa.py' \
'2be35ddaa5ad861aa8b31dfc313adcbd31787d8f8449ca1c9418ce3afac4f8ff  pdf_descarga_directa_release.json' | sha256sum -c -
python3 publicar_pdf_descarga_directa.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-pdf-directo" --check
python3 publicar_pdf_descarga_directa.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-pdf-directo" --apply
)
```

Después de **APLICADO**, recargar el módulo y pulsar **Exportar PDF**. Se conserva la misma pantalla y se inicia una sola descarga con el nombre calculado por el servidor.

Para restaurar, utilizar la ruta exacta mostrada por `APLICADO. RESPALDO:`:

```bash
python3 "$HOME/impe-pdf-directo-2b12de7a/publicar_pdf_descarga_directa.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-pdf-directo" \
  --restore "$HOME/respaldos-impe-pdf-directo/REEMPLAZAR_POR_EL_RESPALDO_GENERADO"
```

## Comprobación

La prueba de navegador utiliza las tres vistas (Reportes, Desempeño de asesores y Analistas) dentro de un shell con el código real de navegación. Comprueba una sola descarga, el nombre indicado por el servidor, ausencia de pestañas del documento, conservación de la pantalla y el iframe persistente y ausencia del aviso de recuperación tras los 20 segundos del supervisor de navegación. También simula un rechazo del servidor, comprueba el aviso dentro del CRM, un reintento correcto y un nombre UTF-8. El teléfono es un contexto de prueba; no se hacen llamadas reales.

El publicador comprueba compatibilidad, ausencia de escrituras con `--check`, rechazo de personalizaciones desconocidas, respaldo, reinstalación y restauración exacta con LF y CRLF.
