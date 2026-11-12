<?php
/** POST V3 no debe tocar DB cuando el piloto de interfaz está apagado. */
require_once __DIR__.'/../app/controllers/EmbudoComercialController.php';
function comprobar($cond,$msg){
    if(!$cond)throw new RuntimeException('POST V3: '.$msg);
}
$oldFlag=getenv('IMPE_VENTAS_V3_UI');
$oldUsers=getenv('IMPE_VENTAS_V3_UI_ASESORES');
$serverBefore=$_SERVER;
$postBefore=$_POST;
$sessionBefore=$_SESSION??[];
try{
    $_SESSION=[
        'usuario_id'=>8,
        'rol_id'=>3,
        'csrf_token'=>'token_prueba_aleatorio_no_real',
        'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']
    ];
    $_SERVER['REQUEST_METHOD']='POST';
    $_SERVER['HTTP_X_CSRF_TOKEN']=$_SESSION['csrf_token'];
    $_POST=[
        'asignacion_id'=>'123','etapa_anterior'=>'NUEVO',
        'etapa_nueva'=>'CONTACTADO',
        'motivo'=>'Conversación humana acreditada',
        'proxima_accion'=>''
    ];
    $invoke=static function(): array {
        http_response_code(200);
        ob_start();
        (new EmbudoComercialController())->moverEtapaV3();
        $html=(string)ob_get_clean();
        return [http_response_code(),json_decode($html,true),$html];
    };
    putenv('IMPE_VENTAS_V3_UI');
    putenv('IMPE_VENTAS_V3_UI_ASESORES');
    [$code,$data]=$invoke();
    comprobar($code===403 && $data['ok']===false,
        'sin bandera el POST se niega antes de conectar a DB');
    putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');
    putenv('IMPE_VENTAS_V3_UI_ASESORES=9');
    [$code,$data]=$invoke();
    comprobar($code===403 && $data['ok']===false,
        'asesor fuera de allowlist no mueve');
    putenv('IMPE_VENTAS_V3_UI_ASESORES=8');
    $_SERVER['HTTP_X_CSRF_TOKEN']='token_incorrecto';
    [$code,$data]=$invoke();
    comprobar($code===403 && $data['ok']===false,
        'CSRF se valida antes de cualquier consulta');
    $_SERVER['HTTP_X_CSRF_TOKEN']=$_SESSION['csrf_token'];
    $_SESSION['rol_id']=1;
    [$code,$data]=$invoke();
    comprobar($code===403 && $data['ok']===false,
        'administrador no ejecuta POST de asesor');
    $_SESSION['rol_id']=3;
    $_SERVER['REQUEST_METHOD']='GET';
    [$code,$data]=$invoke();
    comprobar($code===405 && $data['ok']===false,
        'no permite GET para modificar etapas');
    echo "CHECK OK: POST V3 desactivado, cuenta fuera de lista, CSRF, administrador y GET rechazados sin conexión de DB.\n";
} finally{
    $_SERVER=$serverBefore;
    $_POST=$postBefore;
    $_SESSION=$sessionBefore;
    if($oldFlag===false)putenv('IMPE_VENTAS_V3_UI');else putenv('IMPE_VENTAS_V3_UI='.$oldFlag);
    if($oldUsers===false)putenv('IMPE_VENTAS_V3_UI_ASESORES');
    else putenv('IMPE_VENTAS_V3_UI_ASESORES='.$oldUsers);
}
