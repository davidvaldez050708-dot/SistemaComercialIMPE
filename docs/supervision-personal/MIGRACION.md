# Migración y publicación de supervisión de personal

**Procedimiento de referencia.** El titular ya ejecutó la instalación inicial autorizada de `de71579`; la política nueva continúa desactivada. Para los ajustes posteriores usar [AJUSTES_INSTALADOS.md](AJUSTES_INSTALADOS.md).

La rama es `feat/supervision-personal-independiente`. La referencia de código son las personalizaciones leídas de Hostinger el 27/11/2026, sobre Git `a90b6ad`. No utilizar una copia completa de esta rama para sustituir producción: el Git remoto no contiene todas sus personalizaciones.

## Alcance y dependencias

Se añaden `supervision_personal`, `supervision_personal_estado` y `supervision_personal_bitacora`. La pareja `(kam_id,colaborador_id)` es única; una persona puede tener varios KAM. Los IDs usan el mismo tipo entero que `usuarios.id`. No se agregan claves foráneas a tablas operativas, para no alterar su flujo de altas/bajas; el servicio valida usuario, rol, estado y permisos vigentes.

`supervision_personal_estado` mantiene modo, revisión global, versión de esquema y huella del plan. `supervision_personal_bitacora` conserva IDs, nombres, antes/después, altas/bajas, actor, fecha UTC y clave de idempotencia. La UI muestra fecha de Ciudad de México. No eliminar estas tablas durante una reversión.

Permisos nuevos: `personal.supervision.ver`, `personal.supervision.gestionar` y `personal.supervision.bitacora`. Se asignan inicialmente sólo al rol 1. Administración puede delegarlos explícitamente desde Roles y permisos; Gestión y Bitácora requieren Ver. La activación/preparación/reversión por CLI sigue requiriendo una cuenta administrativa activa. No se conceden permisos de reportes, desempeño o audio al crear una relación. Los permisos generales siguen siendo obligatorios. Los colaboradores desactivados pueden conservar su historial; no pueden añadirse nuevos inactivos desde el módulo. Las relaciones cuyo usuario desapareció o cambió de rol se muestran para revisión y no se eliminan al aplicar un filtro.

No se modifican asignaciones territoriales, carteras, responsables, prospectos, extensiones, eventos PBX, transferencias, webhooks, importaciones, señalización, cierre de llamadas ni registro de resultados. El único ajuste al endpoint antiguo de audio ocurre antes de obtener el archivo: valida al autor supervisado. Las reglas de evidencia, proveedor, rangos y reproducción se conservan.

## 1. Auditoría y revisión, antes de cualquier activación

Preparar una copia de staging de **la versión instalada**, con base anonimizada y sin webhooks, cron, correos ni llamadas reales. Guardar un respaldo verificado de código y base. Usar carpetas privadas fuera de `public_html`; no colocar planes, respaldos o decisiones bajo una URL pública.

El CLI puede mantenerse en una carpeta privada con sus cuatro clases de servicio, conservando la estructura `tools/` y `app/services/`. `--site` identifica la instalación cuya configuración se utilizará. `--auditar` realiza únicamente lecturas; `--revisar` sólo procesa JSON y no abre MySQL. Las instrucciones siguientes son de staging; su uso en producción requiere autorización posterior.

```bash
php tools/supervision_personal_migrar.php --site /ruta/staging/public_html --auditar --salida /ruta/privada/plan-supervision.json
```

El plan incluye relaciones explícitas válidas, únicas y vigentes y detecta vínculos huérfanos, discrepancias de rol/estado y exposición histórica por territorio sin equipo explícito. No cambia asignaciones ni corrige anomalías.

Revisar **cada** entrada de `requieren_revision` con Administración/Dirección. Crear un JSON privado indexado por su `id`, con `accion` (`incluir`, `excluir` o `conservar_sin_migrar`) y `motivo` específico de al menos 10 caracteres. No aprobar en bloque ni inventar personas para una relación huérfana. Las relaciones explícitas válidas se conservan automáticamente. Un acceso territorial adicional legítimo debe incluirse expresamente antes de activar; excluirlo requiere una decisión autorizada.

```json
{
  "alcance:ID_KAM:ID_COLABORADOR": {
    "accion": "incluir",
    "motivo": "Sustituir por la decisión y justificación aprobadas para esta relación."
  }
}
```

Este ejemplo no es un archivo válido de aprobación: hay que usar exclusivamente los IDs presentes en el plan real. Si no hay ambigüedades, las decisiones son `{}`.

```bash
php tools/supervision_personal_migrar.php --site /ruta/staging/public_html --revisar --plan /ruta/privada/plan-supervision.json --decisiones /ruta/privada/decisiones-supervision.json
```

Conservar `REVISION_HASH`. Liga la aprobación a **ese plan y esas decisiones**. Cambios de asignaciones, roles, estados, autores/responsables o fecha de auditoría invalidan el plan: repetir la lectura y revisión, sin forzar huellas. El CLI rechaza planes editados y decisiones incompletas. La auditoría real de producción sigue pendiente.

## 2. Preparación conservadora, sólo después de autorización

Antes de aplicar DDL, comprobar permisos de creación, tipo de IDs, motor InnoDB y espacio para las tres tablas. Reservar una ventana controlada para preparar y validar el esquema; MySQL hace commits implícitos en DDL. La preparación respalda el plan antes de crear tablas y deja la autorización nueva **apagada**.

