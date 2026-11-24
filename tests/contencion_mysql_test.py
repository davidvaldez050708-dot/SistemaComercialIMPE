import ast,base64,importlib.util,json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
script=root/'tools/publicar_contencion_mysql.py'
ast.parse(script.read_text(encoding='utf-8'),feature_version=(3,6))
spec=json.loads(script.with_name('contencion_mysql_release.json').read_text(encoding='utf-8'))
loader=importlib.util.spec_from_file_location('p',str(script));p=importlib.util.module_from_spec(loader);loader.loader.exec_module(p)
php='C:/xampp/php/php.exe' if Path('C:/xampp/php/php.exe').exists() else 'php'
os.environ['PATH']=str(Path(php).parent)+os.pathsep+os.environ['PATH']
with tempfile.TemporaryDirectory() as tmp:
    site=Path(tmp)/'public_html';private=Path(tmp)/'impe-private';site.mkdir()
    originals={}
    for row in spec['files']:
        f=site/row['path'];f.parent.mkdir(parents=True,exist_ok=True)
        if not row['new_file']:
            originals[row['path']]=(row['hunks'][0]['before']+'echo "NEGOCIO";\n').encode()
            f.write_bytes(originals[row['path']])
    cmd=[sys.executable,str(script),'--site',str(site),'--backup-root',str(private)]
    def deploy(mode):
        r=subprocess.run(cmd+[mode],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert r.returncode==0,(r.stdout,r.stderr)
        return r.stdout
    assert b'COMPROBACION_OK: 4' in deploy('--check')
    assert not private.exists()
    assert b'PAUSA_ACTIVA' in deploy('--apply')
    assert b'PAUSA_ACTIVA' in deploy('--apply')
    def request(path,get=None,post=None,method='GET'):
        data=base64.b64encode(json.dumps([str(site/path),get or {},post or {},method]).encode()).decode()
        code='$v=json_decode(base64_decode($argv[1]),true);$_GET=$v[1];$_POST=$v[2];$_SERVER["REQUEST_METHOD"]=$v[3];$_SERVER["SCRIPT_FILENAME"]=$v[0];require $v[0];'
        r=subprocess.run([php,'-r',code,data],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert r.returncode==0,(r.stdout,r.stderr)
        return r.stdout.decode()
    for controller,action in [('reminder','pendientes'),('convocatoriaNotificacion','pendientes'),('seguimientoVinculacion','resumenVerificacionTelefonicaHoy'),('whatsapp','mensajes')]:
        assert json.loads(request('index.php',dict(controller=controller,action=action)))['pausa_automatica'] is True
    assert json.loads(request('index.php',dict(controller='telefonia',action='disponibilidad'),dict(accion='POLL',id=0),'POST'))['pausa_automatica'] is True
    assert json.loads(request('prueba_telefonia/api/avisos_llamadas.php'))['pausa_automatica'] is True
    assert 'PAUSA_TEMPORAL' in request('tools/cron_zoho_leads.php')
    for method,controller,action,post in [
        ('POST','login','iniciarSesion',{}),('GET','home','index',{}),
        ('GET','telefonia','estadoUsuario',{}),('POST','telefonia','guardarResultadoVenta',{}),
        ('POST','seguimientoVinculacion','registrarInteraccionTrabajo',{}),
        ('POST','telefonia','disponibilidad',dict(accion='POLL',id=42)),
        ('POST','telefonia','disponibilidad',dict(accion='ACEPTAR',id=42)),
        ('POST','whatsappWebhook','webhook',{}),('POST','whatsapp','enviar',{})
    ]:
        assert request('index.php',dict(controller=controller,action=action),post,method)=='NEGOCIO'
    flag=private/'pausa-consultas-automaticas.json'
    flag.write_text(json.dumps(dict(hasta=1)))
    assert request('index.php',dict(controller='reminder',action='pendientes'))=='NEGOCIO'
    assert b'REANUDACION_OK' in deploy('--reanudar') and not flag.exists()
print('PASS: pausa antes de negocio/SQL; cron diferido; login, páginas, llamadas/resultados, webhooks y transferencia en curso excluidos; caducidad, reanudación e idempotencia. Sin conexiones MySQL.')
