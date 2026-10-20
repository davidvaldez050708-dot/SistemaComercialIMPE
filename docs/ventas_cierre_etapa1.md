# Ventas: cierre confirmado y recuperación del resultado

## Base y alcance

Main revisado: `fd5fd282b3f6774d1bdc045295d655b72620ce17` (PR112). Las fases comerciales A–E y PR105/106/108/109 están integradas. PR110 sigue abierto: su cabeza `a5a61f5eb27ff1e1143f0e267fd7b6426f29eebb` contiene las tres bandejas compactas, filtros y avisos, aún fuera de main. Esta rama conserva esa cabeza mediante un merge sobre main; no retrocede su interfaz. El PR debe revisarse considerando esa dependencia; no implica fusionar ni desplegar PR110 automáticamente.

La corrección nueva de aplicación alcanza cuatro archivos: `TelefoniaVentasLlamadasService.php`, `resultado_ventas_form.php`, `telefonia_ventas_resultados.js` y `telefonia_ventas_shell.js`. No modifica CSS, credenciales, permisos, transferencias, motor SIP, webhook, tablas ni el runtime compartido de Marketing/Analista. Los cambios locales pendientes de Aliados quedan en otra copia de trabajo y no forman parte de esta etapa.

## Causas reproducidas

El webhook guarda `last_internal` en `payload_json`. Ventas sólo lo usaba cuando `internal` estaba vacío: un END de la misma referencia con `internal=100,last_internal=150` no cerraba el tramo atribuido al asesor. Detalle, historial, resultado y campana consumen el mismo reductor; quedaban esperando y el pendiente del shell impedía marcar. Ahora se admite el último participante explícito también con iniciador presente. Se conserva la respuesta propia y se sigue rechazando un cierre de otra extensión sin esa evidencia. Referencia oficial: [eventos de Zadarma](https://zadarma.com/es/support/api/#api_webhook_notify_end).

La duración global de Recepción no se convierte en duración propia del asesor. Si sólo llega ese cierre, el resultado puede habilitarse con respuesta propia confirmada, pero la duración permanece desconocida hasta recibir duración del tramo propio. El audio mantiene sus reglas estrictas: IDs propios y sin ambigüedad; ni el número, ni un END global, ni el reloj WebRTC autorizan grabaciones. Un RECORD tardío puede completar el audio sin crear otra llamada.

El formulario anterior notificaba el mismo error en cada uno de sus reintentos automáticos. Ahora la espera se muestra dentro del mismo modal institucional, sin campos de resultado ni guardado habilitados. Hay cuatro reconsultas con pausas de 1,5/3/6/12 segundos; después queda «Comprobar cierre». Cada consulta tiene timeout de diez segundos. Clics simultáneos comparten una consulta; cerrar, navegar o recibir una nueva llamada cancela el ciclo anterior. Inicializaciones duplicadas no agregan observadores ni botones. El shell reutiliza la página abierta al solicitar el mismo pendiente, evitando reiniciar el iframe en cada clic.

Sólo el servidor habilita el resultado con eventos persistidos y propiedad histórica. Guardar mantiene las validaciones, CSRF y unicidad originales. Tras guardar se retira el pendiente, se libera el marcador y se actualiza Historial. Si se recuperó el cierre y se cierra el formulario sin guardar, se refresca Historial conservando el borrador y el resultado pendiente. WebRTC no se desmonta.

## Validación y límites

- DB real aislada: cierre transferido 100→150, rechazo antes del END, paridad detalle/listado, duración global no prestada, duración propia tardía, segmentos propios, resultado único y aislamiento entre asesores. Regresiones comerciales, audio, agenda/devoluciones, notificaciones, Analista e identidad.
- Navegador real: espera sin toasts repetidos, tres clics/una consulta, doble inicialización inocua, cancelación al cerrar, recuperación posterior, formulario sin superposición, borrador/recarga, guardado, dos segmentos y responsive. Se reutilizan además las pruebas de bandejas, auricular, disponibilidad y toasts.
- Publicador: réplicas de main y PR110, personalización PHP compatible, CRLF, variante JS desconocida y restauración protegida. `--check` no escribe el sitio. No requiere Python en Hostinger.

No se accedió a eventos de producción ni se reprodujo una llamada real en Zadarma. Las pruebas con transporte simulado no certifican audio real. El defecto de last_internal y los reintentos están reproducidos en código y DB; para atribuir la incidencia exacta de Hostinger hacen falta sus eventos. Si no llega cierre propio ni último participante inequívoco, la espera continúa con reintento seguro; no se inventa un final para desbloquear.

## Publicación selectiva manual

Aplicación fija: `21ec8d2b0460e76b873ac6d8641f8fa513d90662`. Descargar el publicador desde el commit de entrega indicado en el PR, nunca desde una rama mutable:

```bash
curl -fsSL --retry 2 \
'https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/DELIVERY_SHA/tools/publicar_ventas_cierre_etapa1_hostinger.sh' \
-o "$HOME/publicar-ventas-cierre-etapa1.sh" &&
bash "$HOME/publicar-ventas-cierre-etapa1.sh" --check
```

Revisa la salida antes de instalar. No se aceptan JS desconocidos ni reemplazos indiscriminados; las personalizaciones PHP fuera del parche se conservan. Una variante incompatible exige revisión, no forzar. Se coteja también el marcador con la conciliación horaria ya revisada. El publicador sólo instala los cuatro archivos nuevos de esta corrección y conserva los estilos publicados de Historial. No instala todas las vistas incluidas por la dependencia PR110 ni ejecuta SQL.

Sin llamadas activas y con revisión compatible:

```bash
bash "$HOME/publicar-ventas-cierre-etapa1.sh" --apply
```

Respaldo privado fuera de public_html, huellas antes/después y rollback si falla. Para revertir, usa exactamente la ruta impresa:

```bash
bash "$HOME/publicar-ventas-cierre-etapa1.sh" --restore "$HOME/respaldos-crm/ventas-cierre-etapa1-FECHA-PID"
```

Rechaza sobrescribir archivos editados después de instalar. No hay migración ni publicación automática.

### Evidencia de sólo lectura si sigue faltando el cierre

El diagnóstico es CLI y debe extraerse en carpeta privada nueva desde la copia HTTPS preparada, sin copiar credenciales:

```bash
VERSION=21ec8d2b0460e76b873ac6d8641f8fa513d90662
DIAGNOSTICO="$HOME/diagnostico-ventas-$VERSION"
mkdir -m 700 "$DIAGNOSTICO" &&
git -C "$HOME/crm-publicaciones/ventas-cierre-etapa1-actual" archive "$VERSION" \
 tools/diagnosticar_cierre_ventas.php | tar -x -C "$DIAGNOSTICO"
# Sustituir USUARIO, REFERENCIA_PBX y EXTENSION por el tramo del asesor:
IMPE_RUNTIME_ROOT="$HOME/domains/fundacionrededumex.org/public_html" \
php "$DIAGNOSTICO/tools/diagnosticar_cierre_ventas.php" USUARIO REFERENCIA_PBX EXTENSION
```

Muestra hechos y eventos mínimos, sin nombre/teléfono ni payload completo. No sincroniza, inserta, cambia configuración ni consulta API. La autorización histórica de la llamada sigue vigente. No colocarlo como endpoint público.

## Repetición con llamadas reales

1. Recibir en 100, aceptar disponibilidad en 150 y transferir manualmente. Conversar y colgar primero desde el cliente; repetir colgando desde el asesor.
2. Abrir resultado inmediatamente. Si tarda el END, ver una sola espera dentro del modal; al confirmar, guardar una vez. Historial debe quedar finalizado y el marcador permitir otra llamada.
3. Repetir con cierre tardío: usar «Comprobar cierre», cerrar/reabrir y navegar; sin toasts repetidos ni formularios anteriores reapareciendo.
4. Comprobar duración y audio propios tras su entrega; si falta referencia, conservar el estado pendiente. Revisar con diagnóstico la referencia exacta, no asociar por número.
5. Saliente, perdida y buzón; revisar que buzón no habilite conversación/audio. Repetir una llamada de Marketing y una del Analista como regresión operativa.
