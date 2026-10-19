# Telefonía de Ventas: validación de la entrega

Base: main `59d5eb6656a7a0a2b6b5ff16db9ff4c691c3bc34`. Las fases son dependientes y no se publican ni integran automáticamente. No se cambian credenciales, asignaciones ni permisos. Los usuarios sin extensión continúan sin teléfono habilitado.

## Implementación

- A: el auricular usa la asignación del shell autenticado y su latido WebRTC vigente. Una consulta secundaria fallida no contradice una conexión real; un latido vencido apaga el indicador.
- B: llamadas y resultados comerciales propios. La identidad histórica se congela por usuario, PBX y extensión. El nombre de Recepción es un dato informado; no una verificación institucional ni prueba de conversación.
- C: Mis llamadas concentra historial, filtros, paginación, resultados y reproducción de segmentos propios. Inicio conserva teléfono, agenda y un resumen compacto. El formulario toma el primer plano mediante el contrato del shell; no desmonta WebRTC.
- D: agenda editable, correo, observaciones, programación y devoluciones comerciales independientes. El resultado, el contacto opcional y el cierre de una devolución se guardan en una transacción. Buzón y sin respuesta conservan el pendiente.
- E: campana y toasts compartidos, lectura sin duplicados, programación y conciliación uno a uno. Se distinguen aceptación, envío, respuesta real y clasificación humana. Las respuestas PBX tardías retiran avisos superados. No se presume retorno automático a Recepción.

## Pruebas ejecutadas

| Suite | Resultado local |
|---|---|
| `telefonia_ventas_db.php` | 31 comprobaciones: saliente/entrante, resultados, propietario, segmentos, paginación e historial tras reasignación |
| `telefonia_ventas_gestion_db.php` | 28 comprobaciones: agenda, devolución, buzón, cierre, transacción y ambigüedad |
| `telefonia_ventas_notificaciones_db.php` | 39 comprobaciones: avisos, respuestas tardías, agenda, conciliación y extensión sin asignar |
| `telefonia_atencion_db.php` | 144 regresiones del Analista |
| `telefonia_identidad_transferencia_db.php` | 12 regresiones de identidad institucional |
| `telefonia_ventas_guard_http.cjs` | 11 casos HTTP del validador real: sesión, rol, permisos, método y CSRF. Sesiones de fixture; no acredita un login de producción |
| Navegador de Ventas | Auricular y latido; fin → formulario; borrador y recarga; agenda editable; devolución; bloqueo sin WebRTC; reproductor de dos segmentos |
| Navegador compartido | Modales de Analista; aceptar/rechazar/vencer disponibilidad; 11 escenarios de toasts con cadencia compartida de ocho minutos |
| Responsive | 1366×900, 390×600 y 740×360; encabezado, cierre, footer y scroll del modal accesibles; sin tarjeta telefónica superpuesta |
| Regresiones adicionales | Shell, navegación, bridge entrante, resultado transferido, autorización territorial, cuatro contratos de Mis llamadas, audio de Marketing y audio transferido institucional |
| Publicadores A–E | Réplicas desechables: check sin cambios, apply, repetición de la etapa, respaldo y restore exacto; personalización PHP conservada; JS desconocido y PHP incompatible rechazados. B no modifica JS existente y ese caso no aplica |

Base local aislada en puerto 33307, sin utilizar la base del CRM. El workflow específico ejecuta también MySQL 8.0 y MariaDB 10.11. Las pruebas de navegador usan vistas, Bootstrap, scripts y shell reales; transporte/API/PBX simulados. No acreditan audio, disponibilidad SIP o llamadas reales de Zadarma.

## Matriz final de llamadas reales

1. Saliente desde 150: marcar, timbrar, contestar, conversar y colgar; registrar interesado con nota. Comparar extensión, estado y duración con PBX.
2. 100 → 150: Prospecto · Ventas, territorio autorizado y nombre informado. Aceptar disponibilidad y comprobar que todavía no se transfiere. Transferir explícitamente, contestar, colgar y registrar resultado propio.
3. Rechazar y dejar vencer solicitudes; comprobar los 45 segundos y los 30 posteriores a la aceptación. No debe haber llamada ni conversación atribuida por aceptar solamente.
4. Aceptar y no contestar la llamada real: distinguir sin confirmación/sin respuesta; notificación y devolución correctas; no asumir regreso automático a 100.
5. Llamada perdida directa y buzón: aviso correcto, sin conversación humana ni audio presentado como conversación; la devolución permite reintentar.
6. Devolución: realizar un intento fallido y uno atendido. El primero conserva el pendiente; el segundo guarda su resultado y cierra la gestión correspondiente sin crear registros de Vinculación.
7. Agenda: crear, editar, buscar, utilizar y eliminar contacto; intentar duplicar su número. Programar y cerrar recordatorio sin generar una llamada falsa.
8. Dos prospectos distintos desde el mismo número: el segundo no hereda nombre, seguimiento ni contexto del primero. Las llamadas internas no se convierten en expedientes.
9. Recargar con llamada finalizada y borrador; recuperar el resultado sin superposición. Probar móvil y teclado dentro del formulario desplazable.
10. Audio: verificar NOTIFY_RECORD de 150 y referencias distintas de 100; reproducir dos tramos si existen. Sin evento propio o con referencia compartida entre extensiones, debe mostrarse audio sin confirmar. No se ha certificado grabación real de producción.
11. Otro asesor no puede leer llamadas, contactos, intentos, resultados ni notificaciones de usuario 8. Los IDs 9/15 no obtienen extensión automáticamente.
12. Repetir llamada entrante/saliente de Marketing y transferencia 100 → 101 con resultado y grabación para comprobar convivencia real.

## Checks heredados

Se compararon las mismas pruebas estáticas contra archivos de main y esta entrega: `telefonia_ux_admin_smoke.php`, `telefonia_shell_smoke.php` y `marketing_recepcion_pestana_smoke.php` fallan con el mismo mensaje en ambas versiones. No se eliminaron sus aserciones para ocultarlo.

La ampliación del selector CSS compartido hizo que el contrato estático del modal de Analista no encontrara una regla que seguía activa. Se actualizó su búsqueda para reconocer `:is(...)`, conservando todas las comprobaciones de flex, scroll, altura y footer, y se comprobó además con navegador real. La corrección del contrato se llevó a las fases C y D.

El check heredado de cadencia también reportó un timeout en Chromium del runner. Sus scripts y prueba no difieren de main; localmente pasan los 11 casos con Playwright 1.62.1/Bootstrap 5.3.8 y 1.56.1/5.3.3 sobre Edge. Esto no equivale a un check remoto aprobado. Revisar el estado actualizado de Actions antes de integrar; no anunciar la suite general como verde.

## Publicación

Consultar `telefonia_ventas_migracion.md`. Los publicadores están fijados a commits de aplicación, usan HTTPS, no requieren Python en Hostinger y nunca ejecutan SQL. Cada uno ofrece `--check`, `--apply` y `--restore RUTA_RESPALDO`, con copia privada fuera de public_html y bloqueo compartido. Una variante de producción no conocida debe detener la instalación y revisarse; no usar force ni git pull sobre public_html.

Las fases se aplican en orden A → B → C → D → E, en mantenimiento y sin llamadas activas; completar explícitamente la migración antes de reabrir la operación comercial. Conservar cada ruta de respaldo. Restaurar en orden inverso E → D → C → B → A. Las columnas y filas nuevas se conservan para no perder resultados. La idempotencia se prueba por etapa; no repetir una etapa antigua sobre archivos ya evolucionados por una posterior.
