# Relevo de telefonía universal — 27/11/2026

## Estado de esta entrega

Rama `codex/telefonia-universal-20261126`, trabajo en curso. El usuario pidió
publicar este estado para continuarlo con otro perfil de Codex. NO es una
entrega terminada ni un paquete instalable. No se ha desplegado en producción
ni ejecutado migraciones productivas. No dar comandos APPLY para este estado.

Base de código instalado capturada: `1d8f7da23907fabce398c13d924ef5996d839473`.
Punto solicitado para continuar: `5b7e4c1b55ecb3352bf67d45270698350719dff0`.
Después se incorporaron `62ac1a03` (DDL Ventas fuera de peticiones y fixtures
nativos), `f35d03be` (captura compartida e identidades) y el commit que contiene
este relevo (bitácora entrante y vínculo exacto de transferencia).

Comparar contra la base instalada para revisar el candidato; comparar sólo
contra main mezcla personalizaciones de Hostinger con correcciones nuevas.
No desplegar el repositorio entero sobre la instalación personalizada.

## Qué está implementado

- El host conserva la autoridad del teléfono. El fin PBX remoto no cuelga un
  diálogo SDK vivo y las respuestas atrasadas se aíslan de la siguiente llamada.
- HTTP común: plazos completos, consultas compartidas, identidad de usuario,
  CSRF renovado y cola durable ordenada de observaciones SDK. No guardar CSRF
  ni credenciales en la cola. SDK observado no equivale a evidencia PBX.
- Salientes: intención persistida antes de marcar, observaciones del diálogo
  físico exacto y cierre local independiente de la espera de evidencia.
- Entrantes de los tres roles: host como productor de la bitácora protocolo2;
  respuestas/end reales del SDK, descubrimiento de diálogo ya establecido y
  nuevo INVITE permitido tras terminar el anterior aunque la interfaz se atrase.
- Negociación por llamada: una atención iniciada en protocolo1 conserva su
  escritor anterior durante convivencia con páginas almacenadas en caché.
  El adaptador Analista moderno consulta ACK/estado, no duplica la escritura.
- Entrante PBX sin SDK: consulta propia por referencia exacta, asignación y
  extensión; usa el reductor común y no fabrica una sesión SDK para el historial.
- Transferencia: evento durable `transfer_link` permite vincular el mismo SDK
  reservado por `sdk_consulta`, operación e intento exactos, entrega acreditada
  y ANSWER PBX propio posterior al marcador y dentro de la asignación actual.
  El servidor obtiene la referencia: el cliente no envía un PBX arbitrario.
  Puede llegar después del cierre local sin reabrirlo ni cambiar el resultado.
- Borradores comunes con revisiones, protección contra ACK antiguo, avisos
  pendientes y alias locales SDK/PBX. Se conservan campos y selecciones al fallar
  el guardado o recargar; el cierre pendiente no resucita tras perder su ACK.
- Ventas: los constructores dejaron de ejecutar DDL; preparación explícita CLI.
  Fixture del servicio nativo valida rollback de resultado/detalles/contacto.
- Proyección común por segmento, permisos y asignación vigentes; sesión PHP
  liberada antes de consultas lentas; caché/presupuesto privado del proveedor.

Archivos principales de la última integración:
`app/services/TelefoniaSesionLocalService.php`,
`prueba_telefonia/api/sesion_local.php`, vistas `telefonia/host.php` y `shell.php`,
`telefonia_host.js`, `telefonia_http.js`, `telefonia_sdk_entrantes_observador.js`,
`telefonia_analista_historial_pasivo.js`, `telefonia_transferencia_runtime.js`.

## Validación y límites

Ejecutar desde la raíz de esta rama:

```powershell
node tools/telefonia_universal_20261126/run_unit.cjs
```

El runner contiene 15 suites aisladas, incluidas entrantes (19 comprobaciones),
lector Analista (15) y cola del vínculo de transferencia (10). No son llamadas
completas ni usan el proveedor. Los contadores de 1,000 avisos/borradores o
reducciones de estado de otras pruebas tampoco equivalen a 1,000 llamadas.

En el laboratorio local ya existente se ejecutaron además: entrantes con host,
HTTP y SQL para roles41/42/43 (42 comprobaciones, pérdida de ACK y recarga),
proyección PBX propia (11), vínculo operación/SDK/PBX (14), sesiones SQL (39),
servicio nativo Ventas (18), entre otras pruebas parciales. La prueba SQL del
vínculo se versiona en `integration/transfer_journal_link_sql.php`.

