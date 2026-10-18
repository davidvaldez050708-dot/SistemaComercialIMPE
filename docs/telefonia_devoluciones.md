# Devoluciones integradas en telefonía

## Componentes compartidos

Llamadas recibidas y Llamadas por devolver renderizan `app/views/telefonia/resultado_analista_form.php` y ejecutan `seguimiento_entrantes_analista.js`. Comparten estructura de modal, controles, búsqueda, borradores, validaciones, contacto referido, próxima acción y evidencia explícita. La devolución aporta únicamente la llamada propia comprobada y su identificador de intento. El alta y la interacción utilizan `TelefoniaEntrantesAnalistaService::registrarAtencionEnExpediente` dentro de la transacción del intento.

La vista de devoluciones reutiliza los componentes del historial, tarjetas, insignias, acciones compactas, estadísticas y paginación de Llamadas recibidas, así como formularios y filtros del dashboard. El antiguo CSS específico queda sin uso. No se añade ninguna regla de z-index para corregir las superposiciones.

## Sincronización de formulario y teléfono

El shell conserva por separado la devolución pendiente y la posesión temporal de su formulario. No dependen del heartbeat ni de `minimized`. Al finalizar la devolución se oculta el teléfono; abrir el registro reclama la superficie mediante `beginCallbackResult`, y cerrar la libera mediante `endCallbackResult`. Mientras está pendiente, no aparece el teléfono finalizado ni el aviso de reconexión encima del registro. Sólo un guardado confirmado de la página CRM asignada elimina el pendiente. Un mensaje ajeno no lo elimina.

Una llamada entrante interrumpe el formulario y conserva su borrador; la animación de Bootstrap se completa antes de retirarlo si aún estaba abriéndose. El usuario puede retomarlo después. La confirmación de un resultado anterior nunca envía CLEAR_FINISHED a una llamada nueva activa.

## Instituciones y resultado

- Existente: selecciona un expediente propio activo; guarda la interacción sin retroceder su etapa ni eliminar una próxima acción futura.
- Identificada sin expediente: conserva nombre y datos de resultado; no crea expediente ni acredita verificación.
- Nueva: permite el alta explícita con territorio propio, tipo y datos originales del analista. Rechaza resultados negativos y nombres duplicados en la misma cartera y estado. Las altas se serializan por usuario, también entre recibidas y devoluciones.
- Sin identificar: conserva el resultado del intento sin inventar una institución.

Cada intento es definitivo, ligado a una saliente propia posterior a la preparación, con número, cierre y duración comprobados. Buzón, sin respuesta, fuera de servicio, ocupado y llamar después conservan el pendiente; número incorrecto y resultados de conversación resuelta lo cierran. Llamar después exige una fecha futura. Verificación efectiva sólo existe con evidencia explícita, persona informante y conversación confirmada; mantiene como máximo una institución por analista y día.

Los datos ampliados quedan en `telefonia_devolucion_intentos.datos_json`. El servicio agrega esa columna únicamente si falta; no reescribe resultados ni llamadas históricos.

## Transferencia directa: hechos separados

| Hecho | Estado | Evidencia |
| --- | --- | --- |
| Analista acepta disponibilidad | ACEPTADA | Confirmación en CRM; caduca, no es una llamada |
| Se solicita envío | ENVIANDO / SOLICITADA | Ruta autorizada y operación única |
| Recepción sale | ENVIADA | Liberación del teléfono de origen; no acredita respuesta |
| Analista contesta | ENTREGADA / TRANSFERIDA | NOTIFY_ANSWER del destino o fin firmado contestado con duración positiva, sobre la referencia y extensión exactas |
| Destino termina sin respuesta | SIN_RESPUESTA | NOTIFY_END del destino sin respuesta; no se supone retorno |
| Faltan eventos por más de 120 s | SIN_CONFIRMAR | Incertidumbre; no se presenta como perdida o atendida |
| La llamada vuelve a recepción | DEVUELTA | NOTIFY_INTERNAL de origen con transfer_from del destino |

La aceptación de disponibilidad y los mensajes del navegador no acreditan respuesta directa. Un fin de la extensión 100 no acredita respuesta de la 101. La operación se sigue conciliando aunque recepción ya haya salido.

Con SIN_RESPUESTA confirmado y teléfono válido, la disponibilidad correspondiente crea una sola devolución para el analista. Éste recibe su aviso en campana con acceso al listado y Llamar; recepción recibe Transferencia no contestada y debe coordinar su devolución. El analista no tiene que registrar resultado sobre un intento perdido; sólo sobre una devolución que efectivamente marque. Sin teléfono válido se conserva el aviso para coordinar la identificación.

SIN_CONFIRMAR genera avisos a ambos para coordinar y revisar el historial, sin crear automáticamente una devolución ni asegurar que el cliente regresó. Una respuesta firmada tardía retira los avisos de incertidumbre. No se llama automáticamente al cliente. El retorno y cualquier desvío dependen de eventos reales y de la configuración de la centralita; esta entrega no modifica la centralita.

## Pruebas

- `telefonia_atencion_db.php`: base MySQL desechable, permisos, referencias propias, atomicidad, resultados definitivos, alta y duplicados, datos y evidencias, notificaciones, envío sin respuesta y confirmación tardía.
- `telefonia_devoluciones_integracion_browser.cjs`: shell, iframe, Bootstrap y ambos scripts reales con red/telefonía simuladas; ausencia de superposición, caducidad de heartbeat, limpieza de fondo, error de guardado, interrupción por entrante, borrador, reintento y móvil.
- `telefonia_devoluciones_browser.cjs`: formulario compartido, modalidades de institución, filtros y paginación.
- Regresiones: atención original en cuatro tamaños, recibidas, salientes, transferencia directa rápida y consultada heredada, campanas, SDK y acceso a grabaciones.

Los escenarios automatizados no sustituyen la comprobación final del audio y los webhooks reales en Hostinger.
