# Aliados: historial y resumen de difusión

Las acciones mantienen el orden **Programas → Compartir → Más** en los aliados manuales e institucionales. Ambos abren el mismo historial de consulta, con convocatoria, canal, fecha, destinatario y responsable. Se elimina el formulario genérico «Registrar difusión realizada» del historial.

El directorio recupera los eventos existentes de los aliados manuales: un correo enviado aparece en Última difusión y muestra «Esperando respuesta»; una confirmación de WhatsApp muestra «Compartida manualmente». Esa confirmación sólo corresponde a la convocatoria seleccionada y no demuestra que la institución ya la redistribuyó. Abrir WhatsApp o consultar el historial no registra un envío. Los registros manuales antiguos sin una convocatoria identificable se conservan como registros históricos.

Marketing usa en el historial y Compartir el mismo alcance nacional del directorio. Cuenta Clave conserva sus asignaciones y territorios. Se requieren `aliados.ver` y `aliados.ver_historial` para consultar el historial; enviar y gestionar programas mantienen sus permisos propios. Esta entrega no asigna permisos nuevos.

## Instalar en Hostinger

Entrega fijada: `820dea4ad74d67a1edacc5cda7f6ce6108f4f1fc`.
Código operativo: `ff2c3ed0e9f59ee7b8e8ffe8d02ca91d7e565aa8`.

Publica sólo ocho archivos de Aliados. Incluye la variante exacta del controlador confirmada por SHA `6cdfd9b2…`; conserva sus bloques personalizados. No instala módulos internacionales, ejecuta SQL ni reenvía correos. Los demás siete hashes recibidos corresponden a las bases incluidas. El servicio de Registro, los QR, Convenios y Prospectos quedan fuera de los destinos.

En SSH, descargar y comprobar:

```bash
(
  set -eu
  PAQUETE="$HOME/impe-aliados-historial"
  BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/820dea4ad74d67a1edacc5cda7f6ce6108f4f1fc"
  mkdir -p "$PAQUETE"
  chmod 700 "$PAQUETE"
  curl -fsSL "$BASE/tools/publicar_aliados_registro.py" -o "$PAQUETE/publicar.py"
  curl -fsSL "$BASE/tools/aliados_historial_release.json" -o "$PAQUETE/entrega.json"
  cd "$PAQUETE"
  printf '%s\n' \
    'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
    '70f371ed142e6313a827e37d6dbeb50c8e4b0256b32783b376e786c6c655ac05  entrega.json' | sha256sum -c -
  python3 publicar.py \
    --site "$HOME/domains/fundacionrededumex.org/public_html" \
    --manifest "$PAQUETE/entrega.json" \
    --backup-root "$HOME/respaldos-impe-aliados-historial" --check
)
```

Debe finalizar con **REVISIÓN COMPATIBLE: 8 archivos** y **SITIO INTACTO** (o menos archivos si ya se aplicó parte de la entrega). Si aparece conflicto o error, no aplicar: el publicador revisa todo antes de escribir en el sitio.

Después de una revisión compatible, publicar:

```bash
python3 "$HOME/impe-aliados-historial/publicar.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --manifest "$HOME/impe-aliados-historial/entrega.json" \
  --backup-root "$HOME/respaldos-impe-aliados-historial" --apply
```

Se imprime `PUBLICADO. Respaldo: ...`. Guarda esa ruta. Recarga el módulo con Ctrl+F5.

## Comprobación en el sistema

1. En un mismo estado, comparar un aliado manual y otro de Vinculación: Programas, Compartir, Más.
2. Abrir Más → Historial de difusión: ambos muestran tarjetas con los datos existentes y un botón Cerrar; no hay formulario de registro genérico.
3. En el IEPFI, comprobar el correo ya enviado: debe aparecer en el historial y en Última difusión sin reenviarlo. Seguimiento debe mostrar Esperando respuesta si ése es el último evento.
4. Con Marketing, abrir el historial del Instituto de la Juventud Celayense. Con Cuenta Clave, comprobar que sólo puede consultar sus aliados autorizados.
5. Si se realiza una difusión nueva de prueba, usar un destinatario interno autorizado: abrir WhatsApp no debe cambiar el historial; marcar la convocatoria como compartida sí registra esa convocatoria. Un envío de correo fallido no debe reemplazar la última difusión exitosa.

No es necesario enviar correos para verificar los cuatro primeros puntos.

## Restaurar únicamente si fuera necesario

Usa la ruta exacta que imprimió la publicación, en lugar de `RUTA_DEL_RESPALDO`:

```bash
python3 "$HOME/impe-aliados-historial/publicar.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --manifest "$HOME/impe-aliados-historial/entrega.json" \
  --backup-root "$HOME/respaldos-impe-aliados-historial" \
  --restore "RUTA_DEL_RESPALDO"
```

La restauración verifica hashes para no sobrescribir cambios posteriores. No modifica los eventos de difusión almacenados en la BD.

## Validación técnica

- Normalización de las instantáneas históricas, incluidos títulos con puntos medios y registros genéricos.
- Navegador con la vista real en seis anchos: 1440, 1366, 1024, 768, 390 y 360 px; historial de consulta, permisos, notas escapadas y respuesta AJAX tardía.
- Publicador: base normal, controlador exacto de Hostinger, personalizaciones, CRLF, conflicto sin cambios en el sitio, idempotencia, sintaxis y restauración.
- CI con MySQL 8 y MariaDB 10.11: proveedor de correo simulado, eventos por convocatoria, resumen consistente, alcance de Marketing/Cuenta Clave, controlador HTTP y ausencia de escrituras durante consultas. No usa la base de producción ni envía correos reales.
