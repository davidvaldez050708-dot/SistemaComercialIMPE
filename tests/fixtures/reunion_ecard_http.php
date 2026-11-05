<?php
// Fixture sin SQL, SMTP ni proveedores externos. Ejecuta controlador y servicio reales.
function tienePermiso($code) { return empty($_GET['denegar']); }
$_SESSION=['usuario_id'=>8,'rol_id'=>4];
function state($key,$value) { $p=__DIR__.'/estado.json';$s=is_file($p)?json_decode(file_get_contents($p),true):[];$s[$key]=$value;file_put_contents($p,json_encode($s)); }
class UsuarioModel { function buscarPorId($id) { return ['nombre'=>'Prueba','apellidos'=>'Remitente','correo'=>'remitente@example.test']; } }
class AgendaReunionService {
    const ROL_ANALISTA=4;
    function marcarCorreoEnviado(...$args) { state('registrado',true);return ['ok'=>true,'reunion_id'=>1]; }
    function marcarCorreoAnalista(...$args) { state('reprogramada',true);return ['ok'=>true,'reunion_id'=>1]; }
}
class FakeRepo {
    function reunion(...$args) { return ['id'=>1,'estado'=>empty($_GET['invalida'])?'CONFIRMADA':'CANCELADA','fecha_propuesta'=>date('Y-m-d H:i:s',time()+86400), 'contacto_correo'=>'destino@example.test','contacto_nombre'=>'Institución sintética','zoom_url'=>'https://example.test/reunion']; }
}
class FakeStatement {
    function bind_param(...$args) {}
    function execute() {}
    function get_result() { return $this; }
    function fetch_assoc() { return ['nombre'=>'Prueba','apellidos'=>'Remitente','correo'=>'remitente@example.test']; }
}
class FakeDb { function prepare(...$args) { return new FakeStatement; } }
class EcardReunionService { const TEMPLATE_SERGIO='SERGIO', TEMPLATE_MANUEL='MANUEL', CID='ecard-reunion'; }
class FakeEcard {
    function generar(...$args) {
        state('generada',true);
        if(!empty($_GET['fallo_default'])) return ['ok'=>false,'mensaje'=>'Generador no disponible'];
        return ['ok'=>true,'ruta'=>__DIR__.'/default.png','archivo'=>'ecard.png','mime'=>'image/png','cid'=>'ecard-reunion','template'=>'manuel'];
    }
}
class FakeSender {
    function enviar($data) {
        $images=$data['imagenes_embebidas'];$i=$images[0];
        state('envio',['cantidad'=>count($images),'ruta'=>$i['ruta'],'mime'=>$i['mime'],'cid'=>$i['cid'],
            'archivo'=>$i['nombre'],'sha'=>hash_file('sha256',$i['ruta']),'imagen_valida'=>(bool)getimagesize($i['ruta']),
            'html'=>$data['html_adicional']]);
        if(!empty($_GET['excepcion'])) throw new RuntimeException('Transporte simulado interrumpido');
        return ['ok'=>empty($_GET['fallo_envio'])];
    }
}
require __DIR__.'/app/services/ReunionEcardImagen.php';
require __DIR__.'/CorreoFirmadoService.php';
require __DIR__.'/CorreoFirmadoController.php';
$class=new ReflectionClass('CorreoFirmadoService');$service=$class->newInstanceWithoutConstructor();
foreach(['connection'=>new FakeDb,'agendaRepo'=>new FakeRepo,'agendaService'=>new AgendaReunionService,'reprogramacionService'=>new AgendaReunionService,'ecardService'=>new FakeEcard,'sender'=>new FakeSender] as $key=>$value) $class->getProperty($key)->setValue($service,$value);
$controllerClass=new ReflectionClass('CorreoFirmadoController');$controller=$controllerClass->newInstanceWithoutConstructor();
$controllerClass->getProperty('service')->setValue($controller,$service);
try { $controller->enviarReunion(); } catch(Throwable $e) { http_response_code(500);echo json_encode(['ok'=>false]); }
