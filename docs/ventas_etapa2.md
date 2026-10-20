# Ventas — Etapa 2: evidencias, operación y datos del prospecto

## Base revisada

Main permanece en `fd5fd282` (PR112). PR110 sigue abierto (`a5a61f5e`); PR115 también (`ec8564debc837005fa4ef6f1c4ecb0c419edf8ea`). El usuario confirmó satisfactoriamente su cierre en Hostinger. Esta rama es sucesora de PR115 y conserva su lectura de `last_internal`, sus guardias de resultados y las tres bandejas compactas. No se accedió a producción ni se supone que sus archivos sean idénticos a GitHub.

Las personalizaciones de CSS conocidas no se reemplazan. La variante JS de shell de Hostinger `92c9265a…`, reconstruida y cotejada desde la fase C, tiene un parche específico que conserva sus comentarios y comportamiento anterior fuera de los cambios nuevos. Una variante desconocida detiene el publicador sin escribir el sitio.

## A. Grabaciones y duración

El cierre global 100→150 corregido en PR115 acredita finalización mediante último participante; su duración global y el aviso RECORD sin extensión no identifican por sí solos un audio propio de 150. Por eso las tarjetas podían quedar conectadas y con resultado, pero sin duración propia ni referencia reproducible. No se atribuye esa causa a una grabación concreta de la captura sin sus eventos y estadísticas.

Se reutilizan el cliente `ZadarmaRecordingService`, el decodificador de IDs decimales, el endpoint autenticado y el reproductor de segmentos existente. Se aplica el mismo patrón de consulta de estadísticas oficiales y verificación por call ID que usa la conciliación de grabaciones internas del Analista. No se modifica su asociación histórica ni las grabaciones individuales de Marketing.

«Comprobar grabación» hace un POST con sesión, rol y CSRF. Primero verifica propietario histórico, referencia PBX, extensión, respuesta y cierre. Consulta estadísticas versión 2 bajo demanda: coincidencia EXACTA de PBX y `sip` de la extensión, no teléfono ni horario. Rechaza IDs compartidos con otras extensiones, contradictorios o aproximados por float. La referencia de cada segmento se confirma mediante `getPbxRecord`, se conserva sin URL pública y se vuelve a resolver al reproducir por el endpoint privado. Un RECORD global no habilita audio ajeno.

La caché durable tiene propietario/extension/PBX como clave; conserva resultado, prospecto y eventos originales. Un UPDATE atómico impone 60 segundos entre consultas incluso con dos pestañas. El Historial sólo lee la caché; no consulta la API al navegar o hacer polling. Se admite hasta ocho segmentos y se rechazan estadísticas truncadas de 1000 filas, sin usar una coincidencia parcial.

Cada tramo conserva sus segundos oficiales y se identifica en el reproductor compartido. Un único tramo puede completar la duración desconocida; varios tramos se presentan individualmente y no se suman sin intervalos inequívocos, evitando solapamientos. La duración propia ya confirmada por webhook no se reemplaza por la de Recepción. Audio en preparación, sin grabación reportada y evidencia ambigua tienen estados distintos y se pueden reconsultar. Buzón/resultados sin conversación siguen sin reproducción.

## B. WebRTC, disponibilidad y navegación

El observador del Analista podía llamar a finalización provisional ocho segundos después de perder el audio, aunque el cierre PBX no hubiera llegado. Ahora conserva una llamada activa en estado de recuperación y reconsulta. No afirma «finalizada» por retirar clases del widget, una negociación fallida o una pérdida de red. Un END firmado sigue cerrando la llamada normalmente.

La recuperación de audio sólo se muestra al volver a observar una RTCSession establecida y un transporte WebRTC conectado. No se crea otra sesión SIP ni se cambian credenciales. Durante una llamada activa no se remonta el host; sin llamada, se conserva el mecanismo de recuperación y lease existente. Se añaden guardias contra inicialización duplicada del shell, host, navegación y disponibilidad.

No se promete continuidad de audio durante F5, cierre de pestaña, renovación de sesión o desconexión: el proveedor y el navegador deben recuperar su transporte real. Si no hay evidencia de recuperación, permanece el aviso verificable. La inicialización previa del widget no se redefine como una prueba nueva de REGISTER; verificar los estados SIP reales requiere la prueba del proveedor.

