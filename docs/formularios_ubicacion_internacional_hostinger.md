# Ubicación internacional: incremento del PR #114

Registro e Inscripción exclusivos resuelven país extranjero por el aliado en BD. Los enlaces históricos sin `convocatoria_ref` continúan disponibles. País, estado/provincia y ciudad se capturan como texto; los IDs mexicanos se guardan NULL. Se rechazan IDs territoriales recibidos en formularios internacionales y ubicaciones mexicanas inexistentes en formularios nacionales. La autorización se revalida dentro de la transacción, antes de detectar reenvíos.

Los datos se guardan en su tabla original y se recuperan en las respuestas administrativas de Aliados Internacionales. No se cambia Prospectos ni se convierten inscripciones en registros. Los enlaces y QR existentes no cambian. CSRF por sesión protege ambos formularios públicos; una pestaña anterior a esta entrega debe recargarse.

## Preparación manual en Hostinger

La migración anterior de Aliados Internacionales ya fue aplicada: **no repetirla**. Respaldar la BD con el procedimiento habitual del alojamiento antes de este incremento. Mantener preparación, scripts y respaldos fuera de `public_html`.

Desde SSH, obtener la entrega del PR y congelar el commit descargado:

```bash
mkdir -p "$HOME/crm-publicaciones"
git clone --no-checkout https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git "$HOME/crm-publicaciones/formularios-ubicacion-revision"
cd "$HOME/crm-publicaciones/formularios-ubicacion-revision"
git fetch origin refs/heads/fix/aliados-internacionales-convocatorias
ENTREGA=$(git rev-parse FETCH_HEAD)
git checkout --detach "$ENTREGA"
printf 'Entrega descargada: %s\n' "$ENTREGA"
export IMPE_FORMULARIOS_UBICACION_SOURCE="$PWD"
export IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html"
bash tools/publicar_formularios_ubicacion_internacional_hostinger.sh --check
php tools/migrar_formularios_ubicacion_internacional.php --check
```

El primer `--check` no modifica el sitio. El segundo devuelve error si la migración está pendiente: es el resultado esperado antes de aplicarla. Si hay personalizaciones incompatibles, el publicador se detiene antes de escribir. No usar `git pull` en el sitio.

Una vez revisado el resultado y respaldada la BD, aplicar **sólo la migración nueva**, manualmente, antes de instalar PHP/JS:

```bash
php tools/migrar_formularios_ubicacion_internacional.php --apply
php tools/migrar_formularios_ubicacion_internacional.php --check
bash tools/publicar_formularios_ubicacion_internacional_hostinger.sh --apply
```

El publicador instala únicamente los archivos enumerados en su manifiesto, valida huellas y PHP, conserva personalizaciones PHP compatibles y CRLF, bloquea conflictos y cambios concurrentes, crea respaldo privado y no ejecuta SQL. El script de migración permanece en el clon privado. Revisar un enlace exclusivo de cada tipo y un formulario nacional después de publicar.

Para restaurar archivos, usar la ruta **exacta** que imprimió `--apply`:

```bash
bash tools/publicar_formularios_ubicacion_internacional_hostinger.sh --restore "$HOME/respaldos-crm/RUTA_IMPRESA_POR_APPLY"
```

La restauración no elimina columnas ni datos internacionales. No volver a NOT NULL: perdería compatibilidad con respuestas internacionales ya recibidas. La migración es aditiva e idempotente; MySQL confirma DDL por sentencia. Si se interrumpe, corregir la causa y repetir `--apply`.

## Validación

MariaDB aislada: migración desde IDs NOT NULL, dos aplicaciones y check; Registro/Inscripción Texas-Houston; recuperación, reenvíos, rechazo de POST territorial, catálogos nacionales, programas autorizados, procedencia exclusiva y rollback. Edge: envío real, CSRF, validaciones, responsive y formularios generales; directorio y modal compartido en 1920/1366/1024/768/390/360. CI conserva la matriz MySQL 8/MariaDB 10.11 y añade la migración y pruebas nuevas. Los resultados remotos deben comprobarse después del push.
