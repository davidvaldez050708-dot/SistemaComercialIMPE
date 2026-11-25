"""Ejecuta los constructores reales anterior/candidato con mysqli sin red."""
import json,re,subprocess,tempfile,shutil
from pathlib import Path
root=Path(__file__).resolve().parents[1];php=shutil.which('php') or 'C:/xampp/php/php.exe'
base=root/'tests/fixtures/conexion_recordatorios_20261124';stage=root
names=['AgendaReunionRepository','AgendaReunionService','ReminderAgendaFilterService','ReminderReunionFollowupService',
 'ReminderDirectLinkService','ReminderObservacionService','ReminderMeetingConfirmationService','SeguimientoCambioDatosService',
 'AliadoModel','AliadoSeguimientoReminderService','AdminNotificationService','ConvocatoriaModel',
 'AgendaComentarioRepository','AgendaComentarioService','TelefoniaNotificacionService','EmbudoComercialV3RecordatoriosService']
def read(name,after):
 p=Path('app/models' if name in ['AliadoModel','ConvocatoriaModel'] else 'app/services')/(name+'.php')
 return ((stage/p) if after and str(p).replace('\\','/') in {x['path'] for x in json.loads((root/'tools/conexion_recordatorios_release.json').read_text(encoding='utf-8'))['files']} else (base/p)).read_text(encoding='utf-8')
def ctor(s):
 start=s.index('    public function __construct(');body=s.index('{',start);pos=body+1;depth=1
 while depth:
  if s[pos]=='{':depth+=1
  elif s[pos]=='}':depth-=1
  pos+=1
 return s[start:pos]
def run(after,role,legacy=False):
 s='''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
class MysqliSinRed extends mysqli{public function __construct(){}public function query(string $query,int $mode=MYSQLI_STORE_RESULT):mysqli_result|bool{return true;}}
class Database{public static int $aperturas=0;private static $shared;public function connect(){self::$aperturas++;return new MysqliSinRed();}public static function compartida(){return self::$shared??= (new self())->connect();}}
class EcardReunionService{public $connection;public function __construct($db=null){$this->connection=$db;}}
require '''+repr(str(root/'app/helpers/ControlCargaMysql.php').replace('\\','/'))+''';
'''
 for name in names:
  body=ctor(read(name,after))
  if name=='AgendaReunionRepository':body+=' public function connection(){return $this->connection;}'
  if name=='ConvocatoriaModel':body+=' public function sincronizarConvocatoriasPorFecha(){}'
  s+='#[AllowDynamicProperties] class '+name+'{'+body+'}\n'
 if legacy:
  s+='$objects=[];'
  for name in names:
   if name not in ['AgendaComentarioService','TelefoniaNotificacionService','EmbudoComercialV3RecordatoriosService']:
    s+='$objects[]=new '+name+'();'
 else:
  controller=((stage if after else base)/'app/controllers/ReminderController.php').read_text(encoding='utf-8')
  marker='        // Compartir una conexión' if after else '        // Conservar el orden'
  block=controller[controller.index(marker):controller.index('        $horarios = [];')]
  block=re.sub(r'^\s*require_once[^\n]*\n','',block,flags=re.M)
  flags={'esAdministrador':role=='admin','puedeOperarSeguimientos':role=='analista','puedeCentroReuniones':role in ['analista','cuenta'],
    'esAnalista':role=='analista','esSupervisor':role=='cuenta','puedeSeguimientoAliados':role=='cuenta'}
  values=''.join('$'+k+'='+('true' if v else 'false')+';' for k,v in flags.items())
  s+='#[AllowDynamicProperties] class RecolectorPrueba{public function iniciar(){'+values+block+'return get_object_vars($this);}}\n'
  s+='Database::compartida();$objects=(new RecolectorPrueba())->iniciar();'
  # Constructores situados después del bloque inicial, con los argumentos reales.
  for name in ['AgendaComentarioService']+(['AdminNotificationService'] if role=='admin' else ['TelefoniaNotificacionService']):
   match=re.search(r'new '+name+r'\((?:[^()]|\([^()]*\))*\)',controller)
   expr=match.group().replace('$conexionRecordatorios','ImpeControlCargaMysql::conexion()')
   s+='$objects[]='+expr+';'
  if role=='asesor':
   match=re.search(r'new EmbudoComercialV3RecordatoriosService\([^)]*\)',controller)
   s+='$objects[]='+match.group().replace('$conexionRecordatorios','ImpeControlCargaMysql::conexion()')+';'
  if role=='admin':
   call=re.search(r'new ConvocatoriaModel\([^)]*\)',read('AdminNotificationService',after)).group()
   s+='$objects[]='+call.replace('$this->conexionInyectada','ImpeControlCargaMysql::conexion()')+';'
 s+='''$ids=[];$visitados=new SplObjectStorage();$walk=function($o)use(&$walk,&$ids,$visitados){if(!is_object($o)||$visitados->contains($o))return;$visitados->attach($o);if($o instanceof mysqli){$ids[spl_object_id($o)]=true;return;}foreach(get_object_vars($o) as $v)$walk($v);};foreach($objects as $o)$walk($o);echo json_encode(['aperturas'=>Database::$aperturas,'handles'=>count($ids)]);'''
 with tempfile.TemporaryDirectory() as t:
  p=Path(t)/'test.php';p.write_text(s,encoding='utf-8');r=subprocess.run([php,str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  assert r.returncode==0,(r.stdout,r.stderr);return json.loads(r.stdout)
for role in ['admin','analista','cuenta','asesor']:
 before=run(False,role);after=run(True,role)
 assert before['aperturas']>1 and after=={'aperturas':1,'handles':1},(role,before,after)
 print(role+': solicitudes de conexion '+str(before['aperturas'])+' -> 1; mismo handle en dependencias')
assert run(False,'analista',True)==run(True,'analista',True),'Fuera del recolector se conserva la construccion independiente'
print('OK: inyeccion completa por perfil; comportamiento predeterminado conservado; ninguna conexion real.')
