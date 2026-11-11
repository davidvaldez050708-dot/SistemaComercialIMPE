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
