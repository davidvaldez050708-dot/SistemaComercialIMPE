# Fase C: Mis llamadas y audio comercial

Depende de A/B y de la migración comercial de B. Inicio conserva Mi teléfono y Mi agenda; el historial extenso y su clasificación se centralizan en Mis llamadas. Las estadísticas nuevas de Inicio se basan en los tramos propios, sin deducir conversación por ANSWERED.

La bandeja permite búsqueda por nombre/número, intervalo de hasta 90 días, dirección, transferidas, perdidas, sin conexión y pendientes; paginación de 20 filas y consulta del resultado definitivo. Los intentos sin PBX se conservan sin resultado ni audio inventados. Una transferencia enviada sin respuesta confirmada se muestra como operación sin confirmación, no como llamada atendida.

Los componentes son los de Seguimiento: tarjetas, botones, filtros de devoluciones, modal y reproductor compartido. El CSS del formulario se extiende a ambos IDs; no se duplica su diseño. El observador comercial sólo consulta la capa persistente y metadata autorizada: no inicia SIP, modifica disponibilidad ni envía DTMF.

Al concluir una llamada con atención, se conserva el pendiente y abre el formulario comercial. `beginCallResult` oculta la tarjeta finalizada, conserva el motor WebRTC y permite interrupción por una entrante nueva. Al guardar, una acción visual limitada descarta el resumen final de esa referencia; el evento PBX y el estado real de conexión se conservan. El borrador queda aislado por usuario y referencia en la sesión del navegador.

La grabación se pide por cada `call_id_with_rec` firmado y atribuido a la extensión propia. Nunca se solicita el PBX completo como sustituto: podría contener el tramo de Recepción. Los IDs decimales se conservan como texto. Sin segmento inequívoco, respuesta, cierre o resultado que declare conversación, se informa el estado pendiente o sin reproducción. Un error del proveedor no se presenta como un archivo disponible. Los audios reales sólo pueden confirmarse mediante pruebas autorizadas en Zadarma.

Un resultado «Comunicación interrumpida» conserva el intento, sin contar por sí solo una conversación humana. No se crean expedientes ni se reutiliza un seguimiento_id para los prospectos.

Validación: pruebas DB de propiedad, filtros, resultados, 27 llamadas paginadas, audio exacto y cambio de titular; navegador real con shell/formulario/Bootstrap y los CSS del CRM, fin → registro → guardado, un solo backdrop, tres tamaños de pantalla y reproducción de dos segmentos simulados. Regresión del formulario y shell del Analista. La agenda ampliada, devolución comercial y avisos quedan en D/E, no se dan por terminados con esta etapa.