Las integraciones CLI requieren `IMPE_PHONE_LAB_ROOT` apuntando al laboratorio
sintético existente. Exigen loopback3308 y base `impe_telefonia_lab`, con fuentes
de esta rama copiadas en su `site`. No usar un config productivo ni credenciales
del sitio. El laboratorio privado y sus credenciales NO están publicados.
No afirmar que otro perfil puede reproducir todo el laboratorio desde este
checkout: aún falta versionar un preparador completo, aislado y reproducible.

Pruebas de navegador parciales: Ventas conserva notas/selecciones tras409,
vinculación del alias PBX y recarga; Analista conserva una/dos confirmaciones.
No validan evidencia del proveedor ni acreditan una verificación comercial.

**1,000 llamadas completas: pendientes. Ocho horas reales continuas: no iniciadas.**
No existe publicador universal terminado con CHECK/respaldo/ROLLBACK.

## Pendientes para completar la intervención

1. Transferencias que sustituyen el diálogo físico: el vínculo implementado
   sólo admite el SDK reservado en la operación. Falta una relación verificable
   con el SDK sucesor, con orden de ACK entre sesiones y recuperación duradera.
   No resolver por teléfono, cercanía de horas ni SIP Call-ID como PBX call_id.
   Una operación todavía no descubierta al terminar el SDK requiere conciliación
   posterior; no está garantizada por el sondeo del runtime actual.
2. Salientes: enlazar intención persistida y PBX con evidencia inequívoca y
   límites entre intentos; el endpoint de estado aún recibe parámetros del cliente.
3. Reducir el reductor entrante duplicado de EventStore al motor común. Sus filas
   normalizadas pierden id y presencia de duración: no pasarlas directamente al
   reductor RAW ni aceptar ANSWER/END de otra extensión como conversación propia.
4. Un único controlador/modal de captura con adaptadores comerciales por rol.
   Los módulos de UI de Ventas/Analista/Recepción siguen coexistiendo: NO afirmar
   que se eliminaron todos los mecanismos redundantes ni que la unificación terminó.
5. Envío aprobado durable y recibo en la MISMA transacción nativa comercial.
   El helper atómico sólo se probó con tabla nativa sintética; no está conectado
   a todos los servicios reales. Nunca autoaplicar un borrador incompleto.
   El puente al embudo de Ventas aún es postcommit y necesita recuperación fiable.
6. Alias: actualmente se replican como máximo tres identidades locales. Falta
   captura canónica PBX con redirecciones SDK y recuperación entre navegadores.
   Los ids del grupo aún no se persisten completamente en el JSON del servidor.
   Conflictos de igual revisión requieren revisión explícita, no elegir valores
   silenciosamente ni interpretar los valores iniciales del formulario como aprobación.
7. Fixtures completos de Vinculación y Recepción, reglas comerciales/embudo,
   permisos, grabaciones y rechazo de asociaciones ambiguas. Auditar comparaciones
   PBX binarias en servicios nativos, DDL restante en rutas activas y concurrencia
   de expediente/contacto/asignación sin sobrescribir cambios de otra persona.
8. Caché del proveedor: limpieza/retención y fallo seguro de presupuesto corrupto;
   verificar TLS en Hostinger. Ningún proceso de laboratorio usa llamadas reales.
9. Completar comparación con Hostinger y metadatos de esquema mediante diagnóstico
   READ ONLY previamente solicitado. Falta hash exacto del footer o excluirlo del
   payload si no es necesario. El conector lee archivos, no ejecuta SQL/SSH.
10. Preparador sintético reproducible y prueba completa de 1,000 llamadas con
    concurrencia de tres roles, pérdidas de respuestas, desconexiones, eventos
    PBX tardíos, recarga, resultados/recibos nativos, grabaciones y recuperación.
    Después, ocho horas de carga REAL continua medidas con reloj monotónico.
    No sustituirlas por avance artificial del reloj o pruebas unitarias repetidas.
11. Publicador selectivo contra hashes instalados, preflight que rechace cambios,
    respaldo privado y reversión comprobada, compatible con proc_open deshabilitado.
    No migraciones ni despliegue productivo sin autorización para ese paso.

## Continuar desde otro perfil

```bash
git clone --branch codex/telefonia-universal-20261126 --single-branch https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git
cd SistemaComercialIMPE
node tools/telefonia_universal_20261126/run_unit.cjs
```

