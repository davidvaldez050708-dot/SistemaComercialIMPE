<?php
putenv('IMPE_BASE_URL=http://pendientes.test/');
require __DIR__.'/../config/config.php';
require ROOT_PATH.'/app/services/TelefoniaContactosService.php';
require ROOT_PATH.'/app/services/TelefoniaResultadoVentasService.php';
require ROOT_PATH.'/app/helpers/VentasPendientesPolicy.php';
$_SESSION=['usuario_id'=>301,'rol_id'=>3,'csrf_token'=>'prueba-csrf','permisos'=>['telefonia.usar','telefonia.salientes']];
$_GET=['estado'=>$argv[2]??'TODAS'];
$programacionesVentas=[['id'=>1,'origen'=>'agenda','nombre'=>'Contacto <seguro>','telefono'=>'5550000000','detalle'=>'Llamar para informar','fecha'=>'2000-01-01 12:00:00','pbx_call_id'=>'','extension'=>''],['id'=>2,'origen'=>'resultado','nombre'=>'Próxima llamada','telefono'=>'5550000001','detalle'=>'Seguimiento','fecha'=>'2099-01-01 12:00:00','pbx_call_id'=>'fixture-llamada','extension'=>'150']];
$devolucionesVentas=[];
foreach (['2000-01-01 12:00:00',gmdate('Y-m-d H:i:s'),'2000-01-01 12:00:00'] as $i=>$fecha) {
    $t=['id'=>$i+1,'nombre'=>'Devolución <segura> '.($i+1),'telefono'=>'5550000000','origen_extension'=>'100','nota'=>'Volver a llamar','devolucion'=>$i===2?'ATENDIDA':'PENDIENTE','created_at'=>$fecha,'updated_at'=>gmdate('Y-m-d H:i:s'),'intento'=>null];
    $t['plazo']=VentasPendientesPolicy::devolucion($t);$devolucionesVentas[]=$t;
}
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/public/css/global.css"><link rel="stylesheet" href="/public/css/dashboard.css"><link rel="stylesheet" href="/icons/bootstrap-icons.css"><body style="padding:16px;background:#f4f7fa">';
require ROOT_PATH.'/app/views/telefonia/'.(($argv[1]??'')==='devoluciones'?'ventas_devoluciones.php':'ventas_programadas.php');
echo '</body>';
