<?php
define('ROOT_PATH',dirname(__DIR__,2));
define('BASE_URL','http://llamadas.test/');
$analyst=($argv[1]??'')==='analista';
$_SESSION=['usuario_id'=>$analyst?8:7,'rol'=>$analyst?'Analista de Datos':'Marketing','csrf_token'=>'test-csrf','nombre'=>'Recepción'];
$asignacionTelefonica=['extension'=>$analyst?'101':'100','permite_entrantes'=>true,'permite_salientes'=>true,'permite_transferir'=>true];
function tienePermiso($codigo){return true;}
require ROOT_PATH.'/app/views/telefonia/shell.php';