```bash
php tools/supervision_personal_migrar.php --site /ruta/staging/public_html --preparar --actor ID_ADMIN --plan /ruta/privada/plan-supervision.json --respaldo /ruta/privada/antes-supervision.json --autorizar-migracion
```

Sólo se importan pares explícitos válidos y se concede el permiso de administración al rol 1. Las ampliaciones por ambigüedad esperan a la activación. Repetir la misma preparación completa no duplica pares, bitácora o permisos. Si ya hay tablas parciales o una preparación diferente, se detiene: no usar `DROP`, no cambiar el flag manualmente, no ejecutar un segundo script de reparación sin revisar el respaldo y el error. Un fallo de DDL puede dejar tablas nuevas parciales; no significa que se haya modificado la asignación territorial.

## 3. Publicación de código, con aprobación independiente

Revisar `tools/supervision_personal_release.json`: 12 archivos nuevos y 12 existentes, con bloques concretos de la versión de Hostinger y huellas de referencia. Los servicios de llamadas/ventas y `SupervisionRolePolicy.php` aparecen como nuevos respecto de Git, pero son archivos **existentes en Hostinger** y se publican mediante parches.

Llevar publicador y manifiesto a una carpeta privada. Primero comprobar sobre staging y después, con autorización, sobre producción:

```bash
python3 tools/publicar_supervision_personal.py --site /ruta/staging/public_html --backup-root /ruta/privada/respaldos-supervision --check
```

`--check` no escribe ni ejecuta SQL. Debe terminar en `COMPROBACION_OK`. Si señala `Bloque diferente`, comparar con la nueva personalización y rehacer/reprobar el paquete; no sustituir el archivo completo ni omitir la comprobación.

Sólo tras aprobación de los cambios concretos, ejecutar en el destino autorizado:

```bash
python3 tools/publicar_supervision_personal.py --site /ruta/staging/public_html --backup-root /ruta/privada/respaldos-supervision --apply --autorizar-publicacion
```

Guardar la ruta `RESPALDO`. El publicador comprueba todos los bloques y sintaxis PHP antes de escribir, instala dependencias antes que navegación, comprueba cambios concurrentes y verifica el resultado. Conserva las personalizaciones fuera de los bloques. No ejecuta migraciones ni llamadas. Comparar de nuevo los archivos tocados con la reestructuración universal de telefonía antes de autorizar producción. Si OPcache tiene validación temporal desactivada, coordinar su invalidación selectiva con el responsable del alojamiento; no reiniciar servicios durante llamadas.

Coordinar una ventana sin otros despliegues sobre estos archivos. Las comprobaciones de concurrencia no sustituyen la coordinación entre publicadores de código; en particular, no publicar esta rama y la reestructuración telefónica simultáneamente.

El administrador puede necesitar renovar su sesión para que el menú reconozca el permiso nuevo. Antes de activar, el módulo sólo permite consultar; el resto sigue con el alcance anterior.

## 4. Activación y validación

No activar hasta resolver las ambigüedades y superar la matriz de staging. El CLI vuelve a comprobar el plan frente a los datos actuales y verifica que la preparación no fue modificada fuera del procedimiento.

```bash
php tools/supervision_personal_migrar.php --site /ruta/staging/public_html --activar --actor ID_ADMIN --plan /ruta/privada/plan-supervision.json --decisiones /ruta/privada/decisiones-supervision.json --revision-autorizada HASH_REVISADO --autorizar-migracion
```

Comprobar acceso, actividad, exportación/PDF, llamadas, enlaces directos de audio y desempeño con cuentas KAM, analista, asesor, Dirección y Administrador. Asignar una persona a dos KAM, cambiarla sin modificar territorio y comprobar revocación en la petición siguiente. Las pestañas de reportes ya abiertas pueden conservar contenido mostrado; cualquier nueva consulta o descarga debe reevaluar permisos. Un archivo ya descargado no puede revocarse.

Comparar filas/huellas de `asignaciones_territorio` antes/después y probar los flujos operativos de la matriz. No activar simultáneamente un cron ni cambios universales de telefonía como parte de esta publicación.

## 5. Reversión segura

Primero desactivar el alcance nuevo mediante CLI, dejando todos los datos y la bitácora:

```bash
php tools/supervision_personal_migrar.php --site /ruta/staging/public_html --desactivar --actor ID_ADMIN --autorizar-reversion
```

Esto restaura la política anterior basada en las asignaciones operativas conservadas. Las nuevas supervisiones independientes dejan de conceder acceso; revisar esa consecuencia con Administración. Las altas/bajas realizadas no se borran. Reactivar después de una reversión exige una revisión nueva; la activación inicial no resembra ni sobreescribe relaciones existentes.

Si también se debe retirar el código y existe autorización, usar el respaldo exacto generado por esta publicación:

```bash
python3 tools/publicar_supervision_personal.py --site /ruta/staging/public_html --backup-root /ruta/privada/respaldos-supervision --restore /ruta/privada/respaldos-supervision/RESPALDO_EXACTO --autorizar-publicacion
```

El publicador se detiene si alguien modificó después un archivo involucrado. Comparar manualmente; no forzar una restauración sobre cambios ajenos. No retirar archivos mientras el flag continúe activo. No borrar tablas, datos o permisos históricos durante esta reversión; la limpieza posterior sería otra operación revisada y autorizada.
