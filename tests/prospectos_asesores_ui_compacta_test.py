#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Contrato visual y de compatibilidad de formularios 1A.3, sin BD ni navegador."""
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
view=(ROOT/'app/views/prospectos/partials/configuracion_asesores.php').read_text(encoding='utf-8')
css=(ROOT/'public/css/prospectos_asesores.css').read_text(encoding='utf-8')
js=(ROOT/'public/javascript/prospectos_asesores.js').read_text(encoding='utf-8')
tests={
  'sigue cargando fotografía de Usuarios': 'renderAvatarUsuario(' in view and "'foto_perfil'" in view,
  'sigue permitiendo múltiples programas': 'name="programas[]"' in view and 'data-asesores-programa' in view,
  'selección completa por grupo intacta': 'data-asesores-todos' in view and 'refrescarGrupo' in js,
  'controles visuales en programas': bool(re.search(r'name="programas\[\]".*?<span class="asesores-check-ui"',view,re.S)),
  'controles visuales en grupos': bool(re.search(r'data-asesores-todos.*?<span class="asesores-check-ui"',view,re.S)),
  'controles visuales en días': bool(re.search(r'data-asesores-dia-check.*?<span class="asesores-check-ui"',view,re.S)),
  'campo POST de disponibilidad intacto': 'name="activo" value="1"' in view and 'input[name="activo"]' in js,
  'jornada sigue siendo medio/tiempo completo': 'name="jornada"' in view and 'MEDIO_TIEMPO' in view and 'TIEMPO_COMPLETO' in view,
  'los días mantienen valores y horas': 'name="dias[<?= $dia ?>][activo]"' in view and 'name="dias[<?= $dia ?>][desde]"' in view and 'name="dias[<?= $dia ?>][hasta]"' in view,
  'disponibilidad en fila junto a jornada': 'asesores-form-grid' in view and 'asesores-disponibilidad' in view and 'asesores-jornada' in view,
  'interruptor y no check cuadrado': 'asesores-switch' in view and 'input:checked+.asesores-switch' in css,
  'foco de teclado en interruptor': 'input:focus-visible+.asesores-switch' in css,
  'foco de teclado en opciones': 'input[type="checkbox"]:focus-visible+.asesores-check-ui' in css,
  'checkbox nativo oculto visualmente, no display none': 'clip-path:inset(50%)' in css,
  'marca de selección parcial en Todos': 'input[type="checkbox"]:indeterminate+.asesores-check-ui' in css,
  'grupos compactos a 3 columnas': 'asesores-programas-grupos{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))' in css,
  'días conservan dos columnas': 'asesores-dias{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))' in css,
  'adaptación móvil': '@media(max-width:700px)' in css and 'asesores-programas-grupos{grid-template-columns:1fr}' in css,
  'versionado y CSRF intactos': 'name="csrf_asesores"' in view and 'name="version"' in view,
  'sin scripts nuevos ni SQL en el formulario': '<script' not in view and 'CREATE TABLE' not in view,
}
for name,ok in tests.items():
    if not ok:
        raise SystemExit('FALLO: '+name)
    print('OK:',name)
# Detección básica de llaves CSS no balanceadas fuera de strings/comentarios:
depth=0;in_comment=False;in_string=None;escaped=False
i=0
while i<len(css):
    c=css[i];nextchar=css[i+1] if i+1<len(css) else ''
    if in_comment:
        if c=='*' and nextchar=='/':
            in_comment=False;i+=1
    elif in_string:
        if escaped:escaped=False
        elif c=='\\':escaped=True
        elif c==in_string:in_string=None
    elif c=='/' and nextchar=='*':
        in_comment=True;i+=1
    elif c in ('"',"'"):
        in_string=c
    elif c=='{':depth+=1
    elif c=='}':
        depth-=1
        if depth<0:raise SystemExit('FALLO: llave CSS inesperada')
    i+=1
if depth!=0 or in_comment or in_string:
    raise SystemExit('FALLO: estructura CSS no balanceada')
print('OK: estructura CSS balanceada')
print('OK: %d verificaciones visuales y de compatibilidad.'%(len(tests)+1))
