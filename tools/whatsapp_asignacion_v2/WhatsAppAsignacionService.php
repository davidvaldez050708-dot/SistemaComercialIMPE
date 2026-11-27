<?php
declare(strict_types=1);
require_once __DIR__.'/WhatsAppContextoService.php';

/** Enruta contactos conocidos y asigna chats libres sin modificar carteras. */
final class WhatsAppAsignacionService
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db=$db; }

    private function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);
        try {
            if($args) {
                $types='';foreach($args as $arg)$types.=is_int($arg)?'i':'s';
                $s->bind_param($types,...$args);
            }
            $s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally { $s->close(); }
    }

    public static function puedeAdministrar(): bool
    {
        return (int)($_SESSION['usuario_id']??0)>0 && (int)($_SESSION['rol_id']??0)===1
            && tienePermiso('whatsapp.ver') && tienePermiso('whatsapp.gestionar_cuentas')
            && WhatsAppContextoService::lecturaGlobal();
    }

    private function exigirAdministrador(): void
    {
        if(!self::puedeAdministrar())throw new DomainException('No tienes permiso para asignar conversaciones.',403);
        $actor=$this->rows('SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.rol_id=1 AND u.estado=1',[(int)$_SESSION['usuario_id']]);
        if(!$actor)throw new DomainException('La cuenta administradora no está activa.',403);
    }

    public function candidatos(): array
    {
        $this->exigirAdministrador();
        return $this->rows("SELECT u.id,TRIM(CONCAT_WS(' ',u.nombre,u.apellidos)) nombre,r.nombre rol
            FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1
            WHERE u.estado=1 AND u.rol_id<>1
              AND (SELECT COUNT(DISTINCT p.codigo) FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1
                   WHERE rp.rol_id=u.rol_id AND p.codigo IN ('whatsapp.ver','whatsapp.enviar'))=2
            ORDER BY u.nombre,u.apellidos,u.id");
    }

    private function libreSql(): string
    {
        return "c.activo=1 AND COALESCE(conv.responsable_usuario_id,0)=0 AND conv.aliado_seguimiento_id IS NULL
            AND NOT EXISTS(SELECT 1 FROM whatsapp_conversacion_prospectos w WHERE w.conversacion_id=conv.id)
            AND EXISTS(SELECT 1 FROM whatsapp_mensajes m WHERE m.conversacion_id=conv.id)";
    }

    public function pendientes(int $pagina=1,string $buscar=''): array
    {
        $this->exigirAdministrador();$pagina=max(1,min(100000,$pagina));$buscar=mb_substr(trim($buscar),0,100);
        $where=$this->libreSql();$args=[];
        if($buscar!==''){$where.=' AND (conv.nombre_contacto LIKE ? OR conv.telefono_normalizado LIKE ?)';$args=['%'.$buscar.'%','%'.$buscar.'%'];}
        $from=' FROM whatsapp_conversaciones conv JOIN whatsapp_cuentas c ON c.id=conv.cuenta_id WHERE '.$where;
        $total=(int)$this->rows('SELECT COUNT(*) total'.$from,$args)[0]['total'];
        $pagina=min($pagina,max(1,(int)ceil($total/50)));$offset=($pagina-1)*50;
        $items=$this->rows('SELECT conv.id,conv.nombre_contacto,conv.telefono_normalizado,conv.ultimo_mensaje_preview,conv.ultimo_mensaje_at,c.nombre canal'.$from.' ORDER BY conv.ultimo_mensaje_at DESC,conv.id DESC LIMIT 50 OFFSET '.$offset,$args);
        return ['filas'=>$items,'total'=>$total,'pagina'=>$pagina,'paginas'=>max(1,(int)ceil($total/50))];
    }

    private function conversacion(int $id): ?array
    {
        return $this->rows('SELECT conv.*, c.tipo AS canal_tipo FROM whatsapp_conversaciones conv JOIN whatsapp_cuentas c ON c.id=conv.cuenta_id AND c.activo=1 WHERE conv.id=? FOR UPDATE',[$id])[0]??null;
    }

    private function tieneContexto(int $id,array $conv): bool
    {
        return !empty($conv['aliado_seguimiento_id']) || (bool)$this->rows('SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id=? FOR UPDATE',[$id]);
    }

    private function puedeRecibir(int $usuario): bool
    {
        return (bool)$this->rows("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1
            WHERE u.id=? AND u.estado=1 AND u.rol_id<>1
            AND (SELECT COUNT(DISTINCT p.codigo) FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1
                 WHERE rp.rol_id=u.rol_id AND p.codigo IN ('whatsapp.ver','whatsapp.enviar'))=2",[$usuario]);
    }

    private function auditar(int $id,?int $antes,int $despues,int $actor,string $origen): void
    {
        $s=$this->db->prepare('INSERT INTO whatsapp_asignaciones_historial(conversacion_id,responsable_anterior_id,responsable_nuevo_id,actor_usuario_id,origen) VALUES(?,?,?,?,?)');
        try {$actor=$actor>0?$actor:null;$s->bind_param('iiiis',$id,$antes,$despues,$actor,$origen);if(!$s->execute())throw new RuntimeException('No se confirmó la auditoría.');}
        finally {$s->close();}
    }

    public function asignar(int $id,int $usuario): array
    {
        $this->exigirAdministrador();
        if($id<1 || $usuario<1)throw new DomainException('Selecciona conversación y responsable válidos.',422);
        $this->db->begin_transaction();
        try {
            $conv=$this->conversacion($id);
            if(!$conv)throw new DomainException('La conversación ya no está disponible.',404);
            if(!$this->puedeRecibir($usuario))throw new DomainException('El responsable debe estar activo y tener acceso para consultar y enviar WhatsApp.',422);
            $rol=(int)($this->rows('SELECT rol_id FROM usuarios WHERE id=?',[$usuario])[0]['rol_id']??0);
            $anterior=(int)($conv['responsable_usuario_id']??0);
            if($this->tieneContexto($id,$conv)) {
                // Sólo reconocer un reintento de una asignación manual ya auditada; no cambiar expedientes.
                if($anterior===$usuario && $this->rows("SELECT 1 FROM whatsapp_asignaciones_historial WHERE conversacion_id=? AND responsable_nuevo_id=? AND origen='MANUAL' LIMIT 1",[$id,$usuario])){
                    $this->db->rollback();return ['id'=>$id,'responsable_id'=>$usuario,'ya_asignada'=>true];
                }
                throw new DomainException('La conversación pertenece a un expediente. Su responsable se gestiona desde esa cartera.',409);
            }
            if($anterior>0 && $anterior!==$usuario)throw new DomainException('Otro usuario ya asignó esta conversación. Actualiza la bandeja.',409);
            if($anterior===$usuario){
                if($rol===3)throw new DomainException('Este chat de Ventas no tiene expediente. Revisa su vínculo desde el embudo.',409);
                $this->db->rollback();return ['id'=>$id,'responsable_id'=>$usuario,'ya_asignada'=>true];
            }
            if(!$this->rows('SELECT 1 FROM whatsapp_mensajes WHERE conversacion_id=? LIMIT 1',[$id]))throw new DomainException('La conversación todavía no tiene mensajes.',409);
            $matches=$this->coincidencias((string)$conv['telefono_normalizado']);
            if(count($matches)>1)throw new DomainException('El teléfono coincide con varios expedientes. Revisa el contacto antes de asignar.',409);
            if($matches) {
                $m=$matches[0];
                if((int)$m['responsable']!==$usuario || $conv['canal_tipo']!=='EMPRESARIAL' || !$this->contextoValido($m,$usuario,(string)$conv['telefono_normalizado']))throw new DomainException('El contacto requiere una asignación comercial vigente y autorizada. Revisa su expediente.',409);
                $this->vincular($id,$m,$usuario);
            } else {
                if($rol===3)throw new DomainException('Ventas sólo atiende prospectos de su cartera. Asigna el prospecto desde el embudo antes de vincular este chat.',409);
                $this->actualizarResponsable($id,$usuario,0);
            }
            $this->auditar($id,null,$usuario,(int)$_SESSION['usuario_id'],'MANUAL');
            if(!$this->db->commit())throw new RuntimeException('No se confirmó la asignación.');
            return ['id'=>$id,'responsable_id'=>$usuario,'ya_asignada'=>false];
        } catch(Throwable $e){$this->db->rollback();throw $e;}
    }

    private function coincidencias(string $telefono): array
    {
        $formatos=array_values(array_unique(array_map(static fn($v)=>ltrim($v,'+'),ProspectoNoContactarV3::formatos($telefono))));
        if(!$formatos)return [];
        foreach($formatos as $formato)if(strlen($formato)===12 && substr($formato,0,2)==='52')$formatos[]=substr($formato,2);
        $formatos=array_values(array_unique($formatos));
        $marks=implode(',',array_fill(0,count($formatos),'?'));
        $prospectos=$this->rows("SELECT 'PROSPECTO' tipo,p.id,a.asesor_id responsable FROM prospectos_comerciales p
            LEFT JOIN prospecto_asignaciones a ON a.prospecto_id=p.id AND a.vigente=1
            WHERE REPLACE(p.telefono_normalizado,'+','') IN ($marks) LIMIT 2",$formatos);
        $pred=[];$args=[];
        foreach(['telefono_fuente','telefono_verificado','whatsapp_verificado'] as $campo){
            $pred[]="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(s.$campo,'+',''),' ',''),'-',''),'(',''),')',''),'.','') IN ($marks)";
            array_push($args,...$formatos);
        }
        $instituciones=$this->rows("SELECT DISTINCT 'SEGUIMIENTO' tipo,s.id,s.analista_id responsable,s.whatsapp_verificado FROM seguimientos_vinculacion s
            WHERE s.activo=1 AND (".implode(' OR ',$pred).') LIMIT 2',$args);
        return array_merge($prospectos,$instituciones);
    }

    private function formatos(string $telefono): array
    {
        $out=array_values(array_unique(array_map(static fn($v)=>ltrim($v,'+'),ProspectoNoContactarV3::formatos($telefono))));
        foreach($out as $v)if(strlen($v)===12 && substr($v,0,2)==='52')$out[]=substr($v,2);
        return array_unique($out);
    }

    private function contextoValido(array $match,int $uid,string $telefono): bool
    {
        if(!$this->puedeRecibir($uid))return false;
        if($match['tipo']==='PROSPECTO') {
            $r=$this->rows("SELECT a.asesor_id,u.rol_id FROM prospecto_asignaciones a JOIN usuarios u ON u.id=a.asesor_id AND u.estado=1 WHERE a.prospecto_id=? AND a.vigente=1 FOR UPDATE",[(int)$match['id']]);
            return count($r)===1 && (int)$r[0]['asesor_id']===$uid && (int)$r[0]['rol_id']===3;
        }
        if($match['tipo']!=='SEGUIMIENTO' || !array_intersect($this->formatos($telefono),$this->formatos((string)($match['whatsapp_verificado']??''))))return false;
        return (bool)$this->rows("SELECT 1 FROM asignaciones_territorio a JOIN usuarios u ON u.id=a.usuario_id AND u.rol_id=4 AND u.estado=1
            JOIN estados e ON e.id=a.estado_id AND e.estado=1
            JOIN seguimientos_vinculacion s ON s.estado_id=a.estado_id AND s.id=? AND s.activo=1 AND s.analista_id=u.id
            WHERE a.usuario_id=? AND a.tipo_asignacion='ANALISTA_DATOS' AND a.activo=1
            AND (a.fecha_inicio IS NULL OR a.fecha_inicio<=CURDATE()) AND (a.fecha_fin IS NULL OR a.fecha_fin>=CURDATE()) LIMIT 1",[(int)$match['id'],$uid]);
    }

    private function actualizarResponsable(int $id,int $uid,int $seguimiento): void
    {
        $s=$this->db->prepare('UPDATE whatsapp_conversaciones SET aliado_seguimiento_id=NULLIF(?,0),responsable_usuario_id=? WHERE id=? AND aliado_seguimiento_id IS NULL AND COALESCE(responsable_usuario_id,0)=0');
        try {$s->bind_param('iii',$seguimiento,$uid,$id);if(!$s->execute() || $s->affected_rows!==1)throw new RuntimeException('La conversación cambió.');}
        finally {$s->close();}
    }

    private function vincular(int $id,array $m,int $uid): void
    {
        if($m['tipo']==='PROSPECTO'){
            $s=$this->db->prepare('INSERT INTO whatsapp_conversacion_prospectos(conversacion_id,prospecto_id) VALUES(?,?)');
            try {$p=(int)$m['id'];$s->bind_param('ii',$id,$p);if(!$s->execute())throw new RuntimeException('No se confirmó el vínculo.');}
            finally {$s->close();}
        }
        $this->actualizarResponsable($id,$uid,$m['tipo']==='SEGUIMIENTO'?(int)$m['id']:0);
    }

    /** Un único enrutador de entradas empresariales. No reclama chats anteriores. */
    public function enrutarEntrada(int $id,string $telefono): array
    {
        $this->db->begin_transaction();
        try {
            $conv=$this->conversacion($id);
            if(!$conv || $conv['canal_tipo']!=='EMPRESARIAL' || $this->tieneContexto($id,$conv) || (int)($conv['responsable_usuario_id']??0)>0){$this->db->rollback();return ['estado'=>'CONSERVADA'];}
            // Usar el teléfono del chat bloqueado, no un destino proporcionado por el cliente.
            $telefono=(string)$conv['telefono_normalizado'];
            $matches=$this->coincidencias($telefono);
            if(count($matches)!==1){$this->db->rollback();return ['estado'=>$matches?'REVISAR':'SIN_ASIGNAR'];}
            $match=$matches[0];$uid=(int)$match['responsable'];
            if(!$this->contextoValido($match,$uid,$telefono)){$this->db->rollback();return ['estado'=>'REVISAR'];}
            $this->vincular($id,$match,$uid);
            $this->auditar($id,null,$uid,0,'AUTOMATICA');
            if(!$this->db->commit())throw new RuntimeException('No se confirmó el enrutamiento.');
            return ['estado'=>'ASIGNADA','responsable_id'=>$uid];
        } catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
