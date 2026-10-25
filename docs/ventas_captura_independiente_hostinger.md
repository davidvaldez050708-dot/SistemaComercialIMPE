# Datos del prospecto: ventana independiente durante la llamada

La captura de Ventas quedaba dentro del teléfono porque el módulo que coloca las fichas independientes sólo contemplaba la captura del Analista. Además, ese módulo no se cargaba para un Asesor sin permiso de transferir.

Ahora ambas capturas usan la misma disposición: ventana independiente al lado del teléfono, estilos compartidos, ajuste al ancho de pantalla y al espacio disponible cuando aparece el teclado. En móvil la ficha ocupa el espacio disponible y puede cerrarse con su X para volver a los controles de llamada. En escritorio el teléfono conserva su posición y tamaño.

Ventas conserva sus cinco campos: nombre completo, teléfono, correo, programa de interés y notas. Se mantienen sus validaciones, borrador, servicio de guardado y referencia de llamada. El formulario del resultado final sigue su propio flujo. La entrega sólo cambia la vista principal y los dos scripts de presentación; no modifica campos, permisos, motor telefónico ni base de datos.

## 1. Descargar y comprobar

Copiar el bloque completo en SSH. Descarga la entrega fuera de `public_html` y comprueba los tres archivos del sitio sin modificarlos.

```bash
(
set -eu
umask 077
ENTREGA="$HOME/impe-ventas-captura-11a2a0d4"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/11a2a0d4232f2f75ece62c10174caeff5799b27c"
mkdir -p "$ENTREGA"
cd "$ENTREGA"
for f in publicar_ventas_captura.py ventas_captura_release.json; do
  curl -fsSL "$BASE/tools/$f" -o "$f"
done
sha256sum -c <<'SHA'
6515e025ed332573dc9554524020bf7b6ac253508977f36cb780e4a600d3ac8e  publicar_ventas_captura.py
558c87797f98b96faace258eebfedade5151f59b1b84fa365dd797b1d48e1798  ventas_captura_release.json
SHA
python3 publicar_ventas_captura.py \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-ventas-captura" --check
)
```

Continuar sólo si ambos archivos dicen `OK` y termina con `CHECK OK`. Una variante desconocida bloquea toda la publicación. La variante de Hostinger verificada conserva el aviso de JavaScript personalizado y la eliminación de «Modo clásico».

## 2. Aplicar

Aplicar sin llamadas activas. Después cerrar y reabrir las pestañas del CRM para cargar la nueva vista y sus scripts.

```bash
python3 "$HOME/impe-ventas-captura-11a2a0d4/publicar_ventas_captura.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-ventas-captura" --apply
```

Guardar la ruta impresa por `APLICADO. RESPALDO:`. Repetir la aplicación no duplica cambios. Para restaurar, sustituir la ruta de ejemplo por la ruta exacta de ese respaldo:

```bash
python3 "$HOME/impe-ventas-captura-11a2a0d4/publicar_ventas_captura.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-ventas-captura" \
  --restore "$HOME/respaldos-impe-ventas-captura/RUTA-EXACTA-DEL-RESPALDO"
```

## 3. Comprobar en el CRM

1. Durante una llamada de prueba con el Asesor, pulsar **Datos del prospecto**. La ficha debe abrirse fuera del panel del teléfono con los cinco campos habituales.
2. Escribir una nota, cerrar con X o Escape y volver a abrir: debe recuperar el borrador.
3. Guardar los datos y volver a abrir: deben conservarse y la llamada debe continuar.
4. Comprobar también un Asesor sin permiso de transferir. La captura debe abrirse igualmente.
5. Comparar con **Datos de llamada** del Analista. Deben compartir tipografía, campos, botones y disposición independiente, conservando los campos propios de cada rol.

La validación automática utiliza el shell real, Bootstrap, Manrope e iconos locales: comprueba posición fuera del teléfono, tamaño del teléfono intacto, 1366/768/390 px, viewport reducido por teclado, cierre, borrador y guardado sin recargar el motor. También verifica ocho escenarios del publicador en réplicas y regresiones del teléfono y transferencias. Las capturas de QA usan datos y servicios simulados.
