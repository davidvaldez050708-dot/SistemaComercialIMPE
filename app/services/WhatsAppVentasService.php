<?php
declare(strict_types=1);
require_once __DIR__.'/WhatsAppContextoService.php';

final class WhatsAppVentasService
{
    private mysqli $db;
    public function __construct(mysqli $db){$this->db=$db;}
    private function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);
        try {
            if($args){$types='';foreach($args as $v)$types.=is_int($v)?'i':'s';$s->bind_param($types,...$args);}
            $s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {$s->close();}
    }

    public function configuracion(int $cuenta): ?array
    {
        if(!WhatsAppContextoService::tabla($this->db,'whatsapp_ventas_config'))return null;
        return $this->rows('SELECT * FROM whatsapp_ventas_config WHERE cuenta_id=?',[$cuenta])[0]??null;
    }

    public function guardarPlantilla(int $cuenta,string $messaging,string $nombre,string $idioma,WhatsAppCloudApiService $api): array
    {
        if((int)($_SESSION['rol_id']??0)!==1 || !tienePermiso('whatsapp.gestionar_cuentas'))throw new DomainException('Sólo el Administrador configura la plantilla institucional.',403);
        if(!preg_match('/^[0-9]{5,80}$/D',$messaging) || !preg_match('/^[a-z0-9_]{1,512}$/D',$nombre) || !preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$idioma))throw new DomainException('Revisa el identificador de cuenta, nombre e idioma de la plantilla.',422);
        if(!$this->rows("SELECT id FROM whatsapp_cuentas WHERE id=? AND activo=1 AND tipo='EMPRESARIAL'",[$cuenta]))throw new DomainException('Selecciona un canal empresarial activo.',422);
        $check=$api->consultarPlantillaComercial($messaging,$nombre,$idioma);
        if(empty($check['ok']))throw new DomainException((string)($check['mensaje']??'No se pudo validar la plantilla con Meta.'),409);
        $body=(string)$check['cuerpo'];$category=(string)$check['categoria'];$actor=(int)$_SESSION['usuario_id'];
        $s=$this->db->prepare('INSERT INTO whatsapp_ventas_config(cuenta_id,messaging_account_id,plantilla_nombre,plantilla_idioma,plantilla_cuerpo,plantilla_categoria,verificada_at,actualizada_por) VALUES(?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE messaging_account_id=VALUES(messaging_account_id),plantilla_nombre=VALUES(plantilla_nombre),plantilla_idioma=VALUES(plantilla_idioma),plantilla_cuerpo=VALUES(plantilla_cuerpo),plantilla_categoria=VALUES(plantilla_categoria),verificada_at=NOW(),actualizada_por=VALUES(actualizada_por)');
        try {$s->bind_param('isssssi',$cuenta,$messaging,$nombre,$idioma,$body,$category,$actor);$s->execute();}
        finally {$s->close();}
        return ['cuerpo'=>$body,'categoria'=>$category];
    }

    /** Sólo reconoce prospectos de ventas; los otros contextos se conservan. */
    public function vincularEntrada(int $id,string $telefono): void
    {
        $formatos=ProspectoNoContactarV3::formatos($telefono);if(!$formatos)return;
        $digits=array_values(array_unique(array_map(static fn($v)=>ltrim($v,'+'),$formatos)));
        foreach($digits as $v)if(strlen($v)===12 && substr($v,0,2)==='52')$digits[]=substr($v,2);
        $digits=array_values(array_unique($digits));$marks=implode(',',array_fill(0,count($digits),'?'));
        $this->db->begin_transaction();
        try {
            $conv=$this->rows("SELECT conv.id,conv.aliado_seguimiento_id FROM whatsapp_conversaciones conv JOIN whatsapp_cuentas c ON c.id=conv.cuenta_id WHERE conv.id=? AND c.tipo='EMPRESARIAL' AND c.activo=1 FOR UPDATE",[$id]);
            if(!$conv || !empty($conv[0]['aliado_seguimiento_id']) || $this->rows('SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id=? FOR UPDATE',[$id])){$this->db->rollback();return;}
            $matches=$this->rows("SELECT DISTINCT p.id,a.asesor_id FROM prospectos_comerciales p JOIN prospecto_asignaciones a ON a.prospecto_id=p.id AND a.vigente=1 WHERE REPLACE(p.telefono_normalizado,'+','') IN ($marks) LIMIT 2",$digits);
            if(count($matches)!==1){$this->db->rollback();return;}
            $uid=(int)$matches[0]['asesor_id'];$prospecto=(int)$matches[0]['id'];
            if(!$this->rows('SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.estado=1 AND u.rol_id=3',[$uid])){$this->db->rollback();return;}
            $s=$this->db->prepare('INSERT INTO whatsapp_conversacion_prospectos(conversacion_id,prospecto_id) VALUES(?,?)');
            try {$s->bind_param('ii',$id,$prospecto);$s->execute();}finally {$s->close();}
            $s=$this->db->prepare('UPDATE whatsapp_conversaciones SET responsable_usuario_id=? WHERE id=?');
            try {$s->bind_param('ii',$uid,$id);$s->execute();}finally {$s->close();}
            $this->db->commit();
        } catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
