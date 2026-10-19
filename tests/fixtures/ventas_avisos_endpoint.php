<?php
// Sólo en el servidor aislado de pruebas; nunca aceptar nombres de BD del cliente.
if(getenv('IMPE_TEST_AVISOS_HTTP')!=='1'||!preg_match('/^impe_bandejas_e2e_[0-9]+$/D',(string)getenv('IMPE_DB_NAME'))){http_response_code(404);exit;}
$_SESSION=['usuario_id'=>($_GET['otro']??'')==='1'?9:8,'rol_id'=>3,'permisos'=>['telefonia.usar','telefonia.salientes','telefonia.recibir']];
require_once dirname(__DIR__,2).'/app/services/TelefoniaNotificacionService.php';
$action=$_GET['test_action']??'';
if($action==='cleanup'){
 $db=(new Database())->connect(false);$name=(string)getenv('IMPE_DB_NAME');$db->select_db('mysql');$db->query("DROP DATABASE `$name`");exit('OK');
}
if($action==='save'){
 require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoVentasService.php';
 $db=(new Database())->connect(false);$db->query('RENAME TABLE telefonia_ventas_marcaciones_offline TO telefonia_ventas_marcaciones');
 (new TelefoniaResultadoVentasService($db))->guardar(8,'pbx-pendiente-comercial','INTERESADO');exit('OK');
}
require_once dirname(__DIR__,2).'/app/controllers/ReminderController.php';
(new ReminderController())->pendientes();
