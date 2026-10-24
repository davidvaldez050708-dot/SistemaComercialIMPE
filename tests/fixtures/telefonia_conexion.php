<?php
// Sesiones sintéticas: sin conexión a BD ni a Zadarma.
define('ROOT_PATH',dirname(__DIR__,2));
define('BASE_URL','https://phone.test/');
$role=$argv[2]??'Marketing';
$_SESSION=['usuario_id'=>8,'rol'=>$role,'rol_id'=>3,'nombre'=>'Prueba','csrf_token'=>'synthetic'];
$asignacionTelefonica=['extension'=>$role==='Marketing'?'100':($role==='Analista de Datos'?'101':'150'),
    'permite_entrantes'=>($argv[3]??'')!=='salientes','permite_salientes'=>true,'permite_transferir'=>true];
function tienePermiso($permiso){global $asignacionTelefonica;return $permiso!=='telefonia.recibir'||$asignacionTelefonica['permite_entrantes'];}
require ROOT_PATH.'/app/views/telefonia/'.(($argv[1]??'')==='host'?'host':'shell').'.php';
