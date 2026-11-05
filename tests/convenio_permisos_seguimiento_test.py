"""Ejecuta el controlador real con servicios aislados; sin SQL ni correo."""
import json, os, re, subprocess, tempfile
from pathlib import Path
R = Path(__file__).resolve().parents[1]
source = (R/'app/controllers/SeguimientoFlujoController.php').read_text(encoding='utf8')
with tempfile.TemporaryDirectory(prefix='impe-convenio-permisos-') as d:
    root = Path(d)
    (root/'controller.php').write_text(re.sub(r'^require_once .*;\s*$', '', source, flags=re.M), encoding='utf8')
    (root/'fixture.php').write_text('''<?php
$case=json_decode($argv[1],true);
$_SESSION=['usuario_id'=>$case['usuario']??8,'rol'=>'Analista de Datos','rol_id'=>4];
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['seguimiento_id'=>1,'accion'=>$case['accion']??'ENVIAR_DOCUMENTACION_CONVENIO'];
$_GET=['seguimiento_id'=>1,'version_id'=>2];$_FILES=[];
function tienePermiso($p){global $case;return in_array($p,$case['permisos'],true);}
function esRolSupervisionNacional(){return false;}
class ServicioAislado {
 function __call($name,$args){global $case;
  if(!empty($case['ajeno']))return ['ok'=>false,'codigo_http'=>403,'mensaje'=>'Expediente fuera de alcance'];
  if(!empty($case['etapa']))return ['ok'=>false,'codigo_http'=>409,'mensaje'=>'Etapa no habilitada'];
  if(str_starts_with($name,'obtenerArchivo'))return ['ok'=>true,'version'=>['seguimiento_id'=>1,'ruta_absoluta'=>__DIR__.'/documento.pdf','nombre_original'=>'documento.pdf']];
  return ['ok'=>true,'servicio'=>$name];
 }
}
class SeguimientoVinculacionModel {
 function obtenerSeguimientoAnalista($usuario,$id){global $case;return empty($case['ajeno'])?['id'=>$id]:false;}
}
require __DIR__.'/controller.php';
register_shutdown_function(function(){echo "\\nHTTP=".(http_response_code()?:200);});
$r=new ReflectionClass('SeguimientoFlujoController');$c=$r->newInstanceWithoutConstructor();
foreach(['convenioDocumentosService','postEnvioService','reunionResultadoService'] as $p)$r->getProperty($p)->setValue($c,new ServicioAislado());
$method=$case['metodo']??'registrarPostEnvio';$c->$method();
''', encoding='utf8')
    (root/'documento.pdf').write_bytes(b'%PDF-1.4 prueba')
    def run(permisos, metodo='registrarPostEnvio', **extra):
        case=dict(permisos=permisos, metodo=metodo, **extra)
        out=subprocess.check_output([os.getenv('PHP_BIN','php'), str(root/'fixture.php'), json.dumps(case)], cwd=root).decode('utf8')
        body,code=out.rsplit('\nHTTP=',1)
        return int(code),body
    operar=['seguimientos_vinculacion.operar_propios']
    leer=['seguimientos_vinculacion.ver']
    for accion in ['ENVIAR_DOCUMENTACION_CONVENIO','REGISTRAR_CONVENIO_RECIBIDO','REGISTRAR_CONVENIO_CORREGIDO','APROBAR_CONVENIO_RECIBIDO','SOLICITAR_CORRECCIONES_CONVENIO','FORMALIZAR_CONVENIO']:
        code,body=run(operar,accion=accion);assert code==200 and json.loads(body)['ok'],(accion,code,body)
        assert run([],accion=accion)[0]==403
        assert run(['convenios.ver','convenios.gestionar'],accion=accion)[0]==403
        assert run(operar,accion=accion,ajeno=True)[0]==403
        assert run(operar,accion=accion,etapa=True)[0]==409
    assert run(operar,'borradorConvenio')[0]==200
    assert run([],'borradorConvenio')[0]==403
    assert run(operar,'borradorConvenio',ajeno=True)[0]==403
    assert run(leer,'archivoConvenio')[0]==200
    assert run(leer,'archivoConvenio',ajeno=True)[0]==403
    assert run([],'archivoConvenio')[0]==403
    assert run(['convenios.ver','convenios.gestionar'],'archivoConvenio')[0]==403
    assert run(operar,usuario=0)[0]==403
print('PASS: seis acciones y borrador con permisos de Seguimiento, consulta autorizada, bloqueo sin permisos/expediente ajeno/etapa incorrecta; convenios.* no concede acceso; sin SQL ni correo.')
