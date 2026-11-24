import ast,importlib.util,json,os,socket,subprocess,sys,tempfile,time,urllib.request,urllib.error
from pathlib import Path
root=Path(__file__).resolve().parents[1]
script=root/'tools/publicar_pausa_recuperacion.py'
ast.parse(script.read_text(encoding='utf-8'),feature_version=(3,6))
loader=importlib.util.spec_from_file_location('p',str(script));p=importlib.util.module_from_spec(loader);loader.loader.exec_module(p)
spec=json.loads(script.with_name('pausa_recuperacion_release.json').read_text(encoding='utf-8'))
php='C:/xampp/php/php.exe' if Path('C:/xampp/php/php.exe').exists() else 'php'
os.environ['PATH']=str(Path(php).parent)+os.pathsep+os.environ['PATH']
dbrow=spec['files'][1]
for header in ['<?php\n','<?php\n\ndeclare(strict_types=1);\n','<?php\n/** comentario */\ndeclare ( strict_types = 1 );\n']:
    for nl in [b'\n',b'\r\n']:
        old=(header+'// Valores privados ficticios que no se deben reemplazar.\n$valor="preservar";\n').encode().replace(b'\n',nl)
        new=p.candidate(old,dbrow)
        assert new.replace(b"require_once dirname(__DIR__).'/app/helpers/PausaConsultasAutomaticas.php';"+nl,b'')==old
        assert p.candidate(new,dbrow)==new
        p.lint(new,'config/db_connection.php')

with tempfile.TemporaryDirectory() as tmp:
    site=Path(tmp)/'public_html';private=Path(tmp)/'impe-private';(site/'app/helpers').mkdir(parents=True)
    (site/'config').mkdir();(site/'prueba_telefonia/api').mkdir(parents=True)
    (site/'app/helpers/PausaConsultasAutomaticas.php').write_bytes(subprocess.check_output(['git','show','79a5f969bb59ed5fc6e4cd9651b7f437bd50dd59:app/helpers/PausaConsultasAutomaticas.php'],cwd=str(root)))
    (site/'index.php').write_text("<?php require_once __DIR__.'/app/helpers/PausaConsultasAutomaticas.php'; require_once __DIR__.'/config/db_connection.php'; echo 'NEGOCIO';",encoding='utf-8')
    original=b'<?php\ndeclare(strict_types=1);\n// Configuracion ficticia, sin MySQL.\n'
    (site/'config/db_connection.php').write_bytes(original)
    for name in ['zadarma_webhook.php','zadarma_entrada_activa.php']:
        (site/'prueba_telefonia/api'/name).write_text("<?php require_once dirname(__DIR__,2).'/config/db_connection.php'; echo 'NEGOCIO';",encoding='utf-8')
    cmd=[sys.executable,str(script),'--site',str(site),'--backup-root',str(private)]
    def deploy(*args):
        r=subprocess.run(cmd+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert r.returncode==0,(r.stdout,r.stderr)
        return r.stdout
    assert b'COMPROBACION_OK: 2' in deploy('--check') and not private.exists()
    assert b'PAUSA_RECUPERACION_ACTIVA' in deploy('--apply')
    assert b'SIN_CAMBIOS' in deploy('--apply')
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    server=subprocess.Popen([php,'-S','127.0.0.1:'+str(port),'-t',str(site)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    try:
        for _ in range(40):
            try:
                s=socket.create_connection(('127.0.0.1',port),timeout=.1);s.close();break
            except OSError:time.sleep(.05)
        def request(path):
            try:r=urllib.request.urlopen('http://127.0.0.1:'+str(port)+path,timeout=3)
            except urllib.error.HTTPError as e:r=e
            return r.status,r.read().decode()
        for path in ['/index.php?controller=login&action=iniciarSesion','/index.php?controller=telefonia&action=detalleVenta','/index.php?controller=telefonia&action=estadoUsuario','/prueba_telefonia/api/zadarma_entrada_activa.php']:
            status,body=request(path);assert status==503 and json.loads(body)['pausa_automatica'] is True
        for path in ['/prueba_telefonia/api/zadarma_webhook.php','/index.php?controller=whatsappWebhook&action=webhook']:
            assert request(path)==(200,'NEGOCIO')
        # SSH permanece disponible para una comprobación puntual del proveedor.
        r=subprocess.run([php,str(site/'index.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert r.returncode==0 and r.stdout==b'NEGOCIO'
        backup=next(p for p in private.iterdir() if p.is_dir())
        deploy('--restore',str(backup));assert (site/'config/db_connection.php').read_bytes()==original
        deploy('--reanudar');assert not (private/'pausa-consultas-automaticas.json').exists()
    finally:server.terminate();server.wait(timeout=5)
print('PASS: guarda única conserva configuración y strict_types LF/CRLF; HTTP previo a negocio; detalle/estado/login pausados; webhooks/SSH excluidos; respaldo/restauración exactos. Sin MySQL real.')
