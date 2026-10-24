# Registro: obtener-beca

La nueva dirección tendrá esta forma (el código del ejemplo es ilustrativo):

`https://fundacionrededumex.org/registro/obtener-beca/Ab7K9mP2xQ4Z`

Las ligas con `/registro/el-primer-paso-hacia-tu-meta-comienza-aqui/` y los QR ya compartidos siguen abriendo el mismo formulario del mismo aliado. También siguen funcionando las ligas largas originales. Al volver a generar una liga existente, el sistema usa `/registro/obtener-beca/` con el mismo código final.

El mensaje que ya enviaste por correo o WhatsApp conserva su dirección original y sigue siendo válido; no necesitas reenviarlo. Los nuevos enlaces y QR usarán la ruta nueva. No se crean otros aliados ni se duplican sus registros por cambiar la ruta.

## Descarga y revisión en SSH

El paquete modifica cinco archivos y conserva la ruta anterior. No necesita migración SQL y no reescribe las convocatorias, equivalencias, tokens ni registros almacenados. El publicador verifica la tabla existente mediante lecturas y prepara todos los cambios antes de publicar.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-registro-beca-e8d5ad9b"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/e8d5ad9b30129eb15829684c6e5e8bce6272192a"
mkdir -p "$PAQUETE"
cd "$PAQUETE"
for f in publicar_registro_beca.py publicar_aliados_registro.py registro_beca_release.json; do
  curl -fsSL "$BASE/tools/$f" -o "$f"
done
sha256sum -c <<'SHA'
31d9a8eebb75a4afcf3116398669c20891c3ccb28794df12a53afb6ed631f308  publicar_registro_beca.py
da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar_aliados_registro.py
e3835f55e42147857bd8b6face9c7b85566b098d02cceba038e9ee656efd32f6  registro_beca_release.json
SHA
python3 publicar_registro_beca.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-registro-beca" --check
)
```

Debe terminar con `REVISIÓN COMPATIBLE` y `SITIO INTACTO`. Los archivos de la entrega se contrastaron con los bytes descargados de GitHub. Si falta la ruta antigua o aparece un conflicto, se detiene sin publicar.

## Publicar con respaldo

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-registro-beca-e8d5ad9b"
python3 publicar_registro_beca.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-registro-beca" --apply
)
```

Guarda la ruta que imprime `PUBLICADO. Respaldo:`. Las rutas nuevas se instalan antes de activar la generación de sus ligas y se actualiza la versión del helper para evitar reutilizar su JavaScript anterior en caché.

## Prueba después de publicar

1. Abre un aliado que ya tenía liga. Pulsa «Generar enlace»: debe devolver `/registro/obtener-beca/` conservando el código final de su liga amigable anterior.
2. Abre la liga nueva en una ventana privada y comprueba el aliado y los programas ofrecidos.
3. Abre la liga antigua o escanea un QR ya compartido: debe seguir mostrando el mismo formulario del mismo aliado.
4. Envía un registro de prueba con correo nuevo y comprueba en Prospectos su aliado y programa de origen. Si ya instalaste el horario de contacto, también se conserva ese campo.

## Restaurar los componentes de generación

La restauración revierte los JavaScript incluidos. Conserva la resolución compatible y ambas rutas para que sigan funcionando también las ligas nuevas que se hayan compartido. Nunca elimina códigos o registros. Usa la ruta exacta del respaldo, no el texto de ejemplo.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-registro-beca-e8d5ad9b"
python3 publicar_registro_beca.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-registro-beca" --restore '/ruta/exacta/impresa/por/el/publicador'
```

Código distribuido: `0fc18e0cbcb9a9bf6f99b219237d4b24dab06083`. Paquete: `e8d5ad9b30129eb15829684c6e5e8bce6272192a`. El soporte del horario de contacto y los formularios no se modifica con esta entrega.

## Verificación

La [matriz de compatibilidad](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40137457722) terminó correctamente en MySQL 8.0.46 y MariaDB 10.11.19: 21 comprobaciones de equivalencia e integridad, 16 comprobaciones HTTP, 16 de carga de generadores y 8 pruebas del publicador por motor. Las ligas y convocatorias de la prueba se crearon primero con el servicio anterior. Después se comprobó el envío a Prospectos desde las dos rutas, incluyendo aliado, programa y horario, sin alterar las equivalencias, tokens ni convocatorias existentes.

También pasó la [regresión de Registro público e interno con horario](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40137457700) en ambos motores. Estos resultados corresponden al código probado en `538c9333`; las correcciones posteriores al paquete sólo afectan a la infraestructura de pruebas. La entrega está preparada para ejecutar los comandos de esta guía en Hostinger; no se realizó una instalación remota.
