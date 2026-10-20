<?php
if (PHP_SAPI!=='cli') exit(2);
require_once __DIR__.'/../app/helpers/PermissionHelper.php';
require_once __DIR__.'/../app/services/AliadoInternacionalService.php';
$cases=[
 ['Anónimo',[],false],
 ['Sin permisos',['usuario_id'=>3,'rol_id'=>8,'permisos'=>[]],false],
 ['Solo creación',['usuario_id'=>3,'rol_id'=>8,'permisos'=>['convocatorias.crear']],false],
 ['Solo consulta de convocatorias',['usuario_id'=>3,'rol_id'=>8,'permisos'=>['convocatorias.ver']],false],
 ['Solo aliados nacionales',['usuario_id'=>2,'rol_id'=>7,'permisos'=>['aliados.ver']],false],
 ['Cuenta Clave sin territorios',['usuario_id'=>2,'rol_id'=>7,'permisos'=>['aliados.ver','convocatorias.ver']],true],
 ['Marketing sin aliados nacionales',['usuario_id'=>3,'rol_id'=>8,'permisos'=>['convocatorias.ver','convocatorias.crear']],true],
 ['Configuración explícita',['usuario_id'=>2,'rol_id'=>7,'permisos'=>['aliados.ver','aliados.internacionales.configurar']],true],
 ['Admin lectura',['usuario_id'=>1,'rol_id'=>1,'permisos'=>['aliados.ver']],true],
];
foreach($cases as [$label,$session,$expected]){
 $_SESSION=$session;$before=$_SESSION;
 if(AliadoInternacionalService::puedeConsultar()!==$expected||$_SESSION!==$before)throw new RuntimeException($label);
 echo "OK: $label\n";
}
$_SESSION=['usuario_id'=>2,'rol_id'=>7,'permisos'=>['aliados.ver','convocatorias.ver']];
foreach(['aliados.internacionales.configurar','convocatorias.crear','convenios.gestionar'] as $p){if(tienePermiso($p))throw new RuntimeException('Elevación de permisos');}
echo "OK: consulta no concede acciones operativas\n";
