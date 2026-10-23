"""Paquete incremental con dependencia canónica primero y variante PR114 revisada."""
import argparse
import base64
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='1a87de2caa779309c613972fe9495ff76c9b5a03'
OPTIONAL_GENERAL='f0474b9eb6e40ef9e228a7d87e8d7a2040b2b419'
parser=argparse.ArgumentParser()
parser.add_argument('--source',required=True)
parser.add_argument('--output',default=str(ROOT/'tools/bachillerato_unificacion_release.json'))
args=parser.parse_args()
def git(*parts):return subprocess.check_output(['git']+list(parts),cwd=str(ROOT))
def pack(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def canonizar_modelo(data):
    text=data.replace(b'\r\n',b'\n').decode('utf-8')
    header=r'(require_once[^\n]*db_connection\.php[^\n]*;\n)'
    if len(re.findall(header,text))!=1:raise RuntimeError('Cabecera del modelo no revisada')
    text=re.sub(header,r"\1require_once __DIR__ . '/../helpers/ProgramaEducativo.php';\n",text)
    for name in ('crear','actualizar'):
        pattern=r'(public function '+name+r'\([^\{]+\)\s*\{)'
        if len(re.findall(pattern,text))!=1:raise RuntimeError('Firma no revisada: '+name)
        text=re.sub(pattern,r'\1\n        $datos = ProgramaEducativo::entrada($datos);',text)
    return text.encode('utf-8')

def replace_once(text,old,new):
    if text.count(old)!=1:raise RuntimeError('Variante internacional no revisada: '+old)
    return text.replace(old,new,1)

def canonizar_formulario(name,data):
    text=data.replace(b'\r\n',b'\n').decode('utf-8')
    if name.endswith('Controller.php'):
        text=replace_once(text,"'Bachillerato 286'","'Bachillerato General'")
        text=replace_once(text,"$limpiar($entrada['perfil_interes'] ?? '')","ProgramaEducativo::nombre($limpiar($entrada['perfil_interes'] ?? ''))")
    elif name.endswith('Model.php'):
        text=replace_once(text,"require_once __DIR__ . '/../../config/db_connection.php';","require_once __DIR__ . '/../../config/db_connection.php';\nrequire_once __DIR__ . '/../helpers/ProgramaEducativo.php';")
        text=replace_once(text,'public function guardar($datos)\n    {','public function guardar($datos)\n    {\n        $datos = ProgramaEducativo::entrada($datos);')
    else:
        text=replace_once(text,"require_once __DIR__ . '/FormularioUbicacionService.php';","require_once __DIR__ . '/FormularioUbicacionService.php';\nrequire_once __DIR__ . '/../helpers/ProgramaEducativo.php';")
        text=replace_once(text,"$aliado['programas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);","$aliado['programas'] = ProgramaEducativo::programas($stmt->get_result()->fetch_all(MYSQLI_ASSOC));")
        text=replace_once(text,"$p['codigo']===$fila['programa_codigo']","$p['codigo']===ProgramaEducativo::codigo($fila['programa_codigo'])")
        text=replace_once(text,"if ($programa['nombre'] === $nombre) { return $programa['codigo']; }","if (ProgramaEducativo::nombre($programa['nombre']) === ProgramaEducativo::nombre($nombre)) { return ProgramaEducativo::codigo($programa['codigo']); }")
        old="""$clave = hash('sha256', json_encode([$datos['correo'], $datos['movil'], $datos['fecha_nacimiento'], $codigo], JSON_UNESCAPED_UNICODE));
            if (isset($aliado['convocatoria_id'])) { $clave=hash('sha256',$clave.':'.$aliado['convocatoria_id']); }"""
        new="""[$clave,$claveAnterior] = ProgramaEducativo::clavesProcedencia(
                [$datos['correo'], $datos['movil'], $datos['fecha_nacimiento'], $codigo],
                isset($aliado['convocatoria_id']) ? (int)$aliado['convocatoria_id'] : null
            );"""
        text=replace_once(text,old,new)
        text=replace_once(text,'AND clave = ?\'',"AND clave IN (?,?) ORDER BY id LIMIT 1'")
        text=replace_once(text,"$stmt->bind_param('iss', $aliado['id'], $tipo, $clave);","$stmt->bind_param('isss', $aliado['id'], $tipo, $clave, $claveAnterior);")
    return text.encode('utf-8')

files=[]
for name in git('diff','--name-only',BASE,args.source).decode().splitlines():
    if not (name.startswith(('app/','public/')) or name in ['database/migrations/2026_10_21_aliados_registro.sql','tools/migrar_aliados_registro.php','tools/unificar_bachillerato_general.php','tools/lib/UnificarBachillerato.php']):continue
    target=git('show',args.source+':'+name)
    exists=subprocess.run(['git','cat-file','-e',BASE+':'+name],cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE).returncode==0
    original=git('show',BASE+':'+name) if exists else None
    row={'path':name,'sha256':hashlib.sha256(target).hexdigest(),'base':pack(original) if exists else None,'target':pack(target)}
    if exists:
        optional=git('show',OPTIONAL_GENERAL+':'+name)
        if optional!=original:row['merge_bases']=[{'ref':OPTIONAL_GENERAL,'base':pack(optional)}]
    files.append(row)
spec=importlib.util.spec_from_file_location('pr114_overlay',str(ROOT/'tools/publicar_ajustes_aliados_v3.py'))
historical=importlib.util.module_from_spec(spec);spec.loader.exec_module(historical)
name='app/models/ConvocatoriaModel.php'
original=historical.overlay_convocatoria_model(git('show','20ec4fb64f211b06cde114da76d6c84e65a96db5:'+name),git('show',historical.BASE+':'+name),git('show',historical.SOURCE+':'+name))
candidate=canonizar_modelo(original)
row=next(row for row in files if row['path']==name)
row['variants']=[{'ref':'modelo-internacional-pr114-v3','base':pack(original),'target':pack(candidate),'sha256':hashlib.sha256(candidate).hexdigest()}]
previous=json.loads((ROOT/'tools/aliados_registro_release.json').read_text(encoding='utf-8'))
for name in ['app/controllers/FormularioPublicoController.php','app/models/FormularioRegistroModel.php','app/services/FormularioAliadoService.php']:
    legacy=next(row for row in previous['files'] if row['path']==name)
    original=gzip.decompress(base64.b64decode(next(v for v in legacy['variants'] if v['ref'].startswith('20ec4fb'))['target']))
    candidate=canonizar_formulario(name,original)
    row=next(row for row in files if row['path']==name)
    row['variants']=[{'ref':'formularios-internacionales-pr114-registro','base':pack(original),'target':pack(candidate),'sha256':hashlib.sha256(candidate).hexdigest()}]
files.sort(key=lambda row:(0 if row['path']=='app/helpers/ProgramaEducativo.php' else 1,row['path']))
Path(args.output).write_text(json.dumps({'base':BASE,'source':args.source,'files':files},indent=2)+'\n',encoding='utf-8')
print('ENTREGA: {} archivos; código y herramientas, SQL nunca automático'.format(len(files)))
