<?php
/** Sin BD ni sesión real. La interfaz V3 está apagada por defecto. */
require_once __DIR__.'/../app/services/EmbudoComercialV3AccesoControlado.php';
function verificarAcceso($yes,$what){
    if(!$yes)throw new RuntimeException('ACCESO V3: '.$what);
}
$oldFlag=getenv('IMPE_VENTAS_V3_UI');
$oldUsers=getenv('IMPE_VENTAS_V3_UI_ASESORES');
$oldPbx=getenv('IMPE_VENTAS_V3_PILOTO');
try{
    $_SESSION=['usuario_id'=>8,'rol_id'=>3,
        'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']];
    putenv('IMPE_VENTAS_V3_UI');
    putenv('IMPE_VENTAS_V3_UI_ASESORES');
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'apagada por defecto');
    putenv('IMPE_VENTAS_V3_UI_ASESORES=8');
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'lista sola no habilita');
    putenv('IMPE_VENTAS_V3_PILOTO=AUTORIZADO_PILOTO_V3_TRES_INTENTOS_IMPE_20261111');
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'activar puente PBX no habilita vista');
    putenv('IMPE_VENTAS_V3_UI=INCORRECTO');
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'token inexacto rechazado');
    putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');
    foreach(['*','8,0','8,-9','08','8, 9','8,','8,999999999999999999999999999999'] as $invalid){
        putenv('IMPE_VENTAS_V3_UI_ASESORES='.$invalid);
        verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
            'lista insegura: '.$invalid);
    }
    putenv('IMPE_VENTAS_V3_UI_ASESORES=8,9');
    verificarAcceso(EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'asesor explícitamente habilitado');
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(9),
        'asesor ajeno a sesión rechazado');
    $_SESSION['rol_id']=1;
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'administrador/supervisor no participa en piloto asesor');
    $_SESSION['rol_id']=3;
    $_SESSION['permisos']=['telefonia.usar'];
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'sin permiso comercial permanece desactivado');
    $_SESSION['permisos']=['ventas.embudo.ver'];
    $_SESSION['usuario_id']=9;
    verificarAcceso(!EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(8),
        'no suplanta la identidad de una sesión');
    verificarAcceso(EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(9),
        'segundo asesor solo desde sesión propia');

    $controller=file_get_contents(__DIR__.'/../app/controllers/EmbudoComercialController.php');
    verificarAcceso(substr_count($controller,
        'EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(')>=3,
        'ruta index, ficha y POST todos comprueban el servidor');
    verificarAcceso(strpos($controller,
        "EmbudoComercialV3AccesoControlado::habilitadoParaAsesor(\$usuarioId) &&")!==false &&
        strpos($controller,"(\$_GET['tablero_anterior']??null)!=='1'")!==false,
        'entrada V3 sólo para el asesor autorizado y retorno explícito a V1');
    verificarAcceso(strpos($controller,'hash_equals($token,$received)')!==false,
        'POST exige además CSRF, permisos y control de sesión');
    echo "CHECK OK: vista V3 OFF, doble llave independiente del PBX, lista cerrada, sesión y permiso; GET, ficha y POST protegidos.\n";
}finally{
    if($oldFlag===false) putenv('IMPE_VENTAS_V3_UI');
    else putenv('IMPE_VENTAS_V3_UI='.$oldFlag);
    if($oldUsers===false) putenv('IMPE_VENTAS_V3_UI_ASESORES');
    else putenv('IMPE_VENTAS_V3_UI_ASESORES='.$oldUsers);
    if($oldPbx===false) putenv('IMPE_VENTAS_V3_PILOTO');
    else putenv('IMPE_VENTAS_V3_PILOTO='.$oldPbx);
}
