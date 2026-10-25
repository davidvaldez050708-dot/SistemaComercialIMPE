"""Ejecuta el controlador real con modelos aislados y captura el contexto de la vista."""
import json, os, pathlib, shutil, subprocess, tempfile
R=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY') or shutil.which('php')
assert php,'PHP requerido'
with tempfile.TemporaryDirectory() as tmp:
    root=pathlib.Path(tmp)
    def write(name,text):
        p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(text,encoding='utf8')
    for name in ['app/controllers/CrearConvocatoriaController.php','app/helpers/PermissionHelper.php','app/helpers/SupervisionRolePolicy.php','app/helpers/AdminRolePolicy.php']:
        write(name,(R/name).read_text(encoding='utf8'))
    write('app/models/CrearConvocatoriaModel.php', '''<?php
class CrearConvocatoriaModel {
 public function estructuraDisponible(){return true;}
 public function listarEstados(){return array_map(fn($n)=>['id'=>$n,'nombre'=>'Estado '.$n],range(1,32));}
 public function listarConveniosRecibidos(?array $ids=null){
  $GLOBALS['scope_sql']=$ids;
  $rows=[['id'=>1,'estado_id'=>1,'origen_tipo'=>'institucional'],['id'=>2,'estado_id'=>2,'origen_tipo'=>'manual'],['id'=>3,'estado_id'=>32,'origen_tipo'=>'institucional']];
  return $ids===null?$rows:array_values(array_filter($rows,fn($r)=>in_array($r['estado_id'],$ids,true)));
 }
}
''')
    write('app/models/TerritorioModel.php', '''<?php
class TerritorioModel {
 public function obtenerEstadosAsignadosUsuario($uid,$tipo){
  $GLOBALS['tipo_consultado']=$tipo;
  return $uid===11?[['id'=>1,'nombre'=>'Estado 1']]:[];
 }
}
''')
    for file in ['dashboard_head','sidebar','topbar','dashboard_footer']:write('app/views/layout/'+file+'.php','<?php')
    write('app/services/AliadoInternacionalService.php','''<?php
class Database {
 public function connect(){return new class {public function query($sql){return (object)['num_rows'=>0];}};}
}
''')
    write('app/views/convocatorias/crear_desde_convenio.php', '''<?php
echo json_encode(['estados'=>array_column($estados,'id'),'convenios'=>$convenios,'scope'=>$GLOBALS['scope_sql'],'tipo'=>$GLOBALS['tipo_consultado']??null,'crear'=>$puedeCrearDesdeConvenio]);
''')
    write('run.php','''<?php
define('BASE_URL','http://localhost/');
$case=json_decode($argv[1],true);
$_SESSION=['usuario_id'=>$case['uid'],'rol_id'=>$case['rol'],'rol'=>$case['nombre'],'permisos'=>$case['permisos']];
$_GET=['estado_id'=>2];
register_shutdown_function(static function(){if(http_response_code()===403)echo '|HTTP=403';});
require __DIR__.'/app/controllers/CrearConvocatoriaController.php';
(new CrearConvocatoriaController())->index();
''')
    def run(rol,name,uid=11,perms=None):
        case={'rol':rol,'nombre':name,'uid':uid,'permisos':perms if perms is not None else ['convenios.ver']}
        r=subprocess.run([php,str(root/'run.php'),json.dumps(case)],capture_output=True)
        assert r.returncode==0,(r.stdout,r.stderr)
        out=r.stdout.decode('utf8',errors='replace')
        if '|HTTP=403' in out:return None
        return json.loads(out)
    for role,name in [(1,'Administrador'),(2,'Director de Operaciones'),(5,'Finanzas'),(7,'Marketing'),(19,'Nuevo rol de consulta')]:
        for permission in ['convenios.ver','convenios.gestionar']:
            if role==1 and permission=='convenios.gestionar':continue # Gobierno conserva su política operativa.
            data=run(role,name,uid=12,perms=[permission])
            assert data['estados']==list(range(1,33)),(role,permission,data)
            assert data['scope'] is None and len(data['convenios'])==3 and data['tipo'] is None
            assert data['crear'] is False,'Consultar no debe conceder publicación'
    for role,name,kind in [(3,'Asesor de Ventas','ASESOR'),(4,'Analista de Datos','ANALISTA_DATOS'),(6,'Cuenta Clave','CUENTA_CLAVE')]:
        data=run(role,name)
        assert data['estados']==[1] and data['scope']==[1] and data['tipo']==kind
        assert [x['estado_id'] for x in data['convenios']]==[1],'Estado ajeno debe quedar fuera antes de renderizar'
        data=run(role,name,uid=12)
        assert data['estados']==[] and data['convenios']==[] and data['scope']==[]
        assert run(role,'Marketing')['estados']==[1],'Renombrar un rol operativo no debe ampliar su alcance'
    assert run(2,'Director de Operaciones',perms=[]) is None,'Permiso revocado debe denegar el módulo'
    assert run(19,'Consulta',perms=[]) is None,'Sin permiso no hay consulta nacional'
print('Convenios: 32 estados para roles no territoriales; tres tipos asignados, sin asignación, datos ajenos y revocación OK')
