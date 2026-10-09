# Depuración previa al despliegue - 2026-10-09

## Qué se eliminó

- Cliente/browser SDK y endpoints dedicados a Twilio, página de prueba telefónica aislada y verificación de Caller ID mediante Twilio.
- Soporte activo de grabaciones Twilio en los endpoints de Vinculación. Las filas históricas de la base de datos **no** se borran; si existen grabaciones Twilio históricas, no podrán recuperarse mediante el CRM después de este cambio. Exportarlas antes de desplegar si son necesarias.
- Acceso alternativo por `tel:`, `sip:` o `callto:` desde el panel de seguimiento; las llamadas usan únicamente el marcador WebRTC persistente Zadarma.
- Avisos que afirmaban que la integración telefónica seguía pendiente.
- Scripts temporales de diagnóstico en la raíz, sin autenticación o innecesarios para operar el CRM.

## Qué permanece y por qué

- `prueba_telefonia/api/zadarma_*.php`, `grabacion_interaccion.php`, `grabacion_ventas.php`, `grabacion_recepcion_marketing.php`, `llamadas_seguimiento.php` y `historial_recepcion_marketing.php`: el sistema los consume desde el seguimiento, Ventas, recepción y estadísticas; el nombre de directorio es heredado.
- `public/css/seguimiento_llamada.css`: estilos existentes del modal, renombrados sin cambiar su contenido.
- Esquema MySQL, migraciones, `storage`, plantillas y archivos que pueden estar vinculados con expedientes: **no se borran** sin cotejar registros.
- Herramientas CLI de diagnóstico en `tools`: se mantienen fuera del acceso web mediante `.htaccess`.

## Requisitos antes de Hostinger

1. Revisar y rotar credenciales presentes en el historial de Git: correo SMTP, INEGI, DENUE y cualquier token real; configurar secretos fuera del repositorio. Marcar el repositorio como privado no invalida credenciales que ya se difundieron.
2. Configurar dominio HTTPS y `BASE_URL`, conexión MySQL, `display_errors=Off` y sesiones HTTPS seguras; comprobar que la versión PHP y extensiones del plan son compatibles.
3. Instalar las dependencias completas con `composer install --no-dev --optimize-autoloader` usando `composer.lock`, o subir el resultado ya instalado. El `vendor` versionado no incluye todas las librerías.
4. Importar esquema base y ejecutar migraciones pendientes mediante `php tools/migrar.php --run`, verificar `--status`. No baselinar una instalación nueva.
5. Configurar en Zadarma el webhook estable, las extensiones por usuario y los permisos; verificar pruebas reales de entrada, salida, transferencia y grabación.
6. Probar recorrido completo con usuarios Analista, Cuenta clave, Administración, Marketing y Ventas: CRUD, permisos, seguimiento, correo, reuniones, convenios, reportes PDF y notificaciones.
7. Confirmar que `.htaccess` está siendo respetado por el alojamiento; peticiones públicas a `/config/`, `/storage/`, `/database/` y `/tools/` deben responder 403 o 404. Preferir el directorio público separado cuando sea posible.
8. Crear y comprobar backup y recuperación antes de migrar datos de trabajo reales.

## Limitaciones de la validación

Esta limpieza no equivale a una prueba funcional integral con servicios reales. No se certifican todavía las llamadas, el alojamiento, la base de datos ni el renderizador DOCX/PDF. Se ejecutan verificaciones estáticas de sintaxis en CI como primer filtro, no como sustituto de un piloto.
