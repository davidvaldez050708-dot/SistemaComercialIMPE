<?php
declare(strict_types=1);

/** Plan de lectura reproducible. Nunca repara asignaciones operativas. */
final class SupervisionPersonalAuditoria
{
    public static function leer(mysqli $db): array
    {
        $read=static fn(string $sql)=>$db->query($sql)->fetch_all(MYSQLI_ASSOC);
        return self::analizar([
            'fecha'=>$db->query('SELECT CURDATE() fecha')->fetch_assoc()['fecha'],
            'usuarios'=>$read('SELECT id,rol_id,estado FROM usuarios ORDER BY id'),
            'roles'=>$read('SELECT id,estado FROM roles ORDER BY id'),
            'estados'=>$read('SELECT id,estado FROM estados ORDER BY id'),
            'asignaciones'=>$read('SELECT id,usuario_id,estado_id,tipo_asignacion,cuenta_clave_asignacion_id,activo,fecha_inicio,fecha_fin FROM asignaciones_territorio ORDER BY id'),
            'autores_por_estado'=>$read("SELECT DISTINCT s.estado_id,i.usuario_id FROM interacciones_vinculacion i
                JOIN seguimientos_vinculacion s ON s.id=i.seguimiento_id AND s.activo=1
                WHERE UPPER(TRIM(COALESCE(i.canal,'')))<>'SISTEMA' ORDER BY s.estado_id,i.usuario_id"),
            'responsables_por_estado'=>$read('SELECT DISTINCT estado_id,analista_id usuario_id FROM seguimientos_vinculacion WHERE activo=1 AND analista_id IS NOT NULL ORDER BY estado_id,analista_id'),
        ]);
    }
    public static function analizar(array $datos): array
    {
        $usuarios=array_column($datos['usuarios'],null,'id');$roles=array_column($datos['roles'],null,'id');
        $estados=array_column($datos['estados'],null,'id');$asignaciones=array_column($datos['asignaciones'],null,'id');
        $fecha=(string)$datos['fecha'];$pares=[];$problemas=[];$estadosKam=[];
        $vigente=static fn($a)=>(int)($a['activo']??0)===1 && (empty($a['fecha_inicio'])||substr($a['fecha_inicio'],0,10)<=$fecha) && (empty($a['fecha_fin'])||substr($a['fecha_fin'],0,10)>=$fecha);
        $issue=static function(string $id,string $motivo,int $kam=0,int $col=0)use(&$problemas){$problemas[$id]=['id'=>$id,'motivo'=>$motivo,'kam_id'=>$kam,'colaborador_id'=>$col];};
        foreach($asignaciones as $a){
            if(!$vigente($a)||!in_array($a['tipo_asignacion'],['ANALISTA_DATOS','ASESOR'],true))continue;
            $c=$asignaciones[$a['cuenta_clave_asignacion_id']??0]??null;
            $uid=(int)$a['usuario_id'];$kam=(int)($c['usuario_id']??0);$rol=$a['tipo_asignacion']==='ASESOR'?3:4;
            if(!$c){if((int)($a['cuenta_clave_asignacion_id']??0)>0)$issue('asignacion:'.$a['id'],'Relación operativa sin Cuenta Clave identificable.',0,$uid);continue;}
            if(!$vigente($c))continue;
            if(($c['tipo_asignacion']??'')!=='CUENTA_CLAVE'||(int)$a['estado_id']!==(int)$c['estado_id']){
                $issue('asignacion:'.$a['id'],'La relación operativa no coincide con su Cuenta Clave o estado.',$kam,$uid);continue;
            }
            if((int)($usuarios[$uid]['rol_id']??0)!==$rol || (int)($usuarios[$kam]['rol_id']??0)!==6 ||
                !(int)($roles[$rol]['estado']??0)||!(int)($roles[6]['estado']??0)){
                $issue('asignacion:'.$a['id'],'Rol incompatible o deshabilitado en la relación operativa.',$kam,$uid);continue;
            }
            if(!(int)($usuarios[$kam]['estado']??0))continue;
            if(!(int)($estados[$a['estado_id']]['estado']??0)){
                $issue('asignacion:'.$a['id'],'Estado inactivo: el alcance heredado no coincide entre módulos.',$kam,$uid);continue;
            }
            $pares[$kam.':'.$uid]=['kam_id'=>$kam,'colaborador_id'=>$uid];
            if($rol===4 && (int)($usuarios[$uid]['estado']??0))$estadosKam[$kam][(int)$a['estado_id']]=true;
        }
        // Los reportes territoriales podían incluir otros autores del mismo estado.
        foreach($estadosKam as $kam=>$territorios)foreach(array_merge($datos['autores_por_estado']??[],$datos['responsables_por_estado']??[]) as $a){
            $uid=(int)$a['usuario_id'];
            if(!isset($territorios[(int)$a['estado_id']]) || isset($pares[$kam.':'.$uid]))continue;
            if(!in_array((int)($usuarios[$uid]['rol_id']??0),[3,4],true))continue;
            $issue('alcance:'.$kam.':'.$uid,'Acceso heredado por estado sin pertenencia explícita al equipo. No se migra sin decisión.',$kam,$uid);
        }
        ksort($pares,SORT_NATURAL);ksort($problemas,SORT_NATURAL);
        $snapshot=hash('sha256',json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $plan=['version'=>1,'fecha'=>$fecha,'snapshot_hash'=>$snapshot,'relaciones'=>array_values($pares),
            'requieren_revision'=>array_values($problemas),'resumen'=>['relaciones_explicitas'=>count($pares),'ambiguedades'=>count($problemas)]];
        $plan['plan_hash']=hash('sha256',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return $plan;
    }
    public static function resolver(array $plan,array $decisiones): array
    {
        self::verificarPlan($plan);
        $pares=[];foreach($plan['relaciones'] as $p)$pares[$p['kam_id'].':'.$p['colaborador_id']]=$p;
        $esperados=array_column($plan['requieren_revision'],'id');
        if(array_diff(array_keys($decisiones),$esperados))throw new InvalidArgumentException('La revisión contiene decisiones ajenas al plan.');
        foreach($plan['requieren_revision'] as $issue){
            $d=$decisiones[$issue['id']]??null;
            if(!is_array($d)||!in_array($d['accion']??'',['incluir','excluir','conservar_sin_migrar'],true)||mb_strlen(trim((string)($d['motivo']??'')))<10)
                throw new DomainException('Falta una decisión explícita y motivada para '.$issue['id']);
            if($d['accion']==='incluir'){
                if($issue['kam_id']<=0||$issue['colaborador_id']<=0)throw new DomainException('La relación no puede incluirse sin identificar a las dos personas.');
                $pares[$issue['kam_id'].':'.$issue['colaborador_id']]=['kam_id'=>$issue['kam_id'],'colaborador_id'=>$issue['colaborador_id']];
            }
        }
        ksort($pares,SORT_NATURAL);return array_values($pares);
    }
    public static function verificarPlan(array $plan): void
    {
        $hash=$plan['plan_hash']??'';$payload=$plan;unset($payload['plan_hash']);
        if(($plan['version']??0)!==1||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||
            !hash_equals($hash,hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))))
            throw new DomainException('El plan fue alterado o no corresponde a esta versión.');
    }
    public static function hashRevision(array $plan,array $decisiones): string
    {
        self::resolver($plan,$decisiones);ksort($decisiones,SORT_NATURAL);
        return hash('sha256',json_encode([$plan['plan_hash'],$decisiones],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}
