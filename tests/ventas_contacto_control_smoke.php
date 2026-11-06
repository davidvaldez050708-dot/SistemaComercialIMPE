<?php
require_once __DIR__.'/../app/services/TelefoniaControlService.php';
require_once __DIR__.'/../app/services/VentasActividadMetricas.php';
// Sin constructores, conexiones ni llamadas al proveedor.
foreach (TelefoniaResultadoVentasService::opciones() as $codigo=>$label) {
    foreach ([false,true] as $conectada) {
        $esperado=$conectada&&TelefoniaResultadoVentasService::esConversacion($codigo);
        if (TelefoniaControlService::contactoVentasConfirmado($conectada,$codigo)!==$esperado) {
            throw new RuntimeException('Control no coincide con métricas: '.$codigo);
        }
    }
}
foreach (['SI','NO'] as $respuesta) {
    $r=TelefoniaResultadoVentasCatalogo::normalizarSeleccion([
        'catalogo_version'=>'4','resultados_seleccionados'=>'["WHATSAPP"]','contacto_persona'=>$respuesta]);
    if (TelefoniaControlService::contactoVentasConfirmado(true,$r['resultado'])!==($respuesta==='SI')) {
        throw new RuntimeException('Control no conserva contacto V4: '.$respuesta);
    }
}
echo "PASS: Control y métricas comparten contacto para todos los resultados, con conexión y sin conexión.\n";
