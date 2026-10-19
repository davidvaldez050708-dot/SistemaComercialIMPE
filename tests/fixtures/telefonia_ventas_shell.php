<?php
define('ROOT_PATH', dirname(__DIR__, 2));
define('BASE_URL', 'http://ventas.test/');
$_SESSION = ['usuario_id'=>8, 'rol_id'=>3, 'rol'=>'Asesor de Ventas', 'csrf_token'=>'test-csrf', 'nombre'=>'Asesor'];
$asignacionTelefonica = ['extension'=>'150', 'permite_entrantes'=>true, 'permite_salientes'=>true];
function tienePermiso($codigo) { return in_array($codigo, ['telefonia.usar','telefonia.recibir','telefonia.salientes'], true); }
require ROOT_PATH.'/app/views/telefonia/shell.php';
