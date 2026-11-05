<?php
// Adaptadores simulados: nunca ejecuta SQL ni contacta servicios de correo/PDF.
function registrar($key,$value){$p=__DIR__.'/estado.json';$s=is_file($p)?json_decode(file_get_contents($p),true):[];$s[$key]=$value;file_put_contents($p,json_encode($s));}
class SeguimientoTerritorialScope { static function analista($alias){return '1=1';} }
class SeguimientoHistorialImportadoService { function esConvenioHistorico($r){return false;} }
class OficioDocxPdfService { function convertirArchivoAPdf($file){registrar('convertida',true);return ['ok'=>true,'contenido_pdf'=>"%PDF-1.4\nDocumento sintético\n%%EOF"];}}
class CorreoSalidaInstitucionalService {
 function enviar($data){$out=[];foreach($data['adjuntos'] as $a)$out[]=$a+['sha'=>hash_file('sha256',$a['ruta'])];registrar('envio',$out);return ['ok'=>empty($_GET['fallo_envio'])];}
}
class FixtureResult {
 public $num_rows=1;
 function fetch_assoc(){return empty($_GET['sin_acceso'])?['id'=>1,'nombre_entidad'=>'Institución sintética','estado_seguimiento'=>'NUEVO','contacto_nombre'=>'Contacto', 'destinatario_correo'=>'destino@example.test','analista_correo'=>'remitente@example.test','reunion_realizada_at'=>'2026-11-01','reunion_resultado'=>empty($_GET['sin_reunion'])?'AVANZAR_CONVENIO':'NO_AVANZAR']:false;}
}
class FixtureStatement {
 function __construct(private $sql){}
 function bind_param($type,&...$params){$this->params=$params;}
 function execute(){if(str_starts_with(trim($this->sql),'UPDATE')||str_starts_with(trim($this->sql),'INSERT')){
  $p=__DIR__.'/estado.json';$s=is_file($p)?json_decode(file_get_contents($p),true):[];$s['sql'][]=['sql'=>$this->sql,'params'=>$this->params??[]];file_put_contents($p,json_encode($s));
 }}
 function get_result(){return new FixtureResult;}
}
class FixtureDb {
 public $insert_id=1;
 function query($q){return new FixtureResult;}
 function prepare($q){return new FixtureStatement($q);}
 function begin_transaction(){}
 function commit(){}
 function rollback(){}
}
require __DIR__.'/service.php';
$ref=new ReflectionClass('ConvenioDocumentosService');$service=$ref->newInstanceWithoutConstructor();
foreach(['connection'=>new FixtureDb,'rootPath'=>__DIR__,'storagePath'=>__DIR__.'/storage/convenios',
 'templateCarta'=>__DIR__.(empty($_GET['sin_plantillas'])?'/carta.docx':'/no-carta.docx'),
 'templateConvenio'=>__DIR__.(empty($_GET['sin_plantillas'])?'/convenio.docx':'/no-convenio.docx')] as $key=>$value)$ref->getProperty($key)->setValue($service,$value);
$out=$service->enviar(1,8,$_POST['carta_fecha']??'',$_POST['asunto']??'',$_POST['cuerpo']??'',$_FILES,
 ['carta'=>$_POST['carta_origen']??'sistema','convenio'=>$_POST['convenio_origen']??'sistema']);
http_response_code($out['codigo_http']??200);header('Content-Type: application/json');echo json_encode($out);
