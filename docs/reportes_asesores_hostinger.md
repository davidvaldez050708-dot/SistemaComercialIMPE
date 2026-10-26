# Reportes y Desempeño telefónico de asesores

La entrega añade el reporte personal de llamadas y la consulta del equipo por asesor y periodo. Integra el área de asesores en Desempeño, reutiliza los componentes visuales del analista y su generador de PDF A4, encabezado institucional, tablas y paginación.

## Acceso

| Perfil | Alcance |
| --- | --- |
| Asesor, rol 3 | Exclusivamente su usuario; cambiar el asesor en la URL devuelve 403. |
| Cuenta Clave, rol 6 | Asesores relacionados expresamente con su asignación vigente en Territorios. Coincidir en el estado no basta. Se consulta la actividad personal de esos asesores. |
| Director de Operaciones, rol 2 | Todos los asesores, sin exigirle asignación territorial. |
| Administrador, rol 1 | Todos los asesores, sin exigirle asignación territorial. |

La consulta nacional incluye asesores inactivos para conservar su historial. Los permisos de consulta y exportación se validan tanto al abrir la pantalla como al descargar el PDF. Un permiso global asignado por error a un Asesor o Cuenta Clave no amplía su alcance.

## Funciones

- Periodos: Hoy, Ayer, Esta semana, Últimos 7 días, Este mes, Mes anterior y Personalizado.
- Selección de asesor en supervisión; filtros de dirección y resultado.
- Llamadas salientes, entrantes, conexiones confirmadas y conversaciones registradas.
- Tiempo confirmado exclusivamente del asesor, promedio y duraciones pendientes.
- Cumplimiento de registro, distribución de resultados, evolución gráfica y tabla diaria.
- Intentos sin confirmar y transferencias pendientes separados de las llamadas registradas.
- Detalle paginado en pantalla y detalle completo del corte en PDF.
- Organización actual: contactos programados, vencidos y devoluciones pendientes; se identifica como situación al consultar, no como una fotografía histórica del periodo.

El gráfico agrupa rangos largos en un máximo de 20 bloques legibles; la tabla conserva todos los días. Al cambiar filtros se oculta la exportación hasta actualizar, para que el PDF corresponda al corte visible. No se asignan puntuaciones, metas ni calificaciones comerciales.

## Definiciones de conteo

Una llamada registrada tiene identidad persistida; eso por sí solo no acredita conversación con una persona. Las conversaciones registradas utilizan las mismas clasificaciones del historial del asesor. La tasa de contacto requiere también conexión confirmada y divide esas conversaciones entre las llamadas registradas del mismo corte.

La identidad usa usuario, extensión y referencia de llamada, por lo que repetir avisos del proveedor no aumenta el conteo. Se conserva el propietario histórico. El periodo corresponde a la fecha de inicio registrada; los avisos tardíos se consultan sin recortarlos a las fechas del evento.

La duración utiliza las mismas reglas de eventos y evidencia persistida del historial. No suma el tiempo de Recepción al asesor. Una duración desconocida sigue pendiente y se excluye del promedio; el total confirmado puede ser parcial. El módulo no descarga audios ni consulta al proveedor, ni modifica llamadas, resultados, grabaciones o agenda.

Los rangos personalizados admiten hasta 366 días, también históricos anteriores a la ventana de 90 días de Mis llamadas. La consulta admite hasta 20,000 registros y el PDF hasta 2,000 registros completos. Si se supera el límite se solicita reducir el periodo o seleccionar un asesor; nunca se entrega un corte truncado.

## Instalar en Hostinger

Paquete inmutable: `bf492d8bb3536c6294ef9b2d99d64d4f56754791`.

Modifica 16 archivos de la interfaz, reportes y permisos. Conserva la personalización del avatar en Desempeño y el pie fijo del menú móvil. Verifica cinco dependencias de telefonía sin reemplazarlas. No cambia `index.php`, estilos globales ni el motor del teléfono. La exportación abre la descarga aparte para conservar el marco del CRM y del teléfono.

Ejecutar el bloque completo en SSH. Las comprobaciones no modifican el sitio ni los datos. La publicación respalda los archivos fuera de `public_html`, instala primero las dependencias nuevas y se detiene ante versiones desconocidas. Después valida la estructura y el generador PDF y activa los permisos del módulo por única vez.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-reportes-asesores-bf492d8b"
RESPALDOS="$HOME/respaldos-impe-reportes-asesores"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/bf492d8bb3536c6294ef9b2d99d64d4f56754791/tools"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/publicar_reportes_asesores.py" -o publicar_reportes_asesores.py
curl -fsSL "$BASE/reportes_asesores_release.json" -o reportes_asesores_release.json
curl -fsSL "$BASE/instalar_reportes_asesores_permisos.php" -o instalar_reportes_asesores_permisos.php
printf '%s\n' \
  '9be51e739dff8c36acb8f90143da7813f379cae2e6c1f0a81719c04a3df6116f  publicar_reportes_asesores.py' \
  'e20a9a318486e000364035d444990f86d016948db52519f6da7ffeda090686ca  reportes_asesores_release.json' \
  'f19a12a188068513fb2247ac19c5f0463d7f1bb9687c4d4e237b9f40d71c7b89  instalar_reportes_asesores_permisos.php' | sha256sum -c -
