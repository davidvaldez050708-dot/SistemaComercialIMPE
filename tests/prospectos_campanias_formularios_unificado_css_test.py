#!/usr/bin/env python3
"""Comprobación estática del diseño sin navegador ni datos reales."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
central=(ROOT/'app/views/prospectos/central.php').read_text(encoding='utf-8')
partial=(ROOT/'app/views/prospectos/partials/formularios_programas.php').read_text(encoding='utf-8')
css=(ROOT/'public/css/prospectos_importador_meta.css').read_text(encoding='utf-8')
checks={
 'sin panel de configuración en la parte superior':
    "if ($seccionProspectos==='meta') require __DIR__.'/partials/formularios_programas.php';" not in central,
 'configuración solo en la vista activa':
    "<?php if ($metaEnConfiguracion): ?>" in central and
    "require __DIR__.'/partials/formularios_programas.php';" in central,
 'se conserva importación manual':
    'data-meta-abrir' in central,
 'ruta original de configuración disponible':
    'configurar_formularios=1#campanias-formularios' in central,
 'vuelta a campañas':
    'Volver a campañas' in central,
 'sin acordeón redundante':
    '<details class="central-panel central-form-config"' not in partial,
 'un encabezado institucional':
    'meta-config-intro' in partial and 'Formularios y programas educativos' in central,
 'selector archivo etiquetado':
    'for="archivoFormularios"' in partial and 'id="archivoFormularios"' in partial,
 'CSRF de detección preservado':
    'name="csrf_meta"' in partial,
 'formularios y versiones preservados':
    'name="formulario_id"' in partial and 'name="version"' in partial,
 'guardado por controlador sin cambios':
    "action=metaFormularioGuardar" in partial and "action=metaFormularioDetectar" in partial,
 'historial independiente':
    'central-form-history' in partial and 'formulariosHistorial' in partial,
 'estilos institucionales para archivo':
    '::file-selector-button' in css and '.meta-config-upload-field' in css,
 'diseño adaptable a móvil':
    '@media(max-width:740px)' in css and '.meta-config-upload' in css,
 'foco visible del botón':
    '.meta-config-action:focus-visible' in css,
}
for label,valid in checks.items():
    if not valid:
        raise SystemExit('FALLO: '+label)
    print('OK:',label)
# Verificar que el CSS completo conserve su estructura sintáctica.
depth=0;comment=False;quoted='';escaped=False;i=0
while i<len(css):
    c=css[i];n=css[i+1] if i+1<len(css) else ''
    if comment:
        if c=='*' and n=='/':comment=False;i+=1
    elif quoted:
        if escaped:escaped=False
        elif c=='\\':escaped=True
        elif c==quoted:quoted=''
    elif c=='/' and n=='*':comment=True;i+=1
    elif c in ('"',"'"):quoted=c
    elif c=='{':depth+=1
    elif c=='}':
        depth-=1
        if depth<0:raise SystemExit('FALLO: llave CSS sobrante')
    i+=1
if depth or comment or quoted:raise SystemExit('FALLO: CSS incompleto')
print('OK: %d verificaciones, CSS balanceado.'%(len(checks)+1))
