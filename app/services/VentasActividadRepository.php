<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
require_once __DIR__.'/TelefoniaVentasEvidenciaService.php';
require_once __DIR__.'/TelefoniaMarcacionesVentasService.php';

/** Lectura sin conciliaciones, escrituras, descargas ni consultas al proveedor. */
final class VentasActividadRepository
{
    private mysqli $db;
    public function __construct(?mysqli $db = null) { $this->db = $db ?? (new Database())->connect(); }
    private function rows(string $sql, array $args = []): array
    {
        $s = $this->db->prepare($sql);
        if ($args) $s->bind_param(str_repeat('s',count($args)), ...$args);
        $s->execute(); $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
    }
    private function tabla(string $name): bool
    {
        return (bool)$this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$name]);
    }
    private static function vigente(string $a): string
    {
        return "$a.activo=1 AND ($a.fecha_inicio IS NULL OR $a.fecha_inicio<=CURDATE()) AND ($a.fecha_fin IS NULL OR $a.fecha_fin>=CURDATE())";
    }
    public function personas(string $scope, int $uid, int $estadoId = 0): array
    {
        if (!in_array($scope,['propio','equipo','global'],true) || $uid<=0) throw new DomainException('Alcance no autorizado.');
        $where = 'u.rol_id=3'; $args = [];
        if ($scope==='propio') { $where .= ' AND u.id=?'; $args[]=$uid; }
        if ($scope==='equipo') {
            // Exigir la relación explícita con SU Cuenta Clave, no compartir estado solamente.
            $where .= ' AND EXISTS(SELECT 1 FROM asignaciones_territorio a JOIN asignaciones_territorio c
                ON c.id=a.cuenta_clave_asignacion_id AND c.estado_id=a.estado_id
                JOIN estados e ON e.id=a.estado_id AND e.estado=1
                WHERE a.usuario_id=u.id AND a.tipo_asignacion=\'ASESOR\' AND c.tipo_asignacion=\'CUENTA_CLAVE\'
                AND c.usuario_id=? AND '.self::vigente('a').' AND '.self::vigente('c').($estadoId>0?' AND a.estado_id='.(int)$estadoId:'').')';
            $args[]=$uid;
        }
        if ($estadoId>0 && $scope!=='equipo') {
            $where .= " AND EXISTS(SELECT 1 FROM asignaciones_territorio a WHERE a.usuario_id=u.id
                AND a.tipo_asignacion='ASESOR' AND a.estado_id=? AND ".self::vigente('a').')';
            $args[]=$estadoId;
        }
        return $this->rows("SELECT u.id,CONCAT_WS(' ',u.nombre,u.apellidos) nombre_completo,u.foto_perfil,u.estado activo
            FROM usuarios u WHERE $where ORDER BY u.nombre,u.apellidos,u.id",$args);
    }
    /** Territorios vigentes del alcance; la Dirección no necesita asignaciones. */
    public function territorios(string $scope, int $uid): array
    {
        if (!in_array($scope,['propio','equipo','global'],true) || $uid<=0) throw new DomainException('Alcance no autorizado.');
        if ($scope==='global') return $this->rows('SELECT id,nombre FROM estados WHERE estado=1 ORDER BY nombre');
        $join='';$condition='a.usuario_id=?';
        if ($scope==='equipo') {
            $join=" JOIN asignaciones_territorio c ON c.id=a.cuenta_clave_asignacion_id AND c.estado_id=a.estado_id";
            $condition="c.usuario_id=? AND c.tipo_asignacion='CUENTA_CLAVE' AND ".self::vigente('c');
        }
        return $this->rows("SELECT DISTINCT e.id,e.nombre FROM estados e JOIN asignaciones_territorio a ON a.estado_id=e.id
            $join WHERE e.estado=1 AND a.tipo_asignacion='ASESOR' AND ".self::vigente('a')." AND $condition ORDER BY e.nombre",[$uid]);
    }
    public function llamadas(array $ids, array $p): array
    {
        if (!$ids) return [];
        foreach (['telefonia_ventas_llamadas','telefonia_ventas_resultados','telefonia_zadarma_eventos'] as $table)
            if (!$this->tabla($table)) throw new RuntimeException('Falta la estructura de llamadas necesaria para generar el reporte.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $from=$p['fecha_desde'].' 00:00:00'; $until=$p['fecha_hasta'].' 23:59:59';
        $rows=$this->rows("SELECT c.*,r.id registro_id,r.resultado,r.observacion,r.prospecto_nombre
            FROM telefonia_ventas_llamadas c LEFT JOIN telefonia_ventas_resultados r
              ON r.usuario_id=c.usuario_id AND r.extension=BINARY c.extension AND r.pbx_call_id=BINARY c.pbx_call_id
            WHERE c.usuario_id IN ($marks) AND c.fecha BETWEEN ? AND ? ORDER BY c.fecha DESC,c.id DESC LIMIT 20001",array_merge($ids,[$from,$until]));
        if (count($rows)>20000) throw new InvalidArgumentException('El reporte supera 20,000 llamadas. Reduce el periodo o selecciona un asesor.');
        $byUser=[];
        foreach ($rows as $r) $byUser[(int)$r['usuario_id']][]=$r;
        $all=[];
        foreach ($byUser as $uid=>$calls) foreach (array_chunk($calls,500) as $chunk) {
            $refs=array_values(array_unique(array_column($chunk,'pbx_call_id')));
            $events=$this->rows("SELECT e.*,CASE WHEN JSON_VALID(e.payload_json) THEN JSON_CONTAINS_PATH(e.payload_json,'one','$.duration') ELSE 0 END has_duration,
                CASE WHEN JSON_VALID(e.payload_json) THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.transfer_from')) ELSE NULL END transfer_from,
                CASE WHEN JSON_VALID(e.payload_json) THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal')) ELSE NULL END last_internal
                FROM telefonia_zadarma_eventos e WHERE pbx_call_id IN (".implode(',',array_fill(0,count($refs),'?')).') ORDER BY id',$refs);
            $grouped=[]; foreach ($events as $event) $grouped[$event['pbx_call_id']][]=$event;
            foreach ($chunk as &$r) {
                $r+=TelefoniaVentasLlamadasService::hechos($grouped[$r['pbx_call_id']]??[],$r['extension'],(string)($r['origen_extension']??''));
                $r['registro']=$r['registro_id']===null?null:['resultado'=>$r['resultado'],'observacion'=>$r['observacion'],'prospecto_nombre'=>$r['prospecto_nombre']];
                unset($r['registro_id'],$r['resultado'],$r['observacion'],$r['prospecto_nombre']);
            }
            unset($r);
            $all=array_merge($all,(new TelefoniaVentasEvidenciaService($this->db))->enriquecer($chunk,(int)$uid));
        }
        $zone=new DateTimeZone('America/Mexico_City');$utc=new DateTimeZone('UTC');
        $utcFrom=(new DateTimeImmutable($from,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $utcUntil=(new DateTimeImmutable($until,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        if ($this->tabla('telefonia_ventas_marcaciones')) {
            $marker=new TelefoniaMarcacionesVentasService($this->db,false);
            $attempts=$this->rows("SELECT * FROM telefonia_ventas_marcaciones WHERE usuario_id IN ($marks) AND pbx_call_id IS NULL AND created_at BETWEEN ? AND ? ORDER BY id LIMIT 20001",array_merge($ids,[$marker->fechaMarcacionMySQL($from),$marker->fechaMarcacionMySQL($until)]));
            foreach ($attempts as $a) $all[]=self::pendiente('intento-'.$a['id'],(int)$a['usuario_id'],$a['extension'],$a['destino'],
                $marker->fechaMarcacionLocal($a['created_at']),'Saliente');
        }
        if ($this->tabla('telefonia_transferencia_operaciones') && $this->tabla('telefonia_disponibilidad')) {
            $ops=$this->rows("SELECT o.*,d.ruta_json FROM telefonia_transferencia_operaciones o JOIN telefonia_disponibilidad d
                ON d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario AND d.referencia=BINARY o.referencia AND d.destino_extension=BINARY o.destino_extension
                WHERE o.destino_usuario IN ($marks) AND o.created_at BETWEEN ? AND ? AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas c
                    WHERE c.usuario_id=o.destino_usuario AND c.extension=BINARY o.destino_extension AND c.pbx_call_id=BINARY o.referencia) LIMIT 20001",array_merge($ids,[$utcFrom,$utcUntil]));
            foreach ($ops as $o) {
                $route=json_decode($o['ruta_json'],true)?:[];
                if (($route['tipo']??'')!=='PROSPECTO' || (int)($route['responsable_id']??0)!==(int)$o['destino_usuario'] ||
                    ($route['extension']??'')!==$o['destino_extension'] || ($route['referencia_llamada']??'')!==$o['referencia']) continue;
                $all[]=self::pendiente('transferencia-'.$o['id'],(int)$o['destino_usuario'],$o['destino_extension'],$o['numero'],
                    (new DateTimeImmutable($o['created_at'],$utc))->setTimezone($zone)->format('Y-m-d H:i:s'),'Entrante');
            }
        }
        if (count($all)>20000) throw new InvalidArgumentException('Reduce el periodo o selecciona un asesor; el reporte supera 20,000 registros.');
        usort($all,static fn($a,$b)=>strcmp($b['fecha'],$a['fecha']));return $all;
    }
    public function organizacion(array $ids): array
    {
        $out=['programadas'=>0,'vencidas'=>0,'devoluciones'=>0,'disponible'=>true];
        if (!$ids) return $out;
        if (!$this->tabla('telefonia_contactos_personales') || !$this->tabla('telefonia_ventas_resultados') || !$this->tabla('telefonia_disponibilidad')) {
            $out['disponible']=false; return $out;
        }
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $scheduled=$this->rows("SELECT a.proxima_accion_at fecha FROM telefonia_contactos_personales a WHERE a.usuario_id IN ($marks)
            AND a.proxima_accion_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_resultados r WHERE r.usuario_id=a.usuario_id
                AND r.contacto_id=a.id AND r.proxima_accion_at=a.proxima_accion_at AND r.recordatorio_atendido_at IS NULL)
            UNION ALL SELECT r.proxima_accion_at fecha FROM telefonia_ventas_resultados r JOIN telefonia_ventas_llamadas c
                ON c.usuario_id=r.usuario_id AND c.extension=BINARY r.extension AND c.pbx_call_id=BINARY r.pbx_call_id
            WHERE r.usuario_id IN ($marks) AND r.proxima_accion_at IS NOT NULL AND r.recordatorio_atendido_at IS NULL",array_merge($ids,$ids));
        $now=(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
        foreach ($scheduled as $s) { $out['programadas']++; if ($s['fecha']<$now) $out['vencidas']++; }
        foreach ($this->rows("SELECT destino_usuario,destino_extension,referencia,ruta_json FROM telefonia_disponibilidad WHERE destino_usuario IN ($marks) AND devolucion='PENDIENTE'",$ids) as $r) {
            $route=json_decode($r['ruta_json'],true)?:[];
            if (($route['tipo']??'')==='PROSPECTO' && (int)($route['responsable_id']??0)===(int)$r['destino_usuario'] &&
                ($route['extension']??'')===$r['destino_extension'] && ($route['referencia_llamada']??'')===$r['referencia']) $out['devoluciones']++;
        }
        return $out;
    }
    private static function pendiente(string $id,int $uid,string $ext,string $number,string $date,string $direction): array
    {
        return ['id'=>$id,'usuario_id'=>$uid,'extension'=>$ext,'numero'=>$number,'fecha'=>$date,'direccion'=>$direction,
            'pbx_call_id'=>'','registro'=>null,'segundos'=>null,'conectada'=>false,'finalizada'=>false,'nombre'=>'',
            'origen_extension'=>'','solicitud_id'=>null];
    }
}
