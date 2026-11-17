# Próximas acciones y Seguimiento V3

Entrega basada en `5ea842ab1c8553c6afba6bc9790213482b0aa5a8`. No aplica cambios a producción por sí sola.

## Comportamiento

| Operación | Resultado |
|---|---|
| Programar/reprogramar en Contactados o Potenciales | Seguimiento, actividad e historial en la misma transacción |
| Programar antes de la primera conversación | Conserva Nuevos |
| Primera conversación PBX con próxima acción | Contactados y luego Seguimiento, con ambos movimientos auditados |
| Programar dentro de un trámite iniciado | Conserva Proceso de Inscripción |
| Registrar una gestión sin nueva fecha | Conserva etapa y cita pendiente |
| Reprogramar/completar desde Seguimiento | Conserva Seguimiento; completar elimina el pendiente activo |
| Iniciar inscripción desde Seguimiento | Reutiliza confirmación de documentos/comprobante e identidad válida |

Se aplica desde Actividad comercial, Gestión de Potenciales y resultados PBX. La actividad V3 es la agenda activa; la agenda anterior se conserva como historial. No se migran en lote los prospectos ni citas anteriores. Recuperación y Descartados mantienen sus restricciones.

Se conservan los estilos actuales, CSRF, permisos de asesor, propiedad de la asignación, bloqueo transaccional y control de versión. No se altera el cupo, reparto, telefonía ni resultados guardados.

## Validación aislada

- `tests/seguimiento_acciones_v3_db.php`: seis etapas, fechas UTC, notas, programación, reprogramación, finalización, PBX, duplicados, inscripción desde Seguimiento, permisos, versiones, dos procesos concurrentes y fallo SQL con rollback. Crea y elimina su propia base `impe_test_acciones_*` en MariaDB local desechable. Requiere `IMPE_SEGUIMIENTO_ACCIONES_TEST=YES` y `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_PORT` sintéticos.
- `tests/gestion_comercial_v3_inscripcion_db.php`: identidad, No contactar, solicitud institucional completa, unicidad, auditoría y rollback.
- `tests/embudo_potencial_pbx_db.php`: matriz PBX existente, número, duplicados y permisos.
- `tests/seguimiento_acciones_v3_browser.cjs`: controles en seis etapas, CSRF, doble clic y confirmación tras refrescar.
- `tests/gestion_comercial_v3_inscripcion_browser.cjs`: inicio desde Seguimiento, requisitos y solicitudes ausentes, incompletas, ambiguas, conflicto y vínculo válido.
- `tests/publicar_seguimiento_acciones_v3_test.py`: comprobación sin escrituras, personalizaciones, respaldo, publicación idempotente, rollback y reversión automática tras fallo parcial.

Las pruebas de navegador usan Playwright con Chromium/Edge y rutas HTTP sintéticas; las pruebas de datos ejecutan los servicios reales contra MariaDB aislada. No constituyen una prueba del estado actual de Hostinger.

La suite antigua `embudo_comercial_v3_previsualizacion_db.php` falla antes de alcanzar actividades en su expectativa de transiciones manuales V1 (línea 328); no se usa como evidencia de aprobación de esta entrega y se conserva sin cambios.

## CHECK en Hostinger

Descargar el archivo de la rama `fix/seguimiento-acciones-v3` usando el commit indicado en la entrega, extraerlo en una carpeta privada fuera de `public_html` y definir:

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
# ENTREGA es la carpeta extraída que contiene app/, public/ y tools/.
PUBLICADOR="$ENTREGA/tools/publicar_seguimiento_acciones_v3_hostinger.py"
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

CHECK verifica hashes de todos los archivos y dependencias, sintaxis PHP/JS y metadatos InnoDB de las tablas existentes. No modifica datos ni crea esquemas. Si hay personalizaciones o una base distinta, bloquea. No forzar hashes ni copiar archivos por encima del bloqueo.

## APPLY después de CHECK compatible

```bash
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" \
  --backup-root "$HOME/respaldos-seguimiento-acciones-v3" \
  --apply --confirm PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE
python3 "$PUBLICADOR" --site "$SITIO" --stage "$ENTREGA" --check
```

Crea/verifica respaldo privado de código y publica únicamente nueve archivos exactos. Conserva un manifiesto con permisos y hashes. Ante fallo parcial intenta devolver todos los archivos anteriores. No ejecuta SQL de escritura. Los mensajes del motor compartido mencionan Gestión de Potenciales V3; la confirmación específica es `SEGUIMIENTO_ACCIONES_V3_OK`.

## ROLLBACK de código

Usar la ruta exacta `ROLLBACK_BACKUP` devuelta por APPLY:

```bash
python3 "$PUBLICADOR" --site "$SITIO" --backup-dir "$RESPALDO_EXACTO" \
  --rollback --confirm REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE
```

Bloquea si alguien personalizó archivos después de publicar. Revierte código; conserva actividades y movimientos ya registrados. No modifica respaldos de otros módulos.

## Prueba funcional después de publicar

Con asesor 22 y prospectos sintéticos: programar una acción en Contactados, verificar movimiento inmediato a Seguimiento y auditoría; repetir desde Potenciales. Comprobar que programar en Nuevos conserva Nuevos. Reprogramar y completar en Seguimiento: cambia la cita sin duplicar prospectos ni regresar de etapa. Iniciar inscripción desde Seguimiento con requisitos completos y verificar Proceso de Inscripción. Una nota sin fecha y una cita de un trámite iniciado conservan su etapa.

## Compatibilidad con telefonía personalizada de Hostinger

Se revisó la variante que omite escribir `embudo_asignacion_id` en `telefonia_ventas_resultados` y obtiene `resultadoId` únicamente cuando hay detalle. Su SHA-256 exacto es `a264542c8bfbd2ec362586f8ecdda561b029501d1a165d730e2e2c58778a55d3` (blob Git `d0cc4cfd94de94363343906d2ce3df2cad135211`). El puente posterior al commit conserva la asignación explícita y los mismos parámetros; la programación V3 usa `prospecto_embudo_llamadas` y actividades, sin depender de esa columna opcional.

El comprobador admite únicamente la base original y esta variante exacta, verificadas antes de CHECK/APPLY/ROLLBACK. El servicio de telefonía permanece fuera de los archivos publicados. Las pruebas reproducen el diff y verifican que la variante permanece byte por byte tras aplicar, revertir y simular un fallo parcial; cualquier otro cambio sigue bloqueado.
