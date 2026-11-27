<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__,2);$cfg=json_decode(file_get_contents($root.'/tmp/sp-test-state.json'),true);
if(!preg_match('/^impe_sp_test_[a-f0-9]{8}$/D',$cfg['database']??'')||!in_array($cfg['port']??0,[33319,33320],true))throw new RuntimeException('Sólo admite base local aislada.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root','',$cfg['database'],$cfg['port']);$db->set_charset('utf8mb4');
require $root.'/app/controllers/SupervisionPersonalController.php';
define('ROOT_PATH',$root);define('BASE_URL','http://personal.test/');
$input=json_decode(stream_get_contents(STDIN),true)??[];
$_SESSION=['usuario_id'=>(int)($input['actor']??1),'rol_id'=>1,'csrf_token'=>str_repeat('csrf-personal-',4)];
if(($input['action']??'')==='render'){
 $datos=(new SupervisionPersonalRepository($db))->catalogo(1);ob_start();require $root.'/app/views/supervision_personal/index.php';$html=ob_get_clean();echo json_encode(['status'=>200,'html'=>$html],JSON_UNESCAPED_UNICODE);exit;
}
class SupervisionPersonalHttpFixture extends SupervisionPersonalController {
 protected function conexion():mysqli{return $GLOBALS['db'];}
 protected function json(array $data,int $status=200):void{echo json_encode(['status'=>$status,'body'=>$data],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
}
$_GET=$input['query']??[];$_POST=$input['post']??[];$_SERVER['REQUEST_METHOD']=$input['method']??'GET';
$_SERVER['HTTP_X_CSRF_TOKEN']=$input['csrf']??'';
(new SupervisionPersonalHttpFixture())->ejecutar((string)($input['action']??'catalogo'));