Conservar las modificaciones originales de otros checkouts. No reutilizar como
payload las exploraciones antiguas `work/impe` o `work/before`. Este relevo y el
README de esta carpeta describen un candidato incompleto, no una versión liberada.

## Continuación autónoma desde 83dad082 — checkpoint local (no liberado)

Se continúa en el mismo motor; no hubo publicación, SQL productivo ni llamadas reales.
Los once pendientes anteriores describen el punto de partida, no todos siguen intactos:

- Preparador `lab/prepare.py`, copia privada en loopback:3308, configuración sintética,
  proveedor bloqueado y usuario de aplicación DML sin CREATE/ALTER/DROP. `lab/sync.py`
  copia únicamente código y registra SHA256. `run_sql.py` reproduce pruebas nativas.
- Confirmación común `TelefoniaResultadoConfirmacion`: comando explícitamente aprobado,
  request_id durable, revisión y recibo/respuesta en la MISMA transacción nativa.
  Integrado en Ventas, Recepción y registrarLlamadaEntrante del Analista. NO afirmar
  todavía que todos los endpoints de Seguimiento/devoluciones están integrados.
- Ventas: puente de embudo dentro de la transacción del resultado; ya no se silencia
  un error postcommit dejando un resultado huérfano. Fixture real de servicios nativos
  prueba creación/contacto/evidencias de Vinculación y movimiento del embudo V1,
  recuperación de respuesta y rollback. Falta ampliar V3 y concurrencia.
- DDL retirado del constructor entrante y guardado de Recepción. Se retiran llamadas
  a preparar evidencias de rutas activas. Esquemas explícitos `schema_recepcion.sql`,
  `schema_atencion_analista.sql`, `schema_evidencias.sql`, `schema_sucesores.sql`.
- EventStore conserva id/has_duration y delega los hechos entrantes al reductor común.
  Se está comprobando el nuevo fence de devolución explícita para no heredar END.
- Salientes modernas usan session_id persistido para resolver destino y marcador;
  comparación de número completo (variantes mexicanas explícitas), propietario,
  ambigüedad y fence de siguiente intención. No es prueba de un identificador PBX
  transmitido por el SDK; falta validar contrato/orden tardío completo en laboratorio HTTP.
- Sucesor físico: Replaces debe coincidir en call-id y ambos tags con el diálogo anterior.
  El sucesor usa otra sesión local y una arista durable; nunca se reabre la anterior.
  Cola con dependencia de ACK. Se elimina heurística de teléfono del adoptador.
  Recuperación servidor de una reserva SDK ya persistida después de END; si nunca
  llegó a existir esa reserva no se inventa por teléfono/tiempo. Falta cerrar ese flujo.
  Referencia primaria del contrato: https://jssip.net/documentation/3.4.x/api/session/#replaces
- Borradores: grupo de alias derivado de sesiones/PBX del mismo usuario, JSON con ids,
  hasta 32 referencias acotadas, recuperación por sucesores y cierre con tombstone.
  Conflictos iguales de revisión se conservan para revisión. Falta completar deduplicación
  de pendientes remotos y auditar todos los adaptadores/casos concurrentes.
- Proveedor: presupuesto vacío/corrupto falla cerrado; TLS obligatorio; limpieza paginada
  que alcanza todas las páginas sin borrar presupuestos/archivos ajenos. Falta conectar
  mantenimiento CLI conservador, probar concurrencia y comprobar TLS del alojamiento.

Evidencia: 17 suites JS; 18 SQL Ventas, 15 recibos tres adaptadores (clasificación simple
Analista), 11 integración nativa institución/embudo, 7 intención saliente y 31 acumuladas
transferencia/sucesores/captura canónica. No sumar suites anidadas como pruebas distintas.
8 checks de proveedor con transporte falso. No equivalen a llamadas completas.

Pendientes críticos que aún impiden liberar: controlador/modal universal real (siguen
adaptadores UI anteriores), endpoints de Seguimiento/devoluciones con recibo atómico,
reconciliación sin reserva temprana, pruebas V3/permisos/grabaciones/concurrencia,
compatibilidad con Supervisión de personal, CHECK contra archivos/esquema personalizados,
publicador/reversión, 1,000 ciclos completos HTTP/SDK/PBX/comercial y ocho horas reales
continuas con reloj monotónico sobre código estable. No están iniciadas esas ocho horas.

