# Aliados: avance por convocatoria y respuestas

La columna **Difusión** muestra cuántas convocatorias vigentes se compartieron y cuántas faltan. **Ver avance y respuestas** abre el detalle por publicación. El filtro «Mostrar sólo pendientes de compartir» identifica las que requieren envío.

Una publicación cuenta una sola vez como compartida si tiene un correo enviado correctamente o una confirmación manual de WhatsApp. Preparar el mensaje, abrir WhatsApp, un envío fallido o un reenvío no aumentan ese número. Los programas autorizados sin publicación todavía no cuentan como convocatorias; sólo entran publicaciones propias, vigentes, activas y con Registro habilitado. El historial conserva los envíos anteriores aunque ya no formen parte del avance vigente.

En **Compartir** también se ve el avance y se puede filtrar por pendientes antes de seleccionar una convocatoria. Después del envío, el modal permanece abierto para continuar con la siguiente. La fila se actualiza sin recargar la página.

## Respuesta de la institución

El envío no genera una respuesta humana. Cada publicación compartida empieza con **Sin respuesta registrada**. El responsable abre **Ver avance y respuestas → Registrar respuesta**, selecciona el resultado y escribe una nota:

- Sin respuesta registrada.
- Solicita información.
- La institución confirmó la difusión.
- No participará.
- Contactado, sin respuesta.

Se conserva responsable, fecha y nota. Una edición desactualizada se rechaza para no sobrescribir el trabajo de otra persona. El avance de envío no cambia al registrar una respuesta. Reenviar la convocatoria conserva la última respuesta humana.

Los aliados institucionales conservan su seguimiento, agenda y auditoría anteriores. Los manuales guardan las respuestas en una tabla propia; no crean expedientes de Vinculación.

**Permiso para registrar respuestas:** `aliados.seguimiento_convocatorias` («Dar seguimiento a convocatorias de aliados»). Para consultar: `aliados.ver` y `convocatorias.ver` o `aliados.ver_historial`. Marketing conserva su alcance nacional; Cuenta Clave, sus asignaciones/territorios; Administración consulta sin operar. Esta entrega no asigna permisos automáticamente. Si falta el botón Registrar respuesta tras instalar, revisa ese permiso en Administrador.

## Instalación en Hostinger

Requiere la entrega anterior de historial unificado, ya instalada. Preserva el controlador personalizado de esa entrega y modifica sólo doce archivos. La base operativa es `ab4af346b69d6f5ebca92fb7e0614d8f8473301c`.

Primero descarga y comprueba los archivos en SSH:

```bash
(
  set -eu
  PAQUETE="$HOME/impe-aliados-avance"
  BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/f3c2c5b86603ac0a55a7fc18c73adb3b2f84565f"
  mkdir -p "$PAQUETE"
  chmod 700 "$PAQUETE"
  curl -fsSL "$BASE/tools/publicar_aliados_registro.py" -o "$PAQUETE/publicar.py"
  curl -fsSL "$BASE/tools/aliados_avance_release.json" -o "$PAQUETE/entrega.json"
  cd "$PAQUETE"
  printf '%s\n' \
    'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
    '8aa708381d422d0451e8b0c13b001606a97f2600979985907348df6d0aa63626  entrega.json' | sha256sum -c -
  python3 publicar.py \
    --site "$HOME/domains/fundacionrededumex.org/public_html" \
    --manifest "$PAQUETE/entrega.json" \
    --backup-root "$HOME/respaldos-impe-aliados-avance" --check
)
```

Debe finalizar con **REVISIÓN COMPATIBLE** y **SITIO INTACTO**. Si aparece un error de hash o conflicto, no ejecutes la publicación. Se revisa todo antes de la primera escritura al sitio.

Después de una comprobación compatible, publica e instala la estructura aditiva:

```bash
(
  set -eu
  SITIO="$HOME/domains/fundacionrededumex.org/public_html"
  python3 "$HOME/impe-aliados-avance/publicar.py" \
    --site "$SITIO" \
    --manifest "$HOME/impe-aliados-avance/entrega.json" \
    --backup-root "$HOME/respaldos-impe-aliados-avance" --apply
  php "$SITIO/tools/migrar_aliados_respuestas.php" --apply
  php "$SITIO/tools/migrar_aliados_respuestas.php" --check
)
```

El publicador imprime la ruta del respaldo de archivos: guárdala. El instalador SQL crea la tabla nueva de respuestas manuales y, si faltan, las dos estructuras institucionales de seguimiento anteriores. No altera tablas existentes, borra registros, reenvía correos ni crea expedientes. Si detecta una estructura existente incompatible, se detiene. Repetir la instalación no duplica tablas ni datos. `--check` del instalador sólo consulta y devuelve código 2 cuando hay estructuras pendientes.

Recarga con **Ctrl+F5**.

## Comprobar el flujo

1. Abre el IEPFI: el correo de Bachillerato General ya enviado debe contar como una publicación compartida. El total dependerá de sus convocatorias vigentes, no del número de programas.
2. Abre Ver avance y respuestas: verifica el correo con su fecha y que la respuesta diga Sin respuesta registrada si aún nadie la capturó. Activa el filtro de pendientes.
3. Abre Compartir: selecciona una pendiente desde las tarjetas. Prepararla o abrir WhatsApp no debe cambiar el avance. Sólo el correo enviado correctamente o Marcar como compartida registran la difusión.
4. Si necesitas una prueba de envío, usa un destinatario interno autorizado. Al terminar se mantiene abierto el modal y se actualiza el avance. Reenviar la misma publicación no suma otra compartida.
5. Registra una respuesta real con una nota: debe cambiar únicamente el apartado de esa publicación. Comprueba que otra convocatoria y otro aliado mantienen sus datos.
6. Con una cuenta de consulta, revisa que no se pueda guardar respuestas; con Cuenta Clave, que se mantenga su alcance territorial.

Los dos primeros puntos se pueden comprobar sin enviar nada ni modificar datos.

## Restauración de archivos, sólo si fuera necesaria

Sustituye RUTA_DEL_RESPALDO por la ruta exacta que imprimió el publicador:

```bash
python3 "$HOME/impe-aliados-avance/publicar.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --manifest "$HOME/impe-aliados-avance/entrega.json" \
  --backup-root "$HOME/respaldos-impe-aliados-avance" \
  --restore "RUTA_DEL_RESPALDO"
```

La restauración verifica los archivos para no sobrescribir cambios posteriores. Conserva las tablas y respuestas capturadas; no elimina información de la BD.

## Validación

Se incluyen pruebas de avance único por publicación, recuperación de envíos históricos, filtros, seis tamaños de pantalla, continuidad del modal, notas escapadas, respuestas por origen, permisos, CSRF, concurrencia, reenvíos, migración idempotente y publicación/restauración con el controlador personalizado. Las pruebas SQL usan MySQL 8 y MariaDB 10.11 efímeros; los proveedores de envío son simulados.
