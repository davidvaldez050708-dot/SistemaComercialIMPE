# Meta Ads — Cola escalable y verificación de duplicados (Fase 1B.1)

## Distinción fundamental
**Importaciones múltiples**: Tania puede importar 1, 2, 10, 30 o más exportaciones de Meta, una por una, con distintos `form_id` y programas confirmados. Cada archivo puede contener hasta 5,000 filas y ocupar hasta 8 MB. No hay un límite comercial de dos archivos por noche. El importador exige completar los lotes internos de 75 y solo declara listo un archivo cuando confirma el lote completo; los reintentos parciales no entran al reparto.

**Motor**: el límite de 2,000 elementos sigue vigente **por página**, no para toda la cola. Un cursor guarda el último ID de origen inspeccionado y continúa en la página siguiente, sin OFFSET ni volver a revisar siempre los primeros pendientes. Los registros sin programa, sin asesor compatible o sin cupo permanecen pendientes, no se asignan forzosamente. Un ciclo terminado se revisa nuevamente en la jornada siguiente para considerar nuevas disponibilidades. Si un ciclo quedó incompleto, continúa aunque cambie de fecha, para evitar inanición. Los leads posteriores al corte de una importación completada durante el barrido pueden ser procesados en la siguiente jornada; por eso Tania debe finalizar los archivos antes del reparto nocturno.

**CLI por páginas**: `php app/services/ProspectoDistribucionCli.php --meta --preview` lee solamente la siguiente página sin escribir cursor ni asignaciones. La opción autorizada `--meta --apply --confirmar-produccion --limite=2000 --rondas=20 --max-segundos=90` procesa sucesivas páginas hasta terminar o alcanzar la duración/rondas. Al detenerse conserva el cursor y otro proceso reanuda. Programar un cron recurrente solo después de habilitar el motor y validar horarios reales y cuotas en Hostinger; publicar código **no habilita cron**. Para estimaciones de toda la cola, la simulación de una sola página no constituye una predicción global de cupos.

**Seguridad**: MySQL `GET_LOCK` por corrida de aplicación, transacciones por prospecto, control de unicidad por asignación y consulta `NOT EXISTS` histórica. Un incidente entre un commit de asignación y la escritura del cursor no duplica, porque la siguiente ejecución volverá a comprobar asignaciones existentes.

**Migración aditiva**: `database/migrations/2026_11_07_prospectos_meta_cursor_escalable.sql` crea únicamente `prospecto_distribucion_meta_cursor` e inserta id=1 con cursor 0. No modifica prospectos ni activa motores. La migración se ejecuta con respaldo antes del despliegue del PHP del motor.

**Publicador**: `tools/publicar_prospectos_meta_escalable.py` y `tools/prospectos_meta_escalable_release.json`. Modifica exclusivamente `app/services/ProspectoDistribucionMotor.php` y `app/services/ProspectoDistribucionCli.php`; valida SHAs de versión base, ambos hashes de destino y cuatro dependencias; respalda fuera de public_html; --check no modifica; --restore exige que nadie haya alterado las versiones aplicadas. Reutiliza el publicador probado de la entrega anterior con paquete propio.

## Duplicados: qué significa «el mismo prospecto»
| Caso | Protección y comportamiento |
| --- | --- |
| Meta: mismo lead `id` en un segundo archivo | Ya registrado, no crea otra identidad; se cuenta como repetido. Se conserva el ID con ceros iniciales y se normaliza prefijo `l:`. |
| Meta: mismo `id` dos veces en el mismo archivo | Una única fila importable; contador de duplicados internos. |
| Meta: nombre o teléfono idéntico, pero ID de Meta diferente | Es otro evento; **no fusionar automáticamente** por teléfono ni correo. |
| Aliado: mismo enlace/aliado, correo, móvil, nacimiento, programa y contexto de convocatoria | Recupera `registro_id` anterior; la incorporación al almacén central también es idempotente por `IMPE_FORMULARIOS:REGISTRO:id`. |
| Aliados distintos, convocatoria distinta, programa distinto o cambió alguno de los datos de identificación | Son solicitudes diferentes según el contrato actual; pueden corresponder a la misma persona y requieren análisis humano antes de fusionar. |
| Meta y Aliados con el mismo teléfono | Dos procedencias/eventos independientes. No se unen automáticamente, ni se redistribuye un registro ya asignado. |

La unicidad `prospecto_origenes(sistema_origen,tipo_registro,identificador_origen)` garantiza el evento original. Aliados usa `formulario_aliado_procedencias(aliado_id,tipo,clave)` y devuelve el ID anterior en reenvíos idénticos. No cambiamos claves, formularios, telefonía, usuarios, grabaciones ni la agenda provisional.

## CI
- `tests/prospectos_duplicados_identidad_unit.php`: Meta IDs repetidos dentro y entre archivos, preservación de ceros y dos personas/eventos posibles con mismo teléfono; claves de Aliados y convocatoria.
- `tests/prospectos_motores_db.php`: dos importaciones originales, luego otras 28 importaciones ficticias para un total de 30. Recorre páginas de 25, mantiene pendientes de Inglés y asigna el último Bachillerato elegible; deja los interruptores apagados al iniciar y confirma que no se escribe en simulación.
- `tests/prospectos_meta_escalable_release_test.py`: paquete incremental de dos servicios, respaldo, restauración, idempotencia y conflictos de personalización.
- `.github/workflows/prospectos-meta-cola-escalable.yml`: suite PHP y MySQL descartable, sin conexiones ni datos de Hostinger.

**Estado del despliegue:** todo el desarrollo está en PR en borrador; no modificar base ni motor de producción hasta que CI completa, respaldo SQL, verificación de paquete y autorización de aplicación en Hostinger. Ambos motores permanecen desactivados en el sitio.