Laboratorio vivo de esta sesión (fuera del repo, NO publicar credenciales):
`C:/Users/chill/AppData/Local/Temp/impe-phone-lab-ahii8exu`, mysqld PID 34448,
3308. Hay otro mysqld ajeno (PID 49020): no detenerlo. Credenciales sólo en el
JSON privado del laboratorio. Nunca copiarlas a Git ni imprimirlas.
Supervisión independiente sigue en `../impe-supervision-personal`, commit
76ed8c0c440caf54b90485442d64f975e8fdd58e; no se ha fusionado ni sobrescrito.

### Segundo checkpoint: controlador de resultados y HTTP real

- Las interfaces de Ventas, Analista/Seguimiento y Recepción delegan su envío a
  `IMPE_CALL_RESULTS.submit`. Los adaptadores mantienen los campos y avisos
  institucionales; un solo controlador administra aprobación durable, doble clic,
  CSRF fresco, controles, transporte, lectura única del recibo ante respuesta
  perdida, cierre y aislamiento de formularios antiguos. No autoenvía borradores.
- Una casilla restaurada ya no cambia su value a 0: restaura checked y conserva
  value=1. Una casilla desmarcada queda explícita en el snapshot confirmado.
- `lab/start_http.py` y `lab/router.php`: tres workers loopback, autenticación y
  rutas nativas de permisos/sesión/CSRF/guardado. El router tiene clave sintética
  privada y es exclusivamente un harness local, nunca un archivo del publicador.
  HTTP externo/SMTP bloqueados en workers. Composer instala composer.lock sin
  scripts ni plugins dentro de la copia; nunca se copia configuración real.
- 15 ciclos salientes completos SDK observado -> HTTP -> PBX sintético -> borrador
  -> resultado nativo -> recibo -> replay, con pérdida de ACK SDK/resultado y
  relogin/CSRF fresco, pasaron por el controlador común. NO son todavía las 1,000
  llamadas universales ni incluyen transferencias/audio/prueba visual completa.
- 18 suites JS y 13 checks nativos del embudo V3: futura llamada/agenda legacy,
  Potencial, No interesado, tercera llamada acreditada y rollback de acciones.
  No se activó V3 fuera del proceso CLI del fixture.
- Se restauran sin cambios dos dependencias COMMITTEADAS existentes ausentes
  de la captura parcial: IntentosPbxRepository y PoliticaPropuesta. Procedencia
  y SHA en DEPENDENCIES.json. El publicador deberá comprobar la copia instalada,
  no reemplazar una personalizada desconocida. No reconstruir sus políticas.
- El controlador Analista libera sesión tras CSRF antes del guardado; la carga
  DENUE se difiere a las tres funciones que la usan, para que su cliente ajeno
  a telefonía no sea dependencia de inicialización del registro de llamadas.

Quedan la cobertura HTTP entrante/transferencias/Recepción con audio y recuperación,
concurrencia, todos los endpoints de Seguimiento/devoluciones con recibo atómico,
regresión visual de los tres adaptadores, integración selectiva de Supervisión y
preflight/publicador/reversión. Después: 1,000 llamadas completas y ocho horas reales
continuas sobre código estable. Ambas aceptaciones siguen pendientes.

IMPORTANTE Supervisión: NO fusionar toda su rama (base muy divergente). Su delta
funcional seguro es `git diff 0d5387d..76ed8c0` (22 archivos de runtime); aplicarlo
selectivamente conservando el motor universal y los ajustes personalizados.
Los HTTP workers vivos figuran en http.json del laboratorio; detener sólo PIDs
cuyo comando verificado corresponde a ese router y raíz privada.

### Tercer checkpoint: recibo atómico de devoluciones de Vinculación

Continuación desde `6b4e80712828780758c1ba551200017ae67b8358`. No se fusionó
Supervisión de personal, no se modificó producción ni se ejecutó SQL productivo.

- `TelefoniaDevolucionService` dejó de ejecutar CREATE/ALTER/SHOW COLUMNS en
  su constructor. Usa la conexión común. Su esquema se prepara explícitamente
  con `schema_devoluciones.sql`; una instalación antigua sin `datos_json` exige
  migración aditiva autorizada, nunca se corrige durante la petición.
- Resultado, estado de tarea y recibo `DEVOLUCION_ANALISTA` se confirman en la
  misma transacción nativa. Una captura con otra PBX no puede consumir el intento.
  La ruta `telefonia.devolucion` usa `TelefoniaResultadoConfirmacion`, mantiene
  los permisos/CSRF y libera la sesión antes del guardado. Compatibilidad con
  formularios antiguos sin metadatos de confirmación conservada.
