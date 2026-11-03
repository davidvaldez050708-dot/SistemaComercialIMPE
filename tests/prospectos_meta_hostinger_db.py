"""Prueba el modelo REAL reconstruido en un árbol y un esquema sintéticos."""
import base64
import gzip
import json
import os
from pathlib import Path
import subprocess
import tempfile
import uuid

ROOT=Path(__file__).resolve().parents[1]
if os.environ.get('APP_ENV')!='testing' or not os.environ.get('IMPE_DB_HOST','').startswith('127.0.0.1:'):
    raise RuntimeError('Sólo servidor local de pruebas')
compat=json.loads((ROOT/'tools/prospectos_meta_rol_hostinger_compat.json').read_text(encoding='utf-8'))
php=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='meta-rol-hostinger-') as temp:
    stage=Path(temp)
    for name in ['config/config.php','config/db_connection.php','config/private_config.php',
                 'app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php']:
        p=stage/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/name).read_bytes())
    p=stage/compat['path'];p.parent.mkdir(parents=True,exist_ok=True)
    p.write_bytes(gzip.decompress(base64.b64decode(compat['target'])))
    p=stage/compat['dependency']['path'];p.write_bytes(gzip.decompress(base64.b64decode(compat['dependency']['fixture'])))
    original=(ROOT/'tests/prospectos_permisos_db.php').read_text(encoding='utf-8').removeprefix('<?php')
    extra='''
// Las reglas locales deben convivir con el nuevo permiso Meta.
$db->query("UPDATE roles SET nombre='Coordinador ficticio' WHERE id=7");
$db->query("INSERT INTO roles VALUES (2,'Director de Operaciones',1)");
$codeIds=[];foreach($db->query('SELECT id,codigo FROM permisos')->fetch_all(MYSQLI_ASSOC) as $p)$codeIds[$p['codigo']]=(int)$p['id'];
assertProspectosDb($m->actualizarPermisosRol(2,[$codeIds['prospectos.importar_meta'],$codeIds['reportes.usuarios'],$codeIds['telefonia.configurar']],1),'Guardar permisos de Dirección');
$assigned=$m->obtenerCodigosPermisosPorRol(2);
assertProspectosDb(in_array('prospectos.importar_meta',$assigned,true)&&in_array('prospectos.ver_todos',$assigned,true),'Dirección recibe importar y su dependencia');
assertProspectosDb(in_array('reportes.usuarios',$assigned,true),'Se conserva el reporte de usuarios autorizado a Dirección');
assertProspectosDb(!in_array('telefonia.configurar',$assigned,true),'La configuración PBX sigue reservada');
$m->actualizarPermisosRol(7,[$codeIds['reportes.usuarios']],1);
assertProspectosDb(!in_array('reportes.usuarios',$m->obtenerCodigosPermisosPorRol(7),true),'Otros roles no heredan el reporte reservado');
$m->actualizarPermisosRol(2,[$codeIds['reportes.ver']],1);
assertProspectosDb(!in_array('reportes.convocatorias',$m->obtenerCodigosPermisosPorRol(2),true),'No se vuelve a forzar Reporte de convocatorias');
$control=$codeIds['telefonia.control_ver'];
$db->query('DELETE FROM rol_permisos WHERE permiso_id='.$control);
$db->query('DELETE FROM permisos WHERE id='.$control);
$m->inicializarPermisosSistema();
assertProspectosDb(in_array('telefonia.control_ver',$m->obtenerCodigosPermisosPorRol(2),true),'Concesión inicial de Control de llamadas preservada');
$m->actualizarPermisosRol(2,[],1);$m->inicializarPermisosSistema();
assertProspectosDb(!in_array('telefonia.control_ver',$m->obtenerCodigosPermisosPorRol(2),true),'La revocación del Control de llamadas persiste');
echo "PASS variante Hostinger: Dirección, Control de llamadas, reportes reservados, dependencia Meta y revocaciones.\\n";
'''
    p=stage/'tests/compat.php';p.parent.mkdir(parents=True)
    p.write_text('<?php\ntry {\n'+original+extra+'''\n} finally {
    if (isset($db,$schema) && preg_match('/^impe_test_prospectos_permisos_compat_[a-f0-9]+$/D',$schema)) $db->query("DROP DATABASE `$schema`");
}
''',encoding='utf-8')
    env={**os.environ,'IMPE_PROSPECTOS_TEST_DB':'impe_test_prospectos_permisos_compat_'+uuid.uuid4().hex[:12],
         'IMPE_PRIVATE_CONFIG_DIR':str(stage/'private'),'IMPE_BASE_URL':'http://127.0.0.1/'}
    subprocess.run([php,str(p)],cwd=str(stage),env=env,check=True)
