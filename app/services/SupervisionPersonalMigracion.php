<?php
declare(strict_types=1);
require_once __DIR__.'/SupervisionPersonalAuditoria.php';
require_once __DIR__.'/SupervisionPersonalRepository.php';
require_once __DIR__.'/SupervisionPersonalAcceso.php';

/** Sólo CLI. Preparar no activa; revertir conserva relaciones y bitácora. */
final class SupervisionPersonalMigracion
{
    public function __construct(private mysqli $db) {}
    private function lock(): void
    {
        if((int)$this->db->query("SELECT GET_LOCK('impe_supervision_personal_v1',5) ok")->fetch_assoc()['ok']!==1)
            throw new RuntimeException('Hay otra preparación en curso.');
    }
    private function unlock(): void {$this->db->query("SELECT RELEASE_LOCK('impe_supervision_personal_v1')");}
    public function preparar(array $plan,callable $respaldo,int $actor=0): void
    {
        SupervisionPersonalAuditoria::verificarPlan($plan);
        $q=$this->db->prepare('SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.estado=1 AND u.rol_id=1');$q->bind_param('i',$actor);$q->execute();$operador=$q->get_result()->fetch_assoc();$q->close();
        if(!$operador)throw new DomainException('La preparación requiere identificar a un administrador activo.',403);
        $this->lock();
        try {
            $actual=SupervisionPersonalAuditoria::leer($this->db);
            if(!hash_equals((string)($plan['plan_hash']??''),$actual['plan_hash']))throw new DomainException('Las asignaciones cambiaron: genera y revisa una auditoría nueva.');
            $type=$this->db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='id'")->fetch_assoc()['COLUMN_TYPE']??'';
            if(!preg_match('/^(?:bigint|int)(?:\([0-9]+\))?(?: unsigned)?$/D',$type))throw new RuntimeException('Tipo de identificador no compatible; revisar antes de migrar.');
            $existe=(int)$this->db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('supervision_personal','supervision_personal_estado','supervision_personal_bitacora')")->fetch_assoc()['n'];
            if($existe){
                if($existe!==3)throw new DomainException('Hay una preparación parcial. Conserva el respaldo y revisa; no se modificó.');
                $row=$this->db->query('SELECT plan_hash,version_schema FROM supervision_personal_estado WHERE id=1')->fetch_assoc();
                if($row && (int)$row['version_schema']===1 && hash_equals($row['plan_hash'],$plan['plan_hash']))return;
                throw new DomainException('Ya existe otra preparación. No se sobrescribe.');
            }
            $permisos=$this->db->query("SELECT p.*,rp.rol_id FROM permisos p LEFT JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE p.codigo='personal.supervision.gestionar'")->fetch_all(MYSQLI_ASSOC);
            if($permisos)throw new DomainException('El permiso ya existe sin su preparación. Revisa su procedencia.');
            $respaldo(['plan'=>$plan,'permiso_previo'=>$permisos,'tablas_previas'=>[]]);
            // DDL separado de la transacción de datos: MySQL hace COMMIT implícito.
            $this->db->query("CREATE TABLE supervision_personal_estado(id TINYINT PRIMARY KEY,activo TINYINT NOT NULL DEFAULT 0,
                revision BIGINT UNSIGNED NOT NULL DEFAULT 0,version_schema INT NOT NULL,plan_hash CHAR(64) NOT NULL,preparado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->query("CREATE TABLE supervision_personal(kam_id $type NOT NULL,colaborador_id $type NOT NULL,activo TINYINT NOT NULL DEFAULT 1,
                origen VARCHAR(16) NOT NULL,actualizado_por $type NULL,creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(kam_id,colaborador_id),KEY idx_sp_colaborador(colaborador_id,activo),KEY idx_sp_kam(kam_id,activo))
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->query("CREATE TABLE supervision_personal_bitacora(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,responsable_id $type NULL,kam_id $type NOT NULL,
                tipo VARCHAR(24) NOT NULL,agregados_json LONGTEXT NOT NULL,retirados_json LONGTEXT NOT NULL,antes_json LONGTEXT NOT NULL,despues_json LONGTEXT NOT NULL,
                solicitud_id CHAR(64) NOT NULL,responsable_nombre VARCHAR(200) NOT NULL,kam_nombre VARCHAR(200) NOT NULL,nombres_json LONGTEXT NOT NULL,
                creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_sp_solicitud(solicitud_id),KEY idx_sp_bitacora(kam_id,id))
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->begin_transaction();
            try {
                foreach($plan['relaciones'] as $p){
                    $s=$this->db->prepare("INSERT INTO supervision_personal(kam_id,colaborador_id,origen,creado_at,actualizado_at) VALUES(?,?,'MIGRACION',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->bind_param('ii',$p['kam_id'],$p['colaborador_id']);$s->execute();$s->close();
                }
                $grupos=[];foreach($plan['relaciones'] as $p)$grupos[$p['kam_id']][]=$p['colaborador_id'];
                foreach($grupos as $kam=>$ids)$this->registrarRelaciones($actor,(int)$kam,[],$ids,'MIGRACION',hash('sha256','seed:'.$plan['plan_hash'].':'.$kam));
                $s=$this->db->prepare('INSERT INTO supervision_personal_estado(id,activo,version_schema,plan_hash,preparado_at) VALUES(1,0,1,?,UTC_TIMESTAMP())');$s->bind_param('s',$plan['plan_hash']);$s->execute();$s->close();
                $this->db->query("INSERT INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES('Supervisión de personal','personal.supervision.gestionar','Administrar supervisión de personal','Administrar colaboradores supervisados y consultar su bitácora, sin alterar territorios.',1)");
                $this->db->query("INSERT INTO rol_permisos(rol_id,permiso_id) SELECT 1,id FROM permisos WHERE codigo='personal.supervision.gestionar'");
                require_once __DIR__.'/SupervisionPersonalPermisos.php';
                SupervisionPersonalPermisos::agregarNuevos($this->db);
                $this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}
        }finally{$this->unlock();}
    }
    public function activar(int $actor,array $plan,array $decisiones,string $planAutorizado): void
    {
        (new SupervisionPersonalRepository($this->db))->autorizarAdministrador($actor);
        $revisionAutorizada=SupervisionPersonalAuditoria::hashRevision($plan,$decisiones);
        if(!hash_equals($revisionAutorizada,$planAutorizado))throw new DomainException('Falta autorizar expresamente el plan y sus decisiones revisadas.');
        $pares=SupervisionPersonalAuditoria::resolver($plan,$decisiones);
        $this->lock();$this->db->begin_transaction();
        try {
            $state=$this->db->query('SELECT * FROM supervision_personal_estado WHERE id=1 FOR UPDATE')->fetch_assoc();
            if(!$state||!hash_equals($state['plan_hash'],$plan['plan_hash']))throw new DomainException('La preparación no corresponde al plan.');
            if((int)$state['activo']){$this->db->commit();return;}
            if((int)$state['revision']!==0)throw new DomainException('Ya hubo cambios administrativos o una reversión. Revisa las asignaciones actuales antes de reactivar.');
            $actual=SupervisionPersonalAuditoria::leer($this->db);
            if(!hash_equals($actual['plan_hash'],$plan['plan_hash']))throw new DomainException('La auditoría ya no corresponde a las asignaciones actuales.');
            $importadas=$this->db->query('SELECT kam_id,colaborador_id FROM supervision_personal WHERE activo=1 ORDER BY kam_id,colaborador_id')->fetch_all(MYSQLI_ASSOC);
            $importadas=array_map(static fn($p)=>['kam_id'=>(int)$p['kam_id'],'colaborador_id'=>(int)$p['colaborador_id']],$importadas);
            if($importadas!==$plan['relaciones'])throw new DomainException('La preparación fue modificada fuera del procedimiento. Revisa antes de activar.');
            $grupos=[];foreach($pares as $p)$grupos[$p['kam_id']][]=$p['colaborador_id'];
            foreach($pares as $p){
                $s=$this->db->prepare("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE (u.id=? AND u.rol_id=6 AND u.estado=1) OR (u.id=? AND u.rol_id IN(3,4))");$s->bind_param('ii',$p['kam_id'],$p['colaborador_id']);$s->execute();$n=$s->get_result()->num_rows;$s->close();
                if($n!==2)throw new DomainException('Una relación revisada contiene roles no elegibles. No se activa.');
                $s=$this->db->prepare("INSERT IGNORE INTO supervision_personal(kam_id,colaborador_id,origen,actualizado_por,creado_at,actualizado_at) VALUES(?,?,'MIGRACION',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->bind_param('iii',$p['kam_id'],$p['colaborador_id'],$actor);$s->execute();$s->close();
            }
            foreach($grupos as $kam=>$ids){
                $previos=array_column(array_filter($plan['relaciones'],static fn($p)=>(int)$p['kam_id']===(int)$kam),'colaborador_id');
                if(array_diff($ids,$previos))$this->registrarRelaciones($actor,(int)$kam,$previos,$ids,'AMPLIACION_REVISADA',hash('sha256','revision:'.$plan['plan_hash'].':'.$kam));
            }
            $this->db->query('UPDATE supervision_personal_estado SET activo=1,revision=revision+1 WHERE id=1');
            $this->registrarEstado($actor,'ACTIVACION',$planAutorizado,$decisiones);
            $this->db->commit();
            SupervisionPersonalAcceso::invalidar($this->db);
        }catch(Throwable $e){$this->db->rollback();throw $e;}finally{$this->unlock();}
    }
    public function desactivar(int $actor): void
    {
        (new SupervisionPersonalRepository($this->db))->autorizarAdministrador($actor);
        $this->db->begin_transaction();
        try {
            $r=$this->db->query('SELECT activo FROM supervision_personal_estado WHERE id=1 FOR UPDATE')->fetch_assoc();
            if($r && (int)$r['activo']){
                $this->db->query('UPDATE supervision_personal_estado SET activo=0,revision=revision+1 WHERE id=1');
                $this->registrarEstado($actor,'REVERSION',bin2hex(random_bytes(32)),[]);
            }
            $this->db->commit();
            SupervisionPersonalAcceso::invalidar($this->db);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    private function registrarEstado(int $actor,string $tipo,string $token,array $decisiones): void
    {
        $q=$this->db->prepare("SELECT TRIM(CONCAT_WS(' ',nombre,apellidos)) nombre FROM usuarios WHERE id=?");$q->bind_param('i',$actor);$q->execute();$nombre=$q->get_result()->fetch_assoc()['nombre'];$q->close();
        $s=$this->db->prepare("INSERT INTO supervision_personal_bitacora(responsable_id,kam_id,tipo,agregados_json,retirados_json,antes_json,despues_json,solicitud_id,responsable_nombre,kam_nombre,nombres_json,creado_at) VALUES(?,0,?,'[]','[]','[]',?,?,?,'Sistema','{}',UTC_TIMESTAMP())");
        $json=json_encode($decisiones,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$s->bind_param('issss',$actor,$tipo,$json,$token,$nombre);$s->execute();$s->close();
    }
    private function registrarRelaciones(int $actor,int $kam,array $antes,array $despues,string $tipo,string $token):void
    {
        $ids=array_values(array_unique(array_merge([$actor,$kam],$antes,$despues)));$marks=implode(',',array_fill(0,count($ids),'?'));
        $s=$this->db->prepare("SELECT id,TRIM(CONCAT_WS(' ',nombre,apellidos)) nombre FROM usuarios WHERE id IN ($marks)");$s->bind_param(str_repeat('i',count($ids)),...$ids);$s->execute();$names=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'nombre','id');$s->close();
        $sql="INSERT INTO supervision_personal_bitacora(responsable_id,kam_id,tipo,agregados_json,retirados_json,antes_json,despues_json,solicitud_id,responsable_nombre,kam_nombre,nombres_json,creado_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())";
        $args=[$actor,$kam,$tipo,json_encode(array_values(array_diff($despues,$antes))),'[]',json_encode($antes),json_encode($despues),$token,$names[$actor]??'#'.$actor,$names[$kam]??'#'.$kam,json_encode($names,JSON_UNESCAPED_UNICODE)];
        $s=$this->db->prepare($sql);$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$s->close();
    }
}
