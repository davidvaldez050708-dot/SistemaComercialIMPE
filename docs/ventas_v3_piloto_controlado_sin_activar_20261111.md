# Integración comercial V3 — piloto cerrado SIN ACTIVAR (11 nov 2026)

**Estado:** desarrollada en rama, pendiente de validar en CI y en copia aislada de Hostinger. **NO desplegada ni activada en producción**. Los asesores actuales siguen usando el teléfono V4, las etapas antiguas y los movimientos normales.

## Evidencia real previa

Hostinger cuenta con las dos tablas nuevas de subetapas, instaladas vacías con respaldo privado y comprobación posterior. La conciliación de lectura encontró:
- `INTERESADO: 1`; tres registros de llamadas vinculadas, resultado "Potencial" declarado, sin programa codificado ni requisitos acreditados. **No convertir automáticamente a Potenciales**. Tener tres llamadas históricas no demuestra tres intentos sin contacto.
- `SEGUIMIENTO: 1`; próxima acción existente. **Conservar etapa y agenda**.
- El puente productivo anterior mantiene la regla de Recuperación desde el primer resultado no contestado. No activar la política nueva antes de garantizar que el cambio de código, UX y movimientos esté completo.

## Cambios preparados en el código

### Bifurcación explícita en EmbudoComercialResultadoBridge

Después del guardado definitivo telefónico V4 y de verificar la llamada y el número del prospecto, el puente comercial crea la relación PBX en su transacción habitual. **Antes** de calcular el cambio de etapa antiguo, puede delegar al piloto cuando y solo cuando:
1. El servidor establezca exactamente `IMPE_VENTAS_V3_PILOTO=AUTORIZADO_PILOTO_V3_TRES_INTENTOS_IMPE_20261111`.
2. El servidor establezca `IMPE_VENTAS_V3_PILOTO_ASESORES` con una **lista cerrada de 1–20 IDs decimales**. Sin comodines y sin incluir a otro asesor por inferencia.
3. La sesión corresponda al mismo usuario autorizado (rol Asesor) y tenga permisos de Gestión comercial.

La aplicación **no lee banderas de GET, POST, JS ni cookies**. **Todos esos valores están ausentes por defecto:** no se incorpora el servicio V3 ni se ejecuta la rama nueva. No se incluyen instrucciones de activación para Hostinger porque todavía es un candidato y no está aprobada una prueba piloto real.

### Reglas conservadoras del candidato

- Los resultados PBX definitivos se guardan **antes** del embudo, igual que ahora; no se editan ni borran si el movimiento falla.
- Para una asignación `NUEVO`, los PBX válidos con no contacto cuentan `1/3` y `2/3` sin mover de etapa; sólo al `3/3` se propone Recuperación y revisión local del día posterior al evento original.
- Una conversación verificada de `NUEVO` o `RECUPERACION` puede mover a `CONTACTADO` sin inventar interés ni pago.
- `NO_INTERESADO` explícito puede mover a Descartados, con subetapa e historial y sin dejar tareas de contacto activas.
- `POTENCIAL` y `PROCESO_INSCRIPCION` nunca se degradan por ausencia de respuesta.
- Si se registró **cualquier conversación humana anterior** en la asignación —incluida una selección V4 de `PROSPECTO_POTENCIAL` aunque no estén revisados sus requisitos—, no se envía a Recuperación tras tres nuevas llamadas fallidas. Se marca la subetapa `REVISION_CONTACTO_PREVIO` para revisión.
- Una declaración telefónica `PROSPECTO_POTENCIAL` sin evidencias adicionales **no crea automáticamente POTENCIAL**. `INSCRITO` o pago no crean inscripciones ni verificaciones.
- El código antiguo `INTERESADO` o `PAGO_CONFIRMADO` queda en revisión y no se modifica automáticamente.
- `EmbudoComercialMovimientoService` rechaza temporalmente el arrastre manual **solo de la cuenta habilitada** para no poder evadir el contador; otros asesores no pierden el arrastre actual.
- Cuando el cambio es válido, escribe `prospecto_embudo_subetapas` e historial en la **misma transacción comercial** que el movimiento. Si algo falla, se revierte la transición comercial (no el resultado telefónico).
- El contador sigue siendo por **fecha local** de `telefonia_ventas_llamadas.fecha`, no por timestamp de registro del asesor ni por un número editable.

