<?php
putenv('IMPE_BASE_URL=http://devoluciones.test/');$_SERVER['HTTP_HOST']='localhost';
require_once dirname(__DIR__,2).'/app/services/TelefoniaDevolucionService.php';
$_SESSION=['usuario_id'=>1,'csrf_token'=>'test-csrf'];
$estadosEntrantes=[['id'=>1,'nombre'=>'Morelos']];
$devoluciones=[['id'=>7,'version'=>1,'devolucion'=>'PENDIENTE','contacto'=>'Ana','telefono'=>'+525551112233','nota'=>'Solicita información de convenio.','origen_extension'=>'100','created_at'=>'2026-10-18 15:00:00','institucion_nombre'=>'Institución Uno','seguimiento_id'=>1,'intento'=>null],
 ['id'=>8,'version'=>1,'devolucion'=>'ATENDIDA','contacto'=>'Carla','telefono'=>'+525551112244','nota'=>'Consulta atendida.','origen_extension'=>'100','created_at'=>'2026-10-18 14:00:00','institucion_nombre'=>'Institución Dos','seguimiento_id'=>0,'intento'=>['id'=>1,'estado'=>'FINALIZADO','resultado'=>'CONTACTO','observacion'=>'Se atendió la consulta.']]];
require ROOT_PATH.'/app/views/telefonia/devoluciones.php';
