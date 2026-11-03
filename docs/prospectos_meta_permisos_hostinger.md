# Importación Meta: conservar Roles y Permisos de Hostinger

## Actualización del selector CSV

El campo instalado incluía `.csv` en `accept` y el servidor ya podía leer CSV,
TSV, TXT y XLSX. Se retira únicamente ese filtro orientativo del explorador
para que el archivo pueda elegirse sin depender de sus asociaciones o su
extensión. Esto incluye las exportaciones de Meta sin extensión que el lector
ya admite. Los formatos anunciados y todas las validaciones del servidor
permanecen iguales: contenido, extensión, cabeceras, 8 MB y 5,000 filas.

El paquete reconoce la vista anterior o su ausencia en una primera instalación.
Si ya se instalaron los permisos personalizados, sólo cambia la vista del modal;
la prueba de publicación verifica explícitamente esa única escritura.

El publicador `52426de1` se detuvo en `RolModel.php` antes de la primera
escritura. El modelo real añade Control de llamadas y su concesión inicial al
Director, aplica `permisoReservadoParaRol`, permite el reporte administrativo a
Dirección y conserva revocable el Reporte de convocatorias. El paquete sólo
reconocía el modelo anterior a esas personalizaciones.

La variante instalada se reconstruyó desde el diff aportado contra `13f0cae3`.
Su SHA-256 coincide exactamente:
`94fe92be07ae319335b4c1fbc11e5a1a1f0026ae79b0418616f3d1a9cd9b1225`.
La política compartida, que no se modifica, coincide con
`f9542f3c578d953cfde72f807cf98bc770beffac7e606f8bf9f975d5324d001b`.
`AdminRolePolicy.php` y la vista central coinciden con la entrega anterior del
importador; no requieren reconstrucción.

## Alcance

Se mantiene el cambio de autorización de `e4aef1e2`: permiso explícito
`prospectos.importar_meta`, dependiente de `prospectos.ver_todos`, tanto para la
interfaz como para las acciones del servidor. El permiso puede concederse y
revocarse desde Roles y Permisos sin depender del nombre del rol. Se conserva
la política ya existente de Administrador protegido y la concesión inicial
única a Marketing si ya tenía acceso al importador.

Esta corrección adapta exclusivamente el publicador. Las fuentes canónicas
de la funcionalidad no cambian. Para la variante real, instala el modelo con
el nuevo permiso y **todas** las modificaciones locales conservadas. Para el
modelo canónico, mantiene la entrega canónica original. Cualquier tercera
variante se rechaza; no se añadió una opción de forzar.

Los originales y el modelo combinado están comprimidos en
`tools/prospectos_meta_rol_hostinger_compat.json`; se validan por hash antes de
utilizarlos. La política compartida se comprueba como dependencia de sólo
lectura y se vuelve a comprobar antes de escribir. Se instala primero la
política protegida del Administrador y después el catálogo del nuevo permiso.
El instalador admite el importador previo, LF/CRLF, idempotencia, respaldo
privado y restauración. No sustituye el panel visual de la ficha ni el sidebar.

## Instalación controlada

Descargar del mismo commit revisado y verificar los hashes de estos **dos**
archivos, fuera de `public_html`:

- `tools/publicar_prospectos_importador_meta.py`
- `tools/prospectos_meta_rol_hostinger_compat.json`

```bash
python3 publicar_prospectos_importador_meta.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-prospectos-meta" --check
python3 publicar_prospectos_importador_meta.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-prospectos-meta" --apply
```

La instalación sólo escribe archivos y no ejecuta SQL. Como ya hacía el CRM,
su inicialización normal registra el permiso en el catálogo de la base de datos.
El Administrador puede después autorizar «Importar prospectos de Meta Ads» al
Director u otro rol; el usuario debe volver a iniciar sesión para cargar su
lista actualizada. La revocación no se restablece al reinicializar permisos.

La ruta de respaldo se imprime al aplicar. `--restore RUTA` restaura los
archivos si no se modificaron después; no deshace cambios posteriores de roles
ni borra el catálogo o los prospectos.

## Validación

- Publicador canónico y personalizado sobre copias temporales. Comprueba
  instalación desde el importador previo, preservación exacta de la política,
  LF/CRLF, check sin cambios, versiones/dependencias desconocidas bloqueadas,
  idempotencia, restore exacto y rollback ante fallo intermedio.
- El modelo personalizado real se carga en un árbol temporal con su política
  compartida exacta. Se ejecutan 55 comprobaciones de permisos existentes más
  los casos de Dirección real (rol 2), Control de llamadas, reportes reservados,
  configuración PBX reservada, dependencia Meta y revocaciones persistentes.
- Las pruebas SQL se limitan a esquemas nuevos ficticios en `127.0.0.1`, con
  prefijos obligatorios; el esquema adicional se elimina al terminar.
- Se conservan las regresiones HTTP del importador, formatos CSV/XLSX,
  duplicados, catálogo, horarios y formularios de Aliados. Hostinger no se usa
  en estas pruebas ni se ha modificado desde las herramientas.