## Verificaciones automatizadas previstas

`tests/ventas_v3_piloto_controlado_db.php` usa bases nuevas y desechables. Reproduce:
- Bandera OFF / lista sin token / token incorrecto / comodines; otro asesor conservando el flujo anterior.
- Rechazo del arrastre antiguo para una cuenta en piloto.
- PBX 1/3, 2/3 y 3/3 con un solo movimiento a Recuperación, tres subetapas auditadas y fecha de revisión correcta.
- Repetición de PBX bloqueada.
- Potenciales con documentos listos que no se degradan.
- INTERESADO histórico sin ascenso inventado.
- Conversación real que cambia de Nuevos a Contactados.
- PBX con número incorrecto que no crea vínculo comercial y conserva el resultado telefónico ya guardado.
- Conversación previa que evita Recuperación aunque después existan tres intentos sin respuesta.
- Rechazo explícito a Descartados, sin citas pendientes.
- Ejecución independiente en MySQL 8 y MariaDB 10.11; regresiones de Kanban, movimientos y matriz.

**La existencia del workflow no sustituye el resultado de CI:** no declarar aprobado hasta comprobar el estado y la conclusión de sus jobs para el SHA definitivo.

## Bloqueos antes de activar el piloto

1. **PENDIENTE:** Ejecutar y aprobar el workflow de esta rama (GitHub Actions puede permanecer en cola).
2. **PENDIENTE:** Auditar compatibilidad de los archivos realmente personalizados en Hostinger, incluidos los SHAs de `EmbudoComercialResultadoBridge.php`, `EmbudoComercialMovimientoService.php`, el catálogo V4 y la vista Kanban. No pisar ningún archivo por copia directa.
3. **PENDIENTE:** Prueba aislada con usuario de pruebas que no trabaje prospectos reales, en copia de sitio/base datos y PBX simulada o autorizada. No bloquear manualmente a un asesor activo.
4. **PENDIENTE:** Integrar el horario de la jornada de cada asesor y ventanas de contacto del prospecto. El conteo actual por **día local** es un prototipo, no una comprobación de cumplimiento del turno real.
5. **PENDIENTE:** Resolver la calificación verificable de Potenciales (intención, programa y requisitos) y el criterio institucional para Proceso Inscripción. **Nunca dar de alta Inscritos desde una selección de teléfono.**
6. **PENDIENTE:** Reconciliar `INTERESADO` y `PAGO_CONFIRMADO` con el nuevo tablero, ficha, drag & drop, filtros y reportes. Una mezcla de códigos viejos/nuevos no puede dejar tarjetas ocultas.
7. **PENDIENTE:** Modal V4 contextual y tarjetas de intento/subetapa, sin duplicar el mecanismo de registro; y el control de manuales por la nueva matriz.
8. **PENDIENTE:** Validación de concurrencia y rotación de workers PHP/DB en Hostinger; preservar el control de conexiones y evitar nuevas persistentes.

## Reversión y seguridad

- La rama no necesita nuevas tablas: utiliza sólo las dos tablas ya instaladas.
- El diseño permite apagar el piloto eliminando la **configuración del servidor** sin reescribir historia, pero si un piloto ya movió tarjetas o generó subetapas, esas filas requieren análisis específico; apagarlo **no revierte datos anteriores**.
- No se deben ejecutar scripts de despliegue ni activar cron/remarketing para esta entrega.
- Antes de cualquier despliegue eventual: respaldo completo de BD (distinto al respaldo de esquema), manifest de hashes, verificación de personalizaciones, pruebas de post-deploy, prueba de reversión de archivos y monitoreo de errores 500/503, carga y sesiones.
- El modo sin activación no afecta rutas, JS, telefonía, transferencias ni los movimientos antiguos.

**Decisión actual recomendada:** mantener candidato OFF; terminar pruebas, compatibilidad y una UX de prueba para un asesor ficticio antes de considerar un piloto real.
