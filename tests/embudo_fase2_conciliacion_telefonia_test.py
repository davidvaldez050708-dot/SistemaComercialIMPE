#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas sin Hosting real: reemplazos conservadores e idempotencia.
Las funciones usadas no escriben archivos ni ejecutan SQL.
"""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import sys

ROOT=Path(__file__).resolve().parents[1]
TOOL=ROOT/'tools/conciliar_embudo_telefonia_personalizada_hostinger.py'
OLD='62f57c06352ad637a283a90fee234e038aab0f75'

def check(value,detail):
    if not value:raise AssertionError(detail)

def gitshow(path):
    p=subprocess.run(['git','show',OLD+':'+path],cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check(p.returncode==0,'git show '+path)
    return p.stdout.decode('utf-8')

spec=importlib.util.spec_from_file_location('conciliar',str(TOOL))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

service=gitshow(m.PHP_SERVICE)
# Personalización ajena: se conserva texto, no depende del formato del return.
service=service.replace('class TelefoniaResultadoVentasService',
 '/* CATALOGO PERSONALIZADO CONSERVADO */\nclass TelefoniaResultadoVentasService')
after=m.render_service(service)
check(after.count('IMPE_EMBUDO_PBX_POSTCOMMIT_V1')==1,'bridge único')
check('CATALOGO PERSONALIZADO CONSERVADO' in after,'conservar servicio existente')
check(after.index(m.ANCHOR_SERVICE)<after.index('IMPE_EMBUDO_PBX_POSTCOMMIT_V1'),
      'vincular después de commit/catch')
check(after.replace(m.ANCHOR_SERVICE+'\n'+m.BRIDGE,m.ANCHOR_SERVICE)==service,
      'cambio mínimo exacto de servicio')
m.lint_php(after.encode('utf-8'),m.PHP_SERVICE)

form=gitshow(m.PHP_FORM)
form=form.replace('name="extension">','name="extension"><input type="hidden" name="catalogo_version" value="1">')
form=form.replace('data-sales-form>', 'data-sales-form data-catalogo-personalizado>')
new=m.render_form(form)
check('catalogo_version' in new and 'data-catalogo-personalizado' in new,
      'conservar extensiones del modal personalizado')
check(new.count('name="prospecto_asignacion_id"')==1,'nuevo campo único')
m.lint_php(new.encode('utf-8'),m.PHP_FORM)

calls=gitshow(m.PHP_CALLS)
calls=calls.replace('data-save-url=', 'data-evidence-url="prueba" data-save-url=')
calls=calls.replace('<form method="get" class="impe-callback-filter-panel">',
 '<form method="get" class="impe-callback-filter-panel"><!-- personalizada -->')
newcalls=m.render_calls(calls)
check('data-evidence-url="prueba"' in newcalls,'mantener evidencia de PBX')
check('personalizada' in newcalls,'mantener filtros del sitio')
check(newcalls.count('data-sales-assignment-id')==1,'data de asignación una vez')
check(newcalls.count('name="prospecto_asignacion_id"')==1,'filtro de asignación una vez')
m.lint_php(newcalls.encode('utf-8'),m.PHP_CALLS)

# Formulario real de Hostinger puede llevar clase personalizada, atributos
# reordenados, GET en mayúsculas y estilos de filtros adicionales.
base_calls=gitshow(m.PHP_CALLS)
base_form='<form method="get" class="impe-callback-filter-panel">'
check(base_calls.count(base_form)==1,'fixture tiene un formulario GET')
for form_tag in [
    '<form class="filtros-comerciales-hostinger" method="get">',
    '<form id="filtroVentas" data-filtros="avanzados" method="GET" class="impe-filtros-v2">',
    "<form data-operacion='ventas' method='get' class='ventas-filtro-personalizado'>",
    '<form method = "get" data-estado="personalizado" class="filtro-ventas">',
]:
    specialized=base_calls.replace(base_form,form_tag)
    adjusted=m.render_calls(specialized)
    check(adjusted.count(form_tag)==1,'se preserva literalmente cada <form> personalizado')
    check(adjusted.count('name="prospecto_asignacion_id"')==1,'campo oculto insertado una vez')
    check(adjusted.count('data-sales-assignment-id')==1,'atributo nuevo insertado una vez')
    check(
        adjusted.replace(m.CALLS_ATTR+'data-save-url=','data-save-url=')
          .replace(form_tag+'\n  '+m.CALLS_HIDDEN,form_tag)==specialized,
        'solo se agregan las dos referencias, sin modificar filtros originales'
    )
    m.lint_php(adjusted.encode('utf-8'),m.PHP_CALLS)
# En caso de formularios GET duplicados, preferimos bloquear la publicación.
ambiguous=base_calls.replace(base_form,base_form+'\n'+base_form)
refused=False
try:m.render_calls(ambiguous)
except m.Conflicto:refused=True
check(refused,'dos formularios GET no son un ancla segura')

# Caso de host especializado: redacción de resultados con borradores múltiples.
js = """(()=>{
 'use strict';
 const root=document.querySelector('[data-sales-history]');
 const form={elements:{pbx_call_id:{},extension:{},prospecto_asignacion_id:{}}};
 const ref='123456',ownExt='150';
 const draftKey=ref=>'x:'+ref;
 const oldKey=draftKey(ref);
 const otherDraft=draftKey(ref);
 function saveDraft(){new FormData(form);}
 function saveResult(){new URLSearchParams(new FormData(form));}
 form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;
})();
"""
newjs=m.render_js(js)
check(newjs.count('IMPE_EMBUDO_ASIGNACION_RESULTADO_V1')==1,
      'ancla de JS de registro única')
check('const otherDraft=draftKey(ref)' in newjs,
      'múltiples borradores conservados')
check(newjs.count('new FormData(form)')==2,'guardar envíos de código original')
check(newjs.replace(
 'form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;\n'+m.JS_ASSIGN,
 'form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;')==js,
 'solo agregar campo en JS')
m.lint_js(newjs.encode('utf-8'),m.JS)

for function,original in [
 (m.render_service,service),(m.render_form,form),
 (m.render_calls,calls),(m.render_js,js)
]:
    modified=function(original)
    blocked=False
    try:function(modified)
    except m.Conflicto:blocked=True
    check(blocked,'bloqueo ante repetición de integración')

# Comprobar que un cambio de estructura de PBX no permite parchear.
broken=service.replace('if($callbacks)$callbacks->aplicarResultado($usuarioId,$call,$resultado);','// omitido')
blocked=False
try:m.render_service(broken)
except m.Conflicto:blocked=True
check(blocked,'bloquear devolución de llamadas personalizada desconocida')

# Simular --check y --apply en un sitio ficticio con los cuatro hashes
# esperados generados a partir de los originales personalizados.
with tempfile.TemporaryDirectory(prefix='impe-embudo-telefono-fake-host-') as d:
    site=Path(d)/'public_html'
    backup=Path(d)/'backups'
    fixtures={
        m.PHP_SERVICE:service,
        m.PHP_FORM:form,
        m.PHP_CALLS:calls,
        m.JS:js
    }
    site.mkdir()
    expected={}
    for path,value in fixtures.items():
        file=site/path
        file.parent.mkdir(parents=True,exist_ok=True)
        raw=value.encode('utf-8')
        file.write_bytes(raw)
        expected[path]=m.blob(raw)
    m.FILES=expected
    originals={name:(site/name).read_bytes() for name in expected}
    proposed=m.preparar(site)
    check(len(proposed)==4,'cuatro ficheros conciliables')
    for name,raw in originals.items():
        check((site/name).read_bytes()==raw,'check no altera '+name)
    check(not backup.exists(),'check sin respaldos')
    m.publicar(site,backup,proposed)
    check(len(list(backup.iterdir()))==1,'se generó un respaldo privado')
    check(len(m.preparar(site))==0,'idempotencia sin escrituras')
    # La copia de producción conserva íntegro su texto original
    # con solo los reemplazos autorizados.
    for name in originals:
        check((site/name).read_bytes() == proposed[name][1],
              'mismo contenido aprobado en '+name)
    # Una modificación posterior debe bloquear cualquier operación nueva.
    target=site/m.PHP_SERVICE
    target.write_bytes(target.read_bytes().replace(
        b'IMPE_EMBUDO_PBX_POSTCOMMIT_V1',b'IMPE_EMBUDO_XYZ_POSTCOMMIT_V1'
    ))
    ok=False
    try:m.preparar(site)
    except m.Conflicto:ok=True
    check(ok,'bloquear integración parcial y hashes inesperados')
print('CHECK OK: fusión mínima, respaldo, idempotencia, bloqueo de conflictos y PBX intacta.')
