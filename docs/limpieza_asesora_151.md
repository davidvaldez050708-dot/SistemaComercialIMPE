# Reinicio de pruebas de Yazmin Hurtado, extensión 151

Herramienta CLI de base de datos con objetivo fijo: usuario 15, rol Asesor de Ventas, nombre Yazmin Hurtado y asignación activa única Zadarma 151. Cualquier diferencia bloquea la operación. No admite parámetros para ampliar el alcance.

## Alcance

Retira los registros de llamadas de esa cuenta y extensión, resultados, detalles y selecciones de resultados, notas adicionales vinculadas, evidencias en caché, capturas de prospecto, intentos de marcación y devolución, borradores y notificaciones telefónicas atribuibles. Retira los metadatos de transferencias asociados a la cuenta/extensión objetivo. Los hijos de resultados se respaldan y borran antes que sus padres.

Conserva íntegros los eventos PBX originales, los resultados de Recepción y las llamadas de las demás cuentas. Añade exclusiones sólo para la pareja PBX/extensión 151 usando las tablas y protecciones ya instaladas en el CRM; no cambia código ni la estructura de la base de datos. No consulta la API de Zadarma ni toca sus grabaciones. Mantiene contactos y citas independientes de la agenda; sólo retira una programación en contacto cuando coincide exactamente con el recordatorio pendiente de un resultado que se va a borrar.

## Ejecución

La asesora debe terminar sus llamadas y cerrar sus pestañas telefónicas antes de generar el plan. `--check` sólo consulta datos y guarda el plan/respaldo privado fuera de `public_html`, con permisos restrictivos. El resumen muestra conteos, sin teléfonos ni notas. `--apply PLAN` comprueba los hashes de las protecciones existentes, la identidad, el corte, las claves primarias y el contenido de cada fila, antes y dentro de la transacción. Si cambian los registros se cancela y debe generarse otro plan.

No usa TRUNCATE, no desactiva claves foráneas ni reinicia AUTO_INCREMENT. Exige InnoDB, rechaza dependencias no incluidas y disparadores con efectos desconocidos. Bloquea una llamada reciente sin cierre o una marcación recién creada sin referencia. No atribuye una llamada por semejanza de teléfono.

La repetición del mismo `--apply PLAN` es idempotente y conserva llamadas nuevas. `--restore PLAN` recupera los datos respaldados en orden inverso, sin reemplazar claves ocupadas por datos posteriores. Las exclusiones previas de otras limpiezas permanecen intactas.

## Validación

`tests/limpieza_asesora_151_db.py` usa MariaDB local y datos sintéticos, con el servicio de llamadas exacto identificado en Hostinger. Prueba el CLI real, respaldo, detección de cambios y versiones desconocidas, resultados dobles y notas, dependencias, ceros tras la limpieza, conservación de Recepción y otras asesoras, agenda personal, exclusiones por tramo, sincronización y webhook tardío, registro de llamadas nuevas, reaplicación y restauración. No accede al sitio real ni modifica archivos de producción.
