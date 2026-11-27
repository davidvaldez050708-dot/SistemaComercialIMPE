<?php
declare(strict_types=1);

/** Administración de supervisión independiente de las asignaciones territoriales. Sin DDL en peticiones web. */
final class SupervisionPersonalRepository
{
    public const PERMISO='personal.supervision.gestionar';
    public const VER='personal.supervision.ver';
    public const BITACORA='personal.supervision.bitacora';
    public function __construct(private mysqli $db) {}
    private function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);
        try { if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC); }
        finally {$s->close();}
    }
    private function exec(string $sql,array $args=[]): void
    {
        $s=$this->db->prepare($sql);
        try {if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();}finally{$s->close();}
    }
    public function permitido(int $actor,string $codigo): bool
    {
        return (bool)$this->rows("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1
            JOIN rol_permisos rp ON rp.rol_id=r.id JOIN permisos p ON p.id=rp.permiso_id AND p.estado=1
            WHERE u.id=? AND u.estado=1 AND p.codigo=?",[$actor,$codigo]);
    }
    public function autorizar(int $actor,string $codigo=self::PERMISO): void
    {
        if(!$this->permitido($actor,$codigo))throw new DomainException('No tienes permiso para esta acción de supervisión de personal.',403);
    }
    public function autorizarAdministrador(int $actor): void
    {
        $this->autorizar($actor);
        if(!$this->rows('SELECT id FROM usuarios WHERE id=? AND rol_id=1 AND estado=1',[$actor]))throw new DomainException('La migración requiere un administrador activo.',403);
    }
    public function estado(): array
    {
        $rows=$this->rows('SELECT activo,revision FROM supervision_personal_estado WHERE id=1');
        if(!$rows)throw new RuntimeException('Falta completar la preparación de supervisión.');
        return ['activo'=>(bool)$rows[0]['activo'],'revision'=>(int)$rows[0]['revision']];
    }
    public function catalogo(int $actor): array
    {
        $this->autorizar($actor,self::VER);
        $kams=$this->rows("SELECT u.id,u.nombre,u.apellidos,u.foto_perfil,
            COUNT(DISTINCT sp.colaborador_id) colaboradores
            FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1
            LEFT JOIN supervision_personal sp ON sp.kam_id=u.id AND sp.activo=1
            WHERE u.rol_id=6 AND u.estado=1 GROUP BY u.id,u.nombre,u.apellidos,u.foto_perfil
            ORDER BY u.nombre,u.apellidos,u.id");
        return ['cuentas_clave'=>$kams,'estado'=>$this->estado(),'permisos'=>['gestionar'=>$this->permitido($actor,self::PERMISO),'bitacora'=>$this->permitido($actor,self::BITACORA)]];
    }
    public function detalle(int $actor,int $kam): array
    {
        $this->autorizar($actor,self::VER);$this->kam($kam);
        $staff=$this->rows("SELECT u.id,u.nombre,u.apellidos,u.foto_perfil,u.rol_id,u.estado activo,
            EXISTS(SELECT 1 FROM supervision_personal sp WHERE sp.kam_id=? AND sp.colaborador_id=u.id AND sp.activo=1) seleccionado
            FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1
            WHERE u.rol_id IN (3,4) AND (u.estado=1 OR EXISTS(SELECT 1 FROM supervision_personal sp
                WHERE sp.kam_id=? AND sp.colaborador_id=u.id AND sp.activo=1))
            ORDER BY u.nombre,u.apellidos,u.id",[$kam,$kam]);
        // Conservar vinculados cuyo rol cambió: no retirarlos al guardar una selección filtrada.
        $noElegibles=$this->rows("SELECT u.id,u.nombre,u.apellidos,u.foto_perfil,u.rol_id,u.estado activo,1 seleccionado,1 requiere_revision
            FROM supervision_personal sp JOIN usuarios u ON u.id=sp.colaborador_id
            LEFT JOIN roles r ON r.id=u.rol_id
            WHERE sp.kam_id=? AND sp.activo=1 AND (u.rol_id NOT IN (3,4) OR COALESCE(r.estado,0)<>1)",[$kam]);
        $noDisponibles=$this->rows("SELECT sp.colaborador_id id,CONCAT('Usuario no disponible #',sp.colaborador_id) nombre,
            '' apellidos,'' foto_perfil,0 rol_id,0 activo,1 seleccionado,1 requiere_revision
            FROM supervision_personal sp LEFT JOIN usuarios u ON u.id=sp.colaborador_id
            WHERE sp.kam_id=? AND sp.activo=1 AND u.id IS NULL",[$kam]);
        return ['colaboradores'=>array_merge($staff,$noElegibles,$noDisponibles),'estado'=>$this->estado(),'puede_gestionar'=>$this->permitido($actor,self::PERMISO)];
    }
    private function kam(int $id): void
    {
        if(!$this->rows('SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.rol_id=6 AND u.estado=1',[$id]))
            throw new InvalidArgumentException('Selecciona una Cuenta Clave activa.');
    }
    public static function normalizar($ids): array
    {
        if(!is_array($ids)||count($ids)>1000)throw new InvalidArgumentException('La selección de colaboradores no es válida.');
        $out=[];foreach($ids as $id){
            if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id))throw new InvalidArgumentException('La selección de colaboradores no es válida.');
            $out[(int)$id]=(int)$id;
        }
        sort($out,SORT_NUMERIC);return array_values($out);
    }
    private function actuales(int $kam): array
    {
        return array_map('intval',array_column($this->rows('SELECT colaborador_id FROM supervision_personal WHERE kam_id=? AND activo=1 ORDER BY colaborador_id',[$kam]),'colaborador_id'));
    }
    private function plan(int $actor,int $kam,array $ids,int $revision,string $secreto): array
    {
        $this->autorizar($actor,self::VER);$this->autorizar($actor);$this->kam($kam);$ids=self::normalizar($ids);$previos=$this->actuales($kam);
        $agregar=array_values(array_diff($ids,$previos));$retirar=array_values(array_diff($previos,$ids));
        if($agregar){
            $marks=implode(',',array_fill(0,count($agregar),'?'));
            $validos=array_map('intval',array_column($this->rows("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id IN ($marks) AND u.rol_id IN (3,4) AND u.estado=1",$agregar),'id'));
            if(array_diff($agregar,$validos))throw new InvalidArgumentException('Sólo puedes agregar analistas o asesores activos.');
        }
        if(strlen($secreto)<16)throw new RuntimeException('La sesión de seguridad cambió. Recarga la página.');
        $token=hash_hmac('sha256',json_encode([$actor,$kam,$ids,$revision]),$secreto);
        $nombres=[];$todos=array_values(array_unique(array_merge($agregar,$retirar)));
        if($todos)$nombres=array_column($this->rows('SELECT id,TRIM(CONCAT_WS(\' \',nombre,apellidos)) nombre FROM usuarios WHERE id IN ('.implode(',',array_fill(0,count($todos),'?')).')',$todos),'nombre','id');
        return ['kam_id'=>$kam,'seleccion'=>$ids,'agregar'=>$agregar,'retirar'=>$retirar,'previos'=>$previos,
            'nombres'=>$nombres,'revision'=>$revision,'confirmacion'=>$token];
    }
    public function previsualizar(int $actor,int $kam,$seleccion,int $revision,string $secreto): array
    {
        $ids=self::normalizar($seleccion);$this->autorizar($actor,self::VER);$this->autorizar($actor);
        if(!$this->estado()['activo'])throw new DomainException('La supervisión está preparada, pendiente de activación autorizada.');
        if($revision!==$this->estado()['revision'])throw new DomainException('Las asignaciones cambiaron. Actualiza antes de continuar.');
        return $this->plan($actor,$kam,$ids,$revision,$secreto);
    }
    public function guardar(int $actor,int $kam,$seleccion,int $revision,string $token,string $secreto): array
    {
        $ids=self::normalizar($seleccion);$this->autorizar($actor,self::VER);$this->autorizar($actor);
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Revisa y confirma los cambios antes de guardar.');
        $this->db->begin_transaction();
        try {
            $state=$this->rows('SELECT revision,activo FROM supervision_personal_estado WHERE id=1 FOR UPDATE');
            $this->autorizar($actor,self::VER);$this->autorizar($actor);
            $prev=$this->rows('SELECT id FROM supervision_personal_bitacora WHERE solicitud_id=? AND responsable_id=? AND kam_id=?',[$token,$actor,$kam]);
            if($prev){$this->db->commit();return ['revision'=>(int)$state[0]['revision'],'idempotente'=>true];}
            if(!$state || !(int)$state[0]['activo'])throw new DomainException('La supervisión no está activa. No se guardaron cambios.');
            if((int)$state[0]['revision']!==$revision)throw new DomainException('Otro administrador cambió las asignaciones. Actualiza y revisa de nuevo.');
            $p=$this->plan($actor,$kam,$ids,$revision,$secreto);
            if(!hash_equals($p['confirmacion'],$token))throw new InvalidArgumentException('La confirmación no corresponde a estos cambios.');
            if(!$p['agregar']&&!$p['retirar']){$this->db->commit();return ['revision'=>$revision,'sin_cambios'=>true];}
            foreach($p['agregar'] as $id)$this->exec("INSERT INTO supervision_personal(kam_id,colaborador_id,activo,origen,actualizado_por,creado_at,actualizado_at) VALUES(?,?,1,'MANUAL',?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE activo=1,origen='MANUAL',actualizado_por=VALUES(actualizado_por),actualizado_at=UTC_TIMESTAMP()",[$kam,$id,$actor]);
            foreach($p['retirar'] as $id)$this->exec('UPDATE supervision_personal SET activo=0,actualizado_por=?,actualizado_at=UTC_TIMESTAMP() WHERE kam_id=? AND colaborador_id=?',[$actor,$kam,$id]);
            $responsable=$this->rows("SELECT TRIM(CONCAT_WS(' ',nombre,apellidos)) nombre FROM usuarios WHERE id=?",[$actor])[0]['nombre'];
            $cuenta=$this->rows("SELECT TRIM(CONCAT_WS(' ',nombre,apellidos)) nombre FROM usuarios WHERE id=?",[$kam])[0]['nombre'];
            $this->exec("INSERT INTO supervision_personal_bitacora(responsable_id,kam_id,tipo,agregados_json,retirados_json,antes_json,despues_json,solicitud_id,responsable_nombre,kam_nombre,nombres_json,creado_at) VALUES(?,?,'MANUAL',?,?,?,?,?,?,?,?,UTC_TIMESTAMP())",[$actor,$kam,json_encode($p['agregar']),json_encode($p['retirar']),json_encode($p['previos']),json_encode($ids),$token,$responsable,$cuenta,json_encode($p['nombres'],JSON_UNESCAPED_UNICODE)]);
            $this->exec('UPDATE supervision_personal_estado SET revision=revision+1 WHERE id=1');
            $this->db->commit();return ['revision'=>$revision+1,'agregados'=>count($p['agregar']),'retirados'=>count($p['retirar'])];
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function bitacora(int $actor,int $kam,int $pagina=1): array
    {
        $this->autorizar($actor,self::VER);$this->autorizar($actor,self::BITACORA);if($kam>0)$this->kam($kam);$pagina=max(1,min(100000,$pagina));$offset=($pagina-1)*30;
        return $this->rows("SELECT b.id,b.tipo,b.creado_at,b.agregados_json,b.retirados_json,b.responsable_id,
            b.responsable_nombre responsable,b.kam_nombre,b.nombres_json
            FROM supervision_personal_bitacora b ".($kam>0?'WHERE b.kam_id=?':'')." ORDER BY b.id DESC LIMIT 30 OFFSET $offset",$kam>0?[$kam]:[]);
    }
}
