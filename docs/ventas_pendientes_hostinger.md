# Programadas y devoluciones de asesores

Entrega inmutable: `77d57adaf871178601e0c40c3527ff5a0ef6eaa7`.

Las programadas de la agenda personal y de resultados se deduplican por contacto y fecha. La campana avisa desde 15 minutos antes; al llegar la hora quedan como **Acción vencida**. Cerrar el recordatorio no acredita llamada ni conversación y conserva el resultado original.

Las devoluciones pendientes vencen al terminar el día en que se solicitan, en `America/Mexico_City`. Las nuevas conservan `devolucion_solicitada_at` en la ruta existente de la solicitud, en UTC. No se usa `updated_at` para extender el plazo. Las antiguas usan la fecha original de la solicitud de Recepción y se identifican así en pantalla; si esa fecha falta, se muestran pendientes sin atribuir vencimiento. No se reconstruye una fecha exacta de devolución que nunca se guardó.

Leer o marcar leída una notificación no atiende la tarea. Las programadas y devoluciones comerciales mantienen sus avisos recurrentes, con **un turno compartido cada ocho minutos** que alterna los pendientes. No se acumulan toasts simultáneos ni se consume turno mientras el CRM está oculto. Los avisos informativos de otros flujos conservan sus reglas de lectura.

Una devolución se resuelve con el mecanismo comercial existente: intento vinculado y resultado de cierre aplicable. Buzón, sin respuesta y seguimiento pendiente permiten reintentar. Iniciar una llamada o leer el aviso no la completa automáticamente.

Reportes y Desempeño, incluidos sus PDF, presentan pendientes y vencimientos **actuales por asesor**, separados del periodo de llamadas. Las vencidas están incluidas en los pendientes. No se presentan como un historial de atrasos resueltos, no fabrican llamadas y no cambian los pesos del ranking. Asesor consulta sus datos; Cuenta Clave, su equipo explícito y vigente; Dirección y Administración, su alcance nacional autorizado. Los filtros de resultado o tipo de llamada no ocultan tareas pendientes.

## Instalación

Cerrar las pestañas del CRM antes de publicar y terminar las llamadas activas. Ejecutar el bloque completo en SSH. No requiere migración SQL, nuevos permisos ni cambios al motor telefónico o a los servicios de grabación.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-ventas-pendientes-77d57ada"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/77d57adaf871178601e0c40c3527ff5a0ef6eaa7"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_ventas_pendientes.py" -o publicar_ventas_pendientes.py
curl -fsSL "$BASE/tools/ventas_pendientes_release.json" -o ventas_pendientes_release.json
printf '%s\n' \
  '3b8b30c926e0ce00f03dc03b6bd593ae24b6ad3e46ca4ff64d1d503d55d21ecd  publicar_ventas_pendientes.py' \
  'ec578318882da837a4e8bf700c9822ebc9aee9cc7986075bf1925917ef0191fb  ventas_pendientes_release.json' | sha256sum -c -
python3 publicar_ventas_pendientes.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-ventas-pendientes" --check
python3 publicar_ventas_pendientes.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-ventas-pendientes" --apply
)
```

`--check` no modifica archivos ni datos. Una versión desconocida o una dependencia distinta detiene el bloque antes de publicar. `--apply` valida los 14 archivos, conserva sus saltos de línea, crea respaldo privado y aplica únicamente los cambios del manifiesto. Si ya está instalado, no lo reinstala. Al terminar debe mostrar `APLICADO` y la ruta de `RESPALDO`.

Volver a abrir el CRM y recargar con Ctrl+F5. Revisar Programadas y Por devolver, sus filtros Vencidas, campana y bloque de pendientes en Reportes/Desempeño. Una programación a más de 15 minutos todavía no aparece en la campana.

## Restaurar

Conservar la ruta de respaldo impresa al aplicar. Sustituir el ejemplo por esa ruta exacta:

```bash
python3 "$HOME/impe-ventas-pendientes-77d57ada/publicar_ventas_pendientes.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-ventas-pendientes" \
  --restore "$HOME/respaldos-impe-ventas-pendientes/RUTA-EXACTA-IMPRESA"
```

La restauración devuelve los archivos anteriores y rechaza ediciones posteriores. No borra datos de llamadas, notas o grabaciones; las fechas nuevas de devolución quedan guardadas y las versiones anteriores simplemente no las utilizan.

## Validación

Pruebas de cambio de día UTC/México, aviso anticipado, fechas antiguas, lectura distinta de atención, repetición y cese del toast; SQL aislado de programación/resultado sin duplicados, cierre sin fabricar llamadas y propietario exacto; HTTP con filtros y permisos revocados; vistas de 320–1366 px y PDF de Reportes/Desempeño con las mismas cifras. Publicador probado en réplicas temporales LF/CRLF, sin escrituras en check, rechazo de archivos desconocidos, reinstalación y restauración exacta. Las comprobaciones de llamadas y audios existentes se mantienen; esta entrega no acredita una llamada real nueva al proveedor.
