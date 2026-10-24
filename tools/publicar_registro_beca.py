#!/usr/bin/env python3
"""Ruta obtener-beca con compatibilidad permanente para enlaces ya compartidos."""
import argparse,importlib.util,json,shutil,subprocess,sys,tempfile
from pathlib import Path
FILES={'app/services/RegistroEnlaceService.php','public/javascript/registro_enlaces_amigables.js','public/javascript/convocatorias_gestion_v5.js','registro/obtener-beca/index.php','registro/obtener-beca/.htaccess'}
PERSISTENTES={'app/services/RegistroEnlaceService.php','registro/obtener-beca/index.php','registro/obtener-beca/.htaccess'}
def validar_bd(site):
    code=r'''try {
        require $argv[1].'/config/db_connection.php';$db=(new Database())->connect(false);$db->query('START TRANSACTION READ ONLY');
        $cols=array_column($db->query('SHOW COLUMNS FROM registro_enlaces_amigables')->fetch_all(MYSQLI_ASSOC),'Type','Field');
        if(($cols['codigo']??'')!=='varbinary(12)'||($cols['destino_hash']??'')!=='binary(32)'||($cols['parametros']??'')!=='text')throw new RuntimeException('Estructura incompatible');
        $db->rollback();echo json_encode(['ok'=>true]);
    }catch(Throwable $e){echo json_encode(['ok'=>false]);exit(1);}'''
    r=subprocess.run(['php','-r',code,str(site)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    try:data=json.loads(r.stdout)
    except ValueError:raise RuntimeError('No se pudo verificar la tabla de enlaces. SITIO INTACTO.')
    if r.returncode or not data.get('ok'):raise RuntimeError('La tabla de enlaces amigables no está disponible o es incompatible. SITIO INTACTO.')
    print('Tabla de enlaces verificada mediante lecturas. No se cambian registros ni códigos.')
def main():
    spec=importlib.util.spec_from_file_location('beca_core',Path(__file__).with_name('publicar_aliados_registro.py'));core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core);original_restore=core.restore
    def restore(site,backup):
        info=json.loads((backup/'respaldo.json').read_text(encoding='utf-8'))
        if info['site']!=str(site):raise RuntimeError('El respaldo pertenece a otro sitio')
        for row in info['files']:
            file=core.target(site,row['path']);old=core.target(backup/'original',row['path'])
            if not file.is_file() or core.sha(file.read_bytes())!=row['installed']:raise RuntimeError('Restauración detenida: archivo modificado desde la publicación: '+row['path'])
            if row['original'] is not None and (not old.is_file() or core.sha(old.read_bytes())!=row['original']):raise RuntimeError('Respaldo original incompleto')
        selected=[r for r in info['files'] if r['path'] not in PERSISTENTES]
        with tempfile.TemporaryDirectory(prefix='restaurar-registro-beca-',dir=str(backup.parent)) as directory:
            temp=Path(directory);copy=dict(info);copy['files']=selected;(temp/'respaldo.json').write_text(json.dumps(copy),encoding='utf-8')
            for row in selected:
                if row['original'] is not None:
                    target=core.target(temp/'original',row['path']);target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(str(backup/'original'/row['path']),str(target))
            original_restore(site,temp)
        print('CONSERVADO: rutas anterior y obtener-beca y resolución de códigos ya compartidos. No se ejecutó SQL.')
    core.restore=restore
    p=argparse.ArgumentParser(add_help=False);p.add_argument('--site',required=True);p.add_argument('--manifest');p.add_argument('--restore');args,_=p.parse_known_args()
    if args.restore is not None and not args.restore.strip():raise RuntimeError('Falta la ruta real del respaldo. SITIO INTACTO.')
    manifest=Path(args.manifest) if args.manifest else Path(__file__).with_name('registro_beca_release.json')
    if not args.manifest:sys.argv.extend(['--manifest',str(manifest)])
    if args.restore is None:
        site=Path(args.site).resolve();data=json.loads(manifest.read_text(encoding='utf-8'))
        if {r['path'] for r in data['files']}!=FILES:raise RuntimeError('Archivos fuera del alcance de la ruta Registro obtener-beca.')
        for dep in data['dependencies']:
            file=core.target(site,dep['path'])
            if not file.is_file() or any(t not in file.read_text(encoding='utf-8') for t in dep['contains']):raise RuntimeError('Falta la ruta anterior compatible: '+dep['path']+'. SITIO INTACTO.')
        validar_bd(site)
    core.main()
if __name__=='__main__':
    try:main()
    except Exception as e:print('ERROR: '+str(e),file=sys.stderr);sys.exit(1)
