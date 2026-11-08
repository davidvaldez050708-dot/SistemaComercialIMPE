<?php
// Pruebas puras: sin MySQL, sin servidor ni sesiones reales.
$_SESSION=[];
$GLOBALS['permisos_embudo_prueba']=[];
function tienePermiso($codigo) {
    return in_array($codigo,$GLOBALS['permisos_embudo_prueba'],true);
}
require_once __DIR__.'/../app/helpers/EmbudoComercialAcceso.php';
require_once __DIR__.'/../app/services/EmbudoComercialRepository.php';
function comprobar($ok,$detalle) {
    if (!$ok) throw new RuntimeException('FALLÓ: '.$detalle);
}
function sesion($rol,$permisos) {
    $_SESSION['usuario_id']=7;
    $_SESSION['rol_id']=$rol;
    $GLOBALS['permisos_embudo_prueba']=$permisos;
}
comprobar(!EmbudoComercialAcceso::puede('embudo'),'sin sesión');
sesion(3,['ventas.embudo.ver']);
comprobar(EmbudoComercialAcceso::puede('embudo'),'asesor ve su embudo');
comprobar(!EmbudoComercialAcceso::puede('recuperacion'),'sin acceso implícito a recuperación');
comprobar(!EmbudoComercialAcceso::puede('descartados'),'sin acceso implícito a descartados');
comprobar(!EmbudoComercialAcceso::esSupervisor(),'asesor no supervisa');
sesion(3,['prospectos.ver_todos','prospectos.mi_cartera']);
comprobar(!EmbudoComercialAcceso::puede('embudo'),'el almacén y Mi cartera no autorizan Kanban');
sesion(1,['ventas.embudo.supervisar']);
comprobar(EmbudoComercialAcceso::puede('embudo'),'supervisor consulta embudo');
comprobar(EmbudoComercialAcceso::puede('descartados'),'supervisor consulta descartados');
comprobar(EmbudoComercialAcceso::esSupervisor(),'supervisor');
sesion(2,['ventas.embudo.ver','ventas.embudo.supervisar']);
comprobar(!EmbudoComercialAcceso::puede('embudo'),'otros roles no escalan');
sesion(3,['ventas.embudo.ver','ventas.recuperacion.ver']);
comprobar(EmbudoComercialAcceso::puede('recuperacion'),'recuperación propia explícita');
comprobar(!EmbudoComercialAcceso::puede('inscritos'),'Inscritos fuera del Kanban');
$f=EmbudoComercialRepository::filtros([
    'buscar'=>'  Ana  ','origen'=>'META_ADS','programa'=>'286',
    'vencidos'=>'1','asesor'=>'999','p_nuevo'=>'2'
],false);
comprobar($f['buscar']==='Ana' && $f['paginas']['NUEVO']===2 &&
          $f['asesor']===0 && $f['vencidos'],'filtros asesor no aceptan otro propietario');
$f=EmbudoComercialRepository::filtros(['asesor'=>'999'],true);
comprobar($f['asesor']===999,'supervisión filtra asesor');
foreach ([
    ['origen'=>'OTHER'],['vencidos'=>'true'],['p_nuevo'=>'-1'],
    ['buscar'=>str_repeat('x',121)],['origen'=>['ALIADO']]
] as $bad) {
    $error=false;
    try {EmbudoComercialRepository::filtros($bad,false);}
    catch(DomainException $e) {$error=true;}
    comprobar($error,'filtro inválido');
}
comprobar(EmbudoComercialRepository::POR_COLUMNA===20,'límite por columna');
comprobar(count(EmbudoComercialRepository::ETAPAS)===8,'seis activas y dos vistas externas');
echo "CHECK OK: permisos, aislamiento por rol, filtros y paginación.\n";
