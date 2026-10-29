# Audio independiente del resultado comercial

> Registro de la entrega anterior `a75e4626`. Su política de resultados fue sustituida por [las reglas de reproducción vigentes](ventas_audio_reglas.md), que exigen resultado guardado y bloquean los resultados sin contacto, incluso combinados con envíos.

Un resultado que sólo contiene WhatsApp/SMS se guarda como envío de información, sin acreditar por sí mismo contacto humano. Las tarjetas y las rutas de audio exigían que el resultado se clasificara como conversación: ocultaban grabaciones aunque la llamada tuviera respuesta y audio verificable.

Se añade `TelefoniaVentasLlamadasService::audioConsultable()`, compartido por Mis llamadas, Supervisión y las rutas privadas de reproducción. Permite consultar cuando hay una referencia PBX válida, extensión y respuesta confirmadas, además de cierre o una transferencia entrante atendida. La transferencia conserva la validación de origen distinto de la extensión propia. El resultado comercial no participa en esa decisión.

Que una llamada admita consulta no confirma que exista un archivo. Los servicios existentes siguen validando la identidad de los segmentos y la disponibilidad con el proveedor antes de entregar audio. No se obtiene una grabación por teléfono, duración o proximidad de fechas. Se conservan el acceso del propietario, el alcance nacional/equipo, la revocación de permisos, la operación de transferencia, el límite de partes, las verificaciones MIME y Range.

La presentación permite revisar evidencia de envíos, buzones y registros sin clasificación, sin convertirlos en contacto humano ni efectividad. No se cambia `esConversacion()`, el catálogo, los resultados guardados, Reportes, Desempeño, metas o el flujo de llamadas. No se incorpora SQL ni una migración.

## Instalación

El paquete incluye exclusivamente los cinco archivos sobre los hashes confirmados de Hostinger: servicio de llamadas, repositorio y servicio de audio de Supervisión, vista del historial del asesor y API privada de grabación del asesor. Primero se publica el helper, compatible con los consumidores anteriores. Todas las versiones se comprueban antes de escribir. Los originales se respaldan fuera de `public_html`; se admite restauración exacta e instalación idempotente.

## Validación

- `tests/ventas_audio_resultado_db.py`: 410 comprobaciones en MariaDB desechable; incluye catálogo, selección doble, lecturas, métricas, PDF, renderizado del historial real y casos de audio propios.
- Verifica WhatsApp, SMS, ambos canales, buzón y contacto humano con WhatsApp; los primeros cuatro conservan cero conversaciones acreditadas aunque el audio pueda revisarse.
- Consulta real en Supervisión para Administración, Dirección y Cuenta Clave del equipo; otro equipo y permiso revocado siguen rechazados.
- Ejecuta la API personal real con proveedor sintético: verificación, entrega, Range y rechazo de propietario/extensión/método incorrectos.
- La duración de 33 segundos sin segmentos no crea una grabación. Las salientes en curso siguen bloqueadas; las transferencias atendidas conservan su regla anterior.
- El publicador se prueba con LF/CRLF, variantes desconocidas, respaldo, reaplicación y restauración, protegiendo fuentes del teléfono y un audio de prueba. Las pruebas no usan producción ni descargan audios reales.
