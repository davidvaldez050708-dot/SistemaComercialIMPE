#!/usr/bin/env python3
"""Acepta las dos entregas previas revisadas sin perder la clasificación General."""
import importlib.util
from pathlib import Path
import re
import sys

NATIVE={
 'app/services/AliadoRegistroService.php':(b"'bachillerato-general'=>['bachillerato|bachillerato-general']",1),
 'app/controllers/ConvocatoriaController.php':(b"'bachillerato-general',",2),
 'app/views/convocatorias/index.php':(b"'valor' => 'bachillerato|bachillerato-general'",1),
 'app/services/ReporteConvocatoriaDataService.php':(b"'bachillerato-general' => 'Bachillerato General'",1),
 'public/javascript/convocatorias_aliado_multiprograma_v3.js':(b"errorBox.classList.add('login-alert');",1),
}


def validate(name,data):
    if name in NATIVE:
        marker,count=NATIVE[name]
        if data.count(marker)!=count:raise RuntimeError('CONFLICTO: clasificación General ausente o duplicada: '+name)
    if name.startswith('app/') and name!='app/helpers/ProgramaEducativo.php' and re.search(br'bachillerato(?:\s+|-)?286',data,re.I):
        raise RuntimeError('CONFLICTO: referencia activa al programa retirado: '+name)
    if name=='app/models/ConvocatoriaModel.php':
        if overlay_modelo_convocatoria(data)!=data:
            raise RuntimeError('CONFLICTO: la normalización del modelo quedó incompleta.')



def overlay_modelo_convocatoria(data):
    """Only add the three reviewed identity hooks; keep every other byte/flow."""
    normalized=data.replace(b'\r\n',b'\n')
    if not normalized.startswith(b'<?php\n'):
        raise RuntimeError('CONFLICTO: cabecera PHP del modelo desconocida.')
    if len(re.findall(br'\bclass\s+ConvocatoriaModel\b',normalized))!=1:
        raise RuntimeError('CONFLICTO: clase ConvocatoriaModel ambigua.')
    anchor=br"(?m)^[ \t]*require_once[ \t]+__DIR__[ \t]*\.[ \t]*['\"]/\.\./\.\./config/db_connection\.php['\"];[ \t]*\n"
    matches=list(re.finditer(anchor,normalized))
    if len(matches)!=1:
        raise RuntimeError('CONFLICTO: conexión del modelo no reconocida.')
    helper=b"require_once __DIR__ . '/../helpers/ProgramaEducativo.php';"
    if normalized.count(helper)==0:
        pos=matches[0].end()
        normalized=normalized[:pos]+helper+b'\n'+normalized[pos:]
    elif normalized.count(helper)!=1:
        raise RuntimeError('CONFLICTO: dependencia del helper duplicada.')
    hook=b'        $datos = ProgramaEducativo::entrada($datos);'
    for name in (b'crear',b'actualizar'):
        pattern=(br'(?m)^[ \t]*public function '+name+br'\([^{}\n]*\)[ \t]*\n[ \t]*\{[ \t]*\n')
        functions=list(re.finditer(pattern,normalized))
        if len(functions)!=1 or b'$datos' not in functions[0].group():
            raise RuntimeError('CONFLICTO: firma del método '+name.decode('ascii')+' no revisada.')
        pos=functions[0].end()
        if normalized[pos:pos+len(hook)]==hook:
            continue
        # If the hook occurs elsewhere we cannot safely infer its execution order.
        if normalized.count(hook)>2:
            raise RuntimeError('CONFLICTO: normalización duplicada o desplazada.')
        normalized=normalized[:pos]+hook+b'\n'+normalized[pos:]
    if normalized.count(hook)!=2:
        raise RuntimeError('CONFLICTO: normalización ausente o duplicada.')
    return normalized.replace(b'\n',b'\r\n') if b'\r\n' in data else normalized


def overlay_modelo_con_verificacion(row,current,core):
    base=core.unpack(row['base'])
    target=core.unpack(row['target'])
    if core.sha(target)!=row['sha256']:
        raise RuntimeError('Entrega corrupta: '+row['path'])
    # The surgical fallback is allowed only while the delivery delta remains
    # exactly the three hooks verified against the historical base.
    if core.lf(overlay_modelo_convocatoria(base))!=core.lf(target):
        raise RuntimeError('CONFLICTO: cambió el alcance aprobado del modelo.')
    if current is None:
        raise RuntimeError('CONFLICTO: falta ConvocatoriaModel.php en Hostinger.')
    revised=overlay_modelo_convocatoria(current)
    validate(row['path'],core.lf(revised))
    if core.lf(revised)!=core.lf(current):
        print('CONCILIACIÓN QUIRÚRGICA: '+row['path']+' (tres líneas, personalizaciones conservadas)')
    return revised


def load_core():
    spec=importlib.util.spec_from_file_location('publicador_base',str(Path(__file__).with_name('publicar_aliados_registro.py')))
    core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    original=core.prepare_candidate
    def prepare(row,current,stage):
        choices=[row]
        for alternative in row.get('merge_bases',[]):
            if alternative['ref']!='f0474b9eb6e40ef9e228a7d87e8d7a2040b2b419':raise RuntimeError('Base alternativa no revisada.')
            candidate=dict(row);candidate['base']=alternative['base'];choices.append(candidate)
        accepted={};failures=[]
        for candidate in choices:
            try:
                result=original(candidate,current,stage);validate(row['path'],core.lf(result));accepted[core.lf(result)]=result
            except RuntimeError as error:failures.append(str(error))
        if not accepted:
            if (row['path']=='app/models/ConvocatoriaModel.php'
                    and current is not None
                    and any('CONFLICTO: personalización superpuesta' in e for e in failures)):
                return overlay_modelo_con_verificacion(row,current,core)
            raise RuntimeError(failures[0])
        if len(accepted)!=1:raise RuntimeError('CONFLICTO: las revisiones anteriores producen candidatos distintos; SITIO INTACTO.')
        return next(iter(accepted.values()))
    core.prepare_candidate=prepare
    return core


if __name__=='__main__':
    try:load_core().main()
    except (RuntimeError,OSError) as error:
        print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
