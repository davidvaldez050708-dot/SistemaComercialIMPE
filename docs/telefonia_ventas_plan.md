# Telefonía comercial: auditoría y etapas

Base examinada: `main` `59d5eb6656a7a0a2b6b5ff16db9ff4c691c3bc34` (PR #93–97 incorporados). No se cambia SIP, credenciales, extensiones ni permisos existentes.

## Auditoría técnica

| Componente | Existe | Brecha comprobada |
|---|---|---|
| HomeController / asesor / marcador | Mi teléfono, agenda, estadísticas y salientes con token previo | Historial extenso duplicado en Inicio; resultado sólo saliente y sin nota |
| TelefoniaExtensionService | Asignación real y capacidades efectivas; sin fallback comercial a 100 | Conservar; no habilitar a usuarios 9/15 sin asignación |
| shell / persistente / topbar | Un motor WebRTC, latidos, lease, marcador compartido y controles entrantes | Auricular depende de GET secundario de asignación aun con shell listo; estado no siempre sincronizado |
| Enrutamiento / disponibilidad / transferencia | Directorio territorial autorizado, aceptación 45 s y reserva 30 s; prospecto obligatorio desde #96 | Conservar operaciones; receptor comercial no tiene contexto ni registro final propio |
| TransferenciaContextoService | Identidad por referencia exacta desde #90 | Su contrato es institucional; Ventas no debe consumir su seguimiento_id |
| MarcacionesVentas / Actividad | Propiedad saliente por token + evento PBX; clasificación humana | Conciliación actual escoge primera coincidencia temporal; historial omite entrantes y depende de extensión actual |
| ResultadoVentas | Tabla comercial independiente y CSRF | Sólo out_* y NOTIFY_OUT_END, upsert editable, sin observaciones/contacto ni gestión entrante |
| MisLlamadas / Control | Atribución, filtros, paginación y bandeja común | Ventas sólo tiene vista de lectura; no operación comercial de devoluciones/resultados |
| Contactos | Agenda personal con normalización y único usuario/teléfono | Sólo nombre/teléfono; falta edición por ID, correo, notas y programación |
| DevolucionService | Intentos, PBX exacto, cierre y reintento | La presentación y el registro usan instituciones y Vinculación: no deben trasladarse a Ventas |
| NotificacionService / Reminder | Campana compartida, lectura por usuario, deduplicación y cadencia de ocho minutos | Asesor recibe URLs recibidas/devoluciones no autorizadas; perdidas se consultan desde servicio del Analista |
| Grabación ventas / ZadarmaRecording | Transmisión privada y clasificación humana | Sólo salientes; consulta por PBX completo puede mezclar segmentos si se reutiliza sin validar extensión |
| EntrantesAnalista / RecepcionMarketing | Formularios maduros y agrupación PBX probada | Reutilizar componentes visuales y agrupadores puros, nunca expedientes o verificaciones institucionales para Ventas |

Se revisaron endpoints del controlador, permisos, webhooks almacenados, contratos WebRTC, vistas, CSS compartido y pruebas existentes. Los eventos PBX y el resultado declarado por una persona son evidencias distintas. Un transporte simulado no acredita una llamada real en Zadarma.

## Plan integral y dependencias

1. **A — Estado de recepción:** sincronización con el shell autenticado, latido vigente y controles existentes. Sin migración.
2. **B — Atención comercial:** propiedad histórica de tramos entrantes/salientes, contexto comercial y resultado definitivo, separado de Vinculación. Depende de A; migración aditiva explícita antes de usar almacenamiento nuevo.
3. **C — Bandeja y audio:** operación comercial de Mis llamadas, filtros, detalles, segmentos verificados y resumen compacto en Inicio. Depende de B.
4. **D — Agenda y devoluciones:** ampliar agenda personal, devolver desde una asignación comercial y cerrar/reintentar con el resultado real. Depende de B/C.
5. **E — Avisos y validación:** campana/toast compartidos con destinos comerciales, conciliación idempotente y pruebas integrales. Depende de B/C/D.

Cada etapa tendrá su PR y validaciones. No se integran ni publican automáticamente. Los PR dependientes se enlazan para revisar sólo su incremento sobre la fase previa, partiendo del main indicado; antes de integrar se deben comprobar dependencias y Actions.

## Publicación

Publicadores selectivos con versión fija, `--check`, `--apply`, `--restore`, respaldo privado y rechazo de JS desconocido o parche incompatible. No ejecutar `git pull` en public_html. Las migraciones nuevas se preparan y validan de forma explícita; no se ejecutan al abrir una vista ni automáticamente en producción. Python, cuando se utilice en el servidor, debe ser compatible con 3.6.

## Validación de A

Sintaxis de PHP/JavaScript, pruebas existentes del shell y navegación, y navegador real con shell/cliente persistente: consulta HTTP secundaria fallida, extensión 150, apertura del marcador y expiración del latido. Sin comunicación con Zadarma. Pendiente probar conexión, desconexión y navegación con la cuenta real del asesor en Hostinger tras publicación autorizada.
