# Supervisión de llamadas de asesores y analistas

La sección **Análisis → Supervisión de llamadas** permite consultar el historial, el resultado y las grabaciones verificadas. Conserva las tarjetas y los reproductores del sistema. Es una consulta de supervisión: no incluye marcador ni edición de resultados.

| Rol | Alcance |
| --- | --- |
| Administrador | Todos los asesores y analistas, incluyendo personas inactivas con historial |
| Director de Operaciones | El mismo alcance nacional |
| Cuenta Clave | Únicamente personas de su equipo vigente, con relación explícita en Territorios |

Compartir estado no concede acceso. No hay filtro de estado. Las grabaciones de una transferencia conservan sus partes y no se suman duraciones que puedan solaparse. Una duración observada se etiqueta como tal; no se presenta como duración confirmada.

## Instalar en Hostinger

Ejecutar el bloque completo en SSH. La primera comprobación no escribe en el sitio. Si detecta una versión distinta, se detiene antes de publicar. Los respaldos se guardan fuera de `public_html`.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-supervision-llamadas-a6287380"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/a62873800cae93df0ec51bf0c2a35aafce2f8a42"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_supervision_llamadas.py" -o publicar_supervision_llamadas.py
curl -fsSL "$BASE/tools/supervision_llamadas_release.json" -o supervision_llamadas_release.json
curl -fsSL "$BASE/tools/instalar_supervision_llamadas_permisos.php" -o instalar_supervision_llamadas_permisos.php
printf '%s\n' \
 '67f93166bab24b61109f4e846837bf06f962ac5754e98c34eddd7bbc790d081d  publicar_supervision_llamadas.py' \
 '3f03488594bf869c27b8f96420048cb47eb6d1ad2ae477297b66ffb7f9adc74e  supervision_llamadas_release.json' \
 'e878366382196ec1a86c547171ba959d714327c8d59506300b753abcc7c1b033  instalar_supervision_llamadas_permisos.php' | sha256sum -c -
python3 publicar_supervision_llamadas.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-supervision-llamadas" --check
python3 publicar_supervision_llamadas.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-supervision-llamadas" --apply
php instalar_supervision_llamadas_permisos.php --site "$SITIO" --backup-root "$HOME/respaldos-impe-supervision-permisos" --check
php instalar_supervision_llamadas_permisos.php --site "$SITIO" --backup-root "$HOME/respaldos-impe-supervision-permisos" --apply
)
```

El publicador añade siete archivos y actualiza únicamente el menú y las entradas de Reportes. No cambia el motor telefónico, las API de audio personales, los servicios de grabación ni los registros de llamadas. Verifica los seis servicios instalados con los hashes proporcionados de Hostinger. Los archivos nuevos se instalan antes de habilitar el menú.

Al finalizar, recargar el CRM cuando no haya una llamada en curso. El acceso directo es:

`index.php?controller=reporte&action=supervisionLlamadas`

## Permisos

La primera instalación concede a los roles 1, 2 y 6 estos dos permisos del grupo **Supervisión de llamadas**:

- **Consultar historial del equipo** (`llamadas.supervision.ver`).
- **Escuchar grabaciones del equipo** (`llamadas.supervision.audio`), que requiere también consultar el historial. Incluye reproducción y descarga.

Reinstalar conserva permisos revocados o desactivados. Ningún permiso habilita alcance nacional para Cuenta Clave ni acceso supervisor para asesores o analistas. La autorización vuelve a consultarse en la base de datos para cada listado, detalle y solicitud de audio, incluyendo avances de reproducción.

## Comprobación después de instalar

1. Dirección: cambiar entre Analistas y Asesores; filtrar persona y periodo, abrir un resultado y una grabación conocida.
2. Cuenta Clave: comprobar que sólo aparezca su equipo. Abrir una URL de otra persona debe responder con acceso denegado.
3. En una transferencia ya verificada, reproducir ambas partes. No se crean nuevas asociaciones por teléfono u horario.
4. Revocar sólo el permiso de audio: el resultado continúa disponible, pero la siguiente solicitud de audio se bloquea. Revocar consulta bloquea toda la sección.

Los historiales se construyen con propietarios persistidos de Ventas, interacciones telefónicas del analista, clasificaciones de entrada y sesiones del navegador. Las asociaciones duplicadas se resuelven con enlaces exactos. Una sesión sin vínculo de grabación inequívoco se muestra pendiente; no se adivina su audio. Se muestran 30 filas por página y se admiten hasta 3,000 registros fuente por consulta y 366 días; ampliar la búsqueda exige seleccionar persona o reducir el periodo.

Validación automatizada: SQL y HTTP aislados, alcance nacional/equipo, usuarios inactivos, revocación con sesión antigua, dos grabaciones transferidas en ambas áreas, descarga parcial 206/416, rechazo de HTML como audio, consultas sin escrituras, interfaz a 320/390/768/1366 px y publicación/restauración LF y CRLF. Los audios de prueba usan un proveedor inyectado; la reproducción real se comprueba desde el CRM después de instalar.

## Restauración

El publicador imprime `RESPALDO` con la carpeta exacta. El instalador imprime `RESPALDO DE PERMISOS` con el JSON privado. Utilizar las rutas impresas:

```bash
php "$HOME/impe-supervision-llamadas-a6287380/instalar_supervision_llamadas_permisos.php" \
 --site "$HOME/domains/fundacionrededumex.org/public_html" \
 --backup-root "$HOME/respaldos-impe-supervision-permisos" \
 --restore "/ruta/exacta/impresa/permisos-supervision.json"
python3 "$HOME/impe-supervision-llamadas-a6287380/publicar_supervision_llamadas.py" \
 --site "$HOME/domains/fundacionrededumex.org/public_html" \
 --backup-root "$HOME/respaldos-impe-supervision-llamadas" \
 --restore "/ruta/exacta/impresa/del/respaldo"
```

La restauración bloquea cambios posteriores desconocidos. No elimina llamadas, resultados ni audios.
