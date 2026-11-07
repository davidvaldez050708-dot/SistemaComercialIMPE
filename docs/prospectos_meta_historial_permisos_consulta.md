# Corrección: botón Historial invisible en Administrador (Campañas)

## Diagnóstico
La interfaz `Prospectos → Campañas` mostraba `Historial` y `Importar archivo` bajo la **misma** condición `$metaPuedeImportar`. Ese permiso es deliberadamente operativo y está **bloqueado al Administrador** por `AdminRolePolicy.php`. La captura del usuario (Administrador ve `Configurar formularios`, pero no los otros dos botones) concuerda exactamente con el error de enlace de permisos; no es un problema de CSS ni de la tabla del historial.

## Corrección
- Nueva capacidad independiente **`prospectos.ver_historial_meta`**: solo lectura de archivos importados, sus contadores, formulario, fecha y responsable. Requiere además **`prospectos.ver_todos`**.
- `Historial` se muestra según `prospectos.ver_historial_meta`; `Importar archivo` sigue condicionado **únicamente** a `prospectos.importar_meta`; `Configurar formularios` mantiene `prospectos.configurar_formularios`. Ningún permiso concede automáticamente los otros.
- Una solicitud directa `?seccion=meta&historial_importaciones=1` sin permiso de consulta recibe HTTP **403**, incluso si el usuario ve el listado general.
- **Administrador:** `Configurar formularios` + `Historial` sin botón de importar. **Marketing autorizado:** `Importar archivo` + `Historial`, y configuración solo si tiene el permiso correspondiente. Se admiten roles de consulta del historial sin capacidad operativa.
- El nuevo permiso aparece en **Usuarios → Roles y permisos**, bajo Prospectos. La autorización utiliza `tienePermiso()` y los códigos de permiso de la sesión, no el nombre del rol.

## Migración aditiva de permisos
`database/migrations/2026_11_07_prospectos_meta_historial_permiso_consulta.sql` inserta únicamente un nuevo permiso y concede **una sola vez** el acceso de consulta a roles que previamente ya contaban con `prospectos.ver_todos` y `prospectos.importar_meta` **o** `prospectos.configurar_formularios` —incluido el Administrador que configuraba formularios y Marketing que importaba—. No habilita `importar_meta` al Administrador ni añade roles/usuarios.

**Es idempotente:** usa `INSERT IGNORE` y guarda `ROW_COUNT()` del permiso recién creado. Reejecutar la migración nunca restituye relaciones rol-permiso que se hayan revocado después. Un permiso preexistente desactivado tampoco se reactiva. El Administrador protegido conserva el permiso de lectura en su política existente; no se modifica `RolModel.php`, que en Hostinger tiene personalizaciones sensibles.

**Importante**: las sesiones existentes conservan la lista de permisos anterior. Después de instalar el permiso y publicar PHP hay que **cerrar sesión e iniciarla de nuevo** para que aparezca el botón en el Administrador. No basta con actualizar la página.

## Despliegue
1. Respaldar BD y confirmar que la bitácora `prospecto_meta_importaciones_historial` ya está instalada (PR #243) y que las políticas originales permanecen.
2. Ejecutar `python3 tools/publicar_prospectos_meta_historial_permisos.py --site SITIO --check`. Verificar SHA base de tres archivos y las cinco dependencias. Si hay conflicto, detenerse.
3. Instalar migración de permisos únicamente tras comprobar respaldo, con SQL SHA verificado. Confirmar permiso activo, asignación al rol Administrador y que **no** dispone de `prospectos.importar_meta`. Los roles sin permiso no deben recibirlo.
4. Publicar exclusivamente tres PHP con respaldo privado fuera de `public_html`: `--apply --backup-root RUTA`; verificar `php -l` y `--check`.
5. Salir y volver a entrar al CRM. Probar Administrador (Configurar + Historial, sin Importar), Marketing (Importar + Historial si tiene permisos), acceso directo sin autorización (403) y paginación del historial.
6. No activa motores Meta/Aliados ni cron, no modifica el historial de importaciones previas, asesoría, telefonía, registros de leads ni agenda. El publicador permite `--restore RUTA` con control de conflictos; restaurar PHP no revierte SQL ni la concesión de permisos.

## Tests
- `tests/prospectos_meta_historial_acceso_permisos_unit.php`: 10 combinaciones de sesiones, roles y permisos; Administrador protegido sin importación.
- `tests/prospectos_meta_historial_ui_test.php`: vista para Administrador, Marketing, rol de sólo lectura y permiso revocado; conserva paginación, filtros y CSRF.
- `tests/prospectos_meta_historial_permisos_db.php`: base MySQL desechable, concesión inicial únicamente a roles elegibles y no restitución al repetir la migración tras revocación.
- `tests/prospectos_meta_historial_permisos_release_test.py`: respaldo exacto, publicación idempotente, restore y rechazo de cambios concurrentes.
