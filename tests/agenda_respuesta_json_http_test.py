"""Controlador HTTP real y servicios sintéticos. Sin BD ni reuniones del CRM."""
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('IMPE_TEST_PHP','C:/xampp/php/php.exe')
with tempfile.TemporaryDirectory(prefix='agenda-json-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp)
    for path in ['app/controllers/AgendaReunionController.php','app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php']:
        p=area/path;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/path,p)
    services=area/'app/services';services.mkdir()
    (services/'AgendaReunionService.php').write_text('''<?php
class AgendaReunionService {
 const ROL_CUENTA_CLAVE=6;const ROL_ANALISTA=4;
 public function __construct(){if(($_GET['scenario']??'')==='init')throw new RuntimeException('SECRETO de conexión',1203);}
 public function puedeAcceder($rol){return tienePermiso('reuniones.ver');}
 public function confirmar($uid,$rol,$datos){
  if(($_GET['scenario']??'')==='exception')throw new RuntimeException('SECRETO en SQL y formulario',1054);
  if(($_GET['scenario']??'')==='encoding')return ['ok'=>true,'invalid'=>fopen('php://memory','r')];
  if(($_GET['scenario']??'')==='conflict')return ['ok'=>false,'mensaje'=>'La solicitud ya no está pendiente.','codigo_http'=>409];
  return ['ok'=>true,'mensaje'=>'Reunión confirmada.','reunion_id'=>(int)$datos['reunion_id']];
 }
}
''',encoding='utf8')
    (services/'ReprogramacionReunionService.php').write_text('''<?php
class ReprogramacionReunionService {
 public function __construct(){if(($_GET['scenario']??'')==='optional')throw new RuntimeException('Dependencia innecesaria');}
 public function confirmarKam($uid,$rol,$datos){return (new AgendaReunionService())->confirmar($uid,$rol,$datos);}
}
''',encoding='utf8')
    (services/'SeguimientoExpedienteService.php').write_text('<?php class SeguimientoExpedienteService {public function __construct(){throw new RuntimeException("Expediente no requerido");}}',encoding='utf8')
    (area/'router.php').write_text('''<?php
ini_set('display_errors','0');
$_SESSION=['usuario_id'=>7,'rol_id'=>6,'permisos'=>(($_GET['scenario']??'')==='denied'?['reuniones.ver']:['reuniones.ver','reuniones.gestionar'])];
require __DIR__.'/app/controllers/AgendaReunionController.php';
$c=new AgendaReunionController();$action=$_GET['action']??'confirmar';$c->$action();
''',encoding='utf8')
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    with (area/'http.log').open('w') as log:
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}',str(area/'router.php')],cwd=area,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
        try:
            for _ in range(80):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.05)
            def request(scenario='',action='confirmar',method='POST'):
                conn=http.client.HTTPConnection('127.0.0.1',port)
                conn.request(method,f'/?scenario={scenario}&action={action}','reunion_id=8&notas_kam=SECRETO',{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'})
                response=conn.getresponse();raw=response.read();data=json.loads(raw)
                assert response.getheader('Content-Type').startswith('application/json')
                conn.close();return response.status,data
            for action in ['confirmar','confirmarReprogramacion']:
                status,data=request(action=action);assert status==200 and data==dict(ok=True,mensaje='Reunión confirmada.',reunion_id=8)
                for scenario in ['init','exception','encoding']:
                    status,data=request(scenario,action);assert status==500 and data['ok'] is False and len(data['referencia'])==16
                    assert 'SECRETO' not in json.dumps(data) and 'Actualiza la agenda' in data['mensaje']
                assert request('conflict',action)[0]==409
                assert request('denied',action)[0]==403
                assert request('',action,'GET')[0]==405
            assert request('optional')[0]==200,'Confirmación no inicializa reprogramación ni expediente ajenos'
            logs=(area/'storage/logs/agenda_reunion_json.log').read_text(encoding='utf8')
            assert 'SECRETO' not in logs
            entries=[json.loads(line) for line in logs.splitlines()]
            assert all(set(row)=={'referencia','accion','clase','codigo','archivo','linea'} for row in entries)
            print('OK: HTTP JSON en confirmar/reprogramación, inicio/SQL/codificación, errores 403/405/409, dependencias diferidas y registro sin datos privados.')
        finally:server.terminate();server.wait(timeout=10)
