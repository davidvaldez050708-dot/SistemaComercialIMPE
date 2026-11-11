# Revisión adicional de carga SQL y respuestas parciales (no publicable)

Base: `dd214aec83273bfc39f4b699da2d57b41f22d6fb` de `codex/mysql-auditoria-capacidad`. Se trabajó en una rama de auditoría separada. **No cambiar Hostinger ni desbloquear el publicador.**

## Causa y alcance de la intervención

Hostinger atribuyó el 403 del 11-11-2026 a protección automática por carga MySQL sostenida. El umbral exacto es desconocido. La rama base ya unificó dos SELECT de detección de disponibilidad en uno, hizo opcional el DDL de la campana y propagó parte de sus errores.

Esta intervención se limita a:

1. No espaciar el `POLL` de disponibilidad de 3 s ni los chequeos de llamadas/transferencias.
2. Compartir por **sesión PHP autenticada** el instante de última escritura exitosa de presencia. No volver a escribir presencia antes de seis segundos si el estado sigue igual. Escribir en el primer sondeo, tras cambiar ocupado/libre y después de la acción explícita `PRESENCIA`. Un error del sondeo **no confirma** el heartbeat, por lo que se reintenta la actualización en el siguiente sondeo normal. Si no hay sesión PHP activa, se conserva el comportamiento previo. La ventana de vigencia telefónica permanece en 15 s.
3. Distinguir fallos de la campana administrativa de una consulta vacía: el controlador emite 503 para errores temporales de MySQL y 500 para otros errores. No se expone texto de excepciones en la respuesta.

## Estimaciones teóricas, no mediciones de Hostinger

Con un solo documento de telefonía activo por usuario, periodo estable de 3 s y sin pausas:

| 30 usuarios | Original | Propuesta |
| --- | ---: | ---: |
| Solicitudes de sondeo / hora | 36.000 | 36.000 (sin cambios) |
| Escrituras de presencia por sondeo | 1 | Aproximadamente 1 cada 6 s |
| Escrituras de presencia / hora | 36.000 | Aproximadamente 18.000 |

Los cálculos NO incluyen expiraciones, permisos, historial, consultas entrantes, devoluciones, PHP session I/O ni escrituras derivadas de cambios de estado. Las pestañas del mismo usuario comparten el marcador de sesión para las escrituras de presencia, pero pueden seguir generando **solicitudes y lecturas duplicadas**. En condiciones de latencia, suspensiones del navegador o fallos MySQL, el número real de escrituras difiere.

## Limitaciones y pruebas pendientes

- El test `tests/mysql_presencia_ritmo_smoke.php` comprueba la decisión de escribir sin DB ni cambios de sesión real.
- `tests/mysql_sondeo_consultas.php` añade una comprobación de INSERT omitido en una MariaDB **desechable**. No se ha ejecutado esta prueba contra MariaDB en la sesión de auditoría.
- No se dispone en este entorno de una réplica PHP 8.2 + LiteSpeed/LSAPI + MariaDB con el esquema de 128 tablas: **no se puede afirmar que pasaron una hora de carga, diez rotaciones, ni que se corrigió el incremento 46→75 de conexiones persistentes**.
- Probar también los bloqueos de sesión entre pestañas y el tiempo de respuesta de la telefonía bajo carga. Si las escrituras salen demasiado espaciadas y la presencia caduca, revertir esta modificación.
- El error parcial de otros roles, los ciclos de sincronización de la campana y el crecimiento de los pools persistentes requieren auditoría y pruebas adicionales antes de aplicar nada.
- Hostinger debe informar el umbral o métricas que activaron la protección antiabuso del 11-11-2026.
