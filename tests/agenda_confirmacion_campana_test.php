<?php
// SQL real del repositorio sobre PDO SQLite en memoria. NOW() emula el reloj
// MySQL UTC observado en Hostinger; no se conecta a ninguna BD del CRM.
require_once __DIR__ . '/../app/services/AgendaReunionService.php';
require_once __DIR__ . '/../app/services/ReminderMeetingConfirmationService.php';
set_error_handler(static function ($level,$message,$file,$line) { if(error_reporting() & $level) throw new ErrorException($message,0,$level,$file,$line); return false; });
function verificar($condition,$message) { if(!$condition) throw new RuntimeException($message); }
class ResultadoAgendaPrueba {
    public $num_rows;private $rows;
    public function __construct($rows) {$this->rows=$rows;$this->num_rows=count($rows);}
    public function fetch_assoc() {return array_shift($this->rows) ?: null;}
    public function fetch_all($mode) {return $this->rows;}
}
class SentenciaAgendaPrueba {
    public $affected_rows=0;private $statement,$values=[];
    public function __construct($pdo,$sql) {$this->statement=$pdo->prepare($sql);}
    public function bind_param($types,&...$values) {verificar(strlen($types)===count($values),'Tipos de parámetros incorrectos');$this->values=&$values;}
    public function execute() {$this->statement->execute($this->values);$this->affected_rows=$this->statement->rowCount();return true;}
    public function get_result() {return new ResultadoAgendaPrueba($this->statement->fetchAll(PDO::FETCH_ASSOC));}
}
class ConexionAgendaPrueba {
    public $pdo,$queries=[];
    public function __construct() {
        $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
        $this->pdo->exec("CREATE TABLE seguimientos_vinculacion(id INTEGER PRIMARY KEY,nombre_entidad TEXT,activo INTEGER,estado_seguimiento TEXT)");
        $this->pdo->exec("INSERT INTO seguimientos_vinculacion VALUES(12,'Institución de prueba',1,'ACTIVO')");
        $this->pdo->exec("CREATE TABLE reuniones_vinculacion(id INTEGER PRIMARY KEY,seguimiento_id INTEGER,analista_id INTEGER,cuenta_clave_id INTEGER,fecha_propuesta TEXT,duracion_minutos INTEGER,modalidad TEXT,estado TEXT,zoom_url TEXT,ubicacion TEXT,notas_kam TEXT,confirmada_at TEXT,confirmada_por INTEGER,notificado_analista_at TEXT,notificado_kam_at TEXT,cambio_motivo TEXT,cambio_solicitado_at TEXT,cambio_solicitado_por INTEGER,correo_confirmacion_asunto TEXT,correo_confirmacion_cuerpo TEXT,correo_confirmacion_at TEXT,correo_confirmacion_por INTEGER,cancelacion_motivo TEXT,cancelada_at TEXT,cancelada_por INTEGER,updated_at TEXT)");
    }
    public function prepare($sql) {
        $this->queries[]=$sql;
        // Adaptar sólo DATE_ADD al dialecto del motor de prueba. Los WHERE,
        // identificadores, parámetros y transiciones se ejecutan sin cambiarlos.
        $sql=str_replace('DATE_ADD(?, INTERVAL 24 HOUR)',"datetime(?, '+24 hours')",$sql);
        $sql=preg_replace('/DATE_ADD\(\s*r\.fecha_propuesta,\s*INTERVAL COALESCE\(NULLIF\(r\.duracion_minutos, 0\), 60\) MINUTE\s*\)/',"datetime(r.fecha_propuesta, '+' || COALESCE(NULLIF(r.duracion_minutos,0),60) || ' minutes')",$sql);
        return new SentenciaAgendaPrueba($this->pdo,$sql);
    }
    public function query($sql) {
        if($sql==="SHOW TABLES LIKE 'reuniones_vinculacion'")return new ResultadoAgendaPrueba([['tabla'=>'reuniones_vinculacion']]);
        throw new RuntimeException('Consulta de prueba no prevista');
    }
    public function propuesta($minutes,$estado='SOLICITADA') {
        $this->pdo->exec('DELETE FROM reuniones_vinculacion');
        $stmt=$this->pdo->prepare("INSERT INTO reuniones_vinculacion(id,seguimiento_id,analista_id,cuenta_clave_id,fecha_propuesta,duracion_minutos,modalidad,estado,updated_at) VALUES(8,12,5,7,?,60,'VIRTUAL',?,?)");
        $stmt->execute([date('Y-m-d H:i:s',time()+$minutes*60),$estado,gmdate('Y-m-d H:i:s')]);
    }
    public function fila() {return $this->pdo->query('SELECT * FROM reuniones_vinculacion WHERE id=8')->fetch(PDO::FETCH_ASSOC);}
}
function repositorioAgendaPrueba($class,$db) {
    $r=(new ReflectionClass($class))->newInstanceWithoutConstructor();$p=new ReflectionProperty($class,'connection');$p->setAccessible(true);$p->setValue($r,$db);return $r;
}
$root=dirname(__DIR__);
$before=shell_exec('git show 12d9c62d:app/services/AgendaReunionRepository.php');
verificar(is_string($before)&&strpos($before,'class AgendaReunionRepository')!==false,'Falta versión anterior para reproducir');
$before=str_replace("require_once __DIR__ . '/../../config/db_connection.php';",'', $before);
$before=str_replace('class AgendaReunionRepository','class AgendaReunionRepositoryAntes',$before);
$actual = file_get_contents($root . '/app/services/AgendaReunionRepository.php');
foreach (['cuentaClaveParaSeguimiento','insertarSolicitud','reunion','reunionesMes'] as $method) {
    $pattern = '/    public function '.preg_quote($method,'/').'\([\s\S]*?(?=    public function )/';
    verificar(preg_match($pattern,$before,$originalMethod)===1 && preg_match($pattern,$actual,$currentMethod)===1 && $originalMethod[0]===$currentMethod[0], 'No modificar asignación ni acceso: '.$method);
}
eval('?>'.$before);
$db=new ConexionAgendaPrueba();$db->propuesta(90);$_SESSION=['usuario_id'=>7,'rol_id'=>6,'permisos'=>['reuniones.ver','reuniones.gestionar','reuniones.solicitar']];
verificar(strpos($before, 'cuenta_clave_asignacion_id') !== false, 'Debe mantenerse el enlace territorial del Analista a Cuenta Clave');
$old=repositorioAgendaPrueba('AgendaReunionRepositoryAntes',$db);$repo=repositorioAgendaPrueba('AgendaReunionRepository',$db);
verificar(!$old->confirmar(8,7,'https://zoom.us/j/prueba','','nota'),'El defecto anterior debía reproducirse con MySQL UTC');
verificar($old->pendientesKam(10)===[],'La campana anterior debía ocultar la propuesta futura local');
verificar($db->fila()['estado']==='SOLICITADA'&&$db->fila()['confirmada_por']===null,'El fallo no significa que otro usuario atendió la solicitud');
verificar(count($repo->pendientesKam(10,7))===1,'Cuenta Clave debe recibir la propuesta al solicitarla');
$_SESSION['usuario_id']=99;
verificar(count($repo->pendientesKam(10,7))===1,'El destinatario debe ser explícito, sin depender de otra sesión');
verificar($repo->pendientesKam(10,99)===[],'Otra cuenta no puede ver el pendiente');
verificar(!$repo->confirmar(8,99,'https://zoom.us/j/prueba','','nota'),'Otra cuenta no puede confirmar');
verificar($repo->confirmar(8,7,'https://zoom.us/j/prueba','','nota'),'Cuenta Clave asignada debe confirmar con fecha futura local');
verificar($db->fila()['estado']==='CONFIRMADA'&&(int)$db->fila()['confirmada_por']===7,'Confirmación asignada al responsable correcto');
verificar(!$repo->confirmar(8,7,'https://zoom.us/j/duplicado','','nota'),'Una segunda confirmación no debe sobrescribir');
verificar($repo->marcarCorreo(8,5,'Asunto','Texto'),'El registro posterior a envío debe usar el mismo reloj; no se envía correo en esta prueba');
verificar($repo->solicitarCancelacionConAviso(8,7,'Motivo'),'El flujo de cancelación conserva la validación futura local');
verificar($repo->cancelar(8,5,4,'Motivo'),'Cancelación posterior preservada');
$db->propuesta(90);verificar($repo->solicitarCambio(8,7,'Cambiar horario'),'Solicitar ajuste de propuesta futura local');
$db->propuesta(-10);verificar(!$repo->confirmar(8,7,'https://zoom.us/j/prueba','','nota'),'Una fecha pasada no puede confirmarse');
verificar(!$repo->solicitarCambio(8,7,'Motivo'),'No debe solicitarse ajuste por la ruta de futuras con fecha vencida');
verificar(!$repo->cancelar(8,7,6,'Motivo'),'No se flexibiliza la fecha de cancelación');
// Probar la lógica real de la campana y el destino al responsable asignado.
class RepositorioAgendaServicioPrueba extends AgendaReunionRepository {
    public $db,$interacciones=0,$forzarFallo=false;
    public function __construct($db) {$this->db=$db;$p=new ReflectionProperty(AgendaReunionRepository::class,'connection');$p->setAccessible(true);$p->setValue($this,$db);}
    public function reunion($id,$uid,$role) {$r=$this->db->fila();return $r&&($r['analista_id']==$uid||$r['cuenta_clave_id']==$uid)?$r:null;}
    public function conflictoHorario($fecha,$duracion,$analista,$kam,$exclude=0) {return null;}
    public function registrarInteraccion($seguimiento,$usuario,$notas) {$this->interacciones++;}
    public function confirmar($id,$uid,$url,$ubicacion,$nota) {return $this->forzarFallo?false:parent::confirmar($id,$uid,$url,$ubicacion,$nota);}
}
$testRepo=new RepositorioAgendaServicioPrueba($db);
$service=(new ReflectionClass(AgendaReunionService::class))->newInstanceWithoutConstructor();$p=new ReflectionProperty($service,'repo');$p->setAccessible(true);$p->setValue($service,$testRepo);
$_SESSION=['usuario_id'=>7,'rol_id'=>6,'permisos'=>['reuniones.ver','reuniones.gestionar','reuniones.solicitar']];
$db->propuesta(24*60);$bell=$service->obtenerNotificacionesCampana(7,6);verificar(count($bell['recordatorios'])===1,'Incluso con ambos permisos, Cuenta Clave recibe una nueva solicitud lejana');
verificar(strpos($bell['recordatorios'][0]['url'],'reunion_id=8')!==false,'La campana abre la reunión correcta');
$db->propuesta(90);$r=$service->confirmar(7,6,['reunion_id'=>8,'zoom_url'=>'https://zoom.us/j/prueba']);verificar($r['ok']&&$testRepo->interacciones===1,'Servicio confirma la propuesta y registra una interacción');
verificar($service->obtenerNotificacionesCampana(7,6)['recordatorios']===[],'La solicitud confirmada desaparece de pendientes');
$_SESSION=['usuario_id'=>5,'rol_id'=>4,'permisos'=>['reuniones.ver','reuniones.solicitar']];
$bell=$service->obtenerNotificacionesCampana(5,4);verificar(count($bell['recordatorios'])===1&&strpos($bell['recordatorios'][0]['accion'],'enviar correo')!==false,'Analista recibe confirmación para continuar el flujo');
verificar(!$service->confirmar(5,4,['reunion_id'=>8,'zoom_url'=>'https://zoom.us/j/prueba'])['ok'],'Analista no puede confirmar');
$_SESSION=['usuario_id'=>7,'rol_id'=>6,'permisos'=>['reuniones.ver','reuniones.gestionar']];
$db->propuesta(90);$testRepo->forzarFallo=true;$r=$service->confirmar(7,6,['reunion_id'=>8,'zoom_url'=>'https://zoom.us/j/prueba']);verificar(!$r['ok']&&strpos($r['mensaje'],'otro usuario')===false,'Un fallo no atribuye acciones a otra cuenta');$testRepo->forzarFallo=false;
$db->propuesta(-10);$r=$service->confirmar(7,6,['reunion_id'=>8,'zoom_url'=>'https://zoom.us/j/prueba']);verificar(!$r['ok']&&strpos($r['mensaje'],'venció')!==false,'Una propuesta vencida explica la causa real');
$confirmation=(new ReflectionClass(ReminderMeetingConfirmationService::class))->newInstanceWithoutConstructor();$p=new ReflectionProperty($confirmation,'connection');$p->setAccessible(true);$p->setValue($confirmation,$db);
$bell=$confirmation->obtener(7,6);verificar(count($bell['recordatorios'])===1&&strpos($bell['recordatorios'][0]['accion'],'nueva fecha')!==false,'La solicitud vencida sigue visible en la campana de su responsable');
verificar(count($bell['avisos'])===1,'Aviso de vencimiento una vez');
verificar($confirmation->obtener(7,6)['avisos']===[],'No se repite el aviso emergente al recargar');
verificar($confirmation->obtener(99,6)['recordatorios']===[],'No se notifica a otra Cuenta Clave');
$db->propuesta(90);$bell=$confirmation->obtener(7,6);verificar(count($bell['recordatorios'])===1&&$bell['avisos']===[],'La fecha futura local no se considera vencida por el reloj UTC de SQL');
verificar($db->fila()['notificado_kam_at']===null,'No se marca como vencida una propuesta futura');
$db->propuesta(240);verificar($confirmation->obtener(7,6)['recordatorios']===[],'El recordatorio urgente conserva la ventana de tres horas; la agenda general ya muestra la solicitud');
echo "OK: defecto UTC reproducido, confirmar/ajustar/cancelar y pendientes con hora PHP, destinatario/estado protegidos, campana con permisos mixtos, vencidas visibles, aviso único y mensaje sin atribución falsa. SQL en memoria, sin BD real ni correos.\n";
