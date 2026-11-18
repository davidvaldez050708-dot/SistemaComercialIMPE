"""Contrato del controlador real adaptado, con proveedores sintéticos y sin base operativa."""
from pathlib import Path
import json, subprocess, tempfile
root=Path(__file__).resolve().parents[1]
controller=root/'tools/recordatorios_comerciales_v3_hostinger/after/app/controllers/ReminderController.php'
with tempfile.TemporaryDirectory(prefix='impe-hostinger-controller-') as tmp:
 site=Path(tmp);(site/'app/controllers').mkdir(parents=True);(site/'app/services').mkdir();(site/'app/helpers').mkdir()
 (site/'app/controllers/ReminderController.php').write_bytes(controller.read_bytes())
 import re
 for rel in re.findall(r"require_once __DIR__ \. \'/\.\./([^']+)';",controller.read_text(encoding='utf-8')):
  f=site/'app'/rel;f.parent.mkdir(parents=True,exist_ok=True);f.write_text('<?php',encoding='utf-8')
 providers={
 'helpers/PermissionHelper.php':"""<?php
 function tienePermiso($p){return in_array($p,$_SESSION['permisos']??[],true);}
 function puedeCrearConvocatoriaDesdeConvenio(){return false;}
 class Database {function connect($x=true){return new stdClass;}}
 """,
 'services/AgendaReunionService.php':"""<?php
 class AgendaReunionService {function avisosHorario($uid){return [['reunion_id'=>7,'agenda_horario_at'=>time()+1800,'agenda_horario_mensaje'=>'Reunión preservada']];}}
 """,
 'services/AgendaComentarioService.php':"""<?php
 class AgendaComentarioService {
  function notificaciones($uid){$r=[];for($i=1;$i<=20;$i++)$r[]=['id'=>$i,'agenda_comentario_id'=>$i,'nombre_entidad'=>'Comentario '.$i,'accion'=>'Abrir comentario','estado'=>'normal','prioridad'=>3];return ['recordatorios'=>$r,'avisos'=>[['tipo'=>'AGENDA_COMENTARIO']],'horarios'=>[['agenda_comentario_id'=>1,'agenda_horario_at'=>time()+600]]];}
  function registrarEntregados($uid,$r){$GLOBALS['entregados']=count($r);}
 }
 """,
 'services/AdminNotificationService.php':"""<?php class AdminNotificationService {function obtener(){return ['ok'=>true,'total'=>1,'recordatorios'=>[['id'=>99,'nombre_entidad'=>'Administración']],'avisos'=>[]];}}""",
 'services/TelefoniaNotificacionService.php':"""<?php class TelefoniaNotificacionService {function pendientes($uid,$x){$r=[];for($i=1;$i<=25;$i++)$r[]=['id'=>-$i,'telefonia_id'=>$i,'pendiente_operativo'=>true,'leida'=>1,'reiterar_hasta_atender'=>true,'nombre_entidad'=>'Llamada '.$i,'prioridad'=>1,'fecha'=>'2026-11-18 10:00:00'];return $r;}}""",
 'services/EmbudoComercialV3RecordatoriosService.php':"""<?php class EmbudoComercialV3RecordatoriosService {function obtener($uid){$r=[];for($i=1;$i<=85;$i++)$r[]=['id'=>$i,'comercial_id'=>$i,'usuario_id'=>$uid,'nombre_entidad'=>'Prospecto '.$i,'accion'=>'Revisar documentos','prioridad'=>$i===1?0:2,'fecha'=>'2099-01-01T16:00:00Z'];return ['recordatorios'=>$r,'avisos'=>[]];}}""",
 'services/AliadoConvocatoriaPendienteService.php':"""<?php class AliadoConvocatoriaPendienteService {function __construct($db){} function obtener($u,$s,$n){return [['id'=>1,'tipo'=>'ALIADO_CONVOCATORIA_PENDIENTE','etiqueta'=>'Configurar programas'],['id'=>2,'tipo'=>'ALIADO_CONVOCATORIA_PENDIENTE','etiqueta'=>'Publicar convocatoria']];}}""",
 }
 for rel,data in providers.items():(site/'app'/rel).write_text(data,encoding='utf-8')
 worker=site/'worker.php'
 worker.write_text("""<?php
 $caso=$argv[1];$meta=$argv[2];
 $roles=['ventas'=>3,'ventas-telefono'=>3,'admin'=>1,'aliado'=>6,'otro'=>5,'anonimo'=>0];
 $permisos=['ventas'=>['ventas.embudo.ver'],'ventas-telefono'=>['ventas.embudo.ver','telefonia.usar','telefonia.salientes'],'admin'=>[],'aliado'=>['aliados.ver','aliados.gestionar_programas'],'otro'=>[],'anonimo'=>[]];
 $_SESSION=['usuario_id'=>$caso==='anonimo'?0:22,'rol_id'=>$roles[$caso],'permisos'=>$permisos[$caso]];
 register_shutdown_function(function()use($meta){file_put_contents($meta,json_encode(['entregados'=>$GLOBALS['entregados']??null]));});
 require __DIR__.'/app/controllers/ReminderController.php';
 $controller=(new ReflectionClass(ReminderController::class))->newInstanceWithoutConstructor();
 $property=new ReflectionProperty(ReminderController::class,'agendaReunionService');$property->setAccessible(true);$property->setValue($controller,new AgendaReunionService());
 $controller->pendientes();
 """,encoding='utf-8')
 for caso in ['ventas','ventas-telefono','admin','aliado','otro','anonimo']:
  result=subprocess.run(['php',str(worker),caso,str(site/'meta.json')],capture_output=True,text=True)
  assert result.returncode==0 and not result.stderr,(caso,result.stdout,result.stderr)
  data=json.loads(result.stdout);meta=json.loads((site/'meta.json').read_text())
  if caso=='anonimo':assert data['ok'] is False;continue
  assert data['servidor_at']>0 and len(data['horarios'])==2,caso
  if caso=='admin':assert data['total']==21 and meta['entregados']==20,caso
  else:
   rows=data['recordatorios'];commercial=[r for r in rows if r.get('comercial_id')];phone=[r for r in rows if r.get('telefonia_id')];allies=[r for r in rows if r.get('tipo')=='ALIADO_CONVOCATORIA_PENDIENTE']
   assert len(commercial)==(85 if caso.startswith('ventas') else 0),caso
   if commercial:assert rows[0].get('comercial_id')==1,'prioridad del vencimiento sin truncar otros pendientes'
   assert len(phone)==(25 if caso=='ventas-telefono' else 0),caso
   assert len(allies)==(1 if caso=='aliado' else 0),caso
   assert meta['entregados']==len(rows)==data['total'],caso
   assert len([r for r in rows if r.get('agenda_comentario_id')])==12,caso
 print('CHECK_OK controlador Hostinger: seis perfiles, horarios, comentarios, acuses, 85 tareas comerciales, 25 obligaciones telefónicas y tareas de Aliados preservadas.')