python3 publicar_reportes_asesores.py --site "$SITIO" --backup-root "$RESPALDOS" --check
python3 publicar_reportes_asesores.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
php instalar_reportes_asesores_permisos.php --site "$SITIO" --backup-root "$RESPALDOS/permisos" --check
php instalar_reportes_asesores_permisos.php --site "$SITIO" --backup-root "$RESPALDOS/permisos" --apply
)
```

La activación inicial añade cuatro permisos de Reportes y concede los accesos necesarios al rol 3 (propio), 6 (equipo) y 1/2 (global), con consulta y exportación de reportes y Desempeño. Guarda el estado previo de permisos en la carpeta privada. No modifica otros roles ni habilita operación telefónica para los supervisores. Repetir la instalación no reactiva permisos posteriormente revocados.

Los permisos pueden ajustarse en Roles y permisos:

| Uso | Permisos |
| --- | --- |
| Abrir reportes | `reportes.ver`, `reportes.ventas` |
| Alcance del reporte | `reportes.ventas.propio`, `.equipo` o `.global`, según el perfil |
| Exportar reporte | `reportes.exportar` |
| Abrir Desempeño | `desempeno.ver` y `desempeno.ver_propio`, `.ver_equipo` o `.ver_global` |
| Exportar Desempeño | `desempeno.exportar` |

## Comprobar en el CRM

1. Recargar con un Asesor. Abrir Reportes → Actividad telefónica. Generar Hoy y Esta semana; comprobar que sólo aparezca su usuario.
2. Abrir Desempeño. Probar un periodo personalizado y descargar ambos PDF. Verificar cifras y fechas contra el mismo corte de Mis llamadas.
3. Con Cuenta Clave, seleccionar un asesor de su equipo. Si no aparece, revisar el vínculo explícito y su vigencia en Territorios.
4. Con Dirección o Administración, consultar todos los asesores o uno en particular. Un usuario sin actividad debe aparecer con ceros y sin duración inventada.
5. Desactivar un permiso de consulta o exportación y recargar. Verificar la denegación correspondiente. Volver a habilitarlo para continuar trabajando.

## Restaurar

Primero restaurar los permisos usando el archivo exacto impreso como `RESPALDO DE PERMISOS:`; después restaurar los archivos usando la carpeta exacta impresa como `APLICADO. RESPALDO:`. Mantener este orden porque el instalador de permisos utiliza un servicio del módulo.

```bash
php "$HOME/impe-reportes-asesores-bf492d8b/instalar_reportes_asesores_permisos.php" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-reportes-asesores/permisos" \
  --restore "/ruta/exacta/al/respaldo-de-permisos.json"

python3 "$HOME/impe-reportes-asesores-bf492d8b/publicar_reportes_asesores.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-reportes-asesores" \
  --restore "/ruta/exacta/a/la/carpeta-de-respaldo-de-archivos"
```

La restauración de archivos se detiene ante cambios posteriores. La de permisos bloquea asignaciones nuevas a otros roles y conserva revocaciones de permisos previos. No restaura ni altera llamadas ni grabaciones.

## Validación

Pruebas aprobadas de la entrega: MySQL 8 y MariaDB 10.11 por SQL y HTTP, cuatro perfiles, relaciones territoriales vigentes y vencidas, usuario ajeno del mismo estado, historial inactivo, evento tardío, duración propia frente a Recepción, permisos revocados, reinstalación y restauración. Se comparan las tablas telefónicas antes y después para asegurar consulta sin escrituras.

Chromium y WebKit: vista personal y nacional, filtros, fechas personalizadas, gráfico, rango de 366 días, contenido escapado, diseño de escritorio y móvil de 320, 390 y 844 px. PDF A4 multipágina con cifras y detalle completo y regresión del PDF del analista. Publicador de 16 archivos probado con LF/CRLF, dependencias intactas, conflictos, respaldo y restauración exacta.

La instalación y comprobación final en el sitio real se realizan con el bloque anterior; las pruebas no usan cuentas, contactos ni grabaciones de producción.

## Corrección de alineación de «Exportar PDF»

La entrega `abb6f40a3bd320cf7e0f3677b6c99afb7a88b04f` centra vertical y horizontalmente el texto y el icono del botón. Sólo cambia `public/css/ventas_actividad.css`; no requiere reinstalar permisos ni cerrar el teléfono. Se comprobó en escritorio y móvil, con botones de 40 y 50 píxeles de altura.

Ejecutar este bloque completo en SSH. Comprueba las descargas, valida la versión instalada y respalda el CSS antes de aplicar. Si detecta una personalización desconocida, se detiene sin cambiar el sitio.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-boton-pdf-abb6f40a"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/abb6f40a3bd320cf7e0f3677b6c99afb7a88b04f"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_boton_pdf_asesores.py" -o publicar_boton_pdf_asesores.py
curl -fsSL "$BASE/tools/boton_pdf_asesores_release.json" -o boton_pdf_asesores_release.json
printf '%s\n' '0724cf5ac3638b23ddb7e2ea348aaa3e585a14ad66d0170870e2c06c7acbe660  publicar_boton_pdf_asesores.py' '8c35acc5c07d42193ac54f51399f1340699abc0de261ba433a418f2a20907053  boton_pdf_asesores_release.json' | sha256sum -c -
python3 publicar_boton_pdf_asesores.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-boton-pdf" --check
python3 publicar_boton_pdf_asesores.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-boton-pdf" --apply
)
```

Después, recargar la pantalla. La vista versiona el CSS con `filemtime` para solicitar automáticamente el archivo actualizado. Si fuera necesario volver al estilo anterior, utilizar el directorio exacto que mostró `APLICADO. RESPALDO:`:

```bash
python3 "$HOME/impe-boton-pdf-abb6f40a/publicar_boton_pdf_asesores.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-boton-pdf" \
  --restore "$HOME/respaldos-impe-boton-pdf/REEMPLAZAR_POR_EL_RESPALDO_GENERADO"
```
