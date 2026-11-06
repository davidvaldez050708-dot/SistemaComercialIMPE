<?php
/**
 * Pruebas unitarias sin MySQL ni escritura de producción.
 * Ejecutar: php tests/prospectos_asesores_config_unit.php
 */
require_once __DIR__.'/../app/services/ProspectoAsesorConfiguracionService.php';

$comprobaciones=0;
$check=function(bool $ok,string $mensaje)use(&$comprobaciones):void{
    if (!$ok) throw new RuntimeException('FALLÓ: '.$mensaje);
    ++$comprobaciones;
};
$falla=function(array $datos,string $esperado)use(&$comprobaciones):void{
    try {ProspectoAsesorConfiguracionService::validar($datos,['BACH286','TIT_EXP']);}
    catch (DomainException $e) {
        if (!str_contains($e->getMessage(),$esperado)) throw $e;
        ++$comprobaciones;return;
    }
    throw new RuntimeException('Se aceptaron datos incorrectos: '.$esperado);
};

$base=[
    'programa_codigo'=>'BACH286','jornada'=>'MEDIO_TIEMPO','activo'=>'1',
    'version'=>'0','dias'=>[
        '1'=>['activo'=>'1','desde'=>'16:00','hasta'=>'21:00'],
        '2'=>['activo'=>'1','desde'=>'16:00','hasta'=>'21:00'],
        '3'=>['activo'=>'0','desde'=>'16:00','hasta'=>'21:00'],
    ],
];
$correcto=ProspectoAsesorConfiguracionService::validar($base,['BACH286','TIT_EXP']);
$check($correcto['programa_codigo']==='BACH286','programa único');
$check($correcto['jornada']==='MEDIO_TIEMPO','medio tiempo');
$check($correcto['meta_diarios']===80,'cuota Meta fija');
$check(count($correcto['horarios'])===2,'sólo días marcados');
$check(!isset($correcto['horarios'][3]),'día inactivo excluido');
$check($correcto['version']===0,'versión inicial');
$check(count(ProspectoAsesorConfiguracionService::DIAS)===7,'semana completa');
$falla(array_replace($base,['programa_codigo'=>'INVENTADO']),'programa educativo');
$falla(array_replace($base,['jornada'=>'OTRA']),'jornada laboral');
$falla(array_replace($base,['version'=>'-1']),'versión');
$falla(array_replace($base,['version'=>'no']),'versión');
$falla(array_replace($base,['dias'=>[]]),'al menos un día');
$falla(array_replace($base,['dias'=>['8'=>['activo'=>'1','desde'=>'09:00','hasta'=>'13:00']]]),'día laboral');
$falla(array_replace($base,['dias'=>['1'=>['activo'=>'1','desde'=>'21:00','hasta'=>'16:00']]]),'anterior a la salida');
$falla(array_replace($base,['dias'=>['1'=>['activo'=>'1','desde'=>'17:00','hasta'=>'17:00']]]),'anterior a la salida');
$falla(array_replace($base,['dias'=>['1'=>['activo'=>'1','desde'=>'99:00','hasta'=>'19:00']]]),'horario de');
$falla(array_replace($base,['dias'=>'lunes']),'horarios no son válidos');
$pausado=ProspectoAsesorConfiguracionService::validar(
    array_replace($base,['activo'=>'0','dias'=>[]]),['BACH286','TIT_EXP']
);
$check($pausado['activo']===0&&$pausado['horarios']===[],'pausa sin días');
$_SESSION=['usuario_id'=>9,'rol_id'=>2,'permisos'=>['prospectos.ver_todos']];
$check(!ProspectoAsesorConfiguracionService::autorizado(),'sin permiso independiente no autoriza');
$_SESSION['permisos'][]='prospectos.configurar_asesores';
$check(ProspectoAsesorConfiguracionService::autorizado(),'ambos permisos autorizan');
$token=ProspectoAsesorConfiguracionService::csrf();
$check(strlen($token)===64,'CSRF aleatorio');
ProspectoAsesorConfiguracionService::verificarCsrf($token);
++$comprobaciones;
try {ProspectoAsesorConfiguracionService::verificarCsrf(str_repeat('0',64));throw new RuntimeException('CSRF falso aceptado');}
catch(DomainException $e){++$comprobaciones;}
echo "OK: $comprobaciones comprobaciones de configuración comercial.\n";
