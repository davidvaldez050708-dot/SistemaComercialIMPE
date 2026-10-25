# Recepción, asesor e historial: corrección y aceptación

## Cambio comprobado

Recepción devuelve `atendida`; el historial leía `contestada`, un campo interno que ya no existe en esa respuesta. Por eso podía mostrar cero llamadas conectadas aunque Recepción sí hubiera confirmado su atención. La corrección consulta el campo público. No modifica ni inventa eventos, resultados o grabaciones históricos. Sin evidencia de respuesta, la llamada conserva su condición sin conexión confirmada, incluso si tiene duración.

Los textos visibles del teléfono, teclado, historial, resultados y reproductor usan lenguaje claro. Los identificadores internos y las comprobaciones de propiedad siguen conservándose. Una conexión telefónica no demuestra que respondió una persona: un buzón puede contestar y debe clasificarse como tal en el resultado.

La entrega contiene doce archivos y conserva las variantes verificadas de Hostinger del teléfono persistente, motor y vista de historial. No sustituye el controlador, el servicio de llamadas de Ventas, el shell, credenciales, permisos, enlaces ni base de datos. La comparación adicional del controlador está pendiente; no se ha afirmado que su variante instalada esté usando los métodos ausentes del servicio de Ventas.

## 1. Descargar y comprobar, sin cambiar el CRM

Copiar el bloque completo en SSH. Los archivos se descargan fuera de `public_html`. El publicador verifica todos los archivos antes de cualquier escritura y bloquea variantes desconocidas.

```bash
(
set -eu
umask 077
ENTREGA="$HOME/impe-telefonia-integridad-05261ecf"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/05261ecfca9f4d42bb29cf05504dbb041405a553"
mkdir -p "$ENTREGA"
cd "$ENTREGA"
for f in publicar_telefonia_integridad.py telefonia_integridad_release.json diagnosticar_telefonia_integridad.php; do
  curl -fsSL "$BASE/tools/$f" -o "$f"
done
sha256sum -c <<'SHA'
5bb9db3cf65f0de2c64c57b5a85e1c04a787a91b5004cbc91c47156e3dae89b6  publicar_telefonia_integridad.py
7b940c38d03705adedc6ea007083b37b8d96046202dd375214c0aae09897b23b  telefonia_integridad_release.json
b31ddafcacc4ec019646eb0f829fbaa5830aea07e414c1538e88cd69ecadd2c2  diagnosticar_telefonia_integridad.php
SHA
python3 publicar_telefonia_integridad.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-telefonia-integridad" --check
)
```

Sólo continuar si los tres archivos dicen `OK` y termina con `CHECK OK`. Si hay conflicto, conservar la salida para conciliar el archivo; no forzar ni editar hashes.

## 2. Publicar con respaldo privado

Hacerlo sin llamadas activas. Cerrar las pestañas y ventanas telefónicas del CRM de los usuarios que van a probar y reabrirlas después. Esto evita conservar código antiguo del teléfono mientras se reemplazan sus archivos.

```bash
python3 "$HOME/impe-telefonia-integridad-05261ecf/publicar_telefonia_integridad.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-telefonia-integridad" --apply
```

Guardar la ruta que imprime `APLICADO. RESPALDO:`. El paquete se puede volver a ejecutar sin duplicar cambios. Para restaurar, reemplazar la ruta de ejemplo por la ruta exacta impresa:

```bash
python3 "$HOME/impe-telefonia-integridad-05261ecf/publicar_telefonia_integridad.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-telefonia-integridad" \
  --restore "$HOME/respaldos-impe-telefonia-integridad/RUTA-EXACTA-DEL-RESPALDO"
```

La restauración se bloquea si hay modificaciones posteriores en un archivo afectado.

## 3. Llamadas reales de aceptación

Usar dos sesiones separadas: Recepción con extensión 100 y Asesor con extensión 150, o sus extensiones reales asignadas. Utilizar un teléfono de prueba autorizado. Primero comprobar que cada sesión muestra el teléfono disponible y permite escuchar y hablar.

