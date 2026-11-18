"""GET real del controlador con sesión y MariaDB sintéticas; sin seguir el redirect."""
from pathlib import Path
import os
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root=Path(__file__).resolve().parents[1]
schema=sys.argv[1]
assert schema.startswith('impe_supervision_test_') and schema.replace('_','').isalnum()
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
env=os.environ.copy()
env.update(IMPE_DB_HOST='127.0.0.1',IMPE_DB_USER=env['MYSQL_USER'],IMPE_DB_PASSWORD=env['MYSQL_PASSWORD'],IMPE_DB_NAME=schema,IMPE_BASE_URL=f'http://127.0.0.1:{port}/')
env.update(IMPE_VENTAS_V3_UI='PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111',IMPE_VENTAS_V3_UI_ASESORES='22')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect)
with tempfile.TemporaryDirectory(prefix='impe-destino-http-') as tmp:
    router=Path(tmp)/'router.php'
    router.write_text("<?php $_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>['ventas.embudo.ver','ventas.recuperacion.ver','ventas.descartados.ver']];require "+repr(str(root/'config/config.php').replace('\\','/'))+";require "+repr(str(root/'app/controllers/EmbudoComercialController.php').replace('\\','/'))+";(new EmbudoComercialController())->ubicar();",encoding='utf-8')
    proc=subprocess.Popen(['php','-d','mysqli.default_port='+env['MYSQL_PORT'],'-S',f'127.0.0.1:{port}',str(router)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        def get(query,method='GET'):
            req=urllib.request.Request(f'http://127.0.0.1:{port}/?'+query,method=method)
            try:result=opener.open(req,timeout=15)
            except urllib.error.HTTPError as e:result=e
            with result:
                if result.code==303:assert result.headers.get('Cache-Control')=='private, no-store, max-age=0'
                return result.code,urllib.parse.parse_qs(urllib.parse.urlparse(result.headers.get('Location','')).query),result.read().decode()
        status,params,_=get('asignacion=4&ciclo=1')
        assert status==303 and params['destacar']==['4'] and params['vista']==['embudo']
        status,params,_=get('asignacion=4&ciclo=99999')
        assert status==303 and params['aviso_actividad']==['actualizada']
        status,params,_=get('asignacion=8&ciclo=1')
        assert status==303 and params['vista']==['recuperacion']
        status,params,_=get('asignacion=10&ciclo=1')
        assert status==303 and params['action']==['expedientes']
        for query in ['asignacion=7&ciclo=1','asignacion=9999&ciclo=1','asignacion[]=4&ciclo=1','asignacion=4&ciclo[]=1']:
            status,params,_=get(query)
            assert status==303 and params['aviso_actividad']==['no_disponible'] and 'destacar' not in params
        assert get('asignacion=4&ciclo=1','POST')[0]==405
        print('CHECK_OK HTTP real: 303 a tarjeta, reprogramación, recuperación y cierre; asesor ajeno y entradas inválidas sin exposición; POST rechazado.')
    finally:
        proc.terminate();proc.wait(timeout=15)
