#!/usr/bin/env python3
"""Entrega selectiva de horario en Registro. La migración es un paso CLI separado."""
import argparse,importlib.util,json,shutil,subprocess,sys
from pathlib import Path
FILES={'app/helpers/HorarioContacto.php','app/views/formularios/partials/horario_contacto.php','public/css/horario_contacto.css','public/javascript/horario_contacto.js','app/controllers/FormularioPublicoController.php','app/controllers/FormularioController.php','app/models/FormularioRegistroModel.php','app/models/ProspectoModel.php','app/views/formularios/publico_registro.php','app/views/formularios/registro.php','app/views/prospectos/index.php','tools/migrar_horario_contacto.php'}

def validar_bd(site):
    code=r'''try {
        require $argv[1].'/config/db_connection.php';$db=(new Database())->connect(false);
        $db->query('START TRANSACTION READ ONLY');
        $row=$db->query("SHOW COLUMNS FROM formulario_registros WHERE Field='horario_contacto'")->fetch_assoc();
        $ok=$row && strtolower($row['Type'])==='varchar(255)' && $row['Null']==='YES' && $row['Default']===null && $row['Extra']==='';
        if($row&&!$ok)throw new RuntimeException('Definición incompatible');
        $db->rollback();echo json_encode(['ok'=>true,'listo'=>(bool)$ok]);
    }catch(Throwable $e){echo json_encode(['ok'=>false]);exit(1);}'''
    result=subprocess.run(['php','-r',code,str(site)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    try:data=json.loads(result.stdout)
    except ValueError:raise RuntimeError('No se pudo verificar el esquema. SITIO INTACTO.')
    if result.returncode or not data.get('ok'):raise RuntimeError('Esquema incompatible o inaccesible. SITIO INTACTO.')
    return data['listo']

def main():
    p=argparse.ArgumentParser(add_help=False);p.add_argument('--site',required=True);p.add_argument('--manifest');p.add_argument('--restore');p.add_argument('--apply',action='store_true');args,_=p.parse_known_args()
    if args.restore is not None and not args.restore.strip():raise RuntimeError('Falta la ruta real del respaldo. SITIO INTACTO.')
    spec=importlib.util.spec_from_file_location('horario_core',str(Path(__file__).with_name('publicar_aliados_registro.py')));core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    manifest=Path(args.manifest) if args.manifest else Path(__file__).with_name('horario_contacto_release.json')
    if not args.manifest:sys.argv.extend(['--manifest',str(manifest)])
    if args.restore is None:
        site=Path(args.site).resolve();data=json.loads(manifest.read_text(encoding='utf-8'))
        if {r['path'] for r in data['files']}!=FILES:raise RuntimeError('Archivos fuera del alcance del horario de Registro.')
        for dep in data.get('dependencies',[]):
            file=core.target(site,dep['path'])
            if not file.is_file():raise RuntimeError('Falta dependencia: '+dep['path']+'. SITIO INTACTO.')
            if any(t not in file.read_text(encoding='utf-8') for t in dep.get('contains',[])):raise RuntimeError('Dependencia incompatible: '+dep['path']+'. SITIO INTACTO.')
        if not shutil.which('php'):raise RuntimeError('Se requiere PHP CLI.')
        ready=validar_bd(site)
        if not ready and args.apply:raise RuntimeError('Falta aplicar migrar_horario_contacto.php --apply antes de publicar el campo. SITIO INTACTO.')
        print('BD verificada mediante lecturas. '+('Horario disponible.' if ready else 'Migración de horario pendiente.'))
    core.main()

if __name__=='__main__':
    try:main()
    except Exception as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