El navegador del shell rechazaba cualquier `controller=telefonia`, incluyendo Mis llamadas. Ahora permite únicamente esa acción y las tres bandejas de Ventas; mantiene la misma ruta/filtros al recargar, excluyendo host, endpoints, login y rutas externas. Elimina parámetros transitorios de apertura de resultado al guardar la ruta. Cada solicitud del iframe vuelve a pasar por las comprobaciones del router y permisos.

Ventas usa el MISMO aviso WebAudio de disponibilidad del Analista, su llave por usuario/solicitud/versión y su recordatorio único. Cuando el navegador aún no permite audio, el modal ofrece «Activar avisos sonoros» mediante gesto real. No se fuerza autoplay, no se reproduce en cada polling y aceptar/rechazar/vencer detiene el aviso. No cambia la cadencia de ocho minutos de los toasts generales.

## C. Datos del prospecto

Botón en el mismo teléfono persistente, con el dialog y clases institucionales existentes; no añade CSS global. Nombre, teléfono editable, correo opcional, programa de interés informado y notas de atención. El programa es texto declarado, no una inscripción ni un alta de catálogo.

Guardar durante la conversación no cuelga, crea resultado, institución ni contacto automático. La captura durable se identifica por propietario histórico/extension/PBX, nunca se fusiona por teléfono. Reenvíos idénticos son idempotentes y una versión antigua no reemplaza una edición nueva. Nombre/teléfono/correo y longitudes se validan también en servidor. Si aún falta PBX, se mantiene un borrador local por sesión SDK y sólo se guarda en servidor cuando puede autorizarse la misma llamada.

La captura se recupera al reabrir y desde una sesión posterior autorizada. Nombre/correo pueden precargar el resultado; las notas se muestran separadas y no se convierten automáticamente en observaciones o resultados comerciales. La agenda y las altas de prospectos generales permanecen independientes: no se renombra otra persona por compartir número.

## Pruebas

- MariaDB local aislada: 64 contratos comerciales (incluyendo migración CLI check intacto/apply idempotente, audio tardío, IDs decimales, segmentos ajenos, multipartes, duración sin suma, cooldown y captura concurrente), 10 audio/DB, 28 agenda/devoluciones, 48 notificaciones/conciliación, 144 Analista y 12 identidad. Sin datos o credenciales de producción.
- Navegador real sobre vistas PHP y transporte simulado: reconsulta POST/CSRF, reproductor compartido multipartes y etiquetas por tramo, captura durante conversación sin cambiar el host, recuperación, botón/sonido único con gesto real, tres bandejas tras F5, notas recuperables y ausencia de listeners duplicados. Escritorio/tablet/móvil (1366/768/390); regresiones de Historial Hostinger compacto, formularios, disponibilidad y modales del Analista.
- Contratos del host y observador: negociación prolongada y pérdida de audio sin cierre ficticio, sesiones consecutivas, consentimiento sonoro, cadencia y navegación segura. Las pruebas aisladas no acreditan una transferencia o grabación real en Zadarma.
- Réplicas del publicador: PR115, variante Hostinger conocida, personalización PHP, CRLF, JS desconocido, archivo nuevo inesperado y restauración tras edición posterior; CSS siempre intacto. Actions ejecuta además MySQL8 y MariaDB10.11.

Capturas generadas en el artefacto `ventas-bandejas-responsive`: `ventas_grabaciones_*` y `ventas_prospecto_*`. La validación final de Actions se indica en el PR.

## Publicación manual por bloques

Aplicación fija: `b57965a84632cfae4f900844d9293914046d3d27`. Los publicadores son Bash/PHP, sin dependencia de Python en Hostinger. No usar git pull en public_html. No ejecutar migraciones ni instalar con conflictos pendientes. Extraer siempre desde una revisión fija del PR.

### 1. Revisar A sin instalar

```bash
curl -fsSL --retry 2 \
'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/DELIVERY_SHA/tools/publicar_ventas_etapa2_a_hostinger.sh' \
-o "$HOME/publicar-ventas-etapa2-a.sh" &&
bash "$HOME/publicar-ventas-etapa2-a.sh" --check
```

### 2. Respaldo verificado y migración explícita

Respaldar la BD con la herramienta institucional/hPanel y verificar la copia fuera de public_html. La migración sólo crea dos tablas InnoDB nuevas (`telefonia_ventas_evidencia`, `telefonia_ventas_prospecto_llamada`), sin alterar/borrar tablas existentes ni inventar datos históricos. MySQL confirma DDL implícitamente; no depende de rollback transaccional de esquema.

