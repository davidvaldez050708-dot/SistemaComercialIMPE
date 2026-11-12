# Auditoría: reaprovisionamiento de permisos durante sondeos telefónicos

Base auditada: `aea878f4e93acd81f8671871aba2ee71eef4db6e`. No se ha modificado Hostinger, `main` ni el publicador.

## Hallazgo demostrado en código

- `index.php` revalida el usuario, su rol y sus permisos en una conexión `Database::compartida()` antes de construir `TelefoniaController`.
- El constructor de `TelefoniaController` abría dos conexiones propias por petición mediante `new RolModel()` y `new TelefoniaExtensionService()`.
- Además llamaba `RolModel::inicializarPermisosSistema()` para cada petición, lo que prepara un `INSERT ... ON DUPLICATE KEY UPDATE` y lo ejecuta **una vez por cada permiso** del catálogo. La versión inspeccionada contiene **81 códigos de permiso**, además de consultas y otras operaciones de inicialización.
- El sondeo `POST telefonia/disponibilidad` pasa por ese constructor y después pide la conexión compartida, multiplicando innecesariamente sesiones y sentencias.
- El login ya invoca `prepararCatalogoParaAutenticacion()`, que sincroniza el catálogo si faltan códigos; la gestión de roles tiene su propio circuito.

## Modificación mínima de prueba

- El constructor de telefonía deja de crear `RolModel` e inicializar permisos.
- Instancia `TelefoniaExtensionService(Database::compartida())`, reutilizando el enlace autenticado por el router.
- No cambia las consultas de autorización del router, telefonía, transferencia, llamadas ni resultados.
- No se cambian variables de admisión, conexiones persistentes, tablas ni datos.

**Estimación superior de operaciones evitadas** (suponiendo 30 sesiones cada 3 s): 10 peticiones/s × 81 UPSERT por instancia ≈ 810 sentencias UPSERT/s potenciales; además de otros SQL de inicialización. No es una medición y sólo aplica si todas las solicitudes pasan por ese constructor y el catálogo está presente. En un esquema real las operaciones repetidas pueden hacer más trabajo que el propio `POLL`. La protección antiabuso no publica un umbral verificable.

## Puertas antes de integrar

- Probar la revisión en PHP 8.2 con las 128 tablas de esquema de prueba, jamás producción.
- Confirmar creación de sesión, revocación y cambios de rol en el mismo instante que antes.
- Comprobar que no se resembrarán permisos al consultar un módulo si una instalación no se aprovisionó: preparación en autenticación y en procedimientos administrativos.
- Ejecutar pruebas de 10 y 30 usuarios concurrentes y al menos una hora en réplica LSAPI con rotaciones, telefonía real simulada y webhooks.
- Medir SQL por endpoint, conexiones Sleep por PID/credencial y aperturas físicas/s. La persistencia y el crecimiento 46 → 75 siguen pendientes.
- Mantener el publicador bloqueado hasta pasar estas pruebas.

La prueba estática `tests/telefonia_constructor_sin_sql_mutante_smoke.php` comprueba la presencia de los invariantes de código, **no** sustituye validación funcional ni de carga.

## Resultado integrado en Windows

El ensayo `tests/mysql_telefonia_constructor_http_local.py` envió POST reales a `index.php?controller=telefonia&action=disponibilidad` en PHP 8.2.12. Creó un esquema sintético de nombre aleatorio en MariaDB loopback y usó la misma sesión inicial y el primer heartbeat para cada variante. Se midieron los contadores globales de MariaDB sin tráfico del escenario en paralelo y se descontó la conexión de la segunda lectura de contadores.

| Medida por POST en frío | aea878f | Candidato |
| --- | ---: | ---: |
| SELECT | 16 | 4 |
| INSERT/UPSERT | 82 | 1 |
| UPDATE | 4 | 1 |
| DELETE | 0 | 0 |
| Total de esas sentencias | 102 | 6 |
| Conexiones físicas nuevas | 3 | 1 |

Son contadores de sentencias, no filas modificadas. El ensayo de petición usa el servidor de desarrollo de PHP; la rotación usa FastCGI. Para reproducir, usar MariaDB de laboratorio en loopback, establecer IMPE_TEST_MYSQL_PORT e IMPE_LAB_CONFIRM=SYNTHETIC_LOOPBACK. IMPE_LAB_ROTACIONES=1 habilita 30 procesos FastCGI y diez rondas. El script usa root local sin contraseña para crear y retirar sólo su esquema sintético.

Con 30 procesos FastCGI y cookies rotadas, el candidato completó 300 respuestas HTTP 200 con ok=true. La ocupación tras cada una de diez rondas fue 30 conexiones. La base falló en la primera ronda: 25 HTTP 500 en el UPSERT de permisos y 5 HTTP 200 en la ejecución registrada, con 65 conexiones al finalizar la ronda. No se usó el tope 75 de Hostinger en esta réplica ni se midió el pico intrarronda. El resultado no demuestra estabilidad del CRM completo ni reproduce exactamente la topología de Hostinger.

En el candidato, revocar el permiso de recibir o desactivar el usuario devolvió 403 en la petición siguiente con la misma cookie. Una tabla temporalmente ausente devolvió 500 con ok=false, y la petición volvió a 200 al restaurarla. La creación del catálogo permanece en autenticación y Roles. Pasaron las comprobaciones de estado saliente, marcador, transferencias seguras y orden de la campana. Se actualizó una aserción anterior que esperaba una consulta inline para comprobar en su lugar la revalidación delegada al servicio de sesión. Esas comprobaciones son estáticas o unitarias, no llamadas ni webhooks reales.

Quedan pendientes LSAPI, el ensayo de una hora, la matriz con el esquema completo instalado y el tráfico combinado de recepción, webhooks, transferencias, Inicio y campana. La protección 403 de Hostinger no se simuló. El publicador permanece bloqueado.