- El formulario institucional reutilizado identifica este envío como
  `DEVOLUCION`. El controlador común recupera la respuesta en `payload.intento`,
  conservando el contrato del adaptador y evitando un reenvío automático.
- 13 comprobaciones SQL nativas: cuenta sin DDL, resultado/recibo/estado juntos,
  rollback por fallo del recibo, usuario/PBX, request inmutable y regla negativa.
- 24 comprobaciones HTTP nativas con CSRF y controlador común: pérdida de ACK,
  una consulta al recibo, replay explícito, rechazo de contenido cambiado y dos
  workers concurrentes devolviendo el mismo resultado para la misma aprobación.
  Runner: `node tools/telefonia_universal_20261126/lab/callback_receipt.cjs
  --lab-root <laboratorio>`. No son ciclos completos SDK ni se suman a las 1,000.
- Regresión justificada por el nuevo tipo de recibo: 18/18 suites JS y 15 checks
  SQL de los tres adaptadores anteriores pasan. No se repitió la auditoría general.

Laboratorio reproducible de este perfil: fuera del checkout, en
`C:/xampp/htdocs/SistemaComercialIMPE/output/telefonia_universal_20261126/lab-checkpoint2`.
Se preparó con `lab/prepare.py`, proveedor externo bloqueado y credenciales
sintéticas privadas. Se detuvo sólo el mysqld anterior cuyo datadir privado exacto
y puerto3308 se verificaron; no se tocó XAMPP. PIDs actuales en `lab.json`/`http.json`.

Sigue pendiente vincular los intentos de devolución con la intención SDK persistida:
su búsqueda antigua de PBX por sufijo de teléfono/ventana aún existe. No presentarla
como identidad fuerte. También siguen pendientes los endpoints de Seguimiento,
transferencias sin reserva temprana, grabaciones, aceptación universal de 1,000
llamadas y ocho horas reales, Supervisión selectiva y publicador CHECK/ROLLBACK.

### Cuarto checkpoint: devolución ligada a intención saliente persistida

El tercer checkpoint está publicado en `bd9d053d`. Este bloque conecta el intento
de devolución al mismo preparador/conciliador saliente del host:

- El host envía `callback_attempt` sólo para contexto DEVOLUCION. El servidor
  valida actor, extensión, versión de tarea, estado pendiente, destino completo,
  asignación actual y ausencia de reserva previa. Inserción de sesión SDK y
  reserva del intento ocurren juntas; un rechazo no deja sesiones huérfanas.
- Campo aditivo propuesto `sdk_session_id` e índice único por usuario/sesión en
  `schema_devolucion_sesion.sql`. Sólo se preparó en el laboratorio, no en producción.
- `estado()` usa `resolverSalientePreparada()` para intentos reservados: marcador,
  emisión SDK, número completo, extensión, siguiente intención y ambigüedad del
  motor existente. No cae al buscador por sufijo cuando el vínculo moderno existe.
  La búsqueda histórica queda sólo para intentos antiguos sin reserva; migrar esa
  compatibilidad requiere evidencia suficiente, no reconstrucción de históricos.
- Una reserva SDK todavía abierta no puede descartarse como preparación vacía.
  Si el SDK falla y termina sin marcar, sí puede cancelarse. PBX se compara BINARY.
- 15 comprobaciones SQL de intención de devolución y 7 de regresión del conciliador
  saliente. Host saliente:36 checks; carrera de preparación:24. El harness HTTP
  anterior ahora pasa 28 checks e incluye host SDK sintético, reserva HTTP real,
  eventos PBX, asociación al intento y guardado/recibo; conserva la concurrencia.
  Aún no constituye aceptación universal ni duración real de una llamada.

Siguiente bloque: endpoints restantes de Seguimiento con recibos dentro de su
transacción real, sin duplicar el pipeline comercial ya implementado. Mantener
pendientes de grabaciones/transferencias/soak/publicador y Supervisión selectiva.

### Quinto checkpoint: envío común desde el expediente de Seguimiento

El cuarto checkpoint está publicado en `6be5608b`. Este bloque conserva la
lógica existente de `registrarInteraccionTrabajo` y la conecta al recibo común:

- La ruta valida aprobación/CSRF y libera la sesión antes de guardar. El intento
  exacto se consulta antes de repetir validaciones comerciales que podrían haber
  cambiado tras el primer guardado. La lógica previa se mantiene en un método
  privado con datos explícitos, sin duplicar ni sustituir las políticas comerciales.
