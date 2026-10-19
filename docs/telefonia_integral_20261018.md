# Recepción y Vinculación · pruebas finales

## Correcciones y límites

| Incidencia | Causa y corrección |
| --- | --- |
| Teléfono finalizado sobre Registrar resultado | El formulario normal no tomaba posesión de la pantalla; el observador podía volver a mostrar el teléfono y no reconocía la nueva ruta del historial. El shell y el formulario ahora coordinan apertura/cierre con un token. Un latido no recupera el panel; una llamada nueva interrumpe el formulario guardando su borrador. No se desmonta el host WebRTC. |
| Tarjeta cortada en móvil | Posición recordada sin límite de alto; se ajusta al viewport visual, con desplazamiento del contenido y cabecera accesible. Un observador de tamaño reajusta la tarjeta cuando reaparece tras girar la pantalla. |
| Institución seleccionada irreversible | Se conservaba la confirmación sin acción para quitarla. Cambiar institución limpia responsable/estado/expediente y búsqueda pendientes; recupera sugerencias, búsqueda manual y No corresponde. |
| Persona que atendió y contacto referido | Los formularios y la captura usaban nombres distintos para las mismas claves. Se normalizan por separado y se respetan los campos canónicos del último borrador. El informante nunca se infiere del nombre del referido. |
| Municipio perdido | El alta desde llamada enviaba siempre cero al modelo. El formulario compartido carga el catálogo existente del estado asignado; el servidor rechaza municipios ajenos, inactivos o inválidos. Sólo afecta altas nuevas, incluidas devoluciones. Municipio sigue siendo opcional. |
| Audio ausente del expediente | El historial reconocía principalmente identificadores de salientes `out_…`; el asociador de sesiones consultaba el contexto antiguo y omitía `contextos_v2`. Se comprueban interacción/autor/expediente, extensión propia y asignación, respuesta, fin y NOTIFY_RECORD del mismo grupo PBX. Para SDK también se exige asociación institucional inequívoca y se rechazan contextos antiguos/v2 incompatibles. Segmentos repetidos se deduplican. |
| Campana sin fecha y orden telefónico inconsistente | Se agrega fecha/hora a avisos telefónicos y se ordenan del más reciente al más antiguo dentro de su prioridad. Las acciones operativas conservan precedencia y orden; el reloj compartido de ocho minutos y el cierre del toast de siete segundos se conservan. |
| Disponibilidad sin sonido propio | Aviso breve de dos notas, separado del timbre. Se habilita por gesto real en el shell o CRM, una sola vez por solicitud/versión, sin repetición por polling; se detiene al responder, posponer o vencer. Si el navegador impide audio, sigue el aviso visual. |

Se reutilizan el formulario de resultado del analista, catálogo territorial, componentes de Seguimiento y reproductor por segmentos. No se modifica el motor SIP, los comandos de transferencia, reservas de 30 s, solicitudes de 45 s ni reglas de ocupación y duplicados.

**Audio de producción no certificado:** las pruebas usan eventos y respuestas de red simulados. No se accedió a la base privada ni se descargó una grabación real. REPORTADA significa que existe referencia en los eventos; la disponibilidad se verifica al abrir el reproductor. Si faltan asociación, respuesta, fin o archivo, el expediente indica la evidencia pendiente; no busca por teléfono aislado. Buzón y resultados negativos conservan la política de no reproducción.

Diagnóstico opcional por SSH, después de instalar esta entrega, usando IDs internos reales del autor, expediente e interacción:

```bash
cd "$HOME/domains/fundacionrededumex.org/public_html"
php tools/inspeccionar_audio_expediente.php USUARIO_ID SEGUIMIENTO_ID INTERACCION_ID
```

Es CLI, inicia transacción de sólo lectura y no imprime teléfonos, credenciales, URL de audio ni identificadores PBX; sólo estado, cantidad de segmentos y huella de referencia.

## Validación automatizada

- `tests/telefonia_atencion_db.php`: base MySQL/MariaDB desechable, aislada de configuración real; incluye disponibilidad aceptada sin respuesta, transferencia sin confirmar, devolución única, verificación diaria, municipios y evidencia PBX/SDK. Los errores SQL simulados de campana son intencionales.
- `tests/telefonia_notificaciones_orden.php`: 50 permutaciones verifican un orden total sin alterar prioridades de Seguimiento.
- `tests/telefonia_modal_entrante_integracion_browser.cjs`: PHP, shell, observador y Bootstrap reales; formulario, latidos, observer, llamada nueva y tres tamaños sin panel superpuesto.
- `tests/telefonia_audio_expediente_browser.cjs`: consulta diferida, dos segmentos, estado pendiente y buzón sin reproductor. No acredita audio real de Zadarma.
- `tests/telefonia_atencion_browser.cjs`: municipio y borrador, referido/informante, error, doble envío, foco y cuatro tamaños.
- `tests/telefonia_disponibilidad_browser.cjs`: confirmación visual, activación por gesto, sonido único por solicitud, aceptación/rechazo/vencimiento y devolución móvil.
- Regresiones: flujos, motor, operaciones de transferencia, SDK bridge, modales de devolución/iframe, campana y once casos de toasts con ocho minutos acumulados entre páginas.
- Workflow: `.github/workflows/telefonia-atencion-analista.yml`; publicación selectiva tiene su propia prueba sobre réplicas.

