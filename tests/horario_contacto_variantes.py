"""Prueba el bind de ubicación publicado y los controles CSRF conservados."""
import base64,gzip,json,os,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
assert os.getenv('APP_ENV')=='testing' and os.getenv('IMPE_DB_NAME','').startswith('impe_test_handly_horario_')
data=json.loads((ROOT/'tools/horario_contacto_release.json').read_text(encoding='utf8'))
with tempfile.TemporaryDirectory() as d:
    site=Path(d)
    for directory in ['config','app/models','app/helpers','app/services','app/controllers']:(site/directory).mkdir(parents=True,exist_ok=True)
    def shim(path,original):
        (site/path).write_text('<?php require_once '+json.dumps(str(ROOT/original).replace('\\','/'))+';',encoding='utf8')
    shim('config/db_connection.php','config/db_connection.php')
    for name in ['HorarioContacto.php','ProgramaEducativo.php']:shim('app/helpers/'+name,'app/helpers/'+name)
    for path in ['app/models/FormularioRegistroModel.php','app/controllers/FormularioPublicoController.php']:
        row=next(r for r in data['files'] if r['path']==path)
        (site/path).write_bytes(gzip.decompress(base64.b64decode(row['variants'][0]['target'])))
    (site/'app/services/FormularioUbicacionService.php').write_bytes(subprocess.check_output(['git','show','20ec4fb64f211b06cde114da76d6c84e65a96db5:app/services/FormularioUbicacionService.php'],cwd=ROOT))
    code=r'''require $argv[1].'/app/models/FormularioRegistroModel.php';
    $db=(new Database())->connect(false);
    foreach(['formulario_registros','formulario_inscripciones'] as $t)$db->query("ALTER TABLE $t ADD pais_nombre VARCHAR(120) NULL,ADD estado_provincia VARCHAR(120) NULL,ADD ciudad VARCHAR(120) NULL");
    $d=['nombre'=>'Variante','apellido'=>'Publicada','fecha_nacimiento'=>'1990-01-01','movil'=>'7771234567','correo'=>'variante@example.test','perfil_interes'=>'Bachillerato General','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>1,'municipio_id'=>1,'creado_por'=>null,'origen'=>'PUBLICO','horario_contacto'=>'Lun · 18:00–20:00 (hora local)'];
    $id=(new FormularioRegistroModel())->guardar($d);
    $r=$db->query('SELECT horario_contacto,pais_nombre,estado_provincia,ciudad FROM formulario_registros WHERE id='.$id)->fetch_assoc();
    if($r['horario_contacto']!==$d['horario_contacto']||$r['pais_nombre']!==null||$r['ciudad']!==null)throw new RuntimeException('Bind incompatible');
    echo "PASS modelo publicado PR114: horario y ubicación conservados\n";'''
    subprocess.check_call(['php','-r',code,str(site)],cwd=ROOT)
    for csrf,message in [('', 'Recarga el formulario'),('test','Selecciona tu disponibilidad')]:
        code='require $argv[1]."/app/controllers/FormularioPublicoController.php";$_SERVER["REQUEST_METHOD"]="POST";$_SESSION=["csrf_formulario_publico"=>"test"];$_GET=[];$_POST=["csrf_formulario_publico"=>'+json.dumps(csrf)+',"contacto_disponibilidad"=>"invalid"];(new FormularioPublicoController())->guardarRegistro();'
        result=subprocess.check_output(['php','-r',code,str(site)],cwd=ROOT).decode();assert message in json.loads(result)['mensaje'],result
    print('PASS controlador publicado: CSRF y validación de horario conservados')
