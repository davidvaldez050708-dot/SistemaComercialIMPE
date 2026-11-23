import ast, importlib.util, json, subprocess, sys, tempfile, time
from pathlib import Path

root=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(root/'tools'))
import publicar_consultas_repetidas as pub
spec=json.loads((root/'tools/consultas_repetidas_release.json').read_text(encoding='utf-8'))
fixture=(root/'tests/fixtures/rendimiento_metodos_instalados_20261123.txt').read_text(encoding='utf-8')
parts=fixture.split('ARCHIVO: ')[1:]
methods={}
for part in parts:
    heading,body=part.split('\n',1)
    filename,method=heading.split(' — METODO: ')
    methods[(filename,method.strip())]=body.strip()

def run(args):
    p=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert p.returncode==0,(p.stdout+p.stderr).decode(errors='replace')
    return p.stdout.decode(errors='replace')

def method_file(filename):
    text='<?php\nclass Installed {\n'
    for (name,_),body in methods.items():
        if name==filename:text+='    public '+body+'\n'
    return (text+'}\n// PERSONALIZACION_CONSERVADA\n').encode()

for row in spec['files']:
    old=method_file(row['path']);new=pub.candidate(old,row)
    pub.lint(new,row['path'])
    assert pub.candidate(new,row)==new
    assert b'PERSONALIZACION_CONSERVADA' in new
    assert pub.candidate(old.replace(b'\n',b'\r\n'),row)==new.replace(b'\n',b'\r\n')
    local=subprocess.check_output(['git','show','d096323:'+row['path']],cwd=str(root))
    pub.lint(pub.candidate(local,row),row['path'])
ast.parse((root/'tools/publicar_consultas_repetidas.py').read_text(encoding='utf-8'),feature_version=(3,6))
print('PASS bloques instalados y locales: PHP lint, idempotencia, CRLF, personalizaciones y sintaxis Python 3.6.')

