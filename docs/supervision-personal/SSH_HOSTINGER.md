# Hostinger: auditoría, CHECK y ensayo privado

Continuación de `76e2f19`, rama `feat/supervision-personal-independiente`. No desplegar el repositorio completo: producción tiene personalizaciones que no están todas en `main`. Se releýeron los once archivos existentes involucrados y sus bloques fueron compatibles en esta revisión; el CHECK local en el servidor sigue siendo obligatorio.

## Ejecutar ahora: ningún cambio al CRM ni a MySQL

Abrir SSH en la cuenta propietaria y pegar este bloque. Clona el paquete público en una carpeta privada única, fuera de `public_html`; no cambia la instalación ni sus configuraciones. La auditoría y la comprobación estructural usan una conexión con transacción `READ ONLY`. Los JSON sólo contienen IDs/metadatos y deben conservarse en privado.

```bash
(
  set -eu
  umask 077
  SP_SITE="$HOME/domains/fundacionrededumex.org/public_html"
  SP_PRIVATE="$HOME/domains/fundacionrededumex.org/impe-private"
  test -f "$SP_SITE/config/db_connection.php"
  mkdir -p "$SP_PRIVATE"
  SP_RUN="$(mktemp -d "$SP_PRIVATE/supervision-revision-XXXXXXXX")"
  git clone --depth 1 --single-branch --branch feat/supervision-personal-independiente \
    https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git "$SP_RUN/paquete"
  cd "$SP_RUN/paquete"
  git rev-parse HEAD > "$SP_RUN/commit-paquete.txt"
  php tools/supervision_personal_migrar.php --site "$SP_SITE" --auditar --salida "$SP_RUN/asignaciones.json"
  php tools/supervision_personal_preflight.php --site "$SP_SITE" --salida "$SP_RUN/estructura.json"
  python3 tools/publicar_supervision_personal.py --site "$SP_SITE" --backup-root "$SP_RUN/respaldos" --check
  printf 'REVISION_PRIVADA=%s\n' "$SP_RUN"
  printf 'COMMIT_PAQUETE='
  cat "$SP_RUN/commit-paquete.txt"
)
```

Debe terminar en `AUDITORIA_SOLO_LECTURA_OK`, `ESTRUCTURA_COMPATIBLE` y `COMPROBACION_OK`. `GRUPOS_DUPLICADOS` informa filas operativas vigentes repetidas: **no se borran ni corrigen**. Las parejas de supervisión se deduplican en la tabla nueva sin tocar las filas originales. Las relaciones ambiguas/inconsistentes y accesos amplios heredados por estado requieren una decisión individual autorizada.

Si falla cualquier paso, detenerse y conservar la carpeta impresa por Git al clonar; no ejecutar `--apply`, `--preparar`, `--activar`, `ALTER`, `DELETE`, ni cambiar huellas para superar un rechazo. El publicador puede detenerse si la reestructuración telefónica cambió un bloque desde esta revisión. Se debe comparar ese cambio, no sustituir el archivo completo.

Compartir sólo el resumen de salida; no subir la carpeta privada, configuración o exportaciones SQL al repositorio.

## Revisar el informe privado

En otra sesión SSH, copiar la ruta `REVISION_PRIVADA` del resultado anterior. `SP_RUN` no persiste al terminar el subshell.

```bash
SP_RUN='/ruta/exacta/mostrada/en/REVISION_PRIVADA'
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo json_encode(["resumen"=>$p["resumen"],"requieren_revision"=>$p["requieren_revision"],"advertencias"=>$p["advertencias"]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;' "$SP_RUN/asignaciones.json"
```

Revisar con Administración/Dirección cada ID ambiguo. Una relación huérfana no puede inventarse o reasignarse automáticamente. Los accesos territoriales legítimos adicionales deben reconocerse expresamente en las decisiones; ninguna activación puede omitir las decisiones pendientes. Las decisiones y la huella de aprobación quedan ligadas a ese plan exacto.

## Ensayo de parches en copia privada, sin SQL

Este paso escribe únicamente en una copia privada nueva de los 21 archivos involucrados. No copia credenciales, no ejecuta la aplicación, no llama proveedores ni abre MySQL. Comprueba CHECK, aplicación de parches, sintaxis, idempotencia y que el origen siga intacto.

```bash
SP_SITE="$HOME/domains/fundacionrededumex.org/public_html"
python3 "$SP_RUN/paquete/tools/supervision_personal_ensayo.py" \
  --site "$SP_SITE" --private-root "$SP_RUN"
```

Debe terminar en `ENSAYO_ARCHIVOS_OK`. Se guarda `resultado.json` privado con huellas del origen y la copia. El campo `validacion_funcional_completa` queda explícitamente en `false`: este ensayo de archivos **no sustituye** las pruebas funcionales.

## Antes de migrar o activar

1. Conservar el commit del paquete, los informes, una copia privada verificada de la base de datos y el respaldo privado de archivos. El publicador genera el respaldo exacto de cada archivo que vaya a modificar; el preparador guarda el plan antes del DDL.
2. Crear una instalación de staging de la versión personalizada con una **base distinta**, datos anonimizados y conexiones externas deshabilitadas. Nunca copiar las credenciales de producción a las pruebas. No basta con usar otro subdirectorio conectado a la misma base.
3. Ejecutar en esa instalación la preparación desactivada y la migración revisada descritas en [MIGRACION.md](MIGRACION.md). No resolver ambigüedades en bloque. Las tres tablas nuevas y el permiso administrativo son los únicos cambios de estructura/gobierno previstos; no se actualizan asignaciones operativas.
4. Aprobar la matriz de [PRUEBAS.md](PRUEBAS.md): roles, doble supervisor, revocación, reportes/PDF/exportaciones, enlaces de audio, permisos generales, integridad territorial y regresión telefónica.
5. Coordinar una ventana sin otro despliegue de telefonía. Repetir auditoría, estructura y CHECK si cambian datos o código; un plan antiguo o modificado se rechaza.
6. Sólo tras revisar estos resultados, autorizar separadamente preparación/publicación y, después, activación en producción. Los comandos que modifican el sitio no forman parte del bloque inicial.

## Reversión preparada

El procedimiento completo está en [MIGRACION.md](MIGRACION.md). Se desactiva primero la política nueva con CLI autorizado y luego, si hace falta, se restaura el respaldo exacto del publicador. La reversión conserva relaciones nuevas y bitácora; no borra tablas ni datos. Se detiene si un archivo tiene cambios posteriores, para evitar pisar la reestructuración de telefonía.

No ejecutar la reversión como parte de la auditoría inicial: todavía no hay nada de esta mejora instalado que revertir.
