> Relevo actualizado: leer el decimocuarto checkpoint al final, VALIDATION.json y
> OUTGOING_1000.json. Los1,000 ciclos son salientes sintéticos; la aceptación
> universal y las ocho horas reales siguen pendientes. Producción intacta.

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

### Séptimo checkpoint: conciliación posterior, duración y audio propios

El sexto checkpoint está publicado en `20a4a4be`. Se conserva la ruta instalada
`vincular_interaccion_zadarma.php`, cerrando vías de atribución incorrecta:

- Reutiliza conexión común, asignación actual, datos nativos de interacción y
  TelefoniaPbxSegmentosService. No usa duración del navegador ni busca otra
  llamada por teléfono cuando falta el identificador. Protocolo2 manda la sesión
  preparada explícita y CSRF fresco; el servidor deriva su referencia y rechaza
  otra sesión/PBX/interacción. Los trabajos antiguos no se convierten en SDK2
  sólo porque su token comience por sdk-. Log histórico queda sólo para legacy.
- Duración ausente queda NULL y conserva la verificación pendiente. Cero PBX
  acreditado queda cero y no produce efectiva ni reintentos indefinidos de esa
  verificación. Una duración positiva posterior confirma las dos evidencias y
  suma exactamente una efectiva usando el indicador nativo; repetir no suma otra.
  No se modifica el resultado humano ni se sustituye un dato técnico anterior
  por desconocido al repetir. Los rechazos revierten y liberan la transacción.
- Las fechas sin zona del almacén local se interpretan como México incluso si
  PHP tiene UTC predeterminado; ISO con zona se convierte una sola vez. No se
  fabrica una fecha final a partir del cronómetro ni de la hora actual del CDR.
- El selector CDR admite extensión explícita y rechaza filas contradictorias,
  ids de grabación compartidos/ajenos, duración inválida y páginas truncadas.
  Entrantes Analista y estado saliente pasan su extensión. IDs decimales largos
  siguen como strings. Varias grabaciones propias se devuelven como segmentos;
  el campo antiguo de grabación única queda vacío cuando no es inequívoco.
- La cola técnica conserva el ID nativo de la interacción y sólo entrega metadata,
  nunca reenvía el alta comercial. Evidencia pendiente persiste entre recargas
  con backoff de1 a15min sobre el timer ya existente, sin añadir sondeos. Se evita
  la cascada inmediata de hasta24 solicitudes en SDK2 y los toasts repetidos.
  La operación local sigue liberada; un ACK antiguo no cambia la llamada activa.
- Evidencia:21/21 suites JS (incluye21 checks del reconciliador real),10 checks
  puros del selector CDR y6 de fechas reales con PHP predeterminado UTC. HTTP:
  39 Seguimiento con evidencia tardía,40 Informativa equivalente,33 con cero PBX.
  Son variantes parciales del harness, NO sumar sus asserts como llamadas.
  Las pruebas usan SDK sintético y la ruta HTTP/SQL/comercial real del candidato.
- Fuentes del laboratorio sincronizadas, hash
  `3848e9245c8728a6b8831df5b32bb864694671073000f84578b45a02a60eb182`.
  `lab/followup_receipt.cjs --pending-duration [--informative] --lab-root <raíz>`
  reproduce la llegada tardía; `--zero-duration` cubre cero acreditado. Los tests
  puros son `tests/lookup_exact_pbx.php` y `tests/technical_dates.php`.