| Prueba | Resultado esperado |
| --- | --- |
| Llamar desde el teléfono externo a Recepción y contestar | Audio en ambos sentidos. Una comunicación propia en el historial y conexión confirmada. |
| Llamar desde Recepción al teléfono externo | El cierre libera el teléfono. Resultado y duración pertenecen a esa comunicación. |
| Transferir directamente de Recepción al Asesor | Sólo la extensión que conteste se registra como atendida. El Asesor recibe la persona y guarda su resultado. |
| Consultar al Asesor antes de entregar | La consulta interna y la entrega son etapas distintas. La entrega final conecta a la persona con el Asesor. |
| Asesor ocupado, rechaza o no contesta | No se inventa una conversación del Asesor. Comprobar la devolución o recuperación de la llamada en Recepción según la operación. |
| Colgar desde el teléfono externo y luego, en otra prueba, desde el Asesor | Termina la llamada correcta; ambas sesiones quedan disponibles para otra llamada. No hay contadores activos ni audio residual de la anterior. |
| No contestar una entrante y una saliente | Se conservan como intentos sin respuesta. No se exige un resultado de conversación humana. |
| Contesta un buzón | Guardar resultado de buzón. La conexión telefónica no se presenta como conversación con una persona. |
| Dos llamadas consecutivas con el mismo número | Conservan referencias, resultados y audios separados. Reenviar un evento no duplica la llamada ni el audio. |
| Reproducir grabaciones tras el cierre | Se escucha el audio que corresponde a la comunicación seleccionada; en una transferencia se revisan las partes disponibles autorizadas. Adelantar y retroceder funciona. |
| Consultar desde otro asesor sin pertenencia | No puede acceder al resultado ni al audio de la llamada ajena, aunque conozca su referencia. |

Registrar para cada prueba la hora, extensiones, sentido de la llamada y quién colgó. Conservar cualquier mensaje de error. Las pruebas automáticas simulan el proveedor; no acreditan que los audios reales estén habilitados y disponibles.

El proveedor informa la preparación del archivo por un aviso específico. Puede tardar después de terminar la llamada; su documentación recomienda esperar al menos 40 segundos después del aviso antes de solicitar el archivo. Verificar también que la grabación esté habilitada para las extensiones involucradas. Fuentes: [API oficial](https://zadarma.com/en/support/api/) y [grabaciones](https://zadarma.com/en/support/faq/pbx/how-to-listen-call-recordings/).

## 4. Diagnóstico de las pruebas, sólo lectura

Después de probar, obtener evidencia de las ocho referencias más recientes de cada extensión en los últimos tres días. El diagnóstico no sincroniza llamadas ni solicita audios. Abre una transacción de sólo lectura y omite nombres, teléfonos, notas y enlaces de grabación.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
IMPE_RUNTIME_ROOT="$SITIO" php "$HOME/impe-telefonia-integridad-05261ecf/diagnosticar_telefonia_integridad.php" 100
IMPE_RUNTIME_ROOT="$SITIO" php "$HOME/impe-telefonia-integridad-05261ecf/diagnosticar_telefonia_integridad.php" 150
```

Un aviso de grabación en el diagnóstico no demuestra por sí mismo que el archivo se pueda reproducir: esa comprobación se hace en el CRM con una sesión autorizada.

## Validación automatizada

- 38 comprobaciones nuevas del contrato real Recepción → historial, incluyendo respuesta propia, transferencia, perdida con duración, ocupada, grabación sin respuesta y eventos desordenados.
- 436 comprobaciones en MySQL 8 y otras 436 en MariaDB 10.11: registro, resultados, propiedad histórica, notificaciones, atención y acceso a grabaciones.
- Navegador: 153 comprobaciones con el código canónico y 153 con las variantes verificadas de Hostinger y transporte simulado.
- Ocho pruebas del publicador: comprobación sin escritura, aplicación, repetición, saltos de línea, variantes instaladas, conflictos, publicación parcial y restauración.

El flujo dedicado está en `.github/workflows/telefonia-integridad.yml`. Los publicadores antiguos tienen pruebas que dependen literalmente de mensajes anteriores y no todos los flujos generales del repositorio pasan; no equivalen a una validación completa de esta entrega. La aceptación final requiere las llamadas reales descritas arriba y conciliar la variante del controlador pendiente.
