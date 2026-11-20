<?php
define('BASE_URL','https://formularios.test/');
define('ROOT_PATH',dirname(__DIR__,2));
$views=['publico_registro.php','publico_inscripcion.php','publico_inscripcion_completa.php','inscripcion_ejecutivas_previa.php'];
$view=$argv[1]??'';if(!in_array($view,$views,true))exit(1);
require ROOT_PATH.'/app/services/FormularioInscripcionProgramas.php';
$programaInscripcion=FormularioInscripcionProgramas::todos()[0];
$estados=[];$perfilesInteres=[];$registroAction=BASE_URL.'guardar';$municipiosUrl=BASE_URL.'municipios';
$aliadoFormulario=['nombre'=>'Instituto de prueba','ubicacion'=>'Querétaro'];
$inscripcionPublica=true;$inscripcionRecepcionLista=true;$inscripcionToken=str_repeat('a',64);
$_SESSION=['csrf_token'=>'prueba','csrf_inscripcion_completa'=>'prueba'];$inscripcionAction=BASE_URL.'guardar';$inscripcionMunicipiosUrl=BASE_URL.'municipios';
require ROOT_PATH.'/app/views/formularios/'.$view;
