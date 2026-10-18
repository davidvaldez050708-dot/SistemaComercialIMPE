<?php
define('ROOT_PATH',dirname(__DIR__,2));
define('BASE_URL','http://llamadas.test/');
$_SESSION=['usuario_id'=>7,'rol'=>'Marketing','csrf_token'=>'test-csrf','nombre'=>'Recepción'];
$asignacionTelefonica=['extension'=>'100','permite_entrantes'=>true,'permite_salientes'=>true,'permite_transferir'=>true];
function tienePermiso($codigo){return true;}
require ROOT_PATH.'/app/views/telefonia/shell.php';
