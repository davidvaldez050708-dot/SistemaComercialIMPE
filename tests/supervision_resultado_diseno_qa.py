"""Renderiza la vista instalada con datos sintéticos y sin acceso a producción."""
import base64,gzip,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request
from reportes_diseno_recursos import cargar_recursos
R=pathlib.Path(__file__).resolve().parents[1]
OUT=R/'tmp/supervision-resultado-diseno';OUT.mkdir(parents=True,exist_ok=True)
# Las consultas de audio y la aplicación automática de filtros no forman parte del cambio.
spec=json.loads((R/'tools/supervision_resultado_diseno_release.json').read_text(encoding='utf8'))
row=next(row for row in spec['files'] if row['path'].endswith('.js'))
original=gzip.decompress(base64.b64decode(row['variants'][0]['before'])).decode()
current=(R/row['path']).read_text(encoding='utf8')
start="    const form=root.querySelector('[data-supervision-filters]');"
assert original[original.index(start):original.index("    root.querySelector('[data-supervision-close]')")] == current[current.index(start):current.index('    const detailStatus=')]
audio="        const panel=card.querySelector('[data-supervision-player]');"
assert original[original.index(audio):] == current[current.index(audio):]
with tempfile.TemporaryDirectory(prefix='impe-supervision-modal-') as folder:
 P=pathlib.Path(folder)
 for directory in ['public/css','public/javascript']:
  shutil.copytree(R/directory,P/directory)
 target=P/'app/views/telefonia/supervision_llamadas.php';target.parent.mkdir(parents=True);shutil.copyfile(R/target.relative_to(P),target)
 (P/'app/helpers').mkdir();(P/'app/helpers/AvatarHelper.php').write_text("<?php function renderAvatarUsuario(...$args){return '<span class=system-avatar aria-hidden=true>YS</span>';}",encoding='utf8')
 (P/'assets').mkdir();cargar_recursos(P)
 (P/'assets/bootstrap.css').write_bytes(urllib.request.urlopen('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css',timeout=30).read())
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base='http://127.0.0.1:'+str(port)+'/'
 script='''<?php
error_reporting(E_ALL);date_default_timezone_set('America/Mexico_City');define('ROOT_PATH',__DIR__);define('BASE_URL',__BASE__);
$scope='global';$puedeAudio=true;$f=['area'=>'asesores','persona_id'=>0,'desde'=>'2026-10-28','hasta'=>'2026-10-29','direccion'=>'','resultado'=>'','buscar'=>''];
$common=['persona'=>'Yazmin Sintética','nombre'=>'Prospecto de prueba','numero'=>'5550000000','extension'=>'151','fecha'=>'2026-10-29 08:19:45','direccion'=>'Saliente','foto_perfil'=>'','segundos'=>94,'observada'=>false,'estado_pbx'=>'Conectada','conectada'=>true,'resultado_guardado'=>true,'audio_consultable'=>true,'resultado_texto'=>'Prospecto potencial · Se envió por WhatsApp'];
$data=['personas'=>[['id'=>15,'nombre'=>'Yazmin Sintética','activo'=>1]],'llamadas'=>[array_merge($common,['key'=>'venta:1']),array_merge($common,['key'=>'venta:2','nombre'=>'Otra llamada']),array_merge($common,['key'=>'analista:3','persona'=>'Analista de prueba'])],'total'=>3,'paginas'=>1,'pagina'=>1,'resultados'=>['POTENCIAL'=>'Prospecto potencial']];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="assets/bootstrap.css"><link rel="stylesheet" href="assets/manrope.css"><link rel="stylesheet" href="assets/bootstrap-icons.css">
<link rel="stylesheet" href="public/css/global.css"><link rel="stylesheet" href="public/css/dashboard.css"><link rel="stylesheet" href="public/css/responsive_sistema.css"></head><body style="padding:20px"><main class="admin-content"><?php require __DIR__.'/app/views/telefonia/supervision_llamadas.php'; ?></main></body></html>'''
 (P/'index.php').write_text(script.replace('__BASE__',json.dumps(base)),encoding='utf8')
 php=os.environ.get('PHP_BINARY','php')
 server=subprocess.Popen([php,'-S','127.0.0.1:'+str(port),'-t',str(P)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
 try:
  for _ in range(40):
   try:urllib.request.urlopen(base,timeout=1);break
   except OSError:time.sleep(.1)
  subprocess.run(['node',str(R/'tests/supervision_resultado_diseno_browser.cjs')],env=dict(os.environ,IMPE_TEST_URL=base,IMPE_TEST_OUT=str(OUT)),check=True)
 finally:server.terminate();server.wait(timeout=10)
 print('PASS: vista real de Supervisión, tipografía e iconos del sistema; bloques de audio/filtros idénticos, sin SQL ni llamadas reales.')
