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
        if not accepted:raise RuntimeError(failures[0])
        if len(accepted)!=1:raise RuntimeError('CONFLICTO: las revisiones anteriores producen candidatos distintos; SITIO INTACTO.')
        return next(iter(accepted.values()))
    core.prepare_candidate=prepare
    return core


if __name__=='__main__':
    try:load_core().main()
    except (RuntimeError,OSError) as error:
        print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