## Repetición con llamadas reales

1. **100 → 101 existente:** seleccionar institución, cambiarla, volver a sugerencias, buscar manualmente y No corresponde. Transferir tras disponibilidad. Comprobar audio bidireccional y un único resultado/expediente.
2. **Registro tras transferencia:** colgar desde celular y desde 101, separadamente. Registrar resultado sin teléfono encima, guardar y abrir expediente. Abrir cada segmento de grabación; documentar estado pendiente si falta webhook/archivo.
3. **Referido:** capturar a Ana como persona que atendió y a Luis como contacto referido. Cerrar/reabrir, cambiar de módulo y registrar. Deben conservarse separados.
4. **Alta nueva y devolución:** elegir estado y municipio, recuperar borrador, guardar. Validar municipio y ausencia de duplicados. Cambiar una llamada a expediente existente no debe cambiar su municipio.
5. **Móvil:** 390×600 y horizontal; recibir, abrir transferencia, captura y resultado. Cabecera, X o control correspondiente y Guardar deben ser accesibles con scroll.
6. **Disponibilidad:** después de un clic normal en CRM, recibir solicitud y oír un aviso breve distinto del timbre. Dejar varios sondeos: sin repetición. Responder/rechazar/vencer; confirmar que el timbrado de la transferencia real sigue igual.
7. **No contestada tras aceptar:** confirmar que nunca se marca atendida sin respuesta PBX, que recepción recibe aviso y 101 una devolución única. No se presupone retorno automático de Zadarma.
8. **Campana/toast:** avisos telefónicos recientes primero y con fecha; Seguimiento vencido mantiene prioridad. Permanecer ocho minutos acumulados navegando: un toast a la vez, cierre en siete segundos y lectura sincronizada con la campana. Una pérdida recién creada no fuerza un toast inmediato.

## Publicación selectiva

La entrega incluye `tools/publicar_recepcion_vinculacion_hostinger.sh`, fijado a un commit de aplicación, y un manifiesto con 21 archivos. No usar `git pull` en producción. El script prepara fuera de `public_html`, valida todo antes de escribir, respalda, conserva permisos y LF/CRLF uniformes, usa bloqueo y verifica huellas antes de instalar. Sólo admite las variantes JS/CSS revisadas; en PHP permite un parche contextual compatible, valida sintaxis completa y conserva personalizaciones ajenas. Ante conflicto se detiene sin cambiar el sitio.

Descargar el script desde el SHA de la entrega de publicación indicado en el PR; ejecutar primero `--check`. Revisar el listado y, en una ventana sin llamadas activas y sin formularios pendientes, ejecutar `--apply` de manera explícita. No requiere Node en Hostinger. No ejecuta SQL, migraciones ni llama al CRM durante la preparación/publicación. No modifica configuración, audios, logs ni archivos fuera de la lista.

El comando de rollback se imprime con la ruta exacta del respaldo. `--restore RUTA_RESPALDO` comprueba huellas de respaldo y de instalación; se niega a sobrescribir ediciones posteriores. Restaurar código no revierte datos registrados por usuarios después de publicar. Las llamadas reales deben repetirse antes de dar esta versión por validada en producción.

## Estado de pruebas heredadas

En el primer commit del PR, las pruebas específicas de esta entrega y las de toasts aprobaron en GitHub Actions. La matriz general también ejecutó verificaciones históricas incompatibles con el código que ya existía en `main` (`939c39f8`). Se contrastaron en una copia independiente de esa base:

| Workflow heredado | Evidencia anterior a esta entrega |
| --- | --- |
| Telefonía - Entrantes Analistas / Telefonia WebRTC persistente | `telefonia_shell_smoke.php` exige el antiguo checkbox `data-marketing-reception-auto` en una plantilla que ya no lo contiene. |
| Telefonia - Marcador integrado / Telefonia - UX extensiones y recepcion | `telefonia_ux_admin_smoke.php` exige el antiguo bloque azul `data-marketing-reception-callback-status`, que ya fue retirado. |
| Marketing - Recepción WebRTC y presentación | El test estático exige una inclusión antigua del dashboard y el navegador un botón `data-marketing-reception-details-toggle` ausente también en la base. Se reprodujeron ambos fallos sobre `939c39f8`, sin las correcciones de este PR. |
| Seguimiento - Historial de llamadas | El test histórico de despliegue de fechas compara snapshots antiguos fijados por su instalador con los archivos actuales, en lugar de comparar con esos targets. El API de la base ya difiere del target histórico. |

Otro job del workflow persistente exige `INTERVAL 5 MINUTE`; la base ya utiliza 90 segundos. Estas verificaciones no justifican reinstalar controles retirados ni cambiar los tiempos de transferencia aprobados. Se mantienen visibles como fallos previos; no se presenta la matriz completa como verde. El PR conserva las pruebas de comportamiento actuales y la publicación selectiva de esta entrega en workflows separados.
