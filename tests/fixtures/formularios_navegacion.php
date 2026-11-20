<?php
// Sólo renderizado de vistas reales: no usa la conexión del CRM.
define('BASE_URL','https://formularios.test/');
define('ROOT_PATH',dirname(__DIR__,2));
$_SESSION=['usuario_id'=>501,'rol_id'=>4,'rol'=>'Analista de Datos','permisos'=>['formularios.ver','formularios.registro.capturar','formularios.inscripcion.capturar']];
if(($argv[2]??'1')==='1')$_SESSION['permisos'][]='formularios.compartir';
require ROOT_PATH.'/app/helpers/FormularioPermisos.php';
require ROOT_PATH.'/app/services/FormularioInscripcionProgramas.php';
$programasInscripcion=FormularioInscripcionProgramas::todos();
$estados=[['id'=>17,'nombre'=>'Morelos']];$perfilesInteres=['Bachillerato General'];
$programaInscripcion=$programasInscripcion[0];$inscripcionRecepcionLista=true;
$inscripcionToken=str_repeat('a',64);$nombreCompleto='Persona de prueba';
switch($argv[1]??'inicio'){
    case 'programas':require ROOT_PATH.'/app/views/formularios/inscripcion_programas.php';break;
    case 'registro':require ROOT_PATH.'/app/views/formularios/registro.php';break;
    default:require ROOT_PATH.'/app/views/formularios/index.php';
}
