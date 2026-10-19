# Toasts del CRM: reloj y publicación selectiva

## Evidencia y alcance

Se revisaron recordatorios.js, convocatoria_notificaciones.js, telefonia_notificaciones.js, dashboard_head.php, dashboard_footer.php, topbar.php, ReminderController y TelefoniaNotificacionService. La carga del layout declara Bootstrap antes del cliente del analista; Marketing registra su listener desde el head. No hace falta modificar esos layouts, endpoints, lectores ni los servicios telefónicos.

El archivo público de producción se descargó sin autenticación el 18/10/2026 y se comparó con a0e2607 (PR #88). Su huella SHA-256 con LF es `38b3b2122f0db11f5edd4823d692c9398d4da6fc492f5270c6c41db3d993bb9e`. No contiene toda la corrección del PR. Las diferencias revisadas afectan al mismo mecanismo de toasts y su cadencia; no se trasladan cambios de otros módulos.

Con ese archivo y dos pendientes, en un navegador real sin Bootstrap JS, la reproducción da: campana 2, inicio por usuario registrado, temporizador de 480000 ms programado, dos claves `impe:phone-toast:8:*` guardadas como vistas y cero toasts al entrar y a los ocho minutos. Los avisos se consumían aunque mostrarToast abandonara por falta de Bootstrap y quedaban fuera de la rotación periódica.

La clave de inicio sin el sufijo `:usuario:8` devuelve null, aunque la clave real exista. La captura histórica con temporizador false no demuestra un fallo de inicialización en el archivo actual: el ReferenceError anterior sí podía interrumpirla, pero su declaración ya está corregida en la versión pública observada. No se afirma haber identificado por qué Bootstrap falta en el navegador autenticado; se elimina esa dependencia del presentador.

## Funcionamiento

- Campana consultada inmediatamente y actualizada cada minuto, sin alterar su contenido ni lecturas.
- Un reloj de ocho minutos por usuario, conservado en sessionStorage entre vistas. No usa las claves telefónicas antiguas que se consumían sin mostrar.
- Toast individual con el HTML y CSS originales de Recordatorios. Presentación y retiro nativos, con o sin Bootstrap; siete segundos también durante hover o foco.
- Sólo confirma turno e identificador después de comprobar conexión, dimensiones y visibilidad del elemento. Ocultación, pestaña no visible o falta de datos conservan el turno vencido.
- Rotación de avisos telefónicos no leídos y acciones vencidas. Los avisos puntuales de Seguimiento comparten el reloj y una cola por usuario, ligada a sus pendientes reales; navegar no los consume.
- Clic telefónico conserva atributos y el lector existente con POST y CSRF. Autocierre no registra lectura. Marketing conserva su presentador de convocatorias y su funcionamiento propio.
- Inicialización válida tanto antes como después de DOMContentLoaded, sin una segunda instancia al cargar dos veces el script. Datos de sesión inválidos no bloquean el reloj. Si el navegador prohíbe sessionStorage, funciona en memoria durante esa página; la persistencia entre navegaciones requiere almacenamiento de sesión disponible.

## Verificación en navegador

`window.IMPE_REMINDER_STATUS()` devuelve versión, usuario, inicio, último aviso realmente mostrado, próximo vencimiento, temporizador activo, número de pendientes, consultas completas y disponibilidad de sessionStorage. No expone contactos ni modifica datos. Se consulta en el contexto del documento del CRM; si se usa el shell persistente, seleccionar el iframe del CRM en la consola.

`recordatorios_toasts_browser.cjs` ejecuta Playwright con los scripts de presentación y lectura reales, Bootstrap CSS y recordatorios.css. Cubre dos pendientes al entrar, Bootstrap presente/ausente, cuatro minutos más navegación más cuatro, cierre a siete segundos, lectura con CSRF, rotación con Seguimiento, duplicados, CSS que impide mostrar, pestaña oculta, cola puntual tras navegación, claves corruptas y usuarios distintos, inicialización tardía/doble, storage bloqueado, ráfagas del backend y respuesta parcial. Red y tiempos están simulados; no modifica datos ni ejecuta llamadas.

## Contrato de publicación

`tools/toasts_8m/manifest.json` contiene huellas completas de entradas revisadas, parches selectivos y resultado probado. El publicador Bash fija el commit de estos recursos. Aplica el parche sobre una copia privada del archivo real y sólo publica si el resultado completo coincide con la huella validada mediante sintaxis JavaScript y Playwright fuera de Hostinger. No requiere Node.js en el servidor. Las variantes desconocidas, parches alterados, finales mezclados o marcadores de conflicto detienen la entrega.

El alcance en producción es exclusivamente `public/javascript/recordatorios.js`. No hace git pull en public_html, no publica otros cambios pendientes, no ejecuta PHP del CRM ni SQL. Respalda antes de escribir, verifica que nadie haya cambiado el archivo durante la preparación, comparte bloqueo con los publicadores anteriores y restaura el original si falla la instalación. La restauración manual sólo permite volver al respaldo si el archivo sigue siendo el publicado por esta entrega; no pisa ediciones posteriores.

Tras publicar, recargar una vista del CRM cuando no interrumpa una llamada y comprobar el diagnóstico: initialized y timerActive verdaderos, pendingCount acorde a la campana. Esperar ocho minutos reales con la pestaña visible; navegar a los cuatro minutos debe conservar startedAt y el vencimiento. El toast dura siete segundos, no borra la campana y el siguiente turno muestra otro pendiente. Pulsarlo debe abrir el módulo indicado y registrar lectura mediante el mecanismo existente.
