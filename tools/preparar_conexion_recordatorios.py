"""Inyección opcional: una conexión en Reminder; comportamiento independiente fuera de él."""
import difflib,json
from pathlib import Path
root=Path(__file__).resolve().parents[1];base=root/'tests/fixtures/conexion_recordatorios_20261124';rows=[]
def edit(name,changes,counts=None):
 old=(base/name).read_text(encoding='utf-8');new=old
 for i,(before,after) in enumerate(changes):
  cantidad=(counts or {}).get(i,1)
  if new.count(before)!=cantidad:raise RuntimeError(name+' contexto diferente: '+before[:80])
  new=new.replace(before,after)
 (root/name).write_text(new,encoding='utf-8')
 out=root/'tmp/aperturas-recordatorios-stage'/name;out.parent.mkdir(parents=True,exist_ok=True);out.write_text(new,encoding='utf-8')
 a,b=old.splitlines(True),new.splitlines(True);h=[]
 for g in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):h.append(dict(before=''.join(a[g[0][1]:g[-1][2]]),after=''.join(b[g[0][3]:g[-1][4]])))
 rows.append(dict(path=name,new_file=False,hunks=h))

simple=['app/services/ReminderAgendaFilterService.php','app/services/ReminderReunionFollowupService.php',
 'app/services/ReminderDirectLinkService.php','app/services/ReminderObservacionService.php',
 'app/services/ReminderMeetingConfirmationService.php','app/services/SeguimientoCambioDatosService.php',
 'app/services/AgendaReunionRepository.php']
for name in simple:
 edit(name,[('public function __construct()','public function __construct(?mysqli $connection = null)'),
 ("        $database = new Database();\n        $this->connection = $database->connect();","        $this->connection = $connection ?? (new Database())->connect();")])
edit('app/services/AgendaReunionService.php',[
 ('public function __construct()','public function __construct(?mysqli $connection = null)'),
 ('new AgendaReunionRepository();','new AgendaReunionRepository($connection);')])
edit('app/services/AliadoSeguimientoReminderService.php',[
 ('public function __construct()','public function __construct(?mysqli $connection = null)'),
 ('$this->modelo = new AliadoModel();','$this->modelo = new AliadoModel($connection);'),
 ("        $database = new Database();\n        $this->connection = $database->connect();","        $this->connection = $connection ?? (new Database())->connect();")])
edit('app/services/AdminNotificationService.php',[
 ('    private $connection;','    private $connection;\n    private $conexionInyectada;'),
 ('public function __construct()','public function __construct(?mysqli $connection = null)'),
 ("        $database = new Database();\n        $this->connection = $database->connect();","        $this->conexionInyectada = $connection;\n        $this->connection = $connection ?? (new Database())->connect();"),
 ('new ConvocatoriaModel()','new ConvocatoriaModel($this->conexionInyectada)')])
edit('app/helpers/ReminderHelper.php',[
 ('function obtenerRecordatoriosSeguimientoAnalista($usuarioId, $limite = 8)','function obtenerRecordatoriosSeguimientoAnalista($usuarioId, $limite = 8, ?mysqli $connection = null)'),
 ('function obtenerAvisosPendientesRecordatoriosAnalista($usuarioId)','function obtenerAvisosPendientesRecordatoriosAnalista($usuarioId, ?mysqli $connection = null)'),
 ('obtenerRecordatoriosSeguimientoAnalista($usuarioId, 50);','obtenerRecordatoriosSeguimientoAnalista($usuarioId, 50, $connection);'),
 ("            $database = new Database();\n            $connection = $database->connect();","            $connection = $connection ?? (new Database())->connect();")],{3:2})
changes=[("        // Conservar el orden y las conexiones independientes de los servicios usados.","        // Compartir una conexión dentro de este recolector; no hay una transacción envolvente.\n        require_once __DIR__.'/../helpers/ControlCargaMysql.php';\n        $conexionRecordatorios=ImpeControlCargaMysql::conexion();")]
for name in ['AgendaReunionService','ReminderAgendaFilterService','ReminderReunionFollowupService','ReminderDirectLinkService','ReminderObservacionService','ReminderMeetingConfirmationService','SeguimientoCambioDatosService','AliadoSeguimientoReminderService','AdminNotificationService','TelefoniaNotificacionService','EmbudoComercialV3RecordatoriosService']:
 changes.append(('new '+name+'()','new '+name+'($conexionRecordatorios)'))
changes.extend([
 ('new AgendaComentarioService()','new AgendaComentarioService(new AgendaComentarioRepository($conexionRecordatorios))'),
 ('obtenerAvisosPendientesRecordatoriosAnalista($usuarioId);','obtenerAvisosPendientesRecordatoriosAnalista($usuarioId, $conexionRecordatorios);'),
 ('new AliadoConvocatoriaPendienteService((new Database())->connect())','new AliadoConvocatoriaPendienteService($conexionRecordatorios)')])
edit('app/controllers/ReminderController.php',changes,{12:2})
(root/'tools/conexion_recordatorios_release.json').write_text(json.dumps(dict(release='conexion-recordatorios-v1',files=rows),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
p=(root/'tools/publicar_marcador_estado.py').read_text(encoding='utf-8').replace("FILES=('public/javascript/telefonia_marcador.js',)",'FILES='+repr(tuple(x['path'] for x in rows))).replace('marcador_estado_release.json','conexion_recordatorios_release.json').replace('marcador-estado-','conexion-recordatorios-').replace('Actualiza sólo la interfaz del marcador; no toca SIP ni permisos.','Comparte una conexion en el recolector de recordatorios; inyeccion opcional sin cambiar host ni credenciales.').replace('Estado del marcador actualizado; llamadas activas y resultados pendientes conservan su bloqueo.','Recordatorios reutiliza una conexion; permisos, avisos y transacciones fuera del recolector conservados.')
(root/'tools/publicar_conexion_recordatorios.py').write_text(p,encoding='utf-8')
print('Paquete: '+str(len(rows))+' archivos, solo inyeccion de conexion en el recolector.')
