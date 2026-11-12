# Gestión Comercial V3 · Cierre operativo integrado

**Estado:** candidato integrado en PR #266 (**BORRADOR, NO PUBLICAR**). No se modifica Hostinger ni se ejecuta SQL en producción desde el PR.

## Qué hace ahora el candidato

1. **Ingreso y distribución:** conserva el importador Meta, la confirmación de lotes, el cursor diario y la asignación por horario de Aliados. No reconfigura los interruptores ni CRON existentes.
2. **Embudo:** conserva las cinco columnas, calificación de conversación, Potenciales, tareas, historiales y etapas antiguas en modo lectura. Toda decisión comercial excepcional es manual y auditable.
3. **Recuperación:** acceso al teléfono persistente, a Mis llamadas y a la ficha del prospecto. No reactivación automática; se exige conversación acreditada posterior al ingreso.
4. **Descartados:** exige resultado PBX que justifique No interesado / Número inexistente; para **No contactar** exige conversación PBX de la asignación, motivo de 8–125 caracteres, dos confirmaciones y movimiento auditable. Nunca equipara rechazo comercial con exclusión de contacto.
5. **No contactar, seguridad de salida:** servicio compartido por el inicio de marcación comercial, el checkpoint común del host Zadarma y la salida de WhatsApp Cloud API. La API de teléfono devuelve 409 si la restricción está activa. WebRTC **espera confirmación** de autorización antes de iniciar. Se reutilizan conexiones MySQL en el checkpoint y en los envíos gestionados por WhatsAppController.
6. **Inscripción con evidencia:** Potencial solo puede pasar a Proceso de Inscripción al vincular una solicitud REAL en `formulario_inscripciones` con móvil y correo coincidentes, único resultado, propietario y CSRF. La transacción registra vínculo, etapa, subetapa e historial; no acredita pago ni matrícula. La ficha muestra la evidencia institucional.

## Seguridad y compatibilidad de despliegue

- Release único: `tools/publicar_gestion_comercial_v3_integral_hostinger.py`. 20 archivos exactos; CHECK → APPLY → POST-CHECK → ROLLBACK, con copia fuera de `public_html`. Cualquier modificación en producción que no coincida con la versión anterior esperada bloquea la operación.
- Migración aditiva independiente: `tools/gestion_comercial_v3_inscripcion_schema_hostinger.php` + `database/migrations/2026_11_12_ventas_inscripcion_v3_evidencia.sql`. CHECK no escribe; APPLY exige confirmación y crea solo **una nueva tabla vacía**, sin editar datos existentes. Nunca ejecutar la migración sin respaldar previamente el esquema. Un rollback del código **no borra la nueva tabla**, para evitar pérdida de evidencia.
- Auditoría de operación: `tools/gestion_comercial_v3_auditar_distribucion_hostinger.php`; transacción READ ONLY, resultados agregados de Meta/aliados, jornada, cupo de 80 y pendientes. No imprime personas ni teléfonos.
- Los archivos anteriores de Potenciales/Seguimiento/Historial no se sustituyen si fueron personalizados; quedan protegidas dependencias fuera de alcance.
- **No ejecutar la publicación si CI está pendiente, no hay respaldo, falta la tabla de evidencia, el CHECK detecta cambios o la telefonía real no ha pasado smoke tests.**

## Pruebas que debe cubrir el cierre

| Área | Comprobación | Resultado aún requerido |
|---|---|---|
| Navegador | Marcador autorizado, prohibición 409, falla de red, resultado y ficha | GitHub Actions verde |
| Navegador | Formulario real, ambiguo, inexistente, vinculación, llamada activa | GitHub Actions verde |
| MySQL/MariaDB | Inscripción: identidad, permisos, concurrencia, rollback | GitHub Actions verde |
| MySQL/MariaDB | No contactar: variantes 52/521, histórica, terceros | GitHub Actions verde |
| MySQL/MariaDB | Recuperación/descarte/reactivación y agenda | GitHub Actions verde |
| Publicador | 20 hashes, personalización bloqueada, backup y rollback | GitHub Actions verde |
| Hostinger | CHECK de integridad + metadata + versión exacta | Pendiente |
| Hostinger | Distribución real de Meta/Aliados y cron nocturno | Pendiente, solo lectura |
| Hostinger | Prueba funcional de interfaz y llamadas controladas | Pendiente |

**Importante:** la existencia de código y la salida del publicador no demuestran funcionamiento productivo completo. El estado es candidato en verificación, no “todo operativo” hasta concluir estas filas. Si GitHub Actions está en cola, no suponer éxito.

## Limitaciones intencionadas

- Este paquete **no** introduce un SMS nuevo; cualquier canal SMS externo debe ser auditado por separado. Los envíos directos que esquiven WhatsAppCloudApiService o el host Zadarma no quedan cubiertos por esta verificación.
- Una solicitud por otro canal sin evidencia PBX todavía requiere un procedimiento institucional seguro para incorporarse a la lista No contactar. No inventar conversaciones.
- Meta: la auditoría de tablas no puede comprobar si el cron de mañana está programado en hPanel; hay que revisar esa configuración en Hostinger.
- El acceso a registros históricos o duplicados con distinta identidad comercial exige conciliación; no se reasignan automáticamente.
- No se garantiza que los dos prospectos actuales de la auditoría representen una muestra suficiente del flujo real. No realizar llamadas a contactos reales de prueba no autorizados.

## Qué no hacer

No fusionar PRs apilados directamente sobre `main`, no ejecutar el SQL desde phpMyAdmin, no modificar el resultado telefónico para simular conversaciones, no reactivar el piloto PBX retirado y no abrir conexiones independientes por cada webhook si se puede reutilizar una ya validada.
