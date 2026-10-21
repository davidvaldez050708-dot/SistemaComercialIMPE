<?php
if(PHP_SAPI!=='cli' || !preg_match('/^impe_test_handly_aliados[a-z0-9_]*$/D',getenv('IMPE_DB_NAME')?:'')) exit(2);
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/../app/services/AliadoRegistroService.php';
require_once __DIR__.'/../app/models/FormularioRegistroModel.php';
$db=(new Database())->connect(false); $s=new AliadoRegistroService($db);
$action=$argv[1]??'registro';
if($action==='manual') {
    $_SESSION=['usuario_id'=>2,'rol_id'=>4,'rol'=>'Cuenta Clave','permisos'=>['aliados.crear_manual','aliados.gestionar_programas']];
    try { echo $s->guardarManual(['nombre_entidad'=>'Concurrente seguro','tipo_entidad'=>'INSTITUCION','estado_id'=>1,'municipio_id'=>1,'programas'=>['ingles']]); }
    catch(DomainException $e) { if(strpos($e->getMessage(),'ya existe')===false) throw $e; echo 'DUPLICADO'; }
} else {
    $row=$db->query('SELECT r.manual_id,a.token FROM formulario_aliado_registro r JOIN formulario_aliados a ON a.id=r.aliado_id ORDER BY r.aliado_id LIMIT 1')->fetch_assoc();
    if($action==='enlace') {
        $_SESSION=['usuario_id'=>4,'rol_id'=>5,'rol'=>'Marketing','permisos'=>['formularios_aliados.gestionar_enlaces']];
        echo hash('sha256',$s->enlace('manual',(int)$row['manual_id'],'generar')['url']);
    } else {
        echo (new FormularioRegistroModel())->guardar(['nombre'=>'Concurrencia','apellido'=>'Sintética','fecha_nacimiento'=>'1990-01-01','movil'=>'7779876543','correo'=>'concurrente@example.test','perfil_interes'=>'Inglés','lugar_laboras'=>'','cargo_puesto'=>'','estado_id'=>1,'municipio_id'=>1,'creado_por'=>null,'origen'=>'PUBLICO','aliado_token'=>$row['token']]);
    }
}
