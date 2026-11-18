"""HTTP real sobre DB sintética. Transporte Meta sustituido explícitamente por fixture; cero envíos reales."""
from pathlib import Path
import http.cookiejar
import json
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
schema,cid=sys.argv[1:]
assert schema.startswith('impe_whatsapp_test_') and schema.replace('_','').isalnum() and cid.isdigit()
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
env=os.environ.copy();env.update(IMPE_DB_HOST='127.0.0.1',IMPE_DB_USER=env['MYSQL_USER'],IMPE_DB_PASSWORD=env['MYSQL_PASSWORD'],IMPE_DB_NAME=schema,IMPE_BASE_URL=f'http://127.0.0.1:{port}/')
token='synthetic-csrf-only-01234567890123456789'
def phpstr(s):return "'"+str(s).replace('\\','/').replace("'","\\'")+"'"
with tempfile.TemporaryDirectory(prefix='impe-wa-http-') as tmp:
    router=Path(tmp)/'router.php';counter=Path(tmp)/'calls.txt';counter.write_text('0')
    router.write_text("""<?php
session_start();
$uid=(int)($_GET['fixture_actor']??23);$rol=$uid===1?1:3;
$_SESSION['usuario_id']=$uid;$_SESSION['rol_id']=$rol;
$_SESSION['permisos']=['whatsapp.ver','whatsapp.enviar','ventas.embudo.ver',...($uid===22||$uid===1?['whatsapp.gestionar_conversaciones']:[])];
$_SESSION['csrf_token']="""+phpstr(token)+""";
require """+phpstr(root/'config/config.php')+""";
require """+phpstr(root/'app/controllers/WhatsAppController.php')+""";
class FixtureWhatsAppApi extends WhatsAppCloudApiService {
 public function enviarTexto($phone,$to,$text){$f="""+phpstr(counter)+""";$n=1+(int)file_get_contents($f);file_put_contents($f,(string)$n);return ['ok'=>true,'wamid'=>'fixture_wa_'.$n,'respuesta'=>[]];}
}
class FixtureWhatsAppController extends WhatsAppController {
 protected function servicioWhatsApp(WhatsAppModel $m): WhatsAppCloudApiService {return new FixtureWhatsAppApi($m->conexionParaAutorizacion());}
}
$action=$_GET['action']??'';
if(!in_array($action,['contexto','panel','mensajes','enviar','crearConversacion','enviarPlantillaPrueba'],true)){http_response_code(404);exit;}
(new FixtureWhatsAppController())->$action();
""",encoding='utf-8')
    server=subprocess.Popen(['php','-d','mysqli.default_port='+env['MYSQL_PORT'],'-S',f'127.0.0.1:{port}',str(router)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        clients={uid:urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())) for uid in [1,22,23]}
        def call(action,uid=23,data=None,csrf=True,params=None):
            query=urllib.parse.urlencode(dict(action=action,fixture_actor=uid,**(params or {})))
            req=urllib.request.Request(f'http://127.0.0.1:{port}/?'+query,data=urllib.parse.urlencode(data).encode() if data is not None else None)
            if csrf:req.add_header('X-CSRF-Token',token)
            try:r=clients[uid].open(req,timeout=15)
            except urllib.error.HTTPError as e:r=e
            with r:return r.code,r.read().decode('utf-8')
        status,body=call('contexto',data={'tipo':'prospecto','id':1})
        assert status==200 and json.loads(body)['conversacion_id']==int(cid)
        assert call('contexto',data={'tipo':'prospecto','id':1},csrf=False)[0]==403
        assert call('contexto',data={'tipo':'prospecto','id[]':1})[0]==422
        assert call('contexto',22,data={'tipo':'prospecto','id':1})[0]==409
        assert call('contexto',params={'tipo':'prospecto','id':1})[0]==405
        status,html=call('panel',params={'conversacion_id':cid})
        assert status==200 and 'pendiente de configuración' in html and 'data-whatsapp-module' in html
        assert call('panel',22,params={'conversacion_id':cid})[0]==200  # consulta global explícita
        payload={'conversacion_id':cid,'mensaje':'Mensaje sintético, no se envía a Meta','request_id':'fixture-request-1234567890'}
        assert call('enviar',22,data=payload)[0]==403  # consulta no habilita respuesta ajena
        assert call('enviar',1,data=payload)[0]==403  # política del Administrador
        status,body=call('enviar',data=payload)
        assert status==200 and json.loads(body)['ok']
        first=counter.read_text();assert first=='1'
        status,body=call('enviar',data=payload)
        assert status==200 and json.loads(body)['ok'] and counter.read_text()==first
        assert call('enviar',data={**payload,'mensaje':'Otro texto'})[0]==409
        status,body=call('mensajes',params={'conversacion_id':cid})
        assert status==200 and len([m for m in json.loads(body)['mensajes'] if m.get('wamid')=='fixture_wa_1'])==1
        print('CHECK_OK HTTP WhatsApp: CSRF, método, contexto, permisos globales separados, panel sin configuración, transporte Meta de fixture y envío idempotente sin mensajes reales.')
    finally:server.terminate();server.wait(timeout=15)
