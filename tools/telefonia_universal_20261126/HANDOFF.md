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
