# Oficios: integración de preparación DOCX en main

Base revisada: `origin/main`, commit `fd5fd282`.
Rama de integración: `codex/oficios-contenido-main`.
Rama aprobada conservada: `codex/oficios-render-institucional`, commit `f4a530e8`.
Sin merge completo, cambios de BD, producción ni motor PDF.

## Selección exacta del cambio

La preparación aprobada está en `3f577b31`; las muestras con todo el cuerpo institucional
restaurado y sus comprobaciones están en `f4a530e8`. Esos commits incluyen archivos del
renderizador o dependen de ellos, por lo que no se hace cherry-pick completo.

Se trasladan únicamente dos fragmentos funcionales, literalmente desde la versión aprobada:

- `app/services/OficioDocxPdfService.php`: método `generarDocx()`. Distingue primera
  preparación de una instantánea `generacion_*` ya preparada, conserva sus bytes y usa
  la sustitución correspondiente a la plantilla general o personalizada.
- `app/controllers/OficioVinculacionController.php`: la llamada de primera preparación
  pasa `true`. Rutas, selección/carga, permisos, estados y generación de folios se conservan.

En main, `generarDocx()` volvía a procesar cualquier archivo, incluida una instantánea
preparada. La generación PDF de main ya reconoce esas instantáneas; ahora la preparación
y recuperación del DOCX respetan la misma distinción aprobada.

El método trasladado coincide literalmente con `f4a530e8`; el resto del servicio coincide
con `origin/main`. No se incorporan los cambios del renderizador, comentarios DOCX,
layouts, vista previa, biblioteca, copias de carga ni módulos ajenos de la otra rama.

## Contenido y personalización

Las frases técnicas y la tabla incorrecta procedían de la preparación de muestras de QA
en la otra rama. No existen en el código funcional de main y no se trasladan. No se añade
un filtro global de texto ni un reemplazo fijo del cuerpo de los documentos.

La prueba se extrae de la preparación aprobada en `f4a530e8` y comprueba todos los párrafos
institucionales, incluido el de validez oficial, con el XML y formato del general.
Personaliza sólo campos existentes de folio, fecha, destinatario, cargo, institución y
analista. Conserva la edición de firma/teléfono del documento preparado y la redacción
propia de otra modalidad legítima. Las plantillas y los históricos no se sobrescriben.

## Validación dirigida ejecutada

```text
php -d memory_limit=128M -d disable_functions=proc_open,exec,shell_exec,system,passthru,popen tests/oficios_contenido_preparacion_test.php --referencia=tmp/oficios-main-validacion
python tests/oficios_contenido_publicacion_test.py
php -l app/services/OficioDocxPdfService.php
php -l app/controllers/OficioVinculacionController.php
git diff --check
```

La referencia local se obtuvo ejecutando `--solo-preparacion` en `f4a530e8`, antes de cambiar
de rama. Se compararon los componentes descomprimidos completos de los tres DOCX con los
de la integración: todos son idénticos, incluidos texto, estilos, imágenes y fuentes.
Las fechas/compresión del contenedor ZIP no forman parte del diseño. Las referencias
ficticias son temporales; no se publican DOCX ni datos de producción.

También se generó el general con el servicio original de `origin/main`: sus componentes
coinciden con los del general de la integración, excluyendo únicamente las cuatro partes
de metadatos/comentarios anonimizadas para QA. Su contenido y presentación no cambian.

Sin `--referencia`, la prueba sigue comprobando el contenido institucional y recursos del
original, estructura sin párrafos/tablas añadidos, campos autorizados, conservación de
instantáneas y de otra modalidad, archivos originales y limpieza de temporales.

Regresión mínima: se ejercitan la selección por ruta, primera preparación y recuperación
del DOCX preparado. El resto del controlador y todos los métodos PDF permanecen idénticos
a main. No se repiten pruebas globales ni se prueban sesiones/BD reales.

**Límite:** main aún no incorpora `OficioRedmexPdfRenderer`. Esta integración respeta la
prohibición de cambiar el motor PDF. La equivalencia con la implementación aprobada se
demuestra para los DOCX; no se declara equivalencia visual de la salida del conversor de
main ni funcionamiento PDF en Hostinger. El renderizador validado sigue en el PR #117.

## Publicación selectiva preparada, no ejecutada

`tools/publicar_oficios_contenido_main_hostinger.sh` reutiliza la publicación reversible
ya probada, reducida a los dos fragmentos anteriores. No instala el renderizador ni
publica plantillas, muestras, configuración o SQL. Requiere el general verificado con
SHA-256 `9c4750cc6f72bb2b182daaefca4d5a9cec67b13f3c4e960c031100a9e56341ad`.

```bash
bash publicar_oficios_contenido_main_hostinger.sh --check
# Sólo tras revisar el OK y aprobar su publicación:
bash publicar_oficios_contenido_main_hostinger.sh --apply
# Usar exactamente la ruta de respaldo impresa por --apply:
bash publicar_oficios_contenido_main_hostinger.sh --restore /ruta/exacta/del/respaldo
```

Raíz predeterminada: `$HOME/domains/fundacionrededumex.org/public_html`, ajustable con
`IMPE_OFICIOS_PDF_PUBLIC`. Respaldos fuera del sitio en `$HOME/respaldos-crm`, ajustables
con `IMPE_OFICIOS_PDF_BACKUPS`. Guardar y ejecutar el publicador fuera de `public_html`.
`--check` sólo revisa; `--apply` respalda, parchea y revierte ante errores; `--restore`
rechaza ediciones posteriores. Conserva personalizaciones, LF/CRLF y permisos existentes.

Pasaron ensayos sobre réplicas locales LF, CRLF y personalizadas: check sin escrituras,
apply/restore exactos, idempotencia, rechazo de conflictos y protección de ediciones
posteriores. No se ha ejecutado contra Hostinger.

El PR se prepara con destino a main para aprobación. No se fusiona ni se despliega.