Contrato contrastado con [API oficial de Zadarma](https://zadarma.com/en/support/api/):
pbx_call_id conserva el grupo externo, call_id identifica una grabación, estadísticas
tienen límite/paginación y el campo internal de NOTIFY_OUT_END es opcional.
No interpretar ausencia de internal como prueba de conversación propia. **Siguiente
bloque:** cierre global sin internal en el motor común, preservando duración
desconocida/propiedad; timezone real de la cuenta CDR y paginación segura siguen
pendientes de contraste/cobertura. La zona local del almacén sí se probó; no afirmar
que se verificó la configuración real de la cuenta o TLS de Hostinger.

También falta recuperación técnica desde el servidor si desaparece toda la cola
del navegador (las evidencias/resultados SQL sobreviven); aprobación diferida ante
PBX tardía para todos los roles; integración entrante/transferencias/audio completo,
regresión visual, Supervisión selectiva,1,000 llamadas universales,8h reales y
publicador. No se ha modificado producción ni arrancado el soak de ocho horas.


## Octavo checkpoint — recibo nativo independiente de la llamada actual

Base: `bdc46e8c`. Seguimiento inicial e Informativa capturan la identidad PBX,
SDK y expediente ANTES del await y entregan el ID nativo mediante onCommitted
al mismo controlador común. La cola técnica se conserva aunque el ACK llegue
tras abrir otro expediente o empezar otra llamada. No se infiere el ID por DOM,
fecha, orden del historial ni último resultado del expediente. El puente legacy
ya no reemplaza un interaccion_id explícito ni rellena uno ausente por ese mapa.

La limpieza de las reglas SDK2 exige el token de su captura; los eventos de
presentación no pueden asignarse a la llamada siguiente. El observador de toast
y los manejadores legacy dejan de inferir asociaciones SDK2. Los controles del
formulario inicial se restauran antes de retirar sus campos telefónicos.
Recepción/Ventas siguen usando el mismo controlador; no cambian sus permisos.

Validación:23/23 suites JS,19 checks de la función real de entrega del ID,
3 del puente real,17 del adaptador inicial y14 del avanzado, incluidos ACK tardío,
formulario cambiado, sobreescritura rechazada y restauración de controles.
Sintaxis PHP de la vista y cuatro scripts correctos. HTTP/SQL no se repitió:
este bloque no modifica contratos ni código servidor. Son pruebas parciales con
HTTP simulado; NO equivalen a1,000 llamadas ni ocho horas de estabilidad real.

Revisión dirigida: fetchCallState del host YA envía session_id; no duplicar ese
cambio. La documentación distingue NOTIFY_END por extensión y NOTIFY_OUT_END
saliente con internal opcional. NO tratar un END sin extensión como fin probado
de todos los tramos: sigue pendiente resolverlo por evidencia inequívoca de tramo
(o CDR exacto) y probarlo con transferencias, sin inventar duración ni audio.
Los demás pendientes del checkpoint7 continúan. Producción intacta.


## Noveno checkpoint — catálogo CDR único para cierre y audio

Base:`b0b15622`. Nuevo TelefoniaPbxEstadisticasService usado por
ZadarmaCallLookupService y ZadarmaRecordingService: entrada/salida de fechas en
México, conversión a la zona declarada en GET /v1/info/timezone/ y contraste de
su datetime/unixtime. No adivinar UTC ni restar seis horas. La lectura de zona
es perezosa y se conserva una hora en la caché privada por cuenta del transporte
existente; no agrega timer ni conexión SQL. NO se consultó la cuenta real.

El catálogo pide skip/limit y sólo entrega resultados tras la página terminal.
Máximo3 páginas por consulta, dentro del presupuesto/plazo compartidos; página
que no avanza, respuesta truncada/inválida o fecha fuera de ventana dejan la
evidencia pendiente. No escoger un audio con una primera página incompleta.
Los IDs opacos largos se conservan. La consulta por PBX in_ utiliza call_type=in;
antes estaba fijada a out. Ventas/cierre conserva el filtro saliente y audio
transferido consulta ambos tipos con sus comprobaciones de propiedad existentes.

TelefoniaVentasEvidenciaService conserva sus permisos/cooldown/cache y ahora
pasa ambos límites de la ventana en México (antes convertía sólo el extremo
final a UTC). Admite un catálogo completo mayor de1,000 filas; el máximo de8
segmentos propios y el rechazo de IDs ajenos/compartidos siguen vigentes.
El catálogo admite ventanas de hasta48h para esta consulta existente. No se
fabrican webhooks, cierres, resultados ni interacciones a partir de la caché.

Validación:19 checks del catálogo,14 del selector PBX,11 del transporte/caché.
5 checks SQL del servicio de evidencia real con catálogo sintético paginado de
1,001 filas, ID de audio decimal largo, cooldown durable y ausencia de resultado
comercial. Se ejecutó contra la DB aislada con fuentes candidatas, sin cambiar
el código congelado del ensayo HTTP saliente que estaba en marcha. La tabla de
caché de audio faltaba en el laboratorio: schema_audio_evidencias.sql define el
contrato mínimo de prueba y prepare_audio.php lo crea EXPLÍCITAMENTE sólo en ese
fixture. NO afirmar que ese DDL coincide con Hostinger: el futuro CHECK debe
contrastar su esquema real y cualquier migración sería un paso separado.

Prueba reproducible:tests/provider_statistics.php,tests/lookup_exact_pbx.php,
tests/provider_cache.php e integration/audio_catalog_sql.php. lab/prepare.py y
run_sql.py incluyen la preparación explícita de audio. La descarga binaria,
Range, fallos de audio y grabaciones reales siguen pendientes. Documentación
primaria contrastada:https://zadarma.com/en/support/api/ (zona, skip/limit y tipos).
No se modificó producción. Continúan los demás pendientes del checkpoint8.


## Décimo checkpoint — mismo reductor en salientes y validación del Analista

Base:`aba2c092`. El endpoint zadarma_estado_llamada.php ya usa
TelefoniaPbxSegmentosService::hechos, igual que Ventas, atención entrante y
transferencias. Se retira su interpretación paralela de ANSWER/END/RECORD y el
cálculo de duración por diferencia de relojes, que convertía cero/desconocido en
conversación y podía tomar respuesta/audio de otra extensión del mismo PBX.
El DTO conserva sus campos y añade duration_known y segmentos_grabacion;
duration desconocida es NULL. La grabación única sólo existe si es inequívoca.
El fallback CDR exacto conserva las comprobaciones de propiedad del catálogo.

TelefoniaResultadoAnalistaService también usa el reductor común para sus reglas
comerciales. Sigue exigiendo expediente/usuario/extensión y teléfono autorizado,
resultado humano y confirmaciones; una conexión no acredita una efectiva sola.
Vincular metadata acepta la proyección de cierre del mismo reductor, sin crear
un webhook artificial ni copiar la duración de otro participante.

NOTIFY_OUT_END sin internal se admite sólo con PBX y call_start exactos, un solo
OUT_START propio y ningún otro participante/arista de transferencia en los
eventos de control del grupo. No se usa teléfono ni proximidad horaria para
inferir ese cierre. Se conserva duración desconocida y no se atribuye su audio
anónimo. Los cierres entrantes anónimos o transferencias ambiguas siguen
pendientes de prueba inequívoca/CDR; NO finalizan todos los tramos. Los avisos
RECORD afectan propiedad de audio, sin inventar participantes de una llamada.

Validación:19 casos puros del reductor; HTTP43 Seguimiento con cierre sin
extensión,44 equivalente avanzado y35 con ANSWER/END/audio de otra extensión.
La duración anónima900 no sustituye NULL; sólo END propio posterior de1s confirma
las evidencias y suma una efectiva nativa. Regresiones SQL:6 entrantes,18 Ventas,
10 Seguimiento,8 Informativa,13 devolución.23/23 suites JS y15 ciclos salientes
HTTP adicionales de los tres roles. Sin proveedor ni grabaciones reales.

### Carga saliente de1,000 ciclos concluida (versión anterior congelada)

OUTGOING_1000.json contiene el resumen verificable del ensayo sobre b0b15622,
fuentes05b8ab2d0351da2561504b456bbbf1b9472d6edb7d1234d6462986f81605218c:
334 Ventas,333 Vinculación,333 Recepción,682.7274319 segundos monotónicos,
144 acuses SDK perdidos y201 acuses comerciales perdidos recuperados. Cada ciclo
pasó host/journal→HTTP/auth/CSRF→SQL→borrador→servicio comercial y verificó un
solo registro nativo. Se verificaron los548 archivos congelados al terminar.
No atribuir este ensayo al código posterior: los cambios CDR/cierre tienen sus
pruebas específicas y deben incluirse en la aceptación final. El harness ahora
valida fuentes al inicio/fin y guarda su hash en el reporte automáticamente.
NO son1,000 llamadas universales con transferencias/audio ni ocho horas.

Después del ensayo se detectaron detenidos MySQL y los tres workers de la sesión
local. No se atribuye causa al producto ni se afirma continuidad: lab/resume.py
reanuda EXCLUSIVAMENTE la DB sintética existente, sin inicializarla/migrarla,
y arranca sus workers. Las pruebas nuevas pasaron tras recuperar esos servicios.
La prueba de ocho horas REALES todavía no comenzó.

**Siguiente bloque:** entrega diferida de comandos comerciales ya aprobados ante
PBX tardía, recuperación técnica desde servidor sin cola local, transferencias y
entrantes completos por HTTP/audio/rangos/fallos, retirar bloqueos legacy de
forma selectiva, regresión visual3roles, Supervisión selectiva, aceptación
universal1,000 y8h, contraste Hostinger y publicador CHECK/respaldo/ROLLBACK.
Nada de este trabajo se publicó en producción. No hay publicador universal
terminado; no reutilizar paquetes viejos para instalar esta intervención.


## Undécimo checkpoint — restaurar conciliaciones desde recibos del servidor

Base:`48d72478`. Al abrir Seguimiento se lee una pasada acotada en el endpoint
existente borrador_llamada.php?conciliaciones=1. No se añade un timer ni un sondeo
periódico. Páginas de20 y máximo10 por pasada; IMPE_ZADARMA_LINKS.restore permite
repetir explícitamente. La consulta usa la conexión compartida existente.

Sólo devuelve IDs de interacciones salientes modernas de SEGUIMIENTO/INFORMATIVA
con recibo durable de ese ID y alguna evidencia PENDIENTE (una o dos
confirmaciones), expediente activo aún propio e inicio PBX de la extensión/
asignación actual. Relee permisos: Analista y operar_propios/salientes. No
expone teléfonos, notas, nombres ni respuestas guardadas. Una fila manual/legacy
sin recibo, recibo de otro ID, otro usuario o expediente reasignado se excluyen.

El lector repone trabajos técnicos en la cola ya existente cuando desapareció
todo el almacenamiento local. Conserva el SDK/backoff/feedback de un trabajo que
ya estaba en ella. La recuperación por PBX exacto no inventa una sesión SDK,
no reenvía el alta comercial ni cierra/borra un borrador nuevo. La llamada
activa permanece aislada. Los reintentos usan la cola existente, sin cascadas
inmediatas por no tener SDK. El POST técnico valida un CSRF suministrado también
cuando no hay session_id; la compatibilidad antigua sin token no cambia aquí.
No afirmar que se eliminó toda esa compatibilidad de producción.

Validación:24/24 suites JS (19 checks del lector real y25 del reconciliador).
HTTP49 Seguimiento y50 Informativa con --server-recovery: borra el storage,
recupera ID nativo/PBX desde servidor, comprueba401/403/cursor, entrega sólo
metadata y confirma después la evidencia, una sola efectiva y sin duplicar
interacciones.9 checks SQL de recibos/propiedad/confirmación parcial/cursor y
rechazo de recibos ausentes o de otro ID. Mutaciones sintéticas revertidas;
el método también se ejecutó completo en READ ONLY. Sintaxis de3 PHP y JS
correcta. Fuentes del laboratorio:
67f080dc6012e2e57384ff2c21d3a0aeb92dd636590a78ebdaa56f9d9bfde080.

Reproducir:lab/followup_receipt.cjs --server-recovery [--informative] --lab-root
<raíz>; después integration/server_recovery_sql.php con IMPE_PHONE_LAB_ROOT.
El test SQL requiere esas filas sintéticas modernas y no está en run_sql.py,
para no depender silenciosamente de un ensayo HTTP previo en el runner básico.

**Límite de alcance:** no restaura resultados sin ninguna evidencia pendiente,
ni las atenciones entrantes/SDK/otras clases de recibo. No es entrega diferida
de comandos comerciales aún no aplicados: eso sigue siendo el próximo bloque.
Continúan pendientes integración universal entrantes/transferencias/audio,
regresión visual, Supervisión selectiva, aceptación universal1,000,8h reales y
publicador Hostinger con CHECK/respaldo/ROLLBACK. Producción sin cambios.


## Duodécimo checkpoint — Recepción usa los hechos del mismo motor

Base:`7d221355`. TelefoniaRecepcionMarketingService conserva la identidad inicial
por extensión/periodo y el DTO institucional, pero retira su intérprete paralelo
de ANSWER/END/RECORD. Agrupa por PBX exacto en una sola pasada y utiliza
TelefoniaPbxSegmentosService::hechos, como Ventas, Vinculación y transferencias.
La consulta existente añade id/call_start para las mismas cercas; no añade
consultas/conexiones. Se elimina la asociación de respuesta anónima por sufijo
del destino. Los avisos de grabación ajenos dejan de anunciarse como propios.

El motor expone transferida_a/tramo_devuelto. Preserva la entrega explícita y el
último participante autenticado de una saliente, sin afirmar que ese destino
contestó. Un retorno no hereda la respuesta del tramo previo: requiere su propio
ANSWER para pedir/aceptar un resultado de conversación. La duración y fecha de
fin del grupo no se atribuyen al origen tras transferir; una duración de grupo
devuelto tampoco se transforma en duración de su tramo actual. Esto deja la
evidencia desconocida hasta una comprobación propia/CDR exacta.

Cero SQL con duration ausente del payload ahora es desconocido, no un cero
acreditado. Valores negativos, no enteros o mayores de24h no acreditan duración.
El cero explícito propio se conserva. La conciliación usa el end_at del motor,
no el END padre; cuando falta evidencia no borra datos técnicos históricos ya
asociados. Recepción muestra un guion para duración desconocida o inválida,
conservando el formato de minutos/horas y el diseño.

Validación:18 casos de la proyección REAL de Recepción (sin SQL/proveedor),
11 del formateador REAL y25/25 suites JS. El runner nativo completo pasó sus17
scripts, incluidos resultados/recibos de3roles, Seguimiento/Informativa, devoluciones,
embudo V1/V3, sucesores/transferencias/captura canónica y audio CDR sintético.
15 ciclos salientes HTTP adicionales con fuentes congeladas:
c940250d4d4e691377f5895394d7555cd2ad8c1817b7627bcca9e4808111a270.
También19 casos de cierre y6 de fechas. No sustituir estas verificaciones por la
aceptación universal/soak pendientes. `integration/reception_projection.php` se
incluye en run_sql.py aunque su proyección es pura y no consulta MySQL.

**Hallazgo para el siguiente bloque:** grabacion_recepcion_marketing.php todavía
invoca obtenerGrabacionParaLlamada, que admite un primer link de grupo PBX.
La autorización del historial es necesaria pero no sustituye seleccionar un
call_id propio inequívoco. Resolver esto y las múltiples grabaciones antes de
considerar audio universal terminado. No se amplió ningún permiso de audio.
La entrega diferida de comandos aprobados todavía no está implementada;
siguen pendientes los demás puntos del checkpoint11 y el publicador. Producción
intacta y rama de Supervisión de personal sin fusionar indiscriminadamente.


## Decimotercer checkpoint — selección de audio estable y autorizada

Base:`1db5a390`. TelefoniaGrabacionSelector valida únicamente dentro del catálogo
que el servidor ya autorizó. Las referencias de grabación siguen siendo strings
opacos (incluidos decimales largos). El parámetro grabacion NO concede propiedad:
se contrasta en cada petición con los IDs propios del PBX/rol/asignación/ruta.
Una llegada tardía no puede cambiar de audio una selección por referencia.

Ventas y transferencias PBX devuelven referencia en segmentos_info y la vuelven
a verificar al reproducir/descargar. El reproductor común transporta esa misma
referencia a audio y descarga, conservando el índice para endpoints anteriores.
Un único audio permite la URL antigua sin selector; varios requieren referencia
o índice ligado al hash del catálogo. No elegir primero por defecto ni aceptar
un ordinal antiguo que pueda apuntar ahora a otra parte. Se soportan parciales
con índices dispersos y máximo8, con rechazo de IDs duplicados/ajenos/malformados.

Recepción usa el MISMO reproductor por segmentos para llamadas individuales y
transferidas. Se retira su segundo verificador/reproductor individual. Su endpoint
ya no llama obtenerGrabacionParaLlamada ni pide el primer link del grupo: sólo
obtenerGrabacionPorCallId para referencias propias. Reutiliza la conexión y
la autorización del historial actual; el lote PBX se filtra con el mismo corte
de periodo/asignación, sin consultas paralelas para esa autorización. Conserva
los filtros de resultado/sin contacto y las rutas de transferencia existentes.
Si hay aviso propio pero aún falta ID inequívoco, devuelve pendiente, sin pedir
un audio global como sustituto. No se añadió un sondeo ni se amplió rol/alcance.

Los resolutores de audio transferido añaden referencia desde el ID verificado
del servidor. El getter/SDK real se probó con transporte sintético: el decimal
largo llega intacto como call_id y no se convierte en petición por pbx_call_id.
No hay hooks de proveedor de prueba en el código de producción ni credenciales
reales del proveedor en el laboratorio.

Validación:26/26 suites JS,21 casos puros del selector,17 del reproductor/adaptador
reales con DOM sintético,21 de proyección/guardia de Recepción.21 checks HTTP
nativos de Recepción/Ventas:401/403, ID ajeno404, multigrabación sin selección409,
índice sin catálogo409, Range malformado416, política comercial de Ventas y
pendiente sin referencia. El502 esperado para referencia propia sólo demuestra
que el fixture bloquea proveedor/configuración reales: NO equivale a reproducir
audio positivo. Compatibilidad getter/SDK con transporte falso también pasó.
Sintaxis7PHP y2JS correcta. Fuentes del fixture:
1f50b8e6ebb22b0a4711fafb4d02f1d0fe298c5febbdc0a7e6e444f080d0f4d2.

Reproducir:tests/recording_selector.php,
tests/test_recording_reference_player.cjs, lab/audio_scope.cjs --lab-root <raíz>
y integration/provider_sdk.php con IMPE_PHONE_LAB_ROOT. El ensayo HTTP bloquea
el proveedor y genera sólo eventos/resultados sintéticos.

**Pendientes inmediatos de audio:** grabacion_interaccion.php todavía llama al
getter por grupo; grabacion_transferida_institucion.php conserva selección sólo
posicional. Resolver con propiedad histórica acreditable, sin trasladar permisos
entre personas ni elegir audios ambiguos. Falta descarga binaria positiva,
Range/seek, respuestas tardías/error y regresión visual/navegador completo.
Recepción sin ID propio inequívoco permanece pendiente; no se añadió fallback
CDR nuevo en este bloque. La interpretación de fecha de asignación del historial
de Recepción se conserva, no se corrigió aquí un supuesto desfase de esa fecha.
Siguen entrega comercial diferida, integración/aceptación universal1,000 y8h,
Supervisión selectiva y publicador. Producción intacta.


## Decimocuarto checkpoint — dependencias instaladas de Supervisión

Base: b7e7aac8. Se recuperan SupervisionLlamadasAudioService.php y
SupervisionVentasAudioCdrPolicy.php con los bytes originales de Git. El controlador
y repositorio instalados ya las requieren, pero faltaban en la copia aislada.
Se contrastaron ambas mediante lectura del conector Hostinger: contenido idéntico
al añadir el salto final que omite la respuesta, y tamaño declarado idéntico.
DEPENDENCIES.json registra origen, hashes locales y limitación: el hash bruto
productivo todavía requiere preflight. No se fusionó Supervisión de personal.

19 comprobaciones del laboratorio pasaron: carga, denegación de audio de
supervisión a los tres roles personales, política CDR existente y respuestas
básicas de audio/Range con bytes sintéticos. Estas clases se recuperan SIN
modificar su política o transporte. No prueba proveedor, reproducción real,
Range avanzado ni aceptación universal. Producción intacta. Los pendientes del
checkpoint13 continúan, incluida selección estable de audio en Supervisión.
