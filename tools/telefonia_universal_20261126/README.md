# Telefonía universal — trabajo en curso, no instalar

La base instalada de Hostinger se conserva en el commit local
`1d8f7da23907fabce398c13d924ef5996d839473`. Las correcciones se preparan
en `codex/telefonia-universal-20261126`, aisladas de la instalación de XAMPP.

Todavía no hay un publicador completo con CHECK, respaldo y ROLLBACK.
Los dos archivos de esquema son propuestas aditivas y sólo se han ejecutado
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
- Adaptador común de sesiones SDK sobre la tabla histórica existente.
- Cola durable de observaciones SDK, con reintento ordenado y ACK exacto.
  **Aún no está conectada al ciclo completo del host.**
- Transporte PHP del proveedor con plazos, TLS, caché exacta y presupuesto
  compartido. No modifica el SDK ni crea sondeos.
- El login se difiere durante una sesión local viva y el cierre se notifica
  antes de navegar. Las transferencias admiten el reemplazo autorizado de
  RTCSession y rechazan callbacks de diálogos anteriores.
- El historial no consolida filas por teléfono y cercanía temporal.
  Una búsqueda saliente ambigua no elige el último registro ni usa un
  fallback más débil para resolver la ambigüedad.

## Validación y límites

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
