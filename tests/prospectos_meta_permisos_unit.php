<?php
/**
 * La autorización de Meta depende de permisos asignados, no de títulos o IDs.
 * Sin DB: se ejecuta contra las mismas funciones del controlador real.
 */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../app/helpers/PermissionHelper.php';
require_once __DIR__.'/../app/services/ProspectoMetaImportador.php';
$casos=[
    ['Sin sesión','Director de Operaciones',7,['prospectos.ver_todos','prospectos.importar_meta'],false,false],
    ['Marketing sin importar','Marketing',5,['prospectos.ver_todos'],true,false],
    ['Director sin importar','Director de Operaciones',7,['prospectos.ver_todos'],true,false],
    ['Director autorizado','Director de Operaciones',7,['prospectos.ver_todos','prospectos.importar_meta'],true,true],
    ['Marketing autorizado','Marketing',5,['prospectos.ver_todos','prospectos.importar_meta'],true,true],
    ['Asesor autorizado por rol','Asesor de Ventas',3,['prospectos.ver_todos','prospectos.importar_meta'],true,true],
    ['Rol sin consulta','Director de Operaciones',7,['prospectos.importar_meta'],true,false],
    ['Administrador protegido','Administrador',1,['prospectos.ver_todos','prospectos.importar_meta'],true,false],
];
foreach($casos as [$nombre,$rol,$id,$permisos,$sesion,$esperado]) {
    $_SESSION=['rol'=>$rol,'rol_id'=>$id,'permisos'=>$permisos];
    if($sesion)$_SESSION['usuario_id']=177;
    $actual=ProspectoMetaImportador::puedeImportar();
    if($actual!==$esperado)throw new RuntimeException($nombre.': autorización inconsistente.');
}
echo "PASS: 8 casos de importación autorizada por permisos, sin rol hardcoded, sin SQL.\n";
