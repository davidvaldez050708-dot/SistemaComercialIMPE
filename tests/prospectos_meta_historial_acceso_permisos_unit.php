<?php
/**
 * Política de consulta de historial Meta: independiente de importación.
 * Usa las mismas sesiones y helper del sistema, sin DB ni datos reales.
 */
require_once __DIR__.'/../app/services/ProspectoMetaImportacionesHistorial.php';
require_once __DIR__.'/../app/services/ProspectoMetaImportador.php';
$casos=[
    ['Administrador autorizado',1,['prospectos.ver_todos','prospectos.configurar_formularios','prospectos.ver_historial_meta'],true,false],
    ['Administrador sin permiso historial',1,['prospectos.ver_todos','prospectos.configurar_formularios'],false,false],
    ['Administrador con importar explícito',1,['prospectos.ver_todos','prospectos.importar_meta','prospectos.ver_historial_meta'],true,false],
    ['Marketing consultar e importar',5,['prospectos.ver_todos','prospectos.importar_meta','prospectos.ver_historial_meta'],true,true],
    ['Marketing importar sin historial',5,['prospectos.ver_todos','prospectos.importar_meta'],false,true],
    ['Marketing consultar sin importar',5,['prospectos.ver_todos','prospectos.ver_historial_meta'],true,false],
    ['Coordinador de consulta autorizado',6,['prospectos.ver_todos','prospectos.ver_historial_meta'],true,false],
    ['Rol que sólo consulta todos',6,['prospectos.ver_todos'],false,false],
    ['Rol con sólo historial',6,['prospectos.ver_historial_meta'],false,false],
    ['Rol sin sesión',6,['prospectos.ver_todos','prospectos.ver_historial_meta'],false,false],
];
$n=0;
foreach ($casos as [$nombre,$rol,$permisos,$puedeVer,$puedeImportar]) {
    $_SESSION=['rol_id'=>$rol,'rol'=>'Prueba','permisos'=>$permisos];
    if ($nombre!=='Rol sin sesión')$_SESSION['usuario_id']=711;
    if (ProspectoMetaImportacionesHistorial::puedeConsultar()!==$puedeVer) {
        throw new RuntimeException('Acceso de historial incorrecto: '.$nombre);
    }
    if (ProspectoMetaImportador::puedeImportar()!==$puedeImportar) {
        throw new RuntimeException('Permiso de importación alterado: '.$nombre);
    }
    $n++;
}
echo "OK: $n combinaciones de permisos independientes; Administrador no importa.\n";
