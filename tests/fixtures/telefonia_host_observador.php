<?php
// Renderiza la vista REAL del host; no sustituye sus condiciones por flags JS.
define('ROOT_PATH', dirname(__DIR__, 2));
$options=json_decode($argv[1] ?? '{}',true) ?: [];
$rol=(string)($options['role'] ?? 'Asesor de Ventas');
define('BASE_URL', $rol==='Analista de Datos'?'http://llamadas.test/':'http://ventas.test/');
$_SESSION=['usuario_id'=>8,'rol'=>$rol,'csrf_token'=>'test-csrf','nombre'=>'Prueba'];
$_GET=['display'=>$options['display'] ?? 'embed'];
$asignacionTelefonica=['extension'=>$options['extension'] ?? ($rol==='Analista de Datos'?'101':'150'),
    'permite_entrantes'=>$options['incoming'] ?? true,'permite_salientes'=>true];
$permisos=$options['permissions'] ?? ['telefonia.usar','telefonia.recibir','seguimientos_vinculacion.operar_propios'];
function tienePermiso($codigo){global $permisos;return in_array($codigo,$permisos,true);}
require ROOT_PATH.'/app/views/telefonia/host.php';
