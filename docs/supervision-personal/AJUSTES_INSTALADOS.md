# Ajustes visuales y permisos sobre la versión instalada

Actualización para `de71579` instalado. No usar de nuevo el paquete completo inicial. El paquete incremental `supervision_personal_ajustes_release.json` contiene 2 archivos nuevos y 9 parches de archivos existentes. No modifica territorios, supervisiones ni activación, y no toca servicios de telefonía.

Cambios visuales: buscador con un solo control institucional, aviso compacto de modo consulta, bitácora con tipo de movimiento, responsable/fecha, etiquetas de altas/bajas y paginación institucional. Se conserva el modal de selección de colaboradores.

## Roles y permisos

En el grupo **Supervisión de personal** aparecen:

- **Ver supervisión de personal**: consultar tarjetas y colaboradores.
- **Administrar supervisión de personal**: revisar y guardar asignaciones. Requiere Ver.
- **Consultar bitácora de supervisión**: consultar movimientos. Requiere Ver.

El permiso existente de Gestión conserva sus concesiones. Los dos permisos nuevos sólo se conceden inicialmente al administrador; repetir el instalador no los vuelve a conceder si fueron revocados. Ningún otro rol obtiene acceso automáticamente. Administración decide los roles que pueden usar cada acción desde el módulo actual de Roles y permisos. Las dependencias se reflejan en sus casillas y se validan también en el servidor. Estos permisos no sustituyen los permisos generales de reportes, desempeño o grabaciones.

La preparación, activación y reversión por CLI siguen requiriendo un administrador. Delegar la gestión web no delega esas operaciones.

## Publicación del ajuste

Usar el paquete de GitHub fijado al commit revisado. En la carpeta privada ya existente, guardar el commit anterior antes de actualizar Git y comprobar que no haya cambios locales. No ejecutar `git reset --hard`, `git clean` ni un checkout sobre archivos modificados. El paquete privado es distinto del sitio público.

Con `SP_RUN` y `SP_SITE` establecidos y la nueva versión del paquete descargada:

```bash
SP_SITE="$HOME/domains/fundacionrededumex.org/public_html"
SP_RUN="$HOME/domains/fundacionrededumex.org/impe-private/supervision-revision-0FRAbZyv"
SP_AJUSTE="$(mktemp -d "$SP_RUN/ajustes-XXXXXXXX")"
cd "$SP_RUN/paquete"

python3 tools/publicar_supervision_personal.py --site "$SP_SITE" --backup-root "$SP_AJUSTE/archivos" --paquete supervision_personal_ajustes_release.json --check
php tools/supervision_personal_permisos.php --site "$SP_SITE" --check
```

Tras comprobar el paquete concreto, instalar únicamente los dos permisos nuevos con respaldo privado y publicar los parches revisados:

```bash
php tools/supervision_personal_permisos.php --site "$SP_SITE" --apply --actor 1 --respaldo "$SP_AJUSTE/permisos-antes.json" --autorizar
python3 tools/publicar_supervision_personal.py --site "$SP_SITE" --backup-root "$SP_AJUSTE/archivos" --paquete supervision_personal_ajustes_release.json --apply --autorizar-publicacion
php tools/supervision_personal_permisos.php --site "$SP_SITE" --check
```

No ejecutar `--preparar` o `--activar` como parte de este ajuste. Guardar la ruta del respaldo. Renovar la sesión del administrador para que el menú reconozca Ver. Comprobar el catálogo, el modo consulta, la bitácora y el grupo de permisos. No concederlos a otros roles hasta que Administración lo decida.

## Reversión

Con autorización, restaurar el respaldo específico de este ajuste usando `--restore RUTA_EXACTA --autorizar-publicacion` y **el mismo `--paquete supervision_personal_ajustes_release.json`**. Se detiene si hay cambios posteriores en los archivos. Los dos permisos nuevos pueden conservarse en el catálogo sin uso por el código anterior; no se borran ni se revocan concesiones automáticamente. La recuperación del catálogo, si se necesita, se revisa con `permisos-antes.json`. No se cambia el flag de activación ni se borran supervisiones o bitácora.

Vistas locales con datos ficticios: [catálogo](catalogo-actualizado.png) y [bitácora móvil](bitacora-movil.png).
