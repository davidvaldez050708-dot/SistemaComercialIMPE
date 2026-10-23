# Tipografía de Redactar correo

Cambio de producción exclusivo en `public/css/correos_marketing.css`, limitado a `#modalCorreoMarketing`. Se reutiliza Manrope ya cargada en `global.css` y la escala existente de `dashboard.css`: título 16 px y subtítulo 11 px conservados; etiquetas/ayudas 11 px; controles, opciones y enlaces 12 px. Pesos de etiquetas 600 y mensaje 500. Los botones conservan sus clases institucionales y medidas (Cancelar/Enviar 11 px; Guardar borrador 13 px). Se mantienen los espacios existentes de 6 px entre etiqueta y control y 12 px entre grupos; sólo se normalizan alturas de línea de etiquetas, ayudas y enlaces a 1.45.

No hay cambios en vistas, JavaScript de producción, controladores, modelos, servicios, transporte Hostinger, recuperación, configuración, fuentes ni otros módulos. No se enviaron correos reales.

Pruebas dirigidas pasadas: Chrome a 1440×1000, 820×1180 y 390×844; tamaños calculados Manrope, comparación antes/después de contenedores, colores, bordes, scroll y controles externos; apertura/cierre, filtros, imagen, enlace, texto/adjunto manual, borrador y envío interceptados. Restauración de selección/borrador con DOM/API ficticios. Publicador probado sobre sitio ficticio con LF/CRLF, revisión sin escritura, aplicación idempotente, restauración exacta y rechazo de CSS personalizados o editados después de publicar. Sin CI global.

Capturas locales de QA (datos ficticios): `tmp/prueba_correo_convocatorias_selectores/tipografia-{escritorio,tableta,movil}-{selector,enlace}.png`; métricas en `tipografia-medidas.json` dentro de esa carpeta.

## Publicación selectiva por SSH

Código/publicador: commit `baafea19ae28a3f1025e3a89c15b6a943f74f822`, rama `codex/correo-modal-tipografia`, basada en `8778e74c` del PR #128. El PR hacia main incluye esa base aún no fusionada; el cambio visual específico es baafea19. No fusionar ni desplegar automáticamente.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/baafea19ae28a3f1025e3a89c15b6a943f74f822/tools/publicar_tipografia_correo_hostinger.sh" \
  -o "$HOME/impe-publicadores/tipografia-correo-baafea19.sh" &&
printf '%s  %s\n' \
  'bcf30aa6511465284f5ca3029479eab684325e973b489df924c360d05b2cfbb6' \
  "$HOME/impe-publicadores/tipografia-correo-baafea19.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/tipografia-correo-baafea19.sh" --check
```

Sólo si el hash es válido y aparece `OK: revisión compatible`:

```bash
bash "$HOME/impe-publicadores/tipografia-correo-baafea19.sh" --apply
```

Se respalda el CSS fuera de public_html y se instala únicamente ese archivo, preservando permisos y finales de línea. Guardar el comando `--restore` con la ruta exacta que imprime `--apply`; la reversión rechaza cambios posteriores y respaldos ajenos. Ante CSS personalizado/incompatible, detenerse: el publicador lo conserva y no debe forzarse.

Después, recargar el CRM con Ctrl+F5 y revisar Redactar correo en escritorio/móvil sin enviar mensajes. La comprobación visual en Hostinger queda pendiente hasta que se publique; las capturas entregadas corresponden al entorno aislado.
