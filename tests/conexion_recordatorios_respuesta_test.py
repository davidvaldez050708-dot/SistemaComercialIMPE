"""Controlador real + constructores reales; respuestas de servicios sintéticas sin red."""
import json,re,subprocess,tempfile
from pathlib import Path
test=Path(__file__).with_name('conexion_recordatorios_constructores.py')
env={'__file__':str(test)};exec(compile(test.read_text(encoding='utf-8').split("for role in ['admin','analista','cuenta','asesor']:")[0],str(test),'exec'),env)
root=env['root'];base=env['base'];stage=env['stage'];read=env['read'];ctor=env['ctor'];names=env['names'];php=env['php']
def scenario(after,role):
 permisos=['telefonia.usar','telefonia.recibir']
 if role in ['analista','cuenta']:permisos+=['reuniones.ver','agenda.ver','agenda.comentarios.ver']
 if role=='analista':permisos+=['reuniones.solicitar','seguimientos_vinculacion.operar_propios']
 if role=='cuenta':permisos+=['reuniones.gestionar','aliados.seguimiento_convocatorias','aliados.ver','aliados.gestionar_programas']
 if role=='asesor':permisos+=['ventas.embudo.ver','telefonia.salientes']
 s='''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
class MysqliSinRed extends mysqli{public function __construct(){}public function query(string $q,int $m=MYSQLI_STORE_RESULT):mysqli_result|bool{return true;}}
class Database{private static $shared;public function connect(){return new MysqliSinRed();}public static function compartida(){return self::$shared??= (new self())->connect();}}
class EcardReunionService{public function __construct($db=null){}}
function tienePermiso($p){return in_array($p,$GLOBALS['permisos'],true);}
function puedeCrearConvocatoriaDesdeConvenio(){return false;}
function fixture($tipo){return ['ok'=>true,'total'=>1,'requiere_migracion'=>false,'recordatorios'=>[['id'=>abs(crc32($tipo))%99999+1,'nombre_entidad'=>$tipo,'accion'=>'Revisar','fecha'=>'2026-11-23 10:00:00','url'=>'index.php?tipo='.$tipo]],'avisos'=>[],'horarios'=>[]];}
class ServicioFixture{public function __call($m,$args){
 if($m==='avisosHorario')return [];
 if(in_array($m,['filtrarRecordatoriosSeguimiento','filtrarAvisosSeguimiento','aplicar']))return $args[0];
 if($m==='registrarEntregados')return null;
 $d=fixture(get_class($this));
 if($m==='pendientes'){$d['recordatorios'][0]['telefonia_id']=901;$d['recordatorios'][0]['pendiente_operativo']=true;return $d['recordatorios'];}
 if(get_class($this)==='AliadoConvocatoriaPendienteService'){$d['recordatorios'][0]['tipo']='ALIADO_CONVOCATORIA_PENDIENTE';return $d['recordatorios'];}
 if(get_class($this)==='EmbudoComercialV3RecordatoriosService'){$d['recordatorios'][0]['comercial_id']=902;}
 return $d;
}}
class AliadoConvocatoriaPendienteService extends ServicioFixture{public function __construct($db){}}
function obtenerAvisosPendientesRecordatoriosAnalista($u,$db=null){return fixture('Seguimiento');}
function serializarRecordatoriosSeguimiento($d){return $d;}
'''
 s+='require '+repr(str(root/'app/helpers/ControlCargaMysql.php').replace('\\','/'))+';\n'
 for name in names:
  body=ctor(read(name,after))
  if name=='AgendaReunionRepository':body+=' public function connection(){return $this->connection;}'
  if name=='AgendaReunionService':body+=' public const ROL_ANALISTA=4,ROL_CUENTA_CLAVE=6;'
  s+='#[AllowDynamicProperties] class '+name+' extends ServicioFixture{'+body+'}\n'
 controller=((stage if after else base)/'app/controllers/ReminderController.php').read_text(encoding='utf-8')
 controller=re.sub(r'require_once\s+[^;]+;','',controller)
 s+=controller.removeprefix('<?php')
 s+='$GLOBALS["permisos"]='+repr(permisos).replace("'",'"')+';'
 rid={'admin':1,'analista':4,'cuenta':6,'asesor':3}[role]
 s+='$_SESSION=["usuario_id"=>50,"rol_id"=>'+str(rid)+'];(new ReminderController())->pendientes();'
 with tempfile.TemporaryDirectory() as tmp:
  p=Path(tmp)/'prueba.php';p.write_text(s,encoding='utf-8');r=subprocess.run([php,str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  assert r.returncode==0,(r.stdout,r.stderr);d=json.loads(r.stdout);d.pop('servidor_at',None);return d
for role in ['admin','analista','cuenta','asesor']:
 before=scenario(False,role);after=scenario(True,role)
 assert before==after,(role,before,after)
 print(role+': JSON de avisos, orden y pendientes identico antes/despues')
print('OK: respuestas por perfil conservadas; controlador candidato ejecutado sin conexiones reales.')
