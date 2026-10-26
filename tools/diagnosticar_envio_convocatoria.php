<?php
// CLI exclusivamente. Sin envíos, consultas API, cambios de BD ni lectura de tokens.
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
ini_set('display_errors','0');
$opts=getopt('',['check','convocatoria:','root:']);
$root=realpath((string)($opts['root']??dirname(__DIR__)));
if(!$root || !is_file($root.'/app/services/CorreoConvocatoriaComposicion.php')) { fwrite(STDERR,"CONVOCATORIA_CODIGO_NO_INSTALADO\n");exit(2); }
try {
    require_once $root.'/app/services/CorreoConvocatoriaComposicion.php';
    // El doble no inicializa transporte: cualquier método heredado por error debe fallar.
    class ConvocatoriaPreflightProbe extends CorreoSalidaInstitucionalService {
        public $calls=[];
        public function __construct() {}
        public function prepararSalida(string $from,int $user): array { $this->calls[]='prepararSalida';return ['ok'=>true]; }
        public function validarRemitente(string $from,int $user): array { $this->calls[]='validarRemitente';return ['ok'=>true]; }
        public function enviar($data) { throw new LogicException('ENVIO_PROHIBIDO_EN_DIAGNOSTICO'); }
    }
    $probe=new ConvocatoriaPreflightProbe();$wrapper=new CorreoConvocatoriaMensaje([],null,$probe);
    $phase='prepararSalida';$wrapper->prepararSalida('qa@example.test',1);
    $phase='validarRemitente';$wrapper->validarRemitente('qa@example.test',1);
    if($probe->calls!==['prepararSalida','validarRemitente']) { throw new LogicException('DELEGACION_NO_CONFIRMADA'); }
    $out=['version'=>'convocatoria-envio-v1','autorizacion_delegada'=>true,'prueba'=>'doble local sin proveedor','sha256_compositor'=>hash_file('sha256',$root.'/app/services/CorreoConvocatoriaComposicion.php')];
    if(isset($opts['convocatoria'])) {
        $phase='lectura_convocatoria';$id=CorreoConvocatoriaComposicion::id($opts['convocatoria']);
        if(!$id) { throw new InvalidArgumentException('ID_REQUERIDO'); }
        $model=new CorreoConvocatoriaConsultaModel();$row=$model->buscar($id);
        if(!$row) { throw new RuntimeException('CONVOCATORIA_NO_EXISTE'); }
        $image=(new CorreoConvocatoriaComposicion($model,$root))->imagen($row);
        $out['convocatoria']=['id'=>$id,'activa'=>$row['activa'],'imagen_referenciada'=>!empty($row['imagen_ref']),'imagen_valida'=>(bool)$image];
        if($image) { $out['convocatoria']+=['bytes'=>$image['tamano'],'mime'=>$image['mime'],'sha256'=>$image['sha']]; }
    }
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    echo "SOLO LECTURA. No se ha enviado ningún correo ni comprobado recepción.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'CONVOCATORIA_DIAGNOSTICO_ERROR fase='.($phase??'carga').' clase='.get_class($error).' codigo='.(int)$error->getCode().". Sin datos privados.\n");exit(2);
}
