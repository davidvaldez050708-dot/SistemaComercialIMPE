"""Contratos locales con driver/servicios sintéticos; nunca carga config del CRM.

Uso: python tests/estabilidad_403_503_http_test.py [--source PATH] [--hostinger]
No sustituye staging equivalente ni valida llamadas PBX reales.
"""
import argparse
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


def main():
    args=argparse.ArgumentParser()
    args.add_argument('--source',type=Path,default=Path(__file__).resolve().parents[1])
    args.add_argument('--hostinger',action='store_true')
    options=args.parse_args()
    source=options.source.resolve()
    php=shutil.which('php')
    assert php, 'PHP CLI requerido'
    with tempfile.TemporaryDirectory(prefix='impe-v2-http-') as temp:
        site=Path(temp).resolve()
        assert site.name.startswith('impe-v2-http-')
        for name in ['index.php','config/db_connection.php','app/controllers/TelefoniaController.php']:
            target=site/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source/name,target)
        fixture=Path(__file__).parent/'fixtures/estabilidad_403_503_http.php'
        (site/'config/config.php').write_bytes(fixture.read_bytes())
        for name in ['app/models/UsuarioModel.php','app/models/RolModel.php','app/helpers/PermissionHelper.php',
                     'app/services/TelefoniaExtensionService.php','app/services/TelefoniaActividadService.php',
                     'app/services/TelefoniaControlService.php','app/services/TelefoniaMarcadorPanelService.php',
                     'app/services/TelefoniaContactosService.php','app/services/TelefoniaResultadoVentasService.php',
                     'app/services/TelefoniaMarcacionesVentasService.php','app/services/TelefoniaDisponibilidadService.php']:
            target=site/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_text('<?php\n')
        (site/'seed.php').write_text("""<?php
session_start(); $id=(int)($_GET['id']??1);
$_SESSION=['usuario_id'=>$id,'rol_id'=>3,'rol'=>'Ventas','csrf_token'=>'csrf-test-'.$id,
'ultima_actividad'=>time()-(isset($_GET['expired'])?1900:0),'permisos'=>['telefonia.usar','telefonia.recibir']];
echo 'seed';
""")
        (site/'connection-contract.php').write_text("""<?php
require __DIR__.'/config/config.php';
$_GET['fault']=2002;
try { (new Database())->connect(true); throw new LogicException('Missing failure'); }
catch (RuntimeException $first) { if ($first->getPrevious()->getCode()!==2002) throw $first; }
unset($_GET['fault']);
try { (new Database())->connect(false); $cached=false; }
catch (RuntimeException $second) { if ($second!==$first) throw $second; $cached=true; }
echo json_encode(['cached'=>$cached,'connections'=>$GLOBALS['fixture_counts']['connections']]);
""")
        with socket.socket() as probe:
            probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
        env=os.environ.copy()
        env.pop('IMPE_DB_PERSISTENT',None);env.pop('IMPE_DB_INDEPENDENT_PERSISTENT',None)
        flags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
        with (site/'http.log').open('w',encoding='utf-8') as log:
            process=subprocess.Popen([php,'-n','-S',f'127.0.0.1:{port}','-t',str(site)],
                stdout=log,stderr=subprocess.STDOUT,env=env,creationflags=flags)
            def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            first,second=client(),client()
            def request(client,path,data=None):
                req=urllib.request.Request(f'http://127.0.0.1:{port}/'+path,
                    data=urllib.parse.urlencode(data).encode() if data is not None else None,
                    headers={'X-Requested-With':'fetch'})
                try:response=client.open(req,timeout=5)
                except urllib.error.HTTPError as error:response=error
                return response.status,dict(response.headers),response.read().decode()
            route='index.php?controller=telefonia&action=disponibilidad'
            poll={'accion':'POLL','csrf_token':'csrf-test-1','disponible':'1'}
            try:
                for _ in range(50):
                    try:request(first,'seed.php');break
                    except urllib.error.URLError:time.sleep(.05)
                else:raise RuntimeError('Servidor local no inició')
                status,headers,body=request(first,route,poll)
                assert status==200 and json.loads(body)['ok'],body
                metrics=json.loads((site/'metrics.json').read_text())
                assert metrics['catalog']==0 and metrics['extensions']==0 and metrics['permissions']==2,metrics
                assert metrics['connections']==(1 if options.hostinger else 3),metrics
                for suffix,data,expected in [('&revoked=1',poll,403),('&inactive=1',poll,403),('',{'accion':'POLL','csrf_token':'wrong'},419)]:
                    request(first,'seed.php');status,_,body=request(first,route+suffix,data)
                    assert status==expected,(status,body)
                    assert json.loads((site/'metrics.json').read_text())['mutations']==0
                request(first,'seed.php?expired=1')
                assert request(first,route,poll)[0]==401
                assert request(first,route,poll)[0]==401
                request(first,'seed.php');request(second,'seed.php?id=2')
                assert json.loads(request(first,route,poll)[2])['entrante']['fixture_user']==1
                other={**poll,'csrf_token':'csrf-test-2'}
                assert json.loads(request(second,route,other)[2])['entrante']['fixture_user']==2
                assert request(second,route,poll)[0]==419
                for code in [1040,1203,1226,2002,2003,2006,2013,1045,1049,1146,1213]:
                    request(first,'seed.php');status,headers,body=request(first,route+f'&query_fault={code}',poll)
                    # Hostinger sondear() y GitHub presencia() inyectan el mismo fallo.
                    expected=503 if code in [1040,1203,1226,2002,2003,2006,2013] else 500
                    assert status==expected,(code,status,body)
                    if status==503:assert headers.get('X-IMPE-DB-Error-ID') and headers.get('Retry-After')=='3'
                    assert 'PRIVATE_QUERY' not in body
                request(first,'seed.php')
                status,_,body=request(first,route,{'accion':'ACEPTAR','csrf_token':'csrf-test-1','id':1,'version':1})
                assert status==200,json.loads(body)
                metrics=json.loads((site/'metrics.json').read_text())
                assert metrics['mutations']==1 and metrics['catalog']==1 and metrics['connections']==4,metrics
                status,_,body=request(first,'connection-contract.php')
                contract=json.loads(body)
                assert status==200 and contract['cached']==options.hostinger,contract
                assert contract['connections']==(1 if options.hostinger else 2),contract
            finally:
                process.terminate();process.wait(timeout=5)
        data=(site/'http.log').read_text(encoding='utf-8')
        records=[json.loads(line.split('[impe_mysql] ',1)[1]) for line in data.splitlines() if '[impe_mysql] ' in line]
        assert [r['errno'] for r in records]==[1040,1203,1226,2002,2003,2006,2013,1045,1049,1146,1213,2002]
        assert 'PRIVATE_QUERY' not in data
        print(json.dumps({'status':'PASS','profile':'Hostinger' if options.hostinger else 'GitHub',
            'connections_modeled_poll':1 if options.hostinger else 3,
            'tests':'POLL, permissions, CSRF, expiry, session/token isolation, 11 errno, single write',
            'limits':'services/driver mocked; no PBX or business-data tests',
            'database_sha256':hashlib.sha256((source/'config/db_connection.php').read_bytes()).hexdigest()}))


if __name__=='__main__':main()
