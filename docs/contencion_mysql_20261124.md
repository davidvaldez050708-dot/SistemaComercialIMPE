# Contención temporal ante revocación automática de SELECT

Hostinger informó protección por volumen sostenido de consultas y denegaciones
SELECT, sin saturación de CPU/memoria y sin pérdida de datos. La API confirma una
tarea programada cada minuto: `tools/cron_zoho_leads.php`. La configuración por sí
sola no prueba que esa tarea sea la causa. El usuario confirmó después que Hostinger
eliminó la tarea; la versión web v2 no modifica su archivo ni la programación.

El paquete instala una guarda antes de iniciar sesión/autorización/conectar MySQL
en index.php y avisos_llamadas.php.
Una bandera privada con caducidad de 60 minutos pausa:

- Recordatorios y notificaciones automáticas, resumen de verificaciones diarias.
- Consulta de mensajes de WhatsApp y avisos de llamadas.
- POLL de disponibilidad sin transferencia concreta en curso (id=0).

La respuesta temporal HTTP 503 incluye JSON y Retry-After:120. Los navegadores ya
abiertos pueden seguir enviando HTTP, pero estas rutas no llegan a sesión, permisos
ni SQL. No cambia privilegios, conexiones, datos ni configuración de telefonía.
No intercepta login, páginas, resultados, marcación/cierre, disponibilidad con ID
concreto/acciones explícitas, WhatsApp saliente ni webhooks. Durante la pausa no
se actualizan automáticamente avisos/chats ni se reciben nuevas alertas de
disponibilidad por POLL. No garantiza funcionamiento contra MySQL mientras los
privilegios sigan restringidos por Hostinger.

`publicar_contencion_mysql.py --apply` respalda y valida los tres archivos,
activa la bandera privada y puede repetirse para renovar 60 minutos. `--reanudar`
elimina sólo la bandera; no consulta SQL. No usar para reactivar toda la carga
mientras siga la protección. La pausa caduca por sí sola; es contención, no la
optimización permanente del volumen de consultas.

Validación: matriz PHP sin conexiones MySQL para rutas pausadas/excluidas,
caducidad, reanudación, idempotencia y respaldo. Sintaxis y coincidencia de los
bloques sobre las lecturas instaladas de index.php (764 líneas), avisos (54) y
sus encabezados. La guarda respeta declare(strict_types=1) antes del require.
El instalador usa Python compatible con 3.6 y no invoca GRANT/REVOKE ni reinicios.
