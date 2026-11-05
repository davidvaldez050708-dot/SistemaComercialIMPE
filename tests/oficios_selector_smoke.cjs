'use strict';
/* Aislamiento de modalidades: el selector no debe confundir plantilla y Word final. */
const fs=require('fs'),assert=require('assert/strict'),cp=require('child_process');
const js=fs.readFileSync('public/javascript/oficios_vinculacion.js','utf8');
const php=fs.readFileSync('app/controllers/OficioVinculacionController.php','utf8');
const preview=fs.readFileSync('app/services/OficioPreviewService.php','utf8');
const converter=fs.readFileSync('app/services/OficioDocxPdfService.php','utf8');
const css=fs.readFileSync('public/css/oficios_selector_compacto.css','utf8');
cp.execFileSync('node',['--check','public/javascript/oficios_vinculacion.js']);
for(const mode of ['predeterminada','personalizada','documento_final']){
 assert.ok(js.includes("id: '"+mode+"'"),'No disponible: '+mode);
}
assert.match(js,/data-oficio-documento-final/);
assert.match(js,/id="oficioDocumentoFinal"/);
assert.match(js,/formulario.append\('archivo_oficio', archivoPlantilla, archivoPlantilla.name\)/);
assert.match(js,/20 \* 1024 \* 1024/,'Archivo DOCX límite 20MB');
assert.match(js,/tipoPlantilla === 'personalizada'/);
assert.match(php,/\$_FILES\['archivo_oficio'\]/);
assert.match(php,/if \(!\$documentoFinal\)/,'No rellenar Word terminado');
assert.match(php,/&& !empty\(\$resultado\['existente'\]\)/,'No conservar carga huérfana si ya hay oficio');
assert.match(converter,/\$prepararPlantilla/,'La preparación no debe confundirse con la descarga');
assert.match(preview,/Ciudad de México, a /,'Ciudad de emisión institucional');
assert.match(preview,/\$esPredeterminada/,'El cambio no debe alterar plantillas externas');
assert.match(css,/\.oficio-selector-institucional/);
assert.match(js,/class="list-group-item oficio-selector-opcion"/,
  'Las tarjetas no deben mezclar d-flex de Bootstrap con grid');
assert.doesNotMatch(js,/class="list-group-item d-flex gap-2 align-items-start"/,
  'Se debe retirar la combinación que superponía iconos y textos');
assert.match(js,/class="form-check-input oficio-selector-radio"/);
assert.match(js,/class="oficio-selector-card-icon"/);
assert.match(css,/display: flex !important;/);
assert.match(css,/flex: 0 0 39px !important;/,
  'El icono tiene anchura fija separada del texto');
assert.match(css,/flex: 1 1 0 !important;/,
  'La columna de texto debe ocupar sólo el espacio restante');
assert.match(css,/content: none !important;/,
  'No deben competir pseudo-iconos decorativos del diseño anterior');
assert.match(js,/class="oficio-file-input" id="oficioDocumentoFinal" type="file"/,
  'El mismo input de subida debe permanecer operativo');
assert.match(js,/class="oficio-file-surface" for="oficioDocumentoFinal"/,
  'El selector visible debe abrir el campo de archivo');
assert.match(js,/data-oficio-documento-nombre aria-live="polite"/);
assert.match(js,/\[data-oficio-documento-nombre\]/,
  'Mostrar el nombre del archivo elegido y restablecerlo');
assert.match(css,/input\.oficio-file-input:focus-visible \+ \.oficio-file-surface/,
  'La selección por teclado mantiene un indicador de enfoque');
assert.match(css,/\[data-oficio-documento-final\]:not\(\.d-none\)/,
  'Los estilos están encapsulados y respetan el selector activo');
console.log('PASS selector: 3 modalidades, archivo final sin relleno, fecha CDMX y estilos encapsulados');
