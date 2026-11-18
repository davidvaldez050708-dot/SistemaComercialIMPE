<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/PermissionHelper.php';
require_once __DIR__.'/ProspectoNoContactarV3.php';

/** El canal compartido nunca concede acceso a la cartera de otro usuario. */
final class WhatsAppContextoService
{
    public static function numero(string $numero): string
    {
        $formatos=ProspectoNoContactarV3::formatos($numero);
        if (!$formatos) throw new DomainException('El contacto no tiene un teléfono válido.');
        return ltrim($formatos[0],'+');
    }

    public static function tabla(mysqli $db,string $tabla): bool
    {
        $s=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        try{$s->bind_param('s',$tabla);$s->execute();return (bool)$s->get_result()->fetch_row();}
        finally{$s->close();}
    }

    public static function lecturaGlobal(): bool
    {
        // El permiso histórico está descrito como supervisión/consulta.
        return tienePermiso('whatsapp.ver_todas') || tienePermiso('whatsapp.gestionar_conversaciones');
    }

    public static function alcance(mysqli $db,int $usuario,bool $global): string
    {
        if ($usuario<1 || (int)($_SESSION['usuario_id']??0)!==$usuario || !tienePermiso('whatsapp.ver')) return '0=1';
        if ($global && (self::lecturaGlobal() || tienePermiso('whatsapp.responder_todas'))) return '1=1';
        $propio="conv.responsable_usuario_id=$usuario";
        if (self::tabla($db,'whatsapp_conversacion_prospectos')) {
            $propio="CASE WHEN EXISTS(SELECT 1 FROM whatsapp_conversacion_prospectos enlace WHERE enlace.conversacion_id=conv.id)
                THEN EXISTS(SELECT 1 FROM whatsapp_conversacion_prospectos enlace
                    JOIN prospecto_asignaciones pa ON pa.prospecto_id=enlace.prospecto_id AND pa.vigente=1
                    WHERE enlace.conversacion_id=conv.id AND pa.asesor_id=$usuario)
                ELSE ($propio) END";
        }
        $territorio=self::scopeSeguimiento($usuario);
        return "(CASE WHEN conv.aliado_seguimiento_id IS NOT NULL THEN
            EXISTS(SELECT 1 FROM seguimientos_vinculacion ws WHERE ws.id=conv.aliado_seguimiento_id AND ws.activo=1 AND ($territorio))
            ELSE ($propio) END)";
    }

    public static function scopeSeguimiento(int $usuario): string
    {
        if ((int)($_SESSION['rol_id']??0)===4 && tienePermiso('seguimientos_vinculacion.ver')) {
            return "EXISTS(SELECT 1 FROM asignaciones_territorio wt
                JOIN usuarios wu ON wu.id=wt.usuario_id AND wu.estado=1 AND wu.rol_id=4
                JOIN roles wr ON wr.id=wu.rol_id AND wr.estado=1
                JOIN estados we ON we.id=wt.estado_id AND we.estado=1
                WHERE wt.estado_id=ws.estado_id AND wt.usuario_id=$usuario
                AND wt.tipo_asignacion='ANALISTA_DATOS' AND wt.activo=1
                AND (wt.fecha_inicio IS NULL OR wt.fecha_inicio<=CURDATE())
                AND (wt.fecha_fin IS NULL OR wt.fecha_fin>=CURDATE()))";
        }
        if ((int)($_SESSION['rol_id']??0)===6 && tienePermiso('aliados.ver')) {
            return "EXISTS(SELECT 1 FROM aliados_asignaciones wa WHERE wa.seguimiento_id=ws.id AND wa.cuenta_clave_usuario_id=$usuario AND wa.activo=1)";
        }
        return '0=1';
    }

    public static function prospecto(mysqli $db,int $asignacion,int $usuario): array
    {
        if (!tienePermiso('ventas.embudo.ver')) throw new DomainException('No tienes acceso a este prospecto.');
        $s=$db->prepare("SELECT p.id,p.nombre_completo,p.telefono_normalizado,a.asesor_id
            FROM prospecto_asignaciones a JOIN prospectos_comerciales p ON p.id=a.prospecto_id
            WHERE a.id=? AND a.vigente=1 AND a.asesor_id=? FOR UPDATE");
        try{$s->bind_param('ii',$asignacion,$usuario);$s->execute();$r=$s->get_result()->fetch_assoc();}
        finally{$s->close();}
        if (!$r) throw new DomainException('El prospecto no está asignado a tu cuenta.');
        return $r;
    }

    public static function enlazar(mysqli $db,int $conversacion,int $prospecto): void
    {
        if (!self::tabla($db,'whatsapp_conversacion_prospectos')) throw new DomainException('Falta preparar el enlace comercial de WhatsApp.');
        $s=$db->prepare('INSERT IGNORE INTO whatsapp_conversacion_prospectos(conversacion_id,prospecto_id) VALUES(?,?)');
        try{$s->bind_param('ii',$conversacion,$prospecto);$s->execute();}finally{$s->close();}
        $s=$db->prepare('SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id=?');
        try{$s->bind_param('i',$conversacion);$s->execute();$r=$s->get_result()->fetch_assoc();}finally{$s->close();}
        if ((int)($r['prospecto_id']??0)!==$prospecto) throw new DomainException('Este teléfono está vinculado a otro expediente. Solicita revisión.');
    }

    public static function exigirSalida(mysqli $db,array $conv): void
    {
        if (!self::tabla($db,'whatsapp_conversacion_prospectos')) return;
        $s=$db->prepare("SELECT 1 FROM whatsapp_conversacion_prospectos w
            JOIN prospecto_asignaciones a ON a.prospecto_id=w.prospecto_id AND a.vigente=1
            JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
            WHERE w.conversacion_id=? AND e.etapa IN ('RECUPERACION','EN_CONTACTO','DESCARTADO') LIMIT 1");
        $id=(int)$conv['id'];
        try{$s->bind_param('i',$id);$s->execute();$bloqueado=(bool)$s->get_result()->fetch_row();}finally{$s->close();}
        if($bloqueado)throw new DomainException('El prospecto está en Recuperación o Descartados. No está habilitado el contacto saliente.');
    }

    public static function abrir(WhatsAppModel $modelo,string $tipo,int $id,int $usuario): int
    {
        if($usuario<1 || (int)($_SESSION['usuario_id']??0)!==$usuario || !tienePermiso('whatsapp.ver'))throw new DomainException('Acceso no disponible.');
        $db=$modelo->conexionParaAutorizacion();
        $db->begin_transaction();
        try {
            $prospecto=0;$seguimiento=0;
            if($tipo==='prospecto') {
                if(!self::tabla($db,'whatsapp_conversacion_prospectos'))throw new DomainException('Falta preparar el enlace comercial de WhatsApp.');
                $r=self::prospecto($db,$id,$usuario);
                $prospecto=(int)$r['id'];$numero=self::numero((string)$r['telefono_normalizado']);$nombre=$r['nombre_completo'];
            } elseif($tipo==='seguimiento' || $tipo==='aliado') {
                $scope=self::scopeSeguimiento($usuario);
                $s=$db->prepare("SELECT ws.id,ws.nombre_entidad,ws.whatsapp_verificado FROM seguimientos_vinculacion ws WHERE ws.id=? AND ws.activo=1 AND ($scope) FOR UPDATE");
                try{$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();}finally{$s->close();}
                if(!$r)throw new DomainException('No tienes acceso a este seguimiento.');
                $seguimiento=(int)$r['id'];$numero=$tipo==='aliado'?'':self::numero((string)$r['whatsapp_verificado']);$nombre=$r['nombre_entidad'];
                if($tipo==='aliado') {
                    $s=$db->prepare('SELECT numero FROM aliados_contactos WHERE seguimiento_id=? AND activo=1 AND confirmado_whatsapp=1 AND autorizado_whatsapp=1 ORDER BY preferido_difusion DESC,id DESC LIMIT 1');
                    try{$s->bind_param('i',$seguimiento);$s->execute();$contacto=$s->get_result()->fetch_assoc();}finally{$s->close();}
                    if(!$contacto)throw new DomainException('El aliado no tiene un contacto con autorización para WhatsApp.');
                    $numero=self::numero((string)$contacto['numero']);
                }
            } else throw new DomainException('Origen de conversación no válido.');
            $cuenta=$modelo->resolverCuentaUsuario($usuario);
            if(!$cuenta)throw new DomainException('Todavía no hay un canal de WhatsApp disponible para tu cuenta.');
            $existente=$modelo->obtenerConversacionPorCuentaTelefono((int)$cuenta['id'],$numero);
            if($existente) {
                $conv=(int)$existente['id'];
                $s=$db->prepare('SELECT id FROM whatsapp_conversaciones WHERE id=? FOR UPDATE');
                try{$s->bind_param('i',$conv);$s->execute();$s->get_result();}finally{$s->close();}
                if((int)($existente['aliado_seguimiento_id']??0)!==$seguimiento)throw new DomainException('El teléfono pertenece a otro contexto. Solicita revisión de la conversación.');
                if(!$modelo->obtenerConversacion($conv,$usuario,self::lecturaGlobal())) {
                    // Un prospecto ya enlazado se autoriza por su asignación vigente.
                    throw new DomainException('La conversación requiere revisión de su responsable.');
                }
            } else {
                if(!tienePermiso('whatsapp.enviar'))throw new DomainException('Aún no existe una conversación disponible para consulta.');
                $conv=$modelo->crearORecuperarConversacion((int)$cuenta['id'],$numero,$nombre,$usuario,$seguimiento);
                // La clave única cuenta/teléfono resuelve aperturas simultáneas.
                if(!$modelo->obtenerConversacion($conv,$usuario,false))throw new DomainException('La conversación fue asignada a otra cuenta.');
            }
            if($prospecto>0)self::enlazar($db,$conv,$prospecto);
            $db->commit();return $conv;
        } catch(Throwable $e){$db->rollback();throw $e;}
    }
}
