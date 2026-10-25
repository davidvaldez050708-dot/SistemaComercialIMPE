# Director de Operaciones: instalación y comprobación

El rol **2** pasa de **Coordinador Comercial** a **Director de Operaciones**. Conserva sus usuarios, estado y permisos actuales. No se crea un segundo rol ni se conceden permisos nuevos automáticamente. Configura las consultas y reportes desde **Roles y permisos → Director de Operaciones**.

Con el permiso de consulta de cada módulo, Dirección tiene alcance nacional: no necesita asignaciones territoriales ni permiso para asignarlas. Analista de Datos, Cuenta Clave y Ventas conservan sus restricciones existentes. El Administrador sigue siendo el rol 1; sus reglas de gobierno no cambian.

## 1. Descargar y comprobar

Pega el bloque completo en SSH. Descarga los tres archivos en una carpeta privada y comprueba los 22 archivos del sitio, sin publicarlos ni modificar la base de datos. Todos los valores necesarios se declaran dentro del bloque.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-director-875fb170"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/875fb1705e13fa047359fd10e9104506490e509e/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
for f in publicar_director_operaciones.py director_operaciones_release.json renombrar_director_operaciones.php; do
  curl -fsSL "$BASE/$f" -o "$f"
done
printf '%s\n' \
  'e03ddfd1cd48e361fcc2acc980f55d4d43d0f04a06711866b52be97040382ec0  publicar_director_operaciones.py' \
  '5958096213afcc3d3b425259737b1281958c4b3f2408feb2dedcd616118aca8b  director_operaciones_release.json' \
  '80e5bbb7359ea8c5deed54dfeeb975b89869b23b46ec58164f62754106036919  renombrar_director_operaciones.php' | sha256sum -c -
python3 publicar_director_operaciones.py \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director" --check
)
```

Resultado esperado: `COMPATIBLE` para archivos pendientes o `ACTUALIZADO` para los ya instalados, seguido de `CHECK OK`. Un `CONFLICTO` detiene la publicación sin sobrescribir archivos. No uses `git pull`, una extracción completa del repositorio ni copias forzadas para resolverlo: la entrega preserva las variantes de Hostinger identificadas mediante hashes y comparaciones.

## 2. Publicar y renombrar el rol existente

El bloque vuelve a comprobar compatibilidad, respalda y publica exclusivamente los archivos previstos. Después comprueba el rol 2 y actualiza únicamente su nombre. Conserva asociaciones de usuarios y permisos. Guarda las dos rutas de respaldo que imprime.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-director-875fb170"
python3 "$PAQUETE/publicar_director_operaciones.py" \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director" --check
python3 "$PAQUETE/publicar_director_operaciones.py" \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director" --apply
php "$PAQUETE/renombrar_director_operaciones.php" \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director-rol" --check
php "$PAQUETE/renombrar_director_operaciones.php" \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director-rol" --apply
)
```

No modifica formularios, prospectos, convocatorias guardadas, grabaciones ni archivos telefónicos. Las personalizaciones de Aliados internacionales, periodos mensuales, programas, Marketing y menú se mantienen. `index.php` ya recarga usuario, rol y permisos en cada petición y no se sustituye.

## 3. Elegir permisos

Como Administrador, entra a **Roles y permisos** y selecciona **Director de Operaciones**. Activa únicamente lo que quieras habilitar. Para una supervisión completa de consulta:

| Consulta | Permisos |
|---|---|
| Todos los integrantes del equipo | `usuarios.ver` |
| Los 32 estados y sus asignaciones | `territorios.ver` |
| Información territorial nacional | `data_territorial.ver` |
| Vinculación y expedientes nacionales | `seguimientos_vinculacion.ver` |
| Oficios y reuniones de todos los equipos | `oficios.ver`, `reuniones.ver` |
| Aliados y su historial | `aliados.ver`, `aliados.ver_historial` |
| Convocatorias | `convocatorias.ver` |
| Prospectos generales y de aliados | `prospectos.ver_todos` |
| Centro de reportes y exportación | `reportes.ver`, `reportes.exportar` |
| Reporte de todos los usuarios/roles | `reportes.usuarios` |
| Reportes de Vinculación | `reportes.seguimiento.cartera`, `reportes.seguimiento.actividad`, `reportes.seguimiento.institucion` |
| Reporte territorial | `reportes.territorial` y `data_territorial.ver` |
| Reporte de convocatorias | `reportes.convocatorias` |
| Panorama de aliados | `reportes.aliados.panorama` y `aliados.ver` |
| Desempeño global, si está habilitado en tu instalación | `desempeno.ver`, `desempeno.ver_global` |

No hace falta conceder `territorios.asignar`, permisos de edición ni de operación para consultar nacionalmente. La configuración de telefonía y las credenciales de cuentas de WhatsApp mantienen su reserva para el Administrador.

Al marcar un permiso que necesita otro, la interfaz activa el permiso padre. Al quitar el padre, también se quitan sus dependientes. **Activar Centro de reportes ya no obliga a activar Reporte de convocatorias**: ese reporte se puede desactivar independientemente. El reporte de usuarios se puede activar o revocar en Dirección sin que el servidor descarte la selección.

## 4. Prueba de aceptación en Hostinger

1. Abre una cuenta que ya tenga el rol 2 y recarga. Debe mostrar **Director de Operaciones**, conservando su usuario.
2. Con `territorios.ver` activado y `territorios.asignar` desactivado, consulta los 32 estados y abre uno sin asignación para esa cuenta. Debes ver sus equipos, sin controles para reasignarlos.
3. Con los permisos de consulta correspondientes, abre seguimientos y reuniones de dos analistas diferentes; filtra usuarios y reportes por analista y asesor.
4. Activa `reportes.ver`, `reportes.usuarios` y `reportes.exportar`. Genera un reporte de usuarios con todos los roles y descarga el PDF.
5. Revoca `reportes.usuarios` desde Administración. En la cuenta de Dirección, recarga y vuelve a abrir la dirección del reporte que acababas de usar: debe denegar el acceso. No basta con comprobar que desaparezca el enlace.
6. Mantén `reportes.ver`, desactiva `reportes.convocatorias`, guarda y vuelve a entrar a Roles: el reporte de convocatorias debe seguir desactivado. Puedes volver a habilitarlo después.
7. Prueba una cuenta de Analista, Cuenta Clave y Ventas: sus territorios y equipos siguen limitados a sus asignaciones.

Los permisos se actualizan en la siguiente petición al servidor; una página que ya estaba dibujada necesita recargarse. Las pruebas automatizadas validan el catálogo, el guardado y la revocación con MySQL 8 y MariaDB 10.11 aislados; esta prueba comprueba además la configuración real de las cuentas del sitio.

## Restaurar

Sustituye las rutas de ejemplo por las rutas exactas impresas al aplicar. Primero restaura el nombre mientras el servicio todavía está instalado; después restaura los archivos:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-director-875fb170"
php "$PAQUETE/renombrar_director_operaciones.php" \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director-rol" \
  --restore "$HOME/respaldos-impe-director-rol/rol-2-RUTA-REAL.json"
python3 "$PAQUETE/publicar_director_operaciones.py" \
  --site "$SITIO" --backup-root "$HOME/respaldos-impe-director" \
  --restore "$HOME/respaldos-impe-director/CARPETA-REAL"
```

La restauración del nombre no revierte cambios posteriores de permisos. La restauración de archivos se bloquea si detecta ediciones posteriores, para evitar sobrescribirlas.
