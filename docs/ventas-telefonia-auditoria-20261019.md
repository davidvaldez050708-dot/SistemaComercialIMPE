# Auditoría: telefonía del Asesor de Ventas (2026-10-19)

Estado: **incompleta**. La presencia de una extensión no equivale a recepción telefónica ni a trazabilidad comercial integral.

## Comportamiento actual identificado en main

- **Inicio**: `HomeController::index`, rol id 3, abre `dashboard/asesor.php` y `telefonia/marcador.php` con teléfono saliente, agenda de contactos de `TelefoniaContactosService` e historial reciente de `TelefoniaActividadService`. No se necesita otra pantalla inicial.
- **Auricular en topbar**: depende conjuntamente de `telefonia.usar`, `telefonia.recibir`, `telefonia_extensiones.permite_entrantes` y conexión WebRTC reportada por el shell. No poner verde artificialmente. El rol tiene permisos *iniciales* en RolModel, pero la base real puede tener otra configuración.
- **Inicio > Llamadas recientes**: `TelefoniaMarcadorPanelService::obtener` consulta `TelefoniaActividadService::consultar(extension, usuario)`; esa consulta restringe por `telefonia_ventas_marcaciones` y eventos `NOTIFY_OUT_START/END`. Por ello no puede presentar las transferencias entrantes del asesor.
- **Mis llamadas**: `TelefoniaController::misLlamadas` ofrece siempre `historial`, pero `recibidas` y `devoluciones` solo a Analista con `seguimientos_vinculacion.operar_propios`. No habilitar al asesor la vista institucional: contiene búsqueda de expedientes y registro de verificación institucional.
- **Recepción > Prospecto**: los destinatarios salen del directorio `TelefoniaEnrutamientoService`, condicionado por asignación territorial `ASESOR`, extensión activa y permiso de recibir. Se mantiene la confirmación voluntaria del asesor antes de transferir. Antes de esta corrección, el nombre del prospecto era tratado como filtro de asesores y estaba oculto.
- **Modal del asesor**: `telefonia_disponibilidad.js` funciona por rol telefónico si su extensión recibe, pero `telefonia_shell.js` registra resultados y cierres especializados solo si `analystIncomingResults`; Ventas no tiene habilitado ese camino.
- **Notificaciones**: `ReminderController` invoca `TelefoniaNotificacionService` cuando existen permisos. Para el rol no Marketing, `TelefoniaNotificacionService` usa `TelefoniaEntrantesAnalistaService` y envía al usuario a la vista `recibidas`, actualmente prohibida a Ventas. Las devoluciones también apuntan a una pestaña no autorizada. Falta ruta, clasificación y origen comercial propios; el mensaje de «buzón» no debe deducirse solo del estado CONNECTED/ANSWERED.
- **Identidad transferida**: `TelefoniaTransferenciaContextoService` resuelve únicamente contexto `INSTITUCION`. El campo PROSPECTO se conserva en `telefonia_disponibilidad.ruta_json` pero no se presenta/atribuye como ficha de prospecto verificada en el momento de contestar.

## Fases de terminación sin mezclar datos institucionales

1. **Identificación en recepción**: mostrar y validar nombre de prospecto; buscar asesor solo por territorio/permisos; conservar ruta `PROSPECTO`. Esta PR aborda únicamente esta etapa.
2. **Recepción y ficha comercial**: servicio y tabla propia para registros comerciales entrantes, vinculados por PBX, usuario, extensión, fecha/asignación y transferencia. No introducir referencias a expedientes de Vinculación. Resultado humano obligatorio cuando corresponda y compatibilidad con segmentos/grabaciones.
3. **Inicio e historial unificados**: misma fuente comercial autorizada para Inicio y Mis llamadas. Vistas de salientes, entrantes, perdidas, transferidas, sin contacto y devoluciones; distinguir tramos PBX de conversaciones y preservar la clasificación manual de salientes.
4. **Agenda**: guardar una persona prospecto desde el contexto recibido, número, nombre indicado por Recepción, estado y notas; validar propiedad y evitar duplicados. Nunca crear clientes o resultados efectivos de forma automática.
5. **Notificaciones**: adaptar `TelefoniaNotificacionService` y rutas a la bandeja de Ventas; sin duplicados entre llamadas perdidas, transferencia no contestada, devolución asignada o buzón. Una llamada atendida no genera alerta de perdida. Buzón exige evento PBX compatible o registro humano específico.
6. **Permisos/estado**: diagnóstico read-only de permisos efectivos, extensión actual y territorios vigentes, después prueba integral con marketing ext. 100 y asesor receptor; nunca forzar la luz verde si SIP no está listo.

## Matriz de aceptación requerida antes de anunciar «100% funcional»

- Extensión del asesor asignada para salientes y entrantes y autorización real; verde solo con PBX conectado.
- Tania elige Prospecto, estado y asesor; anota nombre, solicita disponibilidad; el asesor acepta, rechaza o deja vencer; la transferencia no se envía automáticamente.
- Entrante contestada y transferida a Ventas: identificación comercial, registro del resultado y grabación del tramo correcto, sin expediente institucional.
- Entrante perdida / transferencia no contestada: evento y aviso únicos, contexto, fecha, duración exacta y vínculo a acción autorizada en Mis llamadas.
- Buzón de voz, mensaje automático, sin respuesta, ocupado, conversación real: resultados diferenciados sin atribución falsa.
- Devolución solicitada desde Recepción: aviso, tarea comercial, llamada saliente resultante, cierre verificable.
- La agenda y el historial del Inicio concuerdan con Mis llamadas, sin duplicar tramos PBX ni mezclar usuarios.
- Volver a entrar o recargar conserva historial/resultados pero no revive llamadas ni solicitudes vencidas.
- Desktop y móvil, en Chrome y al menos otro navegador, sin romper Marketing/Analista.
- Copia de seguridad, comprobación `--check`, publicación selectiva y restauración probada.

**Nota**: No hay evidencia suficiente para afirmar que Hostinger ya tiene todos los permisos y asignaciones reales. Se requiere validación read-only de producción y prueba con llamada real; las pruebas automáticas no sustituyen ese paso.
