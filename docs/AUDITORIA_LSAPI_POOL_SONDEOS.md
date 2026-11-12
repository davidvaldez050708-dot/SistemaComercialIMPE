# Resistencia LSAPI — revisión aislada de conexiones por petición

Base: `aa2a3b98acaf7957f8401f41c097c7b2dc14bb81` de `codex/mysql-auditoria-capacidad`. Rama: `audit/mysql-resistencia-pool-20261111`.
**No apta para publicar. No se modificó Hostinger, main ni el publicador.**

## Evidencia de GitHub Actions
Ejecución: https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/43130304880
- 10 y 30 usuarios: humos completos.
- La versión base falló por una respuesta HTTP 500 de `telefonia/disponibilidad`.
- El candidato falló a los ~296 segundos, antes de cualquier rotación de usuarios, porque alcanzó una condición de saturación; el artefacto de resistencia registra un pico de **75 conexiones MySQL simultáneas**, 75 Sleep. Corresponde al laboratorio; no reproduce el desafío antiabuso de Hostinger.
- Las trazas `[impe_mysql_traza]` contienen 370 aperturas nuevas independientes en `home.index` y 1071 reutilizaciones independientes del sondeo `api.transferencia_operacion`, además de otras aperturas compartidas y reciclaje de procesos. Esto es evidencia de actividad, **no prueba causal de que estas rutas expliquen todo el pico**.
- La carga corta siguió teniendo 38 conexiones como pico en humo; para autorizar instalación se requiere nueva resistencia y medir la meseta de la carga prolongada.

## Ajuste propuesto

1. `HomeController::index` / Marketing: pasar `Database::compartida()` a `ConvocatoriaModel` en vez de abrir un segundo pool persistente para la misma petición. No modificar consultas de dashboard ni caducidad.
2. `prueba_telefonia/api/transferencia_operacion.php`: usar `Database::compartida()` sólo para GET; las operaciones POST mantienen `TelefoniaTransferenciaOperacionService` sin parámetro, por lo que conservan su conexión independiente para las transacciones operativas. Aunque GET puede reconciliar y escribir, la conexión es local a la petición y ese endpoint no la usa para otro servicio simultáneo.
3. No cambiar TTL de presencia, intervalos, permisos, php.ini, límites de admisión, semántica de resultados o transacciones en servicios.

## Limitaciones

`tests/mysql_pool_sondeos_conexiones_smoke.php` es una prueba estática de contrato que no valida conexiones ni resultados DB. No hay medición A/B ni una ejecución LSAPI para este ajuste. La evidencia de múltiples PIDs en la réplica sugiere estudiar la vida útil de LSPHP y no atribuir automáticamente toda la saturación a Marketing/transferencias.

Antes de integrar: comprobar GET de transferencias con evento pendiente, TIMBRANDO, ENTREGADA, DEVUELTA, SIN_RESPUESTA y escritura de `sdk_consulta`; autorización; POST idempotente; dashboard Marketing y sincronización de convocatorias; 30 usuarios, 10 rotaciones, reciclaje, una hora, límite 75 y 20/s, estabilidad y ausencia de HTTP 200 falsamente vacíos. No activar el publicador hasta cumplir los requisitos.
