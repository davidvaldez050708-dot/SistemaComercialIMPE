# Horario de contacto en Registro

Registro público e interno permiten elegir «Sin preferencia de horario» o días de la semana y un rango de horas. Se interpreta como hora local del lugar donde vive el prospecto; no representa una cita confirmada.

Se añade `formulario_registros.horario_contacto VARCHAR(255) NULL DEFAULT NULL` y una columna «Horario de contacto» en Prospectos, conservando sus 14 campos anteriores y los filtros de Fase 2. Ejemplo guardado: `Lun, Mié, Vie · 18:00–20:30 (hora local)`. Los registros anteriores quedan NULL y se muestran como «Sin indicar».

Las ligas largas y amigables ya compartidas muestran el campo al abrirse después de publicar. No se regeneran enlaces ni QR y no se modifica ninguna convocatoria. Los formularios que estaban abiertos antes de publicar pueden seguir enviándose sin horario. Se conserva la deduplicación existente del registro de aliados; no se actualizan automáticamente las respuestas históricas.

Inscripción conserva sus campos y tabla. El publicador no ejecuta migraciones ni crea componentes internacionales: conserva las personalizaciones presentes en los archivos que modifica.

## 1. Descargar y revisar en SSH

Este bloque descarga una versión fija en una carpeta privada, comprueba sus hashes y sólo revisa el sitio y el esquema.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-horario-contacto-776950e3"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/776950e32ae051f9c54ef5aae28fc17a0d6918f0"
mkdir -p "$PAQUETE"
cd "$PAQUETE"
for f in publicar_horario_contacto.py publicar_aliados_registro.py horario_contacto_release.json migrar_horario_contacto.php; do
  curl -fsSL "$BASE/tools/$f" -o "$f"
done
sha256sum -c <<'SHA'
0f8c19c781c4e2fb0ae79752727beec99db3e9c45d54848d1d815bea1bd7717f  publicar_horario_contacto.py
da1d2348d317e2073c6fea4a24b46541aaf39e3664b1c32e6d3dab4df7a262cc  publicar_aliados_registro.py
c716db1b9e189b1085a30a137187cd9fa2d6b8ee8265e4e5415d0743bbafd876  horario_contacto_release.json
104da9eb728824ba49f4c21cd09744b8ddb802f8504f7eea43becc1105df809f  migrar_horario_contacto.php
SHA
php migrar_horario_contacto.php --site "$SITIO" --check
python3 publicar_horario_contacto.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-horario-contacto" --check
)
```

Antes de instalar la columna, es normal ver `Migración de horario pendiente`. La revisión de archivos debe terminar con `REVISIÓN COMPATIBLE` y `SITIO INTACTO`. Si aparece un conflicto o un hash distinto, el comando se detiene sin publicar.

## 2. Aplicar la columna y publicar

Este bloque añade la columna opcional, instala los 12 archivos revisados y guarda un respaldo privado de los archivos originales. La migración es idempotente y no reescribe personas, procedencias, tokens ni convocatorias.

```bash
(
set -eu
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-horario-contacto-776950e3"
python3 publicar_horario_contacto.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-horario-contacto" --check
php migrar_horario_contacto.php --site "$SITIO" --apply
python3 publicar_horario_contacto.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-horario-contacto" --apply
php migrar_horario_contacto.php --site "$SITIO" --check
)
```

El resultado esperado es `PUBLICADO. Respaldo: ...` y `OK: horario_contacto instalado`. Guarda la ruta exacta del respaldo que imprimió el publicador.

## 3. Comprobación funcional

1. Abre una liga de Registro que ya habías compartido, preferiblemente en una ventana privada. Debe aparecer «¿En qué horario podemos contactarte?» en Datos de contacto. La dirección compartida sigue siendo válida.
2. Selecciona «Elegir días y horario», marca dos días y captura 18:00 a 20:30. Envía un registro de prueba con correo y móvil nuevos para evitar que la deduplicación recupere una respuesta histórica.
3. Busca ese correo en Prospectos. Su horario debe estar en «Horario de contacto» y mantener el aliado y programa de origen. Si la liga era general, conserva su origen general.
4. Repite en Registro interno con «Sin preferencia de horario». La nueva fila debe mostrar «Sin preferencia».
5. Verifica que un registro anterior muestre «Sin indicar». Un rango con fin anterior o igual al inicio debe rechazarse sin guardar.

## Restaurar archivos

Usa la ruta exacta impresa en `PUBLICADO. Respaldo:`. La restauración conserva la columna y cualquier horario ya capturado; no borra información de personas. Se detiene si algún archivo cambió después de publicar.

```bash
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
cd "$HOME/impe-horario-contacto-776950e3"
python3 publicar_horario_contacto.py --site "$SITIO" --backup-root "$HOME/respaldos-impe-horario-contacto" --restore '/ruta/exacta/impresa/por/el/publicador'
```

## Trazabilidad

- Código distribuido: `a219be2a` (SHA completo dentro de `source` del manifiesto).
- Paquete y archivos descargables: `776950e32ae051f9c54ef5aae28fc17a0d6918f0`.
- Los hashes anteriores se calcularon sobre objetos Git y se contrastaron con las descargas de GitHub, sin conversiones CRLF de Windows.
- Se conciliaron las variantes publicadas del controlador y modelo de Registro y la tipografía V3 de Prospectos. El JavaScript existente de ambos formularios se conserva.

## Verificación de la entrega

El [flujo de horario en MySQL 8.0.46 y MariaDB 10.11.19](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40124115939) terminó correctamente en ambos motores: 21 comprobaciones de validación, 13 de persistencia/migración, 58 de navegador con HTTP y BD reales, 8 pruebas del publicador, compatibilidad del controlador/modelo publicados y 35 comprobaciones con el JavaScript público de Hostinger. Incluye una liga amigable creada antes de instalar la columna.

También terminaron correctamente las regresiones de [Prospectos Fase 2](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40124115946) y [formularios por aliado](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/actions/runs/40124115970), en ambos motores. La revisión visual local cubrió Registro público e interno a 1440, 768, 390 y 360 px, además de 92 comprobaciones de Prospectos.

Los checks generales del repositorio no están todos en verde: la prueba antigua de Aliados monta la vista actual de Prospectos sin las variables de Fase 1/2; Convocatorias V5 usa un selector de alertas que ahora encuentra dos elementos; y Telefonía tiene aserciones pendientes fuera de esta entrega. Esos fallos se detallan en el PR; los archivos productivos de dichos flujos no se modifican para resolverlos en este paquete.
