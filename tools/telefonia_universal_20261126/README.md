Estado vigente: leer el último checkpoint de HANDOFF.md y VALIDATION.json.
OUTGOING_1000.json documenta1,000 ciclos salientes HTTP/SQL sobre la versión
congelada indicada; no acredita transferencias/audio universales ni ocho horas.
El laboratorio nuevo se prepara con lab/prepare.py; si sus servicios terminan,
lab/resume.py conserva sus datos existentes y no ejecuta migraciones.

# Telefonía universal — trabajo en curso, no instalar

Para continuar con otro perfil, leer primero [HANDOFF.md](HANDOFF.md): contiene
el estado de la integración, límites de las pruebas y pendientes concretos.

La base instalada de Hostinger se conserva en el commit local
`1d8f7da23907fabce398c13d924ef5996d839473`. Las correcciones se preparan
en `codex/telefonia-universal-20261126`, aisladas de la instalación de XAMPP.

Todavía no hay un publicador completo con CHECK, respaldo y ROLLBACK.
Los archivos de esquema son propuestas explícitas y sólo se han ejecutado
contra un laboratorio sintético. No deben ejecutarse en producción.

## Correcciones preparadas

- Autoridad del host y descarte de respuestas antiguas; el fin PBX remoto
  no ejecuta un hangup sobre una sesión SDK viva.
- Transporte HTTP común con plazo completo, consultas GET compartidas,
  cancelación por llamada y protección de identidad de usuario.
- Sesiones PHP liberadas antes de las consultas lentas autorizadas.
- Reductor de segmentos PBX común para transferencias y llamadas comerciales.
- Borradores durables con revisiones, fusión de campos y protección ante ACK
  antiguo; los formularios conservan la captura si falta evidencia.
- La unión local de alias conserva los campos aportados por ambos formularios,
  detecta conflictos de revisión y conserva los recibos de aplicación.
- El shell conserva el cierre de la atención mediante la misma función para los
  tres perfiles. Recepción delega en ella. Los grupos de alias explícitos se
  conservan al editar o cerrar mediante una sola referencia.
- Recepción conserva varias llamadas pendientes de PBX en el almacén común.
  Ventas no descarta avisos por superar siete días o cuarenta llamadas.
- Analista restaura las confirmaciones sólo después de consultar las reglas
  actuales; una respuesta tardía no reemplaza cambios del usuario.
- Adaptador común de sesiones SDK sobre la tabla histórica existente.
- Cola durable de observaciones SDK, con reintento ordenado y ACK exacto.
  La preparación saliente y sus eventos SDK ya usan esa cola en el host.
  En SDK2 el host es el único escritor entrante; se conserva el protocolo legacy
  únicamente cuando fue negociado para esa sesión.
- Transporte PHP del proveedor con plazos, TLS, caché exacta y presupuesto
  compartido. No modifica el SDK ni crea sondeos.
- Resultados, contactos y marcaciones de Ventas no crean tablas al construir
  sus servicios. El esquema y las tablas de selección del catálogo se preparan
  exclusivamente mediante el migrador CLI explícito.
- El login se difiere durante una sesión local viva y el cierre se notifica
  antes de navegar. Las transferencias admiten el reemplazo autorizado de
  RTCSession y rechazan callbacks de diálogos anteriores.
- El historial no consolida filas por teléfono y cercanía temporal.
  Una búsqueda saliente ambigua no elige el último registro ni usa un
  fallback más débil para resolver la ambigüedad.

## Validación y límites

Las pruebas unitarias portables se ejecutan con Node.js, sin dependencias:

```bash
node tools/telefonia_universal_20261126/run_unit.cjs
```

Cubren23 suites de capturas, alias, ACK, sincronización, restauración,
pendientes, carreras al marcar/colgar, registro entrante/saliente del host y
cola de vinculación exacta de transferencias.
No abren conexiones ni llamadas.
El laboratorio HTTP/SQL y las pruebas de navegador se mantienen separados.

`integration/prepare_sales.php` y `integration/sales_native_sql.php` necesitan
el laboratorio privado existente indicado por `IMPE_PHONE_LAB_ROOT`. Ambos
rechazan ejecución web y cualquier destino distinto de `127.0.0.1:3308`, base
`impe_telefonia_lab`. El primero prepara únicamente ese fixture; el segundo usa
un usuario sin privilegios de esquema y prueba los servicios comerciales nativos.
lab/prepare.py prepara el laboratorio reproducible. Existen regresiones nativas
del embudo; falta su aceptación universal con todos los flujos.

Se ejecutan pruebas sintéticas puras, HTTP y SQL con tres perfiles, además
de la reproducción de eventos de las transferencias 87, 88 y 89 facilitados
mediante un diagnóstico de sólo lectura. No se modifican llamadas reales,
grabaciones, interacciones, expedientes ni asignaciones productivas.

Las 1,000 reducciones locales y las 1,000 capturas SQL probadas por separado
no equivalen a 1,000 llamadas completas. La prueba de ocho horas continuas
todavía no ha comenzado. Falta completar la integración del host, la captura
universal, la conciliación comercial, regresiones y el publicador selectivo.
No se ha publicado este candidato en producción ni se han ejecutado sus
migraciones productivas.
