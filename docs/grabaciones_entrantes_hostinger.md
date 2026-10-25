# Grabaciones de llamadas entrantes transferidas a Ventas

La consulta anterior exigía que Recepción hubiese iniciado una llamada saliente. Una llamada externa que entraba a Recepción y se transfería al asesor se rechazaba aunque su operación estuviera entregada y existieran dos avisos de grabación.

El diagnóstico de las llamadas del 24/10 a las 21:32 y 21:35 confirma inicio entrante en 100, atención de 100 y 150, transferencia entregada y dos referencias de grabación por llamada. El cierre indica 44 y 62 segundos con origen 100 y última extensión 150: no aporta una duración individual de conversación de 150. La llamada del 22/10 a las 09:25 empezó como saliente y no recibió cierre después de responder el asesor; por eso la validación anterior sí ofrecía sus audios, pero no confirmaba su duración.

Esta entrega modifica sólo tres archivos: el servicio común de grabaciones transferidas, la vista del historial de Ventas y su JavaScript. Conserva los campos de captura y el motor telefónico. No contiene migraciones SQL ni reemplaza resultados o archivos de audio. Reconoce entrantes atendidas y salientes, valida la operación y sus participantes exactos y conserva el rechazo de audios ajenos. La consulta presenta los segmentos ya disponibles aunque otro siga en preparación. Un fallo temporal del proveedor no se presenta como un archivo aún en preparación.

La duración del asesor se obtiene únicamente cuando el proveedor identifica inequívocamente un tramo suyo. Nunca copia los segundos de Recepción ni suma tramos potencialmente solapados. Si hay cierre confirmado, reutiliza la conciliación POST y la tabla de evidencias existente para conservar el dato al volver al historial. Esa consulta no bloquea los audios. Si no llega cierre propio o no se identifica su duración, informa «Duración del asesor pendiente». Los avisos de grabación por sí solos no acreditan que un archivo se pueda descargar y escuchar.

## 1. Descargar y comprobar

Copia el bloque completo en SSH. Descarga el publicador y el paquete en una carpeta privada; `--check` no modifica el sitio ni la base de datos. Los tres hashes de entrada coinciden con los proporcionados desde Hostinger. Si detecta una versión distinta, se detiene sin publicar.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-grabaciones-entrantes-318546c7"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/318546c7a2bde8ebe33b6668264b8d6367e09cee"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
for f in publicar_grabaciones_entrantes.py grabaciones_entrantes_release.json; do
  curl -fsSL "$BASE/tools/$f" -o "$f"
done
sha256sum -c <<'SHA'
678df0427197d162cac1d3335faedf0208613b859297cd95c79e3586a4ec560e  publicar_grabaciones_entrantes.py
dcf6ec0b3afc46974904ba8f36fd94532f15dceddb5de2662bf742c6ca67905c  grabaciones_entrantes_release.json
SHA
python3 publicar_grabaciones_entrantes.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-grabaciones-entrantes" --check
)
```

Debe terminar con `CHECK OK: sin escrituras.` Si hay conflicto, conserva la salida y no continúes al siguiente bloque.

## 2. Aplicar

Ejecuta este bloque cuando no haya llamadas en curso. Comprueba nuevamente las dos descargas y guarda un respaldo privado de los archivos que cambia. No reemplaza el sitio completo.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-grabaciones-entrantes-318546c7"
sha256sum -c <<'SHA'
678df0427197d162cac1d3335faedf0208613b859297cd95c79e3586a4ec560e  publicar_grabaciones_entrantes.py
dcf6ec0b3afc46974904ba8f36fd94532f15dceddb5de2662bf742c6ca67905c  grabaciones_entrantes_release.json
SHA
python3 publicar_grabaciones_entrantes.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-grabaciones-entrantes" --apply
)
```

Guarda la ruta que aparece después de `APLICADO. RESPALDO:`. Abre nuevamente el historial para cargar el JavaScript actualizado.

## 3. Comprobar con las llamadas existentes

1. En Ventas, abre las llamadas del 24/10 a las 21:32 y 21:35. Antes de consultar, ambas transferencias deben decir «Consultar grabaciones», sin depender de la existencia de un resultado o cierre antiguo para elegir singular o plural.
2. Consulta cada una y comprueba que aparecen sus dos reproductores. Reproduce unos segundos de cada archivo para confirmar el sonido de Recepción y del asesor. El orden de los archivos es estable, pero sólo se etiqueta una extensión cuando el proveedor la corrobora.
3. Repite la consulta con Recepción para las mismas llamadas. Debe ofrecer el mismo conjunto de segmentos autorizado para esa transferencia.
4. Si un archivo se está preparando, debe ofrecer el que ya esté disponible. Un error del proveedor debe permitir volver a intentar. No se crean grabaciones que el proveedor no entregue.
5. Si se confirma una duración individual del asesor, comprueba que aparece y que se conserva al volver al historial cuando ya hay cierre confirmado. No tiene por qué coincidir con los 44 o 62 segundos de Recepción. Si falta evidencia individual, seguirá pendiente de forma explícita.
6. Abre también la transferencia saliente del 22/10 a las 09:25: sus audios deben conservarse. Su ausencia de cierre posterior al asesor no se corrige inventando un cierre.
7. En una llamada nueva de prueba entrante → Recepción → asesor, comprueba nuevamente captura, resultado y ambos audios tras colgar. No se requiere reenviar ni modificar los resultados guardados.

## Restaurar

Usa la ruta exacta del respaldo creado, en lugar de `RUTA_DEL_RESPALDO`. La restauración comprueba que no haya modificaciones posteriores; si las hay, se detiene.

```bash
python3 "$HOME/impe-grabaciones-entrantes-318546c7/publicar_grabaciones_entrantes.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-grabaciones-entrantes" \
  --restore "RUTA_DEL_RESPALDO"
```

Las pruebas aisladas cubren ambos roles, dos llamadas distintas del mismo número sin cruzar audios, participantes ajenos, referencias duplicadas, índices estables, archivos parciales, fallos del proveedor y duración propia persistente. El navegador utiliza la vista y el reproductor reales con respuestas simuladas. La reproducción efectiva de las grabaciones de Hostinger se confirma con la comprobación del apartado 3.
