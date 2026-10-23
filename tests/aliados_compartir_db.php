<?php
if(PHP_SAPI!=='cli'||!preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/helpers/PermissionHelper.php';
require_once __DIR__.'/../app/services/AliadoCompartirService.php';
$db=(new Database())->connect(false);$checks=0;
function verificar($v,$m){global $checks;if(!$v)throw new RuntimeException($m);$checks++;echo "OK $m\n";}
function rechaza(callable $fn,int $code,$m){try{$fn();}catch(DomainException $e){verificar($e->getCode()===$code,$m);return;}throw new RuntimeException('Aceptó: '.$m);}
class CorreoCompartirPrueba extends CorreoSalidaInstitucionalService {
 public int $llamadas=0;public bool $exito=true;public array $ultimo=[];
 public function __construct(){}
 public function enviar($datos){$this->llamadas++;$this->ultimo=$datos;return ['ok'=>$this->exito,'proveedor'=>'SIMULADO','mensaje'=>$this->exito?'OK':'Fallo simulado'];}
}
$_SESSION=['usuario_id'=>2,'rol_id'=>4,'permisos'=>['aliados.ver','convocatorias.ver','aliados.compartir_correo','aliados.preparar_whatsapp']];
$mail=new CorreoCompartirPrueba();$s=new AliadoCompartirService($db,new AliadoDifusionService($mail));
$manual=(int)$db->query("SELECT id FROM aliados_manuales WHERE nombre_entidad='Aliado Regional'")->fetch_assoc()['id'];
$contar=fn($tipo)=>(int)$db->query($tipo==='manual'?'SELECT COUNT(*) n FROM aliados_manuales_difusiones':'SELECT COUNT(*) n FROM aliados_convocatorias_envios')->fetch_assoc()['n'];
$enlaces=[];
foreach(['manual'=>$manual,'institucional'=>10] as $tipo=>$id){
 $o=['origen_tipo'=>$tipo,'origen_id'=>$id,'ambito'=>'asociadas'];$p=$s->preparar($o);
 $propias=array_values(array_filter($p['convocatorias'],fn($c)=>str_starts_with($c['titulo'],ucfirst($tipo).' · Programa')));
 verificar(count($propias)===2,"dos programas propios $tipo");
 verificar(count(array_unique(array_column($propias,'enlace_registro')))===1,"enlace estable por aliado $tipo");
 verificar(!str_contains($propias[0]['enlace_registro'],'ajeno.example'),"no reutiliza enlace de otra institución $tipo");
 foreach($propias as $c){$d=$s->borrador($o+['convocatoria_id'=>$c['id']]);verificar(str_contains($d['borrador']['cuerpo'],$c['enlace_registro'])&&str_contains($d['borrador_whatsapp'],$c['enlace_registro']),"borradores usan enlace propio $tipo #{$c['id']}");}
 verificar($propias[0]['imagen']!==$propias[1]['imagen'],"imágenes por convocatoria $tipo");
 verificar(count($p['canales']['whatsapp_manual']['contactos'])===2,"dos números autorizados; excluye no autorizado $tipo");
 $foranea=(int)$db->query("SELECT convocatoria_id FROM marketing_aliado_convocatorias_origen WHERE origen_tipo<> '$tipo' ORDER BY convocatoria_id DESC LIMIT 1")->fetch_assoc()['convocatoria_id'];
 rechaza(fn()=>$s->borrador($o+['convocatoria_id'=>$foranea]),409,"no comparte publicación de otro aliado $tipo");
 $datos=$o+['operacion_id'=>$p['operacion_id'],'convocatoria_id'=>$propias[0]['id'],'asunto'=>'Prueba segura','mensaje'=>'Convocatoria de prueba','destinatario'=>$p['canales']['correo']['destinatario'],'contacto_id'=>$p['canales']['whatsapp_manual']['contactos'][1]['id']];
 rechaza(fn()=>$s->ejecutar(array_replace($datos,['destinatario'=>'intruso@example.test']),'CORREO'),422,"correo ajeno rechazado $tipo");
 rechaza(fn()=>$s->ejecutar(array_replace($datos,['destinatario'=>'correo incorrecto']),'CORREO'),422,"correo inválido rechazado $tipo");
 $antes=$contar($tipo);$respuesta=$s->ejecutar($datos,'CORREO');verificar($respuesta['ok']&&$contar($tipo)===$antes+1,"proveedor confirma antes de registrar $tipo");
 verificar($mail->ultimo['destinatario']===$datos['destinatario']&&str_contains(html_entity_decode($mail->ultimo['html_adicional']),$propias[0]['enlace_registro']),"proveedor institucional y tarjeta correcta $tipo");
 $veces=$mail->llamadas;verificar($s->ejecutar($datos,'CORREO')['ok']&&$mail->llamadas===$veces&&$contar($tipo)===$antes+1,"reintento idempotente sin reenviar $tipo");
 $nuevo=$s->preparar($o);$datos['operacion_id']=$nuevo['operacion_id'];$duplicado=$s->ejecutar($datos,'CORREO');verificar(!empty($duplicado['requiere_confirmacion'])&&$mail->llamadas===$veces,"duplicado exige confirmación $tipo");
 $datos['confirmar_reenvio']=1;verificar($s->ejecutar($datos,'CORREO')['ok']&&$mail->llamadas===$veces+1,"reenvío explícito $tipo");
 verificar($s->ejecutar($datos,'CORREO')['ok']&&$mail->llamadas===$veces+1,"reintento del reenvío tampoco duplica $tipo");
 $datos['operacion_id']=$s->preparar($o)['operacion_id'];$datos['convocatoria_id']=$propias[1]['id'];unset($datos['confirmar_reenvio']);
 $mail->exito=false;$antes=$contar($tipo);verificar(!$s->ejecutar($datos,'CORREO')['ok']&&$contar($tipo)===$antes,"fallo del proveedor no registra envío $tipo");$mail->exito=true;
 $datos['operacion_id']=$s->preparar($o)['operacion_id'];
 rechaza(fn()=>$s->ejecutar(array_replace($datos,['contacto_id'=>999999]),'WHATSAPP_MANUAL'),422,"teléfono ajeno no autorizado $tipo");
 $noAutorizado=$db->query($tipo==='manual'?"SELECT id FROM aliados_manuales_contactos WHERE aliado_id=$id AND whatsapp_autorizado=0 LIMIT 1":"SELECT id FROM aliados_contactos WHERE seguimiento_id=$id AND autorizado_whatsapp=0 LIMIT 1")->fetch_assoc()['id'];
 rechaza(fn()=>$s->ejecutar(array_replace($datos,['contacto_id'=>$noAutorizado]),'WHATSAPP_MANUAL'),422,"autorización WhatsApp se valida en servidor $tipo");
 $veces=$mail->llamadas;$antes=$contar($tipo);verificar($s->ejecutar($datos,'WHATSAPP_MANUAL')['ok']&&$mail->llamadas===$veces&&$contar($tipo)===$antes+1,"marca manual sin enviar WhatsApp $tipo");
 verificar($s->ejecutar($datos,'WHATSAPP_MANUAL')['ok']&&$contar($tipo)===$antes+1,"registro WhatsApp idempotente $tipo");
 $enlaces[$tipo]=$propias[0]['enlace_registro'];
}
verificar($enlaces['manual']!==$enlaces['institucional'],'no mezcla el enlace de instituciones diferentes');
$o=['origen_tipo'=>'manual','origen_id'=>$manual];
foreach(['Vencida','Borrador'] as $titulo){$cid=$db->query("SELECT id FROM convocatorias WHERE titulo='$titulo'")->fetch_assoc()['id'];rechaza(fn()=>$s->borrador($o+['convocatoria_id'=>$cid]),409,"servidor rechaza $titulo");}
$db->query('UPDATE formulario_aliado_registro SET habilitado=0 WHERE seguimiento_id=10');
verificar(!$s->preparar(['origen_tipo'=>'institucional','origen_id'=>10])['convocatorias'],'perfil deshabilitado no cae en enlace histórico activo');
$db->query('UPDATE formulario_aliado_registro SET habilitado=1 WHERE seguimiento_id=10');
$m=new AliadoModel();$nombres=array_column($m->obtenerConvocatoriasAsociadas('institucional',10),'titulo');
$exclusiva=(int)$db->query("SELECT x.convocatoria_id FROM marketing_aliado_convocatorias_origen x JOIN convocatorias c ON c.id=x.convocatoria_id WHERE x.origen_tipo='manual' AND c.estado=1 AND c.fecha_inicio<=CURDATE() AND c.fecha_termino>=CURDATE() ORDER BY c.id DESC LIMIT 1")->fetch_assoc()['convocatoria_id'];
verificar(!$m->obtenerConvocatoriaAplicable($exclusiva,1),'endpoint territorial antiguo no permite eludir la pertenencia exclusiva');
verificar(in_array('Legado institucional',$nombres,true)&&in_array('Internacional Registro ligado',$nombres,true),'conserva relaciones legadas e internacionales de Registro');
verificar(!in_array('Internacional Inscripción excluida',$nombres,true)&&!in_array('Sólo territorial',$nombres,true),'excluye Inscripción y publicaciones sólo territoriales');
$territorial=$s->preparar(['origen_tipo'=>'institucional','origen_id'=>10,'ambito'=>'territorial']);verificar(in_array('Sólo territorial',array_column($territorial['convocatorias'],'titulo'),true),'difusión territorial histórica separada y operativa');
rechaza(fn()=>$s->preparar(['origen_tipo'=>'manual','origen_id'=>$manual,'ambito'=>'territorial']),422,'manual no suplanta difusión territorial');
$_SESSION['usuario_id']=3;rechaza(fn()=>$s->preparar(['origen_tipo'=>'manual','origen_id'=>$manual]),403,'manual fuera de territorio');rechaza(fn()=>$s->preparar(['origen_tipo'=>'institucional','origen_id'=>10]),403,'institucional sin asignación');
$_SESSION['usuario_id']=2;$_SESSION['permisos']=[];rechaza(fn()=>$s->preparar(['origen_tipo'=>'manual','origen_id'=>$manual]),403,'permisos efectivos requeridos');
$_SESSION=[];rechaza(fn()=>$s->preparar(['origen_tipo'=>'manual','origen_id'=>$manual]),401,'sesión caducada');
verificar((int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion')->fetch_assoc()['n']===1,'no crea expedientes ficticios');
// Dejar la bandeja sintética limpia para las pruebas HTTP/navegador siguientes.
$db->query('DELETE FROM aliados_convocatorias_envios');$db->query('DELETE FROM aliados_manuales_difusiones');
echo "PASS $checks comprobaciones Compartir DB/proveedor simulado\n";
