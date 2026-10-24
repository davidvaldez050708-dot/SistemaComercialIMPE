# Aliados: iconos y alineación del avance

Programas y Compartir usan botones de icono, con nombre accesible y título al pasar el cursor. Conservan sus funciones y permisos. La barra ocupa el espacio de su columna y las tres acciones tienen espacio reservado. Registrar/Actualizar respuesta reutiliza el botón primario del módulo y añade un icono de conversación. El texto del aviso se centra verticalmente junto al icono.

Entrega visual de **tres archivos**: vista de Aliados, estilos del avance y script de presentación del avance. No requiere ejecutar SQL ni repetir el instalador de respuestas.

## Instalar

En SSH, descarga y comprueba:

```bash
(
  set -eu
  PAQUETE="$HOME/impe-aliados-iconos"
  BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/a4500268931ae550a051fc16f3c8aca30c0b08e0"
  mkdir -p "$PAQUETE"
  chmod 700 "$PAQUETE"
  curl -fsSL "$BASE/tools/publicar_aliados_registro.py" -o "$PAQUETE/publicar.py"
  curl -fsSL "$BASE/tools/aliados_iconos_release.json" -o "$PAQUETE/entrega.json"
  cd "$PAQUETE"
  printf '%s\n' \
    'da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar.py' \
    'd4bdfc910546fd815b1fa0cf5ed0021644027f7fe938ff1d7d243085a28577b3  entrega.json' | sha256sum -c -
  python3 publicar.py \
    --site "$HOME/domains/fundacionrededumex.org/public_html" \
    --manifest "$PAQUETE/entrega.json" \
    --backup-root "$HOME/respaldos-impe-aliados-iconos" --check
)
```

Después de que termine con **REVISIÓN COMPATIBLE** y **SITIO INTACTO**, publica:

```bash
python3 "$HOME/impe-aliados-iconos/publicar.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --manifest "$HOME/impe-aliados-iconos/entrega.json" \
  --backup-root "$HOME/respaldos-impe-aliados-iconos" --apply
```

Si la comprobación detecta un conflicto o falla un hash, no publiques. El publicador valida los tres archivos antes de escribir al sitio. Guarda la ruta `Respaldo:` que imprime al publicar.

## Comprobar

Recarga con **Ctrl+F5**. En la tabla debe aparecer el birrete de Programas, el avión de Compartir y el menú de tres puntos, separados de la barra. Al pasar el cursor se identifica cada acción. Comprueba ambos orígenes de aliado.

Abre Ver avance y respuestas: Registrar respuesta debe verse azul, con icono de conversación. El texto del aviso debe quedar centrado verticalmente en el bloque. Registrar o actualizar una respuesta conserva el flujo anterior.

## Restaurar, sólo si fuera necesario

Sustituye RUTA_DEL_RESPALDO por la ruta exacta que imprimió la publicación:

```bash
python3 "$HOME/impe-aliados-iconos/publicar.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --manifest "$HOME/impe-aliados-iconos/entrega.json" \
  --backup-root "$HOME/respaldos-impe-aliados-iconos" \
  --restore "RUTA_DEL_RESPALDO"
```

La restauración verifica hashes para proteger cambios posteriores. La entrega y su restauración no modifican registros en la BD.

## Validación

96 comprobaciones del flujo visual en seis tamaños, incluidas medidas de separación entre barra y acciones, nombres accesibles, centrado vertical y apariencia del botón. Cuatro pruebas del publicador verifican publicación, idempotencia, restauración exacta, personalizaciones/CRLF y rechazo de conflictos sin cambios al sitio. Los hashes de las descargas se verificaron contra los objetos Git.
