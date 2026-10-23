# Avatar y selector de fotografía: ajuste visual

Cambio exclusivo de producción en `public/css/global.css`, acotado al avatar de encabezado, tarjeta lateral y elementos de `#modalMiPerfil`. Manrope ya existente; iniciales compactas de 11 a 14 px con centrado flex, sin cambiar los círculos. Mi perfil conserva sus 76/84 px y texto proporcional heredado. Las fotos se centran con cover, sin doble borde interior ni deformación; se conserva el borde exterior.

En la sesión real consultada las iniciales ya estaban centradas, aunque pequeñas: 11 px en 36 px. No se reprodujo el desplazamiento de la primera captura en esa sesión. Se refuerza el centrado con selectores específicos. El selector real combinaba unos 48 px de campo con 36 px de botón y márgenes negativos; ahora el input nativo mide 44 px y el botón interior 42 px, alineados y con truncamiento visual de nombres largos.

No cambian HTML, cálculo de iniciales, recuperación de imágenes, fallback de error existente, JavaScript, carga/validaciones/almacenamiento, datos, roles o sesión. Tampoco correo, recuperación de contraseña ni otros módulos. En producción sólo se consultó la interfaz y se cerró Mi perfil sin guardar.

Validación dirigida pasada: Chrome 1440×1000, 820×1180 y 390×844; iniciales centradas, fotos ficticias horizontales/verticales, medidas y colores conservados, componentes externos iguales, selector nativo y nombre largo, lectura FileReader, cancelación de selección, actualización con API interceptada y fallback existente. Sin usuarios reales, correos o CI global. Publicador probado en sitio ficticio LF/CRLF: check sin escritura, apply idempotente, restore exacto y rechazo de personalizaciones/cambios posteriores.

Capturas con datos ficticios en `tmp/perfil_avatar_visual/{escritorio,tableta,movil}-{avatares,iniciales,selector}.png`; mediciones en `tmp/perfil_avatar_visual/medidas.json`. Revisión visual del cambio aplicado en Hostinger pendiente de publicación.

## SSH de Hostinger

Rama `codex/perfil-avatar-selector-visual`; commit visual `e8d06f95fe2e76478d5531ea5d6324be38c9241c`. Basada en b7b25125 del PR #130, con los avances del #128; el PR hacia main incluye esas bases aún pendientes de fusión. No fusionar ni desplegar automáticamente.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/e8d06f95fe2e76478d5531ea5d6324be38c9241c/tools/publicar_perfil_visual_hostinger.sh" \
  -o "$HOME/impe-publicadores/perfil-visual-e8d06f95.sh" &&
printf '%s  %s\n' \
  'ef60a636fdbb38135e297fc70d52676dbed045af2a863946fb85091c670245a6' \
  "$HOME/impe-publicadores/perfil-visual-e8d06f95.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/perfil-visual-e8d06f95.sh" --check
```

Sólo con hash válido y revisión compatible:

```bash
bash "$HOME/impe-publicadores/perfil-visual-e8d06f95.sh" --apply
```

Se respalda fuera de public_html y cambia únicamente `global.css`, preservando permisos y finales de línea. Guardar el comando `--restore` y la ruta exacta del respaldo que imprime. Si detecta CSS personalizado/incompatible, detenerse: no se sobrescribe ni debe forzarse. Luego recargar con Ctrl+F5 y revisar avatares y Mi perfil; no hace falta guardar información para revisar el diseño.