Extraer en carpeta privada NUEVA desde la copia HTTPS preparada por el check:

```bash
VERSION=b57965a84632cfae4f900844d9293914046d3d27
PREPARACION="$HOME/ventas-etapa2-$VERSION"
mkdir -m 700 "$PREPARACION" &&
git -C "$HOME/crm-publicaciones/ventas-etapa2-a-actual" archive "$VERSION" \
 tools/migrar_ventas_etapa2.php database/migrations/2026_10_20_ventas_etapa2.sql \
 | tar -x -C "$PREPARACION"
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/migrar_ventas_etapa2.php" --check
# Si falta la estructura y ya se verificó el respaldo:
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/migrar_ventas_etapa2.php" --apply
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$PREPARACION/tools/migrar_ventas_etapa2.php" --check
```

Usa la configuración privada existente; no copia credenciales. El preparador CLI no queda dentro del sitio.

### 3. Instalar A y comprobar audio

Sin llamadas activas:

```bash
bash "$HOME/publicar-ventas-etapa2-a.sh" --apply
```

A instala diez archivos de backend, Historial y reproductor. Puede publicarse antes del botón de captura y mejoras del shell. No reemplaza CSS. Comprobar primero una llamada guardada de la captura con «Comprobar grabación» y, después, una transferencia nueva. Esperar al menos un minuto para una segunda consulta si el proveedor aún prepara el audio. No declarar recuperado un audio sin reproducir su segmento correcto.

### 4. Revisar e instalar B después de A

```bash
curl -fsSL --retry 2 \
'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/DELIVERY_SHA/tools/publicar_ventas_etapa2_b_hostinger.sh' \
-o "$HOME/publicar-ventas-etapa2-b.sh" &&
bash "$HOME/publicar-ventas-etapa2-b.sh" --check
# Sólo con revisión compatible y extensiones sin llamada activa:
bash "$HOME/publicar-ventas-etapa2-b.sh" --apply
```

B instala ocho archivos del shell, disponibilidad, recuperación, navegación y captura; exige el backend A cotejado. Renovar la página sin llamada activa para cargar el runtime nuevo. Esto crea una conexión del proveedor nueva: no mantiene audio durante la recarga completa.

### Restauración

Cada publicador imprime su ruta de respaldo privado y verifica integridad/cambios concurrentes. Usar las rutas EXACTAS impresas. Revertir primero B y después A; A bloquea la restauración si detecta el cliente B instalado.

```bash
bash "$HOME/publicar-ventas-etapa2-b.sh" --restore "$HOME/respaldos-crm/ventas-etapa2-b-FECHA-PID"
bash "$HOME/publicar-ventas-etapa2-a.sh" --restore "$HOME/respaldos-crm/ventas-etapa2-a-FECHA-PID"
```

No elimina tablas, evidencias, notas ni resultados nuevos. No restaurar toda la BD para revertir la presentación. Una edición posterior o variante desconocida se conserva y exige revisión.

## Comprobación real pendiente

1. **Audio 100→150:** llamada real, resultado humano, comprobar segmentos/fechas/duración contra la estadística; reproducir cada segmento y confirmar que pertenece a Ventas, no a Recepción. Repetir individual, multipartes, audio tardío, inexistente y tras nueva sesión. Nunca inferir por el número compartido de la captura.
2. **Analista 100→101:** Contestar, negociación prolongada, corte de red y recuperación; si el celular sigue activo y falta END, debe mostrar recuperación, no «finalizada». Confirmar el END real y una llamada posterior.
3. **Persistencia:** navegar entre módulos conserva un solo host; segunda pestaña respeta lease. F5 conserva Historial/Por devolver/Programadas, pero reabre el transporte. Verificar desconexión y sesión renovada, sin afirmar verde por mera presencia de PBX.
4. **Disponibilidad Ventas:** sin gesto previo aparece activación sonora; después del gesto, aviso distinto al timbre, un recordatorio como máximo, sin duplicados por polling. Rechazo, vencimiento y aceptación conservan 45/30 segundos y transferencia manual.
5. **Prospecto:** capturar/editar nombre, teléfono, correo, interés y notas durante audio; guardar sin colgar; resultado separado, dato recuperado y segundo asesor sin acceso. Probar móvil y dos personas con el mismo número sin fusión.
6. Repetir saliente, perdida y buzón de Ventas; una llamada de Marketing y una del Analista como regresión operativa.
