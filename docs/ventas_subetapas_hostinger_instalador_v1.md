# Gestión comercial — Instalación protegida de subetapas (sólo esquema)

## Estado y evidencia de Hostinger

La inspección CLI aportada por el operador el **11 de noviembre de 2026** indicó:

- Estructuras compatibles: `prospecto_asignaciones`, `prospecto_embudo_etapas`, `prospecto_embudo_llamadas`, `telefonia_ventas_llamadas`, `telefonia_ventas_resultados`, `telefonia_ventas_resultado_detalles`, `telefonia_ventas_resultado_selecciones` y `prospecto_asesores_horarios`.
- `INTERESADO: 1`, `SEGUIMIENTO: 1`, asignaciones vigentes sin etapa explícita: **0**.
- Las tablas `prospecto_embudo_subetapas` y `prospecto_embudo_subetapas_historial` **no existían**.

## Alcance de este paquete

El comando de instalación preparado `tools/ventas_instalar_subetapas_hostinger_v1.php` **solo** ejecuta las dos sentencias `CREATE TABLE IF NOT EXISTS` del archivo exacto `database/migrations/2026_11_11_ventas_subetapas_comerciales_propuesta.sql`. El contenido es aceptado exclusivamente si coincide con el hash Git SHA-1 del blob ya revisado.

1. `--check --site RUTA --sql RUTA` es solo lectura. Comprueba el esquema mínimo y los tipos de PK/FK y muestra totales por etapa sin datos identificables.
2. `--install --site RUTA --sql RUTA --backup-root RUTA --confirm INSTALAR_SOLO_SUBETAPAS_IMPE_V1` exige autorización explícita. Antes de DDL crea un manifiesto privado `0700/0600` con el esquema original de dos tablas base, los totales previos y el hash de la migración. **No es un respaldo completo de toda la base de datos**: no se modifican filas anteriores. Se aconseja mantener además los respaldos habituales del proveedor.
3. Si ambas tablas ya existen y su definición es compatible, se limita a verificar; repetir el comando no debe generar DDL nuevo.
4. Si existe solamente una, si hay una estructura personalizada/incompatible o si falla el respaldo, **se bloquea**. No elimina ni reescribe tablas ajenas.
5. En MySQL/MariaDB el DDL se confirma por separado. Si falla la segunda creación, podría quedar la primera instalada; **no se debe reintentar a ciegas ni fingir una reversión transaccional**. El programa imprime bloqueo, no ejecuta DROP. Una restauración física solo podrá planificarse tras comprobar que las nuevas tablas siguen vacías y sin dependencias.
6. No instala permisos, triggers, vistas, PHP del embudo, telefonía, WebRTC, cron, Meta, remarketing ni tareas automáticas.

## Conciliación pendiente de los dos prospectos existentes

- **INTERESADO (1)**: no actualizar automáticamente a `POTENCIAL`. Hace falta validar intención real, programa y documentación/requisitos; podría corresponder a `CONTACTADO` con subetapa `PENDIENTE_CALIFICACION`, o `POTENCIAL` si está confirmada la elegibilidad. Ninguna regla de pruebas puede decidirlo sin datos de negocio.
- **SEGUIMIENTO (1)**: conservar la etapa por ahora; la futura interfaz debe mostrar su tarea, fecha y razón, sin obligarlo a retroceder.
- **0 asignaciones sin etapa explícita**: no requiere backfill de Nuevos en este momento.
- Las migraciones de etapa y subetapa **no están incluidas** en este instalador. Ambos prospectos permanecen visibles en el tablero actual.

## Secuencia siguiente, separada

1. Comprobar el resultado del instalador en Hostinger y que sigan los registros `INTERESADO: 1` y `SEGUIMIENTO: 1` u otros que hayan evolucionado legítimamente desde la auditoría.
2. Auditar la calificación del único `INTERESADO`, conservando el resultado PBX y la bitácora que lo originó.
3. Diseñar una transición controlada en el backend para las cinco etapas nuevas, el contador 1/3 a 3/3 por jornada y las subetapas; implementar en el **mismo despliegue lógico** el reemplazo de la regla antigua de Recuperación al primer intento.
4. Adaptar el formulario telefónico V4 solo en el contexto de Gestión comercial y la tarjeta; mantener inalterado el registro de llamadas fuera del embudo y los datos históricos.
5. Probar con base desechable, escenarios de seguridad y Hostinger personalizado; no activar remarketing sin elegibilidad, permiso y revisión.

## Pruebas automáticas

`tests/ventas_instalar_subetapas_hostinger_v1_db.php` crea una base nueva y desechable, simula los conteos `INTERESADO:1` y `SEGUIMIENTO:1`, verifica `--check`, bloqueo de aplicación sin confirmación, fuente SQL adulterada, instalación idempotente, preservación exacta de filas y tipos y bloqueo de tablas parciales o personalizadas. GitHub Actions la ejecuta por separado en **MySQL 8.0 y MariaDB 10.11**, además de las regresiones del embudo.

**La preparación en GitHub NO implica instalación en Hostinger.**
