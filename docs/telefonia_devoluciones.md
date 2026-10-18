# Transferencias y devoluciones

La recepción selecciona un único destino «Estado · Responsable · Extensión». El estado se deriva del responsable autorizado. Si la institución no está confirmada, se solicita su nombre tanto al recibir un número nuevo como al descartar una coincidencia del seguimiento.

Una solicitud de disponibilidad se muestra aunque exista un resultado pendiente. El analista puede rechazarla o abrir ese registro; sólo podrá aceptar después de guardarlo. La solicitud conserva su caducidad de 45 segundos. No se envía una transferencia sin aceptación explícita.

Las devoluciones llegan a la campana propia y a Llamadas por devolver. La vista permite buscar, filtrar, llamar y revisar el resultado. Preparar una devolución no acredita una llamada: el servidor exige un inicio saliente propio, al mismo número, posterior a la preparación, y un cierre confirmado. Una conversación sólo se registra con respuesta y duración confirmadas. Si faltan eventos de la centralita, debe esperarse su confirmación.

Cada intento guarda un resultado definitivo. Buzón, sin respuesta, no disponible y llamar después conservan el pendiente. Persona atendió o número incorrecto lo cierran. Una preparación sin llamada puede descartarse; una llamada ya iniciada debe finalizar y registrar su resultado.

Elegir una institución con seguimiento guarda una interacción en su expediente, con el identificador y duración confirmados de esa llamada, conservando su etapa y próxima acción futura. No acredita verificación efectiva. Una institución nueva conserva su nombre sin crear automáticamente un expediente. La verificación de datos mantiene el flujo y la meta de Seguimiento.

La tabla telefonia_devolucion_intentos es aditiva y se prepara al utilizar el servicio. No se reescribe el historial previo. La consulta y modificación se limitan al usuario y extensión asignados; las escrituras requieren CSRF.

Validación: tests/telefonia_atencion_db.php (base desechable), tests/telefonia_devoluciones_browser.cjs (navegador real con servicios simulados), tests/telefonia_disponibilidad_browser.cjs y tests/telefonia_shell_runtime_smoke.js. Las llamadas y el audio reales requieren una prueba final en Hostinger con las extensiones 100 y 101.
