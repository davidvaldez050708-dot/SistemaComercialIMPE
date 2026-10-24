#!/usr/bin/env python3
"""Publicación selectiva de Prospectos. Sólo archivos; preflight SQL de lectura."""
import argparse,importlib.util,json,shutil,subprocess,sys
from pathlib import Path
FILES={'app/controllers/ProspectoController.php','app/models/ProspectoModel.php','app/views/prospectos/index.php','app/views/prospectos/acceso_denegado.php','public/css/prospectos_fase1.css','public/javascript/prospectos_fase1.js'}

def validar_bd(site):
    code=r'''try {
        require $argv[1].'/config/db_connection.php';
        $db=(new Database())->connect(false);
        $db->query('START TRANSACTION READ ONLY');
        $required=['formulario_registros'=>['id','nombre','apellido','fecha_nacimiento','movil','movil_secundario','tel_recomendado','correo','perfil_interes','lugar_laboras','cargo_puesto','estado_id','municipio_id','origen','created_at'],'estados'=>['id','nombre','estado'],'municipios'=>['id','nombre'],'formulario_aliados'=>['id','seguimiento_id'],'formulario_aliado_registro'=>['aliado_id','manual_id','seguimiento_id'],'aliados_manuales'=>['id','estado_id'],'seguimientos_vinculacion'=>['id','estado_id']];
        foreach($required as $table=>$columns){$actual=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetch_all(MYSQLI_ASSOC),'Field');if(array_diff($columns,$actual))throw new RuntimeException('Estructura incompleta: '.$table);}
        $origin=(bool)$db->query("SHOW TABLES LIKE 'formulario_aliado_procedencias'")->num_rows;
        if($origin){$columns=array_column($db->query('SHOW COLUMNS FROM formulario_aliado_procedencias')->fetch_all(MYSQLI_ASSOC),'Field');$origin=!array_diff(['id','tipo','registro_id','aliado_id','aliado_nombre','programa_codigo'],$columns);}
        $db->rollback();echo json_encode(['ok'=>true,'procedencias'=>$origin]);
    }catch(Throwable $e){echo json_encode(['ok'=>false]);exit(1);}'''
    run=subprocess.run(['php','-r',code,str(site)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
    try:data=json.loads(run.stdout)
    except ValueError:raise RuntimeError('No fue posible comprobar las dependencias de BD. SITIO INTACTO.')
    if run.returncode or not data.get('ok'):raise RuntimeError('Dependencias de BD no disponibles o incompletas. SITIO INTACTO. No se ejecutó SQL de escritura.')
    print('BD: tablas y columnas verificadas mediante lecturas; sin cambios.')
    if not data.get('procedencias'):print('AVISO: procedencias no disponibles; los indicadores de origen se mostrarán sin verificar.')

def main():
    spec=importlib.util.spec_from_file_location('prospectos_core',str(Path(__file__).with_name('publicar_aliados_registro.py')));core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    p=argparse.ArgumentParser(add_help=False);p.add_argument('--site',required=True);p.add_argument('--manifest');p.add_argument('--restore');args,_=p.parse_known_args()
    if args.restore is not None and not args.restore.strip():raise RuntimeError('Falta la ruta real del respaldo. No se publicaron archivos.')
    manifest=Path(args.manifest) if args.manifest else Path(__file__).with_name('prospectos_fase1_release.json')
    if not args.manifest:sys.argv.extend(['--manifest',str(manifest)])
    if args.restore is None:
        site=Path(args.site).resolve();data=json.loads(manifest.read_text(encoding='utf-8'))
        if {row['path'] for row in data['files']}!=FILES:raise RuntimeError('Archivos fuera del alcance de Prospectos Fase 1.')
        for dep in data['dependencies']:
            file=core.target(site,dep['path'])
            if not file.is_file():raise RuntimeError('Falta dependencia: '+dep['path']+'. SITIO INTACTO.')
            if any(token not in file.read_text(encoding='utf-8') for token in dep.get('contains',[])):raise RuntimeError('Dependencia incompatible: '+dep['path']+'. SITIO INTACTO.')
        if not shutil.which('php'):raise RuntimeError('Se requiere PHP CLI para comprobar dependencias y sintaxis.')
        validar_bd(site)
        if not shutil.which('node'):
            print('JS nuevo: se verificará el SHA de la entrega con sintaxis validada; Node no está instalado.')
    core.main()
if __name__=='__main__':
    try:main()
    except Exception as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
