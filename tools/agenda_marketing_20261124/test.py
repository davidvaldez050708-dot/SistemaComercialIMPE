from pathlib import Path
import json,subprocess,os,hashlib

HERE=Path(__file__).resolve().parent;ROOT=HERE.parents[1]
OUT=ROOT/'output/agenda_marketing_20261124';LIVE=OUT/'live';TMP=OUT/'stage'
LIVE.mkdir(parents=True,exist_ok=True);TMP.mkdir(parents=True,exist_ok=True)
def put(name,text):
    path=LIVE/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(text,encoding='utf-8',newline='\n')
put('index.php','<?php // fixture aislada\n')
for name in ['ConvocatoriaNotificacionController.php','convocatoria_notificaciones.js']:
    target=('app/controllers/' if name.endswith('.php') else 'public/javascript/')+name
    put(target,(HERE/'base'/name).read_text(encoding='utf-8'))
put('public/javascript/agenda_alertas.js',(HERE/'base/agenda_alertas.js').read_text(encoding='utf-8'))
put('public/audio/agenda-aviso.mp3','audio simulado; el navegador instrumenta Audio.play')
put('app/services/AgendaComentarioService.php','''<?php
class AgendaComentarioService {
 public function notificaciones($usuario) {
  if (getenv('AGENDA_FALLA')==='1') throw new RuntimeException('Fallo de Agenda simulado');
  $data=json_decode(file_get_contents(getenv('AGENDA_DATOS')),true);
  return $data[(string)$usuario]??['recordatorios'=>[],'avisos'=>[],'horarios'=>[]];
 }
 public function registrarEntregados($usuario,$items) {}
}
''')
before={str(p.relative_to(LIVE)):hashlib.sha256(p.read_bytes()).hexdigest() for p in LIVE.rglob('*') if p.is_file()}
r=subprocess.run(['php',str(HERE/'preparar.php'),'preparar',str(LIVE),str(TMP)],capture_output=True)
assert r.returncode==0,r.stderr.decode(errors='replace')
assert before=={str(p.relative_to(LIVE)):hashlib.sha256(p.read_bytes()).hexdigest() for p in LIVE.rglob('*') if p.is_file()}
for name in json.loads((TMP/'plan.json').read_text()):
    target=LIVE/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes((TMP/'stage'/name).read_bytes())
    if name.endswith('.php'):subprocess.run(['php','-l',str(target)],check=True,capture_output=True)
subprocess.run(['node','--check',str(LIVE/'public/javascript/convocatoria_notificaciones.js')],check=True)
subprocess.run(['php',str(HERE/'preparar.php'),'despues',str(LIVE),str(TMP)],check=True)
subprocess.run(['php',str(HERE/'preparar.php'),'preparar',str(LIVE),str(TMP)],check=True)
assert not any(p['cambio'] for p in json.loads((TMP/'plan.json').read_text()).values())
put('config/db_connection.php','<?php class Database { public function connect() {return null;} }')
put('app/helpers/PermissionHelper.php',"<?php require_once __DIR__.'/../../config/db_connection.php'; function tienePermiso($p) {return in_array($p,['convocatorias.gestionar','convocatorias.crear','telefonia.usar','telefonia.recibir'],true);}")
put('app/models/ConvocatoriaModel.php','<?php class ConvocatoriaModel { public function sincronizarConvocatoriasPorFecha() {} }')
put('app/models/ConvocatoriaNotificacionModel.php',"<?php class ConvocatoriaNotificacionModel { public function contarNoLeidas($u){return 1;} public function obtenerPorUsuario($u,$limit){return [['id'=>11,'titulo'=>'Convocatoria activa','mensaje'=>'Convocatoria original','leida'=>0,'url'=>'index.php?controller=convocatoria']];} }")
put('app/services/TelefoniaNotificacionService.php',"<?php class TelefoniaNotificacionService { public function pendientes($u,$a){return [['id'=>12,'telefonia_id'=>12,'titulo'=>'Llamada pendiente','leida'=>0,'url'=>'index.php?controller=telefonia']];} }")
put('app/services/AliadoConvocatoriaPendienteService.php',"<?php class AliadoConvocatoriaPendienteService { public function __construct($db){} public function obtener($u,$a,$l){return [['id'=>13,'titulo'=>'Preparar convocatoria','pendiente_operativo'=>true]];} }")
wrapper=OUT/'controller.php'
wrapper.write_text("<?php $_SESSION=['usuario_id'=>(int)(getenv('AGENDA_USUARIO')?:7),'rol'=>getenv('AGENDA_ROL')?:'Marketing']; $_GET['usuario_id']=999; require "+repr(str(LIVE/'app/controllers/ConvocatoriaNotificacionController.php').replace('\\','/'))+"; (new ConvocatoriaNotificacionController())->pendientes();",encoding='utf-8')
import time
now=int(time.time())
record={'id':0,'agenda_comentario_id':92,'agenda_comentario_evento':'92|nuevo','agenda_autor_id':2,'agenda_creado_at':now+3,
 'nombre_entidad':'Comentario de Ana','accion':'Nuevo comentario autorizado','titulo':'Nuevo comentario en la agenda',
 'url':'index.php?controller=agendaReunion&action=index&comentario_id=92&dia=2026-11-24','fecha':'2026-11-24 12:00:00','estado':'normal','prioridad':4,'icono':'bi-chat-left-text'}
payload=OUT/'datos.json';payload.write_text(json.dumps({'7':{'recordatorios':[record],'avisos':[record],'horarios':[dict(record,agenda_horario_at=now+60,agenda_horario_mensaje='Actividad programada')]}}),encoding='utf-8')
env=os.environ.copy();env['AGENDA_DATOS']=str(payload)
def response(**extra):
    result=subprocess.run(['php',str(wrapper)],env={**env,**extra},capture_output=True)
    assert result.returncode==0,result.stderr.decode(errors='replace')
    return json.loads(result.stdout)
data=response();assert data['ok'] and data['agenda_ok'] and data['usuario_id']==7
assert data['recordatorios']==[record] and data['horarios'][0]['agenda_comentario_id']==92
assert data['no_leidas']==4 and len(data['notificaciones'])==4
assert [n.get('id') for n in data['notificaciones']]==[13,12,0,11]
assert data['notificaciones'][2]['pendiente_operativo'] and data['notificaciones'][2]['mensaje']==record['accion']
assert data['servidor_at']>0 and data['zona_horaria']
other=response(AGENDA_USUARIO='8');assert other['recordatorios']==[] and other['no_leidas']==3
denied=response(AGENDA_ROL='Cuenta Clave');assert denied['ok'] is False and 'recordatorios' not in denied
failed=response(AGENDA_FALLA='1');assert failed['ok'] and not failed['agenda_ok'] and failed['no_leidas']==3
assert failed['recordatorios']==[] and len(failed['notificaciones'])==3
(OUT/'response.json').write_text(json.dumps(data),encoding='utf-8')
print('OK: fuente de comentarios en Marketing, permisos de sesión, conteo combinado, horarios, preservación de convocatorias/teléfono y fallo aislado de Agenda.')
