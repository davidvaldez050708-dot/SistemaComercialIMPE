# Gestión de Convocatorias V5

Rama `feat/convocatorias-aliado-segmentacion-v5`, basada en `d1104ae4` del PR #129. Código funcional: `16001b9c`. Siete archivos operativos; sin SQL nuevo ni cambios de datos históricos.

## Modelo relacional y edición

La lectura parte exclusivamente de `convocatorias`. Resuelve primero `marketing_aliado_convocatorias_origen` y contrasta los vínculos manuales, históricos e internacionales: `marketing_aliado_manual_convocatorias`, `marketing_convenio_convocatorias` y `formulario_aliado_convocatorias`. Los perfiles de Registro nacionales se reconcilian mediante `formulario_aliado_registro`. El nombre procede de `aliados_manuales`, `seguimientos_vinculacion` o `formulario_aliados`; nunca se deduce del título o enlace.

Las relaciones se leen por lotes, sin unirlas a la consulta principal: un ID aparece una sola vez. Vínculos repetidos de una misma identidad se consolidan; identidades, programas contradictorios o registros ausentes se señalan para revisión y bloquean edición ordinaria. Sin vínculo verificable se muestra **Sin aliado asociado · Publicación territorial**. No se rellenan procedencias antiguas mediante actualizaciones masivas.

La clasificación almacenada determina el programa y la segmentación. La búsqueda del servidor incluye título, aliado y programa, sin distinguir acentos. Los contadores respetan territorio, búsqueda, fecha y pestaña. La modalidad precede al mes en la agrupación. No había paginación en este listado y no se introduce una nueva.

Se reutilizan `actualizar()` y `modalEditarConvocatoria`. El servidor comprueba todos los territorios reales antes de modificar, conserva programa, relación y enlace de los aliados y vuelve a verificarlos dentro de la transacción. Un POST que sustituye el enlace se rechaza. La imagen anterior se retira únicamente después del éxito. Las publicaciones territoriales conservan la edición histórica de su enlace; la gestión internacional personalizada sigue protegida. Consultar aliado sólo aparece con acceso al directorio y al territorio.

## Archivos operativos

- `app/services/ConvocatoriaOrigenService.php`: resolución y búsqueda por identidad.
- `app/models/ConvocatoriaModel.php`: listado/detalle enriquecidos y protección transaccional de identidad.
- `app/controllers/ConvocatoriaController.php`: contadores, permisos y edición protegida.
- `app/views/convocatorias/index.php`: integración con listado, detalle y editor existentes.
- `public/javascript/convocatorias_gestion_v5.js`: presentación compartida, agrupación y contexto del editor.
- `public/css/convocatorias_gestion_v5.css`: alcance exclusivo del listado/editor, tarjetas móviles y cuerpo desplazable.
- `public/javascript/crear_convocatoria_v2_extension.js`: abre el detalle auténtico solicitado desde Gestión.

La variante modular de Hostinger del index se reconstruyó con SHA256 `f3062f352b1ca85fe26022cea20d2c46f90db1d065646e1409594a630657dbe4`. También se verificaron los hashes y diferencias de los dos archivos PHP personalizados. El publicador conserva sus extensiones internacionales, `crear_modal.php` y `convocatoria_crear_compartido.js`. No sustituye el CSS general personalizado ni los componentes V3/V4 de creación, Registro o QR.

## Validación

MySQL **8.0.44** y MariaDB **10.11.14**, navegador Edge real y datos sintéticos, sin producción:

- 17 comprobaciones DB de resolución, relaciones duplicadas/contradictorias, búsqueda, clasificación e identidad protegida.
- 5 regresiones DB: enlace territorial editable, imagen conservada, sin duplicados e histórico de aliado desactivado.
- 47 comprobaciones navegador/HTTP/DB: filtros, conteos, historial, detalle, edición, imagen real, rollback, permisos territoriales y publicación V4 de IQJ → Querétaro → Bachillerato General.
- 5 casos adicionales: búsqueda sin acentos, fecha, publicación manual real → Guanajuato → Titulación y apertura de detalle del aliado.
- 7 casos de disponibilidad desde Todas/Historial, edición de una publicación V4 real, enlace/QR estable y descarga de la nueva imagen.
- 16 comprobaciones visuales por motor en 1440, 1024, 768, 390 y 360 px: sin desbordamiento, acciones completas y footer visible. También se validó el index nativo de GitHub.
- 12 pruebas del publicador: GitHub y Hostinger, LF/CRLF, personalizaciones compatibles, conflictos, integridad y restauración sin perder cambios posteriores.

Capturas sintéticas: [directorio escritorio](assets/convocatorias-v5/gestion-1440.png), [directorio móvil](assets/convocatorias-v5/gestion-390.png), [editor móvil](assets/convocatorias-v5/editar-390.png). El workflow dirigido `convocatorias-gestion-v5.yml` reproduce las pruebas en ambos motores. No se ejecutó toda la CI global.

## Descargar y comprobar en SSH, sin instalar

Usar el **SHA completo del commit de entrega indicado en la conversación**, que incluye manifiesto y publicador. No usar el commit funcional `16001b9c` para descargar el paquete.

```bash
read -r -p "SHA completo del commit de entrega (40 caracteres): " REV &&
[[ "$REV" =~ ^[0-9a-f]{40}$ ]] &&
SITIO="$HOME/domains/fundacionrededumex.org/public_html" &&
PREP="$HOME/impe-convocatorias-v5-$REV" &&
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV" &&
mkdir -p -m 700 "$PREP" &&
curl -fL "$RAW/tools/publicar_convocatorias_gestion_v5.py" -o "$PREP/publicar.py" &&
curl -fL "$RAW/tools/publicar_convenios_inline_v4.py" -o "$PREP/publicar_convenios_inline_v4.py" &&
curl -fL "$RAW/tools/publicar_aliados_registro.py" -o "$PREP/publicar_aliados_registro.py" &&
curl -fL "$RAW/tools/convocatorias_gestion_v5_release.json" -o "$PREP/entrega.json" &&
curl -fL "$RAW/tools/convocatorias_gestion_v5_sha256.txt" -o "$PREP/sha256.txt" &&
(cd "$PREP" && sha256sum -c sha256.txt) &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5" --check
```

Debe terminar con **REVISIÓN COMPATIBLE** y **SITIO INTACTO**. Un conflicto requiere revisar la diferencia concreta; no forzar ni copiar archivos completos.

## Aplicar únicamente los archivos

Después de revisar el diff compatible:

```bash
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5" --apply
```

**No hay migración V5 que ejecutar.** El publicador revisa todos los archivos antes de escribir, crea respaldo privado y usa reemplazos atómicos. Conservar la ruta real `Respaldo:`. La migración y los borradores V4 ya instalados se conservan.

Recargar Gestión de Convocatorias. Verificar IQJ y su programa sin depender del título; probar búsqueda por aliado, modalidad, fecha e historial. Editar una publicación de prueba, conservar su enlace y verificar su imagen. Con Cuenta Clave, comprobar que un registro de otro territorio no puede editarse.

## Restaurar sólo si se necesita revertir V5

Este bloque **no es un paso de instalación**. Usar la ruta real emitida por `--apply`:

```bash
read -r -p "Ruta real del respaldo de archivos V5: " RESPALDO &&
[ -n "$RESPALDO" ] &&
python3 "$PREP/publicar.py" --site "$SITIO" --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convocatorias-v5" --restore "$RESPALDO"
```

La restauración bloquea archivos modificados después de la publicación. No revierte SQL, publicaciones creadas, relaciones, imágenes publicadas ni borradores V4. No se desplegó automáticamente.