with tempfile.TemporaryDirectory(prefix='impe-consultas-test-') as tmp:
    tmp=Path(tmp);site=tmp/'public_html';private=tmp/'impe-private';site.mkdir();private.mkdir()
    (site/'index.php').write_text('<?php')
    for row in spec['files']:
        path=site/row['path'];path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(method_file(row['path']))
    cmd=[sys.executable,str(root/'tools/publicar_consultas_repetidas.py'),'--site',str(site),'--backup-root',str(private)]
    originals={r['path']:(site/r['path']).read_bytes() for r in spec['files']}
    run(cmd+['--check']);assert not list(private.iterdir())
    run(cmd+['--apply']);run(cmd+['--apply'])
    records=list(private.glob('consultas-repetidas-*/manifest.json'));assert len(records)==1
    run(cmd+['--restore',str(records[0].parent)])
    assert all((site/name).read_bytes()==data for name,data in originals.items())
    # Un conflicto en el último archivo impide también modificar los primeros.
    (site/spec['files'][-1]['path']).write_text('<?php /* variante desconocida */')
    p=subprocess.run(cmd+['--apply'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert p.returncode!=0
    assert all((site/r['path']).read_bytes()==originals[r['path']] for r in spec['files'][:-1])
    print('PASS publicación: check sin escrituras, tres archivos, repetición, restauración y rechazo antes de escribir.')

    # Se ejecutan los métodos instalados con dependencias simuladas, manteniendo
    # un bloqueo real PHP compartido entre dos procesos. Ninguna llamada PBX.
    sessions=tmp/'sessions';sessions.mkdir();ready=tmp/'ready';probe=tmp/'probe.php'
    probe.write_text("<?php session_save_path($argv[1]);session_id('consultas-prueba');$t=microtime(true);session_start();echo json_encode(['ms'=>(microtime(true)-$t)*1000,'seen'=>$_SESSION['recordatorios_observaciones_mostradas']??null,'csrf'=>$_SESSION['csrf_token']??null]);session_write_close();")
    reminder=pub.candidate(method_file(spec['files'][2]['path']),spec['files'][2]).decode()
    body=reminder[reminder.index('function pendientes()'):reminder.rindex('\n}')]
    # Validar antes de soltar sesión; conservar deduplicación del Analista y
    # liberar después de guardarla, antes del resto de la lectura lenta.
    prefix='''<?php
session_save_path($argv[1]);session_id('consultas-prueba');session_start();
$_SESSION=['usuario_id'=>7,'rol_id'=>(int)$argv[3],'csrf_token'=>'synthetic'];
function tienePermiso($p){return in_array($p,$GLOBALS['permissions'],true);}
$GLOBALS['permissions']=(int)$argv[3]===4?['reuniones.ver','reuniones.solicitar']:[];
function wait_probe(){file_put_contents($GLOBALS['argv'][2],'1');usleep(1000000);}
function obtenerAvisosPendientesRecordatoriosAnalista($u){return ['ok'=>true];}
function serializarRecordatoriosSeguimiento($r){return $r;}
class BaseSvc {function __call($m,$a){return [];} function obtener(...$a){return [];} }
class AgendaReunionService extends BaseSvc {const ROL_CUENTA_CLAVE=6, ROL_ANALISTA=4;function avisosHorario($u){if($_SESSION['rol_id']!=4)wait_probe();return [];} }
class ReminderAgendaFilterService extends BaseSvc {}
class ReminderReunionFollowupService extends BaseSvc {}
class ReminderDirectLinkService extends BaseSvc {function aplicar($r,$u){return $r;} }
class ReminderObservacionService extends BaseSvc {function obtener(...$a){if(session_status()!==PHP_SESSION_ACTIVE)throw new Exception('Se perdió deduplicación');$_SESSION['recordatorios_observaciones_mostradas']=['fixture'=>1];return [];} }
class ReminderMeetingConfirmationService extends BaseSvc {}
class SeguimientoCambioDatosService extends BaseSvc {}
class AliadoSeguimientoReminderService extends BaseSvc {}
class AgendaComentarioService extends BaseSvc {function notificaciones($u){if($_SESSION['rol_id']==4)wait_probe();return ['recordatorios'=>[],'avisos'=>[]];} }
class AdminNotificationService extends BaseSvc {function obtener(...$a){return ['ok'=>true];} }
class Installed {private function ordenarRecordatorios($r){return $r;}private function seleccionarRecordatoriosCampana($r){return $r;}
'''
    owner=tmp/'owner.php';owner.write_text(prefix+'public '+body+'\n}\n(new Installed())->pendientes();',encoding='utf-8')
    for role in [1,4,3]:
        if ready.exists():ready.unlink()
        process=subprocess.Popen(['php',str(owner),str(sessions),str(ready),str(role)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        until=time.monotonic()+4
        while not ready.exists() and time.monotonic()<until:time.sleep(.01)
        assert ready.exists(),process.communicate(timeout=3)
        data=json.loads(run(['php',str(probe),str(sessions)]));assert data['ms']<300 and data['csrf']=='synthetic',data
        if role==4:assert data['seen']=={'fixture':1}
        out,err=process.communicate(timeout=4);assert process.returncode==0 and not err,(out+err).decode(errors='replace')
    print('PASS sesión real: Administrador y Ventas liberan antes de leer; Analista conserva la deduplicación y libera después; CSRF persistido.')

    detail=pub.candidate(method_file(spec['files'][1]['path']),spec['files'][1]).decode()
    detail=detail[detail.index('function detalleVenta()'):detail.rindex('\n}')]
    services=site/'app/services';controllers=site/'app/controllers'
    (services/'TelefoniaVentasLlamadasService.php').write_text('''<?php
class TelefoniaVentasLlamadasService {
function sincronizar($uid){if(session_status()===PHP_SESSION_ACTIVE||$uid!==7)throw new Exception('Sesión bloqueada o actor distinto');file_put_contents($GLOBALS['argv'][2],'1');usleep(1000000);}
function obtener(...$a){return ['synthetic'=>true];}
function conVinculosSdk($u,$c){return $c;}
}
''')
    endpoint=controllers/'session_test.php'
    endpoint.write_text('''<?php
session_save_path($argv[1]);session_id('consultas-prueba');session_start();$_SESSION=['usuario_id'=>(int)$argv[3],'csrf_token'=>'synthetic'];
class Installed {
private function validarAtencionVentaJson($post){if($_SESSION['usuario_id']!==7){http_response_code(403);echo 'DENEGADO';exit;}}
private function responderJson($data,$status=200){echo json_encode($data);exit;}
public '''+detail+'\n}\n(new Installed())->detalleVenta();',encoding='utf-8')
    ready.unlink()
    process=subprocess.Popen(['php',str(endpoint),str(sessions),str(ready),'7'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    until=time.monotonic()+4
    while not ready.exists() and time.monotonic()<until:time.sleep(.01)
    assert ready.exists(),process.communicate(timeout=3)
    assert json.loads(run(['php',str(probe),str(sessions)]))['ms']<300
    out,err=process.communicate(timeout=4);assert process.returncode==0 and not err and json.loads(out)['llamada']=={'synthetic':True},(out,err)
    ready.unlink();assert run(['php',str(endpoint),str(sessions),str(ready),'0'])=='DENEGADO' and not ready.exists()
    print('PASS detalle instalado: autorización antes de liberar, lectura PBX sin bloqueo y mismo JSON; actor sin sesión rechazado.')

    # Informe distingue muestras previas de posteriores y nunca imprime parámetros.
    (private/'consultas-repetidas-publicado.json').write_text(json.dumps({'desde':int(time.time())}))
    log=private/'perfil-peticiones.jsonl'
    log.write_text(json.dumps({'utc':'2000-01-01T00:00:00Z','ruta':'antigua.ruta','total_ms':999})+'\n'+json.dumps({'utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'ruta':'territorio.index','metodo':'GET','total_ms':4,'sql_envios':3,'fases_ms':{'sesion':1},'parameter':'NEVER_PRINT'})+'\n')
    output=run(['php',str(root/'tools/informe_consultas_repetidas.php'),str(site)])
    assert 'territorio.index' in output and 'SQL_MEDIANA=3' in output and 'antigua.ruta' not in output and 'NEVER_PRINT' not in output
    print('PASS informe posterior: excluye muestras antiguas y datos ajenos a métricas.')
