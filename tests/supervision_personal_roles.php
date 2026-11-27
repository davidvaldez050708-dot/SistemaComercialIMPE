<?php
declare(strict_types=1);
if(!isset($database)||!preg_match('/^impe_sp_test_[a-f0-9]{8}$/D',$database))throw new RuntimeException('Sólo prueba aislada.');
require_once __DIR__.'/../app/helpers/SupervisionRolePolicy.php';
check(!permisoReservadoParaRol(2,SupervisionPersonalRepository::PERMISO),'Roles y permisos permite delegar la gestión explícitamente.');
check((int)$db->query("SELECT COUNT(*) n FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo LIKE 'personal.supervision.%' AND rp.rol_id<>1")->fetch_assoc()['n']===0,'No conceder permisos administrativos a otros roles automáticamente.');
$grant=static function(string $code)use($db):void{$s=$db->prepare('INSERT INTO rol_permisos SELECT 2,id FROM permisos WHERE codigo=?');$s->bind_param('s',$code);$s->execute();$s->close();};
try{
 $grant(SupervisionPersonalRepository::VER);
 check(count($repo->catalogo(2)['cuentas_clave'])===2,'Una cuenta con Ver puede consultar tarjetas.');
 check(!$repo->detalle(2,10)['puede_gestionar'],'Una cuenta de consulta no puede editar.');
 rejected(fn()=>$repo->previsualizar(2,10,[22,24],$repo->estado()['revision'],str_repeat('seguridad',4)),'Ver no permite previsualizar escrituras.');
 rejected(fn()=>$repo->bitacora(2,0),'Ver no permite consultar bitácora sin su permiso.');
 $grant(SupervisionPersonalRepository::BITACORA);
 check(count($repo->bitacora(2,0))>0,'Bitácora requiere y respeta su permiso propio.');
 $grant(SupervisionPersonalRepository::PERMISO);$rev=$repo->estado()['revision'];$secret=str_repeat('seguridad',4);
 $p=$repo->previsualizar(2,10,[22,24],$rev,$secret);
 check($repo->guardar(2,10,[22,24],$rev,$p['confirmacion'],$secret)['sin_cambios'],'Un rol delegado puede usar el mismo guardado protegido.');
 rejected(fn()=>$repo->autorizarAdministrador(2),'Delegar gestión no delega la activación o migración CLI.');
 $db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=2 AND p.codigo='personal.supervision.ver'");
 rejected(fn()=>$repo->catalogo(2),'Retirar Ver impide consultar sin renovar la sesión.');
 rejected(fn()=>$repo->guardar(2,10,[22,24],$rev,$p['confirmacion'],$secret),'Retirar Ver impide guardar aunque conserve Gestión.');
 check(SupervisionPersonalPermisos::agregarNuevos($db)===[],'Repetir catálogo no restaura permisos revocados ni duplica concesiones.');
}finally{$db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=2 AND p.codigo LIKE 'personal.supervision.%'");}
echo "PASS: Ver, Gestión y Bitácora separados; delegación explícita por rol; sin concesiones automáticas; revocación inmediata; migración exclusiva del administrador.\n";
