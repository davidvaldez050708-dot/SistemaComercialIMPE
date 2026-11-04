<?php
require __DIR__.'/../app/services/VerificacionInstitucionalService.php';
require __DIR__.'/../app/services/InteraccionRutaService.php';
function check($ok,$m){if(!$ok)throw new RuntimeException($m);}
$actual=['datos_verificados'=>1,'telefono_verificado'=>'+527777123456','correo_verificado'=>'Contacto@ejemplo.test','contacto_nombre'=>'María Pérez','contacto_cargo'=>'Dirección'];
$datos=['resultado'=>'CONTACTO_CORRECTO','verificacion_telefono_confirmado'=>1,'verificacion_correo_confirmado'=>1,'verificacion_contacto_confirmado'=>1];
check(VerificacionInstitucionalService::detalles($datos,$actual)===[],'Datos vigentes no admiten repetir casillas por POST');
$sin=$actual;$sin['datos_verificados']=0;check(count(VerificacionInstitucionalService::detalles($datos,$sin))===3,'Primera verificación permanece disponible');
$referido=['resultado'=>'CONTACTO_REFERIDO','nuevo_telefono_contacto'=>'777 712-3456','nuevo_correo_contacto'=>'contacto@ejemplo.test','nuevo_contacto_nombre'=>'MARÍA   PÉREZ','nuevo_contacto_cargo'=>'dirección'];
check(VerificacionInstitucionalService::detalles($referido,$actual)===[],'Formato, espacios y mayúsculas no generan otro crédito');
$referido['nuevo_correo_contacto']='nuevo@ejemplo.test';$det=VerificacionInstitucionalService::detalles($referido,$actual);
check(count($det)===1&&$det[0]['campo']==='correo','Sólo el dato realmente cambiado puede verificarse');
class ResVigente{private $fila;function __construct($fila){$this->fila=$fila;}function fetch_assoc(){return $this->fila;}}
class StmtVigente{private $db,$sql,$params=[];function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}function bind_param($types,&...$params){$this->params=&$params;}function execute(){if(str_contains($this->sql,'INSERT INTO interacciones_vinculacion'))$this->db->notas=$this->params[5];return true;}function get_result(){check(str_contains($this->sql,'seguimientos.datos_verificados'),'Ruta avanzada consulta el indicador real');return new ResVigente($this->db->actual);}function close(){}}
class DbVigente{public $actual,$notas,$ddl=0,$insert_id=123;function prepare($s){return new StmtVigente($this,$s);}function query($s){$this->ddl++;return true;}function begin_transaction(){}function commit(){}function rollback(){}}
$db=new DbVigente;$db->actual=$actual+['id'=>1,'estado_seguimiento'=>'ESPERANDO_RESPUESTA','fecha_envio'=>'2026-01-01 10:00:00','estado_oficio'=>'ENVIADO'];
$ref=new ReflectionClass(InteraccionRutaService::class);$service=$ref->newInstanceWithoutConstructor();$prop=$ref->getProperty('connection');$prop->setAccessible(true);$prop->setValue($service,$db);
$entrada=$datos+['canal'=>'LLAMADA','origen_llamada'=>'ZADARMA','persona_atendio'=>'','observacion'=>'Consulta de seguimiento','fecha_inicio'=>date('Y-m-d H:i:s')];
$r=$service->registrarInformativa(1,5,$entrada);
check($r['ok']&&!$r['verificacion_telefonica']['candidata']&&!$r['verificacion_telefonica']['contabilizable'],'Una interacción avanzada se guarda sin verificación');
check(!str_contains($db->notas,'VERIFICACION_')&&$db->ddl===0,'No marcadores de meta ni evidencia nueva en datos vigentes');
check($db->actual['estado_seguimiento']==='ESPERANDO_RESPUESTA','Conservar etapa');
echo "PASS verificación vigente: servidor, POST antiguo, equivalencias, cambios reales y registro avanzado sin crédito ni DDL\n";
