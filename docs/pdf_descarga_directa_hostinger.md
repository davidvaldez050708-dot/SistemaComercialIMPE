# Descarga de PDF sin pestañas externas

Entrega: `6271997a3365ab1f4a96a4e7957ff2aa9fe6885b`.

Los enlaces de Reportes de actividad telefónica y Desempeño abrían una pestaña por tener `target="_blank"`. El navegador cerraba esa pestaña al recibir la respuesta adjunta. Se sustituye ese destino por un iframe de descarga oculto dentro del mismo documento. La pantalla y el teléfono mantienen sus contextos; la navegación persistente reconoce que el destino no es el documento del CRM. El nombre del archivo sigue siendo el enviado por PHP.

La publicación sólo cambia dos vistas. No cambia los generadores de PDF, permisos, registros ni archivos de telefonía.

## Instalar

Requiere las versiones recientes de Reportes y Desempeño, incluido el retiro del filtro territorial de Asesores (`c959c06c`). Un archivo desconocido bloquea la publicación antes de escribir; el respaldo conserva los archivos exactos.

Pegar el bloque completo en SSH:

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-pdf-directo-6271997a"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/6271997a3365ab1f4a96a4e7957ff2aa9fe6885b"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_pdf_descarga_directa.py" -o publicar_pdf_descarga_directa.py
curl -fsSL "$BASE/tools/pdf_descarga_directa_release.json" -o pdf_descarga_directa_release.json
printf '%s\n' \
'ab0bc05436a98c61fddd8b9d331e05ee947357ea1b552c73a36e6b2d2203e683  publicar_pdf_descarga_directa.py' \
'05b658e17110d6fba6cc22f9f462d55e07496f5630dde4938e0b9bae8816775f  pdf_descarga_directa_release.json' | sha256sum -c -
python3 publicar_pdf_descarga_directa.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-pdf-directo" --check
python3 publicar_pdf_descarga_directa.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-pdf-directo" --apply
)
```

Después de **APLICADO**, recargar el módulo y pulsar **Exportar PDF**. Se conserva la misma pantalla y se inicia una sola descarga con el nombre calculado por el servidor.

Para restaurar, utilizar la ruta exacta mostrada por `APLICADO. RESPALDO:`:

```bash
python3 "$HOME/impe-pdf-directo-6271997a/publicar_pdf_descarga_directa.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-pdf-directo" \
  --restore "$HOME/respaldos-impe-pdf-directo/REEMPLAZAR_POR_EL_RESPALDO_GENERADO"
```

## Comprobación

La prueba de navegador utiliza las tres vistas (Reportes, Desempeño de asesores y Analistas) dentro de un shell con el código real de navegación. Comprueba una sola descarga, el nombre indicado por el servidor, ausencia de pestañas del documento, conservación de la pantalla y el iframe persistente y ausencia del aviso de recuperación tras los 20 segundos del supervisor de navegación. El teléfono es un contexto de prueba; no se hacen llamadas reales.

El publicador comprueba compatibilidad, ausencia de escrituras con `--check`, rechazo de personalizaciones desconocidas, respaldo, reinstalación y restauración exacta con LF y CRLF.
