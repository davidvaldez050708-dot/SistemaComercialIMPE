# Fecha de registro de Prospectos

## Evidencia y alcance

Hostinger confirmó `formulario_registros.created_at DATETIME DEFAULT current_timestamp()` y el SHA-256 del modelo `44a309db33436a857fd4a7e7ff5480df60a05c5ef22887c13677ed8dfb0700a2` (versión de `a219be2a`). Esa versión incluye consulta territorial por procedencia del aliado, filtros combinados y disponibilidad de contacto; se conservan íntegros.

La comprobación anterior del mismo sitio confirmó PHP `America/Mexico_City` y MySQL `SYSTEM/UTC`, con NOW igual a UTC_TIMESTAMP. El escritor FormularioRegistroModel de esa versión guarda created_at con NOW para los registros internos y públicos; FormularioAliadoService delega la inserción al mismo escritor. La consulta de Prospectos devolvía ese DATETIME UTC sin conversión y la vista imprimía created_at directamente.

La corrección convierte exclusivamente el campo de salida created_at con DateTimeImmutable y la zona IANA America/Mexico_City, después de consultar/ordenar/paginar. No interpreta ni convierte horario_contacto. Tampoco cambia filtros, consultas territoriales, escritores, reloj SQL, datos almacenados, telefonía o grabaciones. Se mantiene el formato existente Y-m-d H:i:s y la vista no se sustituye. La misma consulta sirve a la carga inicial y a las búsquedas automáticas.

El supuesto de origen UTC corresponde al escritor y configuración comprobados en este sitio. No es una conversión genérica para cualquier DATETIME de otros módulos. Un cambio futuro de zona de escritura requeriría revisar ese contrato antes de cambiar el reloj SQL.

## Validación

- `tests/prospectos_hora_mexico_mysql.py`: crea una copia efímera y una base local aleatoria con datos sintéticos; carga únicamente el DDL canónico del Registro. Comprueba datos anteriores/nuevos, cambio al día anterior, nueve combinaciones de zonas PHP/SQL para lectura, disponibilidad local intacta, filtros por territorio/aliado/programa/búsqueda, orden y paginación. Verifica que ninguna lectura cambie las filas y que el comprobador CLI no imprima datos personales. También comprueba reglas IANA históricas y conservación de valores nulos/inválidos.
- `tests/publicacion_prospectos_hora_mexico_test.py`: paquete de dos archivos, variantes exactas LF/CRLF, rechazo previo de personalizaciones desconocidas, check sin escrituras, respaldo privado, aplicación repetible y restauración protegida. Comprueba un archivo de audio y el helper telefónico como testigos inalterados.
- PHP lint del modelo, helper y comprobador.

## Publicación

`tools/publicar_prospectos_hora_mexico.py` usa `prospectos_hora_mexico_release.json`. Instala sólo el helper nuevo y el modelo confirmado; no ejecuta SQL. `--check` no escribe, `--apply` respalda fuera del sitio antes de escribir y `--restore RUTA` recupera los bytes originales si no hubo cambios posteriores. El comprobador `verificar_hora_prospectos.php --site RUTA` usa una transacción de sólo lectura y muestra relojes, tipo de columna y fecha máxima UTC/local, sin datos de prospectos. El paquete debe descargarse desde el commit inmutable de esta entrega; la instalación en producción se confirma con la salida del usuario.
