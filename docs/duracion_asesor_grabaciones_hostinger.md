# Duración del asesor en llamadas transferidas con grabación

La consulta real de la llamada del 22/10 a las 09:25 confirma dos tramos: uno con origen 100 y 33 segundos, y otro con origen externo, destino 150 y 13 segundos. El segundo es el tramo del asesor. El CRM buscaba la extensión sólo en el origen de la estadística y, además, exigía el aviso de cierre para guardar y recuperar su duración.

Esta corrección identifica la extensión en el origen o destino explícito de la estadística. Sólo acepta duración de un tramo inequívoco de la misma llamada; no usa el orden de los archivos ni suma ambos tiempos. Una fila con dos extensiones distintas se mantiene sin atribución individual. Los campos usados corresponden a la [documentación oficial del proveedor](https://zadarma.com/es/support/api/): `sip`, `destination`, `seconds`, `call_id` y `pbx_call_id`.

Si falta el cierre, la conciliación exige la transferencia entregada, la ruta y solicitud exactas, los usuarios y extensiones autorizados, la respuesta del asesor y las referencias firmadas de grabación. Guarda la duración en la tabla de evidencias existente y la recupera al volver al historial. No crea un cierre, no cambia resultados humanos y no amplía las referencias reproducibles para una llamada sin cierre. Una llamada activa sin transferencia grabada no obtiene esta excepción.

Se publican cuatro archivos: los servicios de segmentos, grabaciones transferidas y evidencias de Ventas, y el JavaScript del historial. Conserva la vista uniforme de los botones y los archivos de grabación. El publicador no ejecuta SQL ni migraciones; la consulta posterior conserva únicamente las evidencias de la llamada autorizada, con el mecanismo ya existente.

## 1. Descargar y comprobar

Copia el bloque completo en SSH. Descarga el paquete fuera de `public_html`, verifica sus dos hashes y comprueba compatibilidad sin publicar. El servicio de evidencias coincide con el hash enviado desde Hostinger; los otros archivos se comparan con las versiones de las entregas anteriores. Una versión distinta detiene la publicación sin reemplazar archivos.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-duracion-asesor-90a21721"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/90a21721a958c10263709f9cb47af146e9bd71b1/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
for f in publicar_duracion_asesor.py duracion_asesor_release.json; do
  curl -fsSL "$BASE/$f" -o "$f"
done
sha256sum -c <<'SHA'
a58e4f0a5c410510bd7acbf8a52b8152278fc44f520d489e4049d45c4c001867  publicar_duracion_asesor.py
b5d4a17e273db87944cce27c3c2451a9db31eb6748bc382acff3840d853111f3  duracion_asesor_release.json
SHA
python3 publicar_duracion_asesor.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-duracion-asesor" --check
)
```

Debe terminar con `CHECK OK: sin escrituras.` Si muestra un conflicto, conserva la salida y no apliques el siguiente bloque.

## 2. Aplicar

Ejecuta este bloque cuando no haya llamadas en curso. Guarda el respaldo privado antes de cambiar los archivos.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-duracion-asesor-90a21721"
sha256sum -c <<'SHA'
a58e4f0a5c410510bd7acbf8a52b8152278fc44f520d489e4049d45c4c001867  publicar_duracion_asesor.py
b5d4a17e273db87944cce27c3c2451a9db31eb6748bc382acff3840d853111f3  duracion_asesor_release.json
SHA
python3 publicar_duracion_asesor.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-duracion-asesor" --apply
)
```

Conserva la ruta de `APLICADO. RESPALDO:` y recarga el historial de Ventas para cargar el JavaScript actualizado.

## 3. Comprobar con la llamada existente

1. Abre la llamada del 22/10 a las 09:25 y pulsa «Consultar grabaciones». Deben seguir apareciendo sus dos reproductores. Comprueba unos segundos de cada audio.
2. La duración del asesor debe cambiar a **00:13**, según la estadística consultada. Los **33 segundos** del otro tramo se mantienen separados; no se suman.
3. Vuelve a entrar al historial: **00:13** debe conservarse. El estado de confirmación del cierre puede seguir pendiente porque este ajuste no inventa ese evento.
4. Revisa las dos llamadas recientes: deben conservar sus audios y sus duraciones propias, **00:16** y **00:18**, según la evidencia ya confirmada.
5. Si acababas de comprobar la evidencia antes de actualizar y sigue pendiente, espera un minuto y vuelve a consultar; la caché existente limita las consultas repetidas al proveedor.

Las llamadas antiguas se concilian al consultar sus grabaciones y conservan el tiempo confirmado. No se modifica masivamente el historial ni se necesita repetir las llamadas.

## Restaurar

Sustituye `RUTA_DEL_RESPALDO` por la ruta exacta del respaldo creado. La restauración comprueba que no haya modificaciones posteriores a esta entrega.

```bash
python3 "$HOME/impe-duracion-asesor-90a21721/publicar_duracion_asesor.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-duracion-asesor" \
  --restore "RUTA_DEL_RESPALDO"
```

Las pruebas aisladas cubren el destino 150 explícito con 13 segundos, persistencia sin aviso de cierre, resultado y eventos intactos, rechazo de otra cuenta o solicitud, audios no anunciados, dos segmentos y disponibilidad parcial. La escucha real de los archivos de Hostinger se confirma después de publicar con el apartado 3.
