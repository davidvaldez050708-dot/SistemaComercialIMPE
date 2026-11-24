# Recuperación mientras SELECT continúa revocado

La contención parcial quedó instalada y se verificó externamente: HTTP 503,
Retry-After:120 y pausa_automatica=true en reminder.pendientes. Una comprobación
única por SSH confirmó conexión nueva y SELECT 1 aceptados, pero SELECT sobre
permisos y telefonia_extensiones devuelve 1142. Hostinger confirmó que la protección
sigue activa. El usuario confirmó que ya no hay llamadas activas.

El modo RECUPERACION_SIN_LLAMADAS amplía la guarda a todas las solicitudes web
del CRM, incluyendo login, detalleVenta y las consultas de estado. Una línea al
principio de config/db_connection.php hace que también se intercepten accesos
directos a las API antes de la conexión. La inserción conserva declare(strict_types),
comentarios, código y valores instalados. No imprime ni exporta credenciales.
El respaldo de ese archivo queda fuera de public_html con permisos privados.

Las entradas exactas de webhooks Zadarma y WhatsApp permanecen excluidas para
conservar su procesamiento normal: esto no garantiza que puedan escribir en
MySQL mientras Hostinger mantenga la restricción. CLI queda excluido para una
comprobación puntual por SSH; no se programan comprobaciones repetidas. El cron
permanece eliminado por Hostinger. El equipo debe abstenerse de usar el CRM o
iniciar nuevas llamadas durante esta pausa.

La bandera privada caduca a los 60 minutos. --apply renueva la pausa; --reanudar
retira la bandera sin consultas SQL. No reanudar antes de que Hostinger confirme
la restauración efectiva. --restore recupera los dos archivos, rechazando cambios
posteriores. No hay GRANT, REVOKE, reinicios ni modificaciones de datos.

Pruebas: PHP HTTP local con configuración ficticia, sin MySQL real; rutas normales
interceptadas antes del negocio, webhooks/CLI excluidos, preservación exacta de
configuración con LF/CRLF y strict_types, publicación repetida, respaldo/restauración.
El código de conexión instalado no se pudo previsualizar por la protección de
credenciales del conector; el instalador aplica sólo la inserción comprobada y
valida sintaxis antes de escribir, sin mostrar el contenido del archivo.

Paquete: publicar_pausa_recuperacion.py y pausa_recuperacion_release.json.
Esta medida limita la actividad; Hostinger debe retirar su protección y continúa
pendiente la reducción permanente de consultas de inicialización y sondeo.
