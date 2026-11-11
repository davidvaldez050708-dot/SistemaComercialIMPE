# Gestión comercial IMPE — conciliación de etapas antiguas (11-nov-2026)

## Evidencia de la instalación real

El usuario ejecutó el instalador protegido de subetapas en Hostinger:
- `prospecto_embudo_subetapas`: **creada, 0 filas**.
- `prospecto_embudo_subetapas_historial`: **creada, 0 filas**.
- Totales `INTERESADO: 1`, `SEGUIMIENTO: 1`, intactos en el post-check.
- Respaldo privado de **esquema** (no de datos) confirmado. Ni resultados telefónicos ni etapas previas fueron modificados.
- El motor V3 todavía NO está activo y la etapa `INTERESADO` sigue utilizándose en producción.

## Diagnóstico seguro, sin reclasificar registros

`tools/ventas_conciliar_etapas_legacy_solo_lectura_v1.php` se ejecuta sólo en CLI, desde directorio privado separado del sitio, usando `IMPE_SITE_ROOT` y `--check`.

La consulta utiliza una conexión MySQL **no persistente**, transacción de **solo lectura** y únicamente código de etapa y banderas de evidencia, **sin nombres, teléfonos, observaciones, IDs de asignación, nombres de programa ni referencias PBX**.

Para los casos vigentes en `INTERESADO`, `SEGUIMIENTO` y `PAGO_CONFIRMADO` devuelve:
- Total de llamadas **ya vinculadas** a la asignación.
- Si existe un programa educativo codificado.
- Si alguna llamada vinculada al mismo usuario/extensión/PBX guardó resultado `PROSPECTO_POTENCIAL` o lo incluye en las **selecciones V4**.
- Si existe próxima acción comercial en la agenda.
- Si una llamada vinculada **declaró** `INSCRITO` (nunca equivalencia automática con módulo Inscritos).
- Requisitos/documentos: **NO DETERMINABLE**, ya que el diagnóstico no contiene una fuente institucional de validación documental.

Para evitar divulgar datos personales en logs, cada caso se enumera como `CASO 1`, `CASO 2`, sin imprimir ID, teléfono, nombre ni fecha de llamada. En el CRM el operador autorizado puede abrir la ficha correspondiente desde su etapa actual.

## Decisiones pendientes para el único INTERESADO

- Si existe una selección `PROSPECTO_POTENCIAL`, solo se acredita que **el asesor la declaró en una llamada**. La calificación comercial exige comprobar con el asesor intención de inscripción, programa/requisitos y documentación relevante.
- Si NO hay esa selección, **no promover a Potenciales** por la etapa anterior. Se requiere revisión humana de la ficha.
- El prospecto en `SEGUIMIENTO` debe mantener su etapa vigente y próxima acción, sin degradarlo.
- No se actualizará `prospecto_embudo_etapas` ni se creará una subetapa por ejecutar este diagnóstico.

## Próxima fase después del resultado

Diseñar migración **aditiva y con versiones compatibles** de los códigos `INTERESADO` / `PAGO_CONFIRMADO` hacia `POTENCIAL` / `PROCESO_INSCRIPCION`, asegurando que el tablero, el arrastre, los reportes y el puente PBX se desplieguen de forma sincronizada. **No activar por partes la regla de recuperación al tercer intento**, porque el puente productivo actual sigue moviendo a Recuperación desde el primero.

Pruebas: `tests/ventas_conciliar_etapas_legacy_solo_lectura_v1_db.php`, ejecutadas sobre bases desechables MySQL 8.0 y MariaDB 10.11.

**No hay escrituras, triggers, cron ni procesos automáticos en esta entrega.**
