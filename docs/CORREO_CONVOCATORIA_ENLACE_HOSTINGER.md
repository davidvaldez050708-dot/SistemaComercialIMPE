# Enlace original de convocatoria en correos

La selección recupera `convocatorias.enlace_registro` por el ID real de la convocatoria. Se valida y conserva exactamente la URL almacenada; no se invoca ningún generador ni se agregan parámetros. El título Registro/Inscripción corresponde a la acción del enlace existente.

El bloque automático aparece junto a la imagen y se incorpora al HTML después de ella, antes de la firma existente. Cambiar o limpiar la convocatoria sustituye o retira ese bloque sin alterar texto ni adjuntos manuales. Sin enlace válido se muestra un aviso discreto y se permite continuar. El snapshot privado existente conserva el enlace en borradores/historial; los registros anteriores sin enlace no reciben uno inventado.

## Alcance

- `app/services/CorreoConvocatoriaEnlace.php`: validación del origen/ruta/parámetros y HTML escapado.
- `app/models/CorreoConvocatoriaConsultaModel.php`: únicamente el detalle seleccionado agrega el campo almacenado.
- `app/services/CorreoConvocatoriaComposicion.php`: publica la referencia validada y añade el bloque mediante la interfaz existente.
- `public/javascript/correo_convocatorias.js` y `app/views/correos/index.php`: presentación en redacción y detalle.
- `tools/diagnosticar_enlace_convocatoria.php`: comprobación CLI de sólo lectura.

No hay migraciones, configuración nueva ni cambios a proveedores, credenciales, SMTP, recuperación, filtros, formularios o generación de enlaces/QR.

## Verificación

Pasaron las pruebas dirigidas PHP de URL exacta, Registro/Inscripción, URLs inválidas, composición, borradores e historial; las de interfaz Node y Chrome (escritorio/móvil); la integración con dobles Hostinger v4 y la imagen CID original; el aislamiento de componentes protegidos y el publicador sobre sitio ficticio v4 con respaldo/restauración. No se ejecutó CI global ni se enviaron correos con estas pruebas.

Se consultó el detalle real de la convocatoria 11 y se abrió su enlace original: muestra el formulario de Registro del convenio PRUEBA en León, Guanajuato. No se envió el formulario. Inscripción se comprobó con datos ficticios.

**Comprobación real en Hostinger (22/10/2026):** la interfaz mostró la mejora aplicada. Con autorización específica se pulsó Enviar una sola vez desde el CRM para la convocatoria 11, asunto `Prueba enlace convocatoria IMPE 11`. Se confirmó un registro Enviado mediante HOSTINGER_MAIL_API y un mensaje recibido en INBOX a las 21:42 UTC, desde el buzón institucional autorizado hacia la cuenta de prueba de la usuaria. No se usaron contactos comerciales.

El MIME recibido conserva el texto autorizado, una imagen CID y un único enlace automático de Registro idéntico al almacenado. El PNG recibido tiene 788316 bytes y SHA256 `1b326f526856cd4fa307d4a2285d2295024cd80b5b9c1be6daf4f4613175cca0`, igual al original. Se abrió exactamente la URL del correo recibido y mostró Registro / PRUEBA / León / Guanajuato; no se envió el formulario. SPF, DKIM y DMARC aparecen como pass en los encabezados recibidos.

La prueba real acredita este caso de Registro. Inscripción, cambio/limpieza, falta de enlace y borradores se verificaron con pruebas dirigidas y datos ficticios; no se enviaron correos reales adicionales ni se modificaron contraseñas.

## Publicación selectiva

Rama `codex/correo-enlace-convocatoria`; código/publicador fijados al commit `49522589621ff2a2ed79d50d5f025f24d6a0fead`. Parte de la versión funcional `16518156` (PR #127); el PR nuevo a main incluye esa base pendiente de integración. No fusionar ni desplegar automáticamente.

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
mkdir -p "$HOME/impe-publicadores"
curl -fL --retry 2 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/49522589621ff2a2ed79d50d5f025f24d6a0fead/tools/publicar_enlace_convocatoria_hostinger.sh" \
  -o "$HOME/impe-publicadores/enlace-convocatoria-49522589.sh" &&
printf '%s  %s\n' \
  'c0caa72ad5a6e55bc269b349f05bab0350d63eeeff8e4c57e432096dc06ccf1b' \
  "$HOME/impe-publicadores/enlace-convocatoria-49522589.sh" | sha256sum -c - &&
bash "$HOME/impe-publicadores/enlace-convocatoria-49522589.sh" --check
```

Sólo tras hash válido y revisión compatible:

```bash
bash "$HOME/impe-publicadores/enlace-convocatoria-49522589.sh" --apply
php "$IMPE_CORREOS_PUBLIC/tools/diagnosticar_enlace_convocatoria.php" --convocatoria=11
```

Esperado para la convocatoria 11: `imagen_valida`, `enlace_guardado`, `enlace_disponible` y `url_conservada` verdaderos; tipo `registro`. El diagnóstico no envía correos ni imprime la URL completa.

El publicador respalda y modifica únicamente seis archivos. Admite la vista Hostinger v4, conserva sus remitentes y rechaza versiones incompatibles. Guardar el comando `--restore` con la ruta exacta del respaldo impresa por `--apply`; no sustituirla por un ejemplo. Restaurar exige que los archivos publicados no hayan cambiado posteriormente.
