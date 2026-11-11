# Gestión comercial — segunda fase: evidencia PBX para tres intentos

**No publicar en Hostinger ni aplicar la migración todavía.** Se trata de componentes independientes, sólo conectados por un test a una MySQL desechable. Nada en producción invoca el lector.

## Objetivo y criterio de aceptación

Para un asesor, asignación y **día local de Ciudad de México**, contar únicamente intentos salientes sin conversación que tengan simultáneamente:

1. Fila en `prospecto_embudo_llamadas` para la asignación y asesor; registrada previamente por el puente que confirma usuario, PBX y número de destino.
2. Fila en `telefonia_ventas_llamadas` con **misma extensión y PBX exactos**, propietario correcto, dirección `Saliente` y `fecha` de la jornada.
3. Resultado definitivo en `telefonia_ventas_resultados` con la **misma identidad y código**.
4. Si el resultado es `COMUNICACION_INTERRUMPIDA`, detalle `contacto_antes_corte=NO`. Si sólo hubo envío de WhatsApp/SMS, únicamente V4 con resultado principal `ENVIO_*` acredita que respondió NO a la pregunta sobre conversación.
5. Asignación todavía vigente del mismo asesor. Evitar duplicados con llave `extension|pbx_call_id`; el índice único de la relación comercial también protege de repetición.

**La jornada de esta fase equivale a un día calendario en America/Mexico_City**, no al horario específico del asesor todavía. Antes de activar, cruzar los turnos aprobados en `prospecto_asesores_horarios`, los horarios de contacto del prospecto y manejar explícitamente fines de jornada/casos excepcionales.

La fecha que determina la jornada es la **fecha de la llamada**, no la creación de la relación comercial (`creado_at` UTC). Se confirmó que `ZadarmaWebhookEventStoreService::fechaMysql` convierte los eventos a `America/Mexico_City`.

## Entregables aislados

- `app/services/EmbudoComercialIntentosPbxRepository.php`: **sólo consultas SELECT**, sin importarlo desde controladores, bridge V4 ni UI. Método `resumenJornada(uid,asignacionId,fechaLocal)`; devuelve `total`, `pendientes` (de tres), `completo`.
- `database/migrations/2026_11_11_ventas_subetapas_comerciales_propuesta.sql`: dos tablas **nuevas, vacías** para subetapa y bitácora futura. La migración no introduce contador mutable ni toca filas de llamadas o etapas actuales. Preparación, NO ejecutada en Hostinger.
- `tests/embudo_intentos_pbx_db.php`: MySQL temporal, sin bases productivas. Comprueba 0/3 a 3/3, llamadas del día anterior, llamadas sin vincular/sin resultado, llamadas entrantes, propiedad, descartes, cortes con conversación y mensajes V4. Aplica la migración únicamente en su base temporal y luego la destruye.
- `.github/workflows/ventas-intentos-pbx-lectura-segura.yml`: PHP lint, regresiones del embudo vigente y MySQL 8 desechable.

## Importante: aún NO está hecho

- No se suman intentos en pantalla ni se cambia el formulario V4.
- No se envía a Recuperación, no se genera remarketing, no se bloquean llamadas ni se programa cron.
- La consulta **no concede acceso** a un usuario: no publicar endpoint ni usar uid enviado por navegador. La futura integración recuperará sesión autenticada y revalidará autorización/propiedad de la asignación.
- La regla de tres por jornada requiere aún el turno aprobado y el tratamiento de intentos incompletos. No simular tres llamadas al cierre ni usarlas para mover expedientes de Potenciales/Proceso Inscripción.
- No se ha conciliado `INTERESADO` ni `PAGO_CONFIRMADO` existentes con las nuevas etapas. No cambiar sus códigos automáticamente.
- La calificación de Potenciales y los trámites de inscripción requieren validaciones adicionales, sin confiar únicamente en declaraciones telefónicas.
- Antes de la eventual instalación de tablas: preflight del esquema Hostinger, respaldo SQL y autorización del responsable; migración aditiva, sin aplicar SQL desde web.

## Próxima tarea tras estas pruebas

Integrar un **servicio comercial transaccional y opt-in** que, después del commit definitivo del resultado PBX, consulte este lector, aplique la política validada sin ambigüedad, registre subetapa y bitácora, y preserve idempotencia. Mantener intacto el teléfono persistente y el formulario estándar fuera de Gestión comercial. La nueva interfaz mostraría 1/3, 2/3 y las tareas, sin duplicar un registro de llamada.
