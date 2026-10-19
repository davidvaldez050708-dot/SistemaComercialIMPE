<?php
putenv('IMPE_BASE_URL=http://ventas.test/');
require_once dirname(__DIR__,2).'/app/services/TelefoniaResultadoVentasService.php';
$_SESSION=['usuario_id'=>8,'rol_id'=>3,'csrf_token'=>str_repeat('b',64)];$_GET=['controller'=>'telefonia','action'=>'misLlamadas','vista'=>'devoluciones'];
$contactosVentas=[];
if(($argv[1]??'')==='agenda'){
 $panelTelefono=['extension'=>'150','csrf'=>str_repeat('b',64),'contactos'=>[['id'=>1,'nombre'=>'María','telefono'=>'+525551112233','correo'=>'maria@example.test','observacion'=>'Contacto original','proxima_accion_at'=>null]]];
 require ROOT_PATH.'/app/views/telefonia/marcador.php';
}else{
 $devolucionesVentas=[['id'=>4,'version'=>1,'nombre'=>'Pedro','contacto'=>'Pedro','telefono'=>'+525551112233','nota'=>'Solicita informes','origen_extension'=>'100','updated_at'=>gmdate('Y-m-d H:i:s'),'devolucion'=>'PENDIENTE','intento'=>null]];
 require ROOT_PATH.'/app/views/telefonia/ventas_devoluciones.php';
}
