# Convenios: crear convocatorias según permisos

La cuenta de Dirección veía sólo el ojo porque el guard de publicación admitía únicamente Marketing y Cuenta Clave. Además, el servicio del editor exigía una asignación de Cuenta Clave y la gestión de enlaces rechazaba otros roles por su nombre/ID.

La corrección utiliza los permisos de publicación y el alcance territorial real:

- Acceso a Convenios mediante **Ver convenios** o **Gestionar convenios**; Marketing conserva su acceso existente.
- **Ver convocatorias** y **Crear convocatorias** para mostrar la acción y abrir/publicar el editor.
- Asesor, Analista de Datos y Cuenta Clave conservan sus asignaciones vigentes del tipo correspondiente. Dirección y los demás roles no territoriales operan nacionalmente cuando tienen permiso.
- Publicar con un Registro habilitado reutiliza su enlace. Generar, habilitar o deshabilitar enlaces sigue exigiendo **Gestionar enlaces de formularios de aliados** (`formularios_aliados.gestionar_enlaces`). No se concede automáticamente este permiso.

La entrega reemplaza tres archivos: `PermissionHelper.php`, `AliadoRegistroService.php` y `ConvocatoriaController.php`. Sus versiones base coinciden exactamente con los hashes proporcionados desde Hostinger. Conserva los cambios internacionales, los programas, enlaces amigables, periodos mensuales, borradores y QR existentes. No modifica la base de datos al instalar.

## Instalar

Pega el bloque completo en SSH. Comprueba integridad y compatibilidad, crea respaldo privado y aplica sólo los tres archivos. Un conflicto detiene el bloque sin sobrescribir versiones desconocidas.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-convenios-publicacion-f0a160f5"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/f0a160f5777e27dfa6dc17ce89f7f33bed792799/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
for f in publicar_convenios_publicacion_permisos.py convenios_publicacion_permisos_release.json; do
  curl -fsSL "$BASE/$f" -o "$f"
done
printf '%s\n' \
  '2003ac4f735b7cb0b9111e04a62f79a01632f68983acf82af7b482bdb0050b60  publicar_convenios_publicacion_permisos.py' \
  'fe9d5b101889323e945563e911d195aad8e6b46331da2a07b7d8d04ecb7ebcb4  convenios_publicacion_permisos_release.json' | sha256sum -c -
python3 publicar_convenios_publicacion_permisos.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-convenios-publicacion" --check
python3 publicar_convenios_publicacion_permisos.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-convenios-publicacion" --apply
)
```

## Comprobar con la cuenta de Dirección

1. Recarga Convenios y abre Baja California. Con los permisos de Convocatorias que ya habilitaste debe aparecer **Crear convocatoria**, además del ojo. No necesitas administrar enlaces para el Registro que aparece **habilitado** en la captura.
2. Abre el editor del aliado y guarda un borrador. Recarga y comprueba que conserve sus campos e imágenes.
3. Cuando tengas una publicación real lista, publícala y comprueba que aparezca en Gestión de Convocatorias con el aliado, programa, territorio e imagen correctos. El enlace de Registro existente debe conservarse.
4. Revoca **Crear convocatorias**, recarga y confirma que desaparezca la acción; la apertura y publicación directas también deben denegarse. Restablece el permiso si debe seguir publicando.
5. Cuenta Clave, Asesor y Analista continúan limitados a sus territorios; una asignación vencida no permite abrir el editor del aliado de ese estado.

Las pruebas automatizadas usan HTTP y bases MySQL/MariaDB aisladas: muestran/ocultan el acceso según permiso, abren y guardan borradores de ambos orígenes, suben imágenes reales, consultan QR, publican dos programas por origen, comprueban persistencia e idempotencia y prueban el guardado tradicional sin permiso de administrar enlaces. También verifican rechazo por permiso revocado, borrador ajeno y asignación ajena/vencida.

## Restaurar

Usa la carpeta exacta impresa como `RESPALDO` al aplicar. La restauración se bloquea si los archivos tienen cambios posteriores:

```bash
python3 "$HOME/impe-convenios-publicacion-f0a160f5/publicar_convenios_publicacion_permisos.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-convenios-publicacion" \
  --restore "$HOME/respaldos-impe-convenios-publicacion/CARPETA-REAL"
```
