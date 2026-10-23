# Editor V3 compartido — corrección puntual

PR #124. Código: `dfcf3fa263125bbabab5312503cad7b4bcadb41f`. Paquete: `b17a7c0aa85fc9411dde2f465457b05e46fc7baa`.

La revisión anterior salía silenciosamente cuando no obtenía opciones compatibles (`!byCode.size`), dejando activo el formulario V2. El componente y script modular remitidos desde Hostinger reproducen ese comportamiento con opciones vacías. Un programa válido sí inicializaba V3: no es una limitación de cantidad. También se cubre el montaje tardío del componente y la inclusión duplicada del script.

Ahora el contexto asociado permanece en V3. Si falta código, clasificación o contexto válido, oculta el formulario individual, explica el dato faltante y bloquea el guardado. No inventa programas, no asigna clasificaciones y no publica un subconjunto incompleto. El registro de submit captura un único lote antes de los manejadores antiguos.

**Alcance:** sólo `public/javascript/convocatorias_aliado_multiprograma_v3.js`. No se publican CSS, plantillas, controladores, SQL ni componentes personalizados. `crear_modal.php`, `convocatoria_crear_compartido.js`, el módulo V2, el QR y la redirección quedan intactos.

**Límite de la comprobación:** no se obtuvo el DOM ni el catálogo de la sesión productiva de IQJ. Las pruebas con IQJ/SSP usan instituciones y programas sintéticos; confirman el mecanismo y los casos de uno/varios programas, no la configuración real de esas instituciones. Tras publicar, revisar IQJ: si faltan clasificaciones compatibles, aparece un aviso y debe revisarse su configuración mediante la gestión autorizada, sin equiparar Bachillerato General con Bachillerato 286.

## Comprobar sin instalar

```bash
REV=b17a7c0aa85fc9411dde2f465457b05e46fc7baa
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PREP="$HOME/impe-convocatorias-v3-b17a7c0a"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV"
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/convocatorias_v3_release.json" -o "$PREP/entrega.json" &&
(cd "$PREP" && printf '%s\n' \
 'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
 '4faa911f2ca8c361154bb169fc5cc988aa2c199a4919a4f5f92c291f8938c5c8  entrega.json' | sha256sum -c -) &&
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-v3-editor" --check
```

Revisar el diff y exigir `REVISIÓN COMPATIBLE`. Una personalización superpuesta detiene la instalación antes de escribir. La integridad de la fuente se verifica por SHA; si Node está disponible, también se ejecuta `node --check`. El script se validó localmente con Node 22. El publicador mantiene compatibilidad sintáctica Python 3.6 y conserva CRLF y personalizaciones compatibles. No instalar un paquete global ni ejecutar SQL.

## Aplicar y restaurar

Después de la revisión compatible:

```bash
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-v3-editor" --apply
```

Guardar la ruta real `Respaldo:`. Recargar el CRM con Ctrl+F5 para evitar la copia anterior del JS `?v=3`. Abrir IQJ y SSP; comprobar una tarjeta por programa compatible, ambos roles con permisos/territorios, guardado único y estado explicativo si falta clasificación. No se ha aplicado esta entrega desde Codex.

Para restaurar sólo esta entrega, usando la ruta exacta mostrada:

```bash
RESPALDO="/ruta/exacta/mostrada/en/Respaldo"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" --backup-root "$HOME/respaldos-impe-v3-editor" --restore "$RESPALDO"
```

El rollback comprueba integridad y bloquea una restauración que sobrescribiría ediciones posteriores.

## Pruebas

80 comprobaciones locales Edge/HTTP/MariaDB: orígenes manual/institucional, uno/dos/tres programas, Cuenta Clave/Marketing, falta de permisos/territorio, opciones incompatibles, montaje tardío, carga duplicada, submit único al endpoint real, IDs/imágenes independientes, un enlace por aliado, vínculos manuales, rechazo de programa ajeno, rollback de primera publicación al fallar la segunda imagen y flujo tradicional sin contexto. Las llamadas HTTP se envían al servidor local real, sin simular el guardado.

Comparativas con V3 anterior: cero diferencias de píxeles en el caso válido de dos programas a 1440/1024/768/390/360 px. Sin errores PHP/JS; 14 regresiones de permisos; cuatro pruebas del publicador con check intacto, aplicación repetida, restauración exacta, CRLF, personalizaciones y conflictos sin escrituras. Sintaxis PHP de fixtures y JavaScript aprobada. Capturas locales: `C:/Users/chill/Documents/Codex/2026-10-16/outputs/convocatorias-v3-unificado/`; CI: `aliados-registro-*/v3-unificado`.