- `registrarInteraccionManual` admite callback antes de COMMIT. Interacción,
  cambios de contacto, auditoría/avisos de Cuenta Clave y recibo con la respuesta
  institucional completa usan la misma conexión/transacción. Fallo del callback
  revierte el registro; los adaptadores anteriores conservan sus contratos.
- El lock existente del expediente compara el contacto/asignación actuales con
  el snapshot validado para la aprobación. Si cambió, rechaza el guardado y
  conserva la captura, sin atribuir verificación a datos obsoletos.
- La consulta PBX devuelve su identidad técnica validada y respeta la fecha de
  asignación. Se guarda proveedor/referencia con la interacción. El inicio PBX
  exacto serializa dos aprobaciones distintas para la misma llamada; se rechaza
  una segunda interacción del mismo usuario con esa referencia, sin modificar
  históricos duplicados ni imponer un índice único sobre ellos.
- El formulario institucional sólo delega el canal ZADARMA con referencia al
  controlador común. Los demás canales conservan su envío. Evita doble submit y
  callbacks tardíos sobre otra institución. Recuperación de SEGUIMIENTO conserva
  el envelope nativo (seguimiento/interacciones/resumen/interaccion_id).
- Validación nueva:10 checks SQL del pipeline nativo (incluye otra aprobación
  para la misma PBX),16 HTTP con CSRF/pérdida de ACK/replay,12 del adaptador real
  extraído de la vista.19/19 suites JS pasan. Regresión por el cambio del modelo:
  11 checks institución/embudo y15 recibos previos. Sintaxis de los cuatro PHP
  cambiados correcta. No son 1,000 llamadas ni un soak de ocho horas.

**Todavía pendiente:** la conciliación posterior de evidencia/duración de este
formulario sigue pasando por `vincular_interaccion_zadarma.php` y su job existente;
no afirmar que su recibo inicial confirma toda la verificación comercial final.
Faltan registrarInformativa/InteraccionRuta y la confirmación posterior, grabaciones,
transferencias sin reserva temprana, regresión visual, Supervisión selectiva,
publicador y aceptaciones universales. No se ha modificado producción.

### Sexto checkpoint: interacciones informativas de ruta avanzada

El quinto checkpoint está publicado en `ade22596`. Se extiende el mismo ciclo
de aprobación a `seguimientoInteraccion.registrarInformativa`:

- InteraccionRutaService usa la conexión común e inyectable; el callback del
  recibo ocurre antes del COMMIT nativo. Mantiene resultado/notas/programación
  originales y no cambia la etapa ni reuniones. Compara el contacto actual
  bajo lock para no sobrescribir datos cambiados mientras se validaba la captura.
- Tipo de adaptador explícito INFORMATIVA. Permisos/CSRF se validan en la ruta;
  los errores mantienen su código comercial. La lectura de recibo recupera su
  envelope nativo, sin reenviar automáticamente el resultado.
- El guard de identidad PBX se extrajo a TelefoniaResultadoAnalistaService y
  lo comparten el modelo inicial e InteraccionRuta. Dos rutas no pueden insertar
  dos resultados para la misma referencia PBX y usuario. No se tocaron históricos.
- El adaptador JS usa el controlador común sólo para llamadas ZADARMA con
  referencia, conserva otros canales y evita callbacks sobre otro formulario.
  Libera controles antes de su reset para que Guardar no quede deshabilitado al
  cambiar sus ids. No se cambió HTML/CSS ni diseño institucional.
- Pruebas:8 SQL nativas de ruta avanzada (ruta/acción futura preservadas, rollback,
  replay y exclusión entre rutas),17 HTTP con CSRF/ACK perdido/recibo y12 del
  adaptador JS real.20/20 suites aisladas pasan;10 checks SQL del guardado inicial
  pasan después de extraer el guard común. No se cuentan como aceptación universal.
- El fixture prepara únicamente la definición de oficios_vinculacion, sin filas
  de dumps, para consultar la ruta avanzada. El modo HTTP `followup_receipt.cjs
  --informative --lab-root <raíz>` reutiliza el harness del checkpoint anterior.

Próximo bloque: conciliación posterior en vincular_interaccion_zadarma.php,
eliminando atribuciones de duración desde el navegador y cerrando su recuperación
con evidencia propia; después transferencias/audio y aceptación completa. Siguen
sin iniciarse las ocho horas reales. No se ha modificado producción.
