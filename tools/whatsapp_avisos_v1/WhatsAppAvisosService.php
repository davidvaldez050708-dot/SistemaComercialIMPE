<?php
declare(strict_types=1);
require_once __DIR__.'/WhatsAppContextoService.php';

/** Resumen operativo de sólo lectura; nunca amplía alcance ni marca mensajes. */
final class WhatsAppAvisosService
{
    public static function resumen(mysqli $db,int $uid): array
    {
        if($uid<1 || (int)($_SESSION['usuario_id']??0)!==$uid || !tienePermiso('whatsapp.ver'))throw new DomainException('Acceso no disponible.',403);
        $s=$db->prepare("SELECT u.rol_id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1
            JOIN rol_permisos rp ON rp.rol_id=u.rol_id JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1 AND p.codigo='whatsapp.ver'
            WHERE u.id=? AND u.estado=1 LIMIT 1");
        try{$s->bind_param('i',$uid);$s->execute();$role=$s->get_result()->fetch_row();}
        finally{$s->close();}
        if(!$role || (int)$role[0] !== (int)($_SESSION['rol_id']??0))throw new DomainException('Tu acceso cambió.',403);
        // Los supervisores consultan el equipo en la bandeja, no reciben alertas de toda la cartera.
        $scope=WhatsAppContextoService::alcance($db,$uid,false);
        $own=(int)$role[0]===4?" AND (conv.aliado_seguimiento_id IS NULL OR conv.responsable_usuario_id=$uid)":"";
        $from=" FROM whatsapp_conversaciones conv JOIN whatsapp_cuentas c ON c.id=conv.cuenta_id
            WHERE c.activo=1 AND c.tipo='EMPRESARIAL' AND conv.no_leidos>0 AND ($scope)".$own;
        $count=(int)$db->query("SELECT COUNT(*)".$from)->fetch_row()[0];
        $rows=$db->query("SELECT conv.id,COALESCE(NULLIF(conv.nombre_contacto,''),'Contacto') AS nombre,
            LEFT(COALESCE(conv.ultimo_mensaje_preview,''),160) AS mensaje,conv.no_leidos,conv.ultimo_mensaje_at".
            $from." ORDER BY conv.ultimo_mensaje_at DESC,conv.id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
        return ['total'=>$count,'conversaciones'=>array_map(static function($r){
            return ['id'=>(int)$r['id'],'nombre'=>(string)$r['nombre'],'mensaje'=>(string)$r['mensaje'],
                'no_leidos'=>(int)$r['no_leidos'],'fecha'=>$r['ultimo_mensaje_at']];
        },$rows)];
    }
}
