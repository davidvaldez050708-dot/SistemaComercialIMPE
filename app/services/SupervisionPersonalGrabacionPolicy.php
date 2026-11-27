<?php
declare(strict_types=1);
require_once __DIR__.'/SupervisionPersonalAcceso.php';

/** Autoriza el autor exacto del audio; NULL mantiene las rutas personales y territoriales originales. */
final class SupervisionPersonalGrabacionPolicy
{
    public static function resolver(mysqli $db,int $usuario,int $autor,int $interaccion): ?bool
    {
        if($usuario===$autor)return null;
        $s=$db->prepare('SELECT u.rol_id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.estado=1');
        $s->bind_param('i',$usuario);$s->execute();$role=(int)($s->get_result()->fetch_assoc()['rol_id']??0);$s->close();
        if(!$role)return false;
        if($role!==6)return null;
        $scope=SupervisionPersonalAcceso::crear($db)->sql('i.usuario_id',$usuario,'grabaciones',4);
        if($scope===null)return null;
        $s=$db->prepare("SELECT p.id FROM permisos p JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE rp.rol_id=6 AND p.estado=1 AND p.codigo='seguimientos_vinculacion.ver'");
        $s->execute();$general=(bool)$s->get_result()->fetch_row();$s->close();if(!$general)return false;
        $s=$db->prepare("SELECT i.id FROM interacciones_vinculacion i WHERE i.id=? AND i.usuario_id=? AND $scope");
        $s->bind_param('ii',$interaccion,$autor);$s->execute();$ok=(bool)$s->get_result()->fetch_row();$s->close();return $ok;
    }
}
