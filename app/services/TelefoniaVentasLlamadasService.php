<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';

/** Identidad histórica de tramos comerciales; sólo consume eventos PBX verificados. */
final class TelefoniaVentasLlamadasService
{
    private mysqli $db;
    public function __construct(?mysqli $db=null) {$this->db=$db??(new Database())->connect();}
    private function rows(string $sql,array $args=[]): array {
        $s=$this->db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    public function disponible(): bool {
        return $this->db->query("SHOW TABLES LIKE 'telefonia_ventas_llamadas'")->num_rows>0;
    }
    public function requerir(): void {
        if(!$this->disponible())throw new DomainException('Falta aplicar la migración de telefonía comercial. Solicita su publicación al administrador.');
    }
    public function autorizar(int $uid): void {
        if(!$this->rows("SELECT u.id FROM usuarios u WHERE u.id=? AND u.estado=1 AND u.rol_id=3
            AND EXISTS (SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
                WHERE rp.rol_id=u.rol_id AND p.codigo='telefonia.usar' AND p.estado=1)",[$uid]))
            throw new DomainException('No tienes acceso a la atención comercial.');
    }
    /** Llamado DESPUÉS de guardar el webhook; no crea otro evento ni toca SIP. */
    public function capturarEvento(array $event): void {
        if(!$this->disponible())return;
        $type=$event['event']??$event['evento']??'';
        if(!in_array($type,['NOTIFY_INTERNAL','NOTIFY_OUT_START','NOTIFY_ANSWER'],true))return;
        $ref=(string)($event['pbx_call_id']??'');$ext=(string)($event['internal']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref)||!preg_match('/^[0-9]{3,6}$/D',$ext))return;
        $incoming=$type!=='NOTIFY_OUT_START';$cap=$incoming?'permite_entrantes':'permite_salientes';
        $permission=$incoming?'telefonia.recibir':'telefonia.salientes';
        $owners=$this->rows("SELECT t.usuario_id,GREATEST(t.created_at,t.updated_at) desde
            FROM telefonia_extensiones t JOIN usuarios u ON u.id=t.usuario_id
            WHERE t.extension=? AND t.proveedor='ZADARMA' AND t.activo=1 AND t.$cap=1
              AND u.rol_id=3 AND u.estado=1 AND EXISTS (
                SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
                WHERE rp.rol_id=u.rol_id AND p.estado=1 AND p.codigo=?)
              AND EXISTS (SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
                WHERE rp.rol_id=u.rol_id AND p.estado=1 AND p.codigo='telefonia.usar')",[$ext,$permission]);
        if(count($owners)!==1)return;
        $date=(string)($event['received_at']??date('Y-m-d H:i:s'));
        $started=(string)($event['call_start']??$date);
        if($date<$owners[0]['desde']||($started!==''&&$started<$owners[0]['desde']))return;
        if($type==='NOTIFY_ANSWER'){
            $proof=$this->rows("SELECT ruta_json FROM telefonia_disponibilidad WHERE destino_usuario=? AND destino_extension=? AND referencia=? AND estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA') LIMIT 2",[$owners[0]['usuario_id'],$ext,$ref]);
            $route=count($proof)===1?(json_decode($proof[0]['ruta_json'],true)?:[]):[];
            if(($route['tipo']??'')!=='PROSPECTO'||(int)($route['responsable_id']??0)!==(int)$owners[0]['usuario_id'])return;
        }
        $this->insertar((int)$owners[0]['usuario_id'],$ext,$ref,$incoming?'Entrante':'Saliente',
            (string)($incoming?($event['caller_id']??''):($event['destination']??'')),$date);
    }
    private function insertar(int $uid,string $ext,string $ref,string $direction,string $number,string $date): void {
        $name='';$source='';$request=null;$state=null;
        if($direction==='Entrante') {
            $d=$this->rows("SELECT id,origen_extension,numero,ruta_json FROM telefonia_disponibilidad
                WHERE destino_usuario=? AND destino_extension=? AND referencia=?
                AND estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA') LIMIT 2",[$uid,$ext,$ref]);
            if(count($d)===1) {
                $route=json_decode($d[0]['ruta_json'],true)?:[];
                if(($route['tipo']??'')==='PROSPECTO'&&(int)($route['responsable_id']??0)===$uid&&
                    (string)($route['extension']??'')===$ext&&(string)($route['referencia_llamada']??'')===$ref) {
                    $name=mb_substr(trim((string)($route['institucion_nombre']??'')),0,150);
                    $source=$d[0]['origen_extension'];$request=$d[0]['id'];$state=(int)($route['estado_id']??0);
                    $number=$d[0]['numero'];
                }
            }
        }
        $s=$this->db->prepare('INSERT IGNORE INTO telefonia_ventas_llamadas
            (usuario_id,extension,pbx_call_id,direccion,numero,nombre,origen_extension,solicitud_id,estado_id,fecha)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $s->bind_param('issssssiis',$uid,$ext,$ref,$direction,$number,$name,$source,$request,$state,$date);$s->execute();$s->close();
        // Permitir metadata tardía sólo sobre el MISMO tramo y propietario.
        if($request!==null) {
            $s=$this->db->prepare("UPDATE telefonia_ventas_llamadas SET nombre=?,origen_extension=?,solicitud_id=?,estado_id=?,numero=?
                WHERE usuario_id=? AND extension=? AND pbx_call_id=? AND solicitud_id IS NULL");
            $s->bind_param('ssiisiss',$name,$source,$request,$state,$number,$uid,$ext,$ref);$s->execute();$s->close();
        }
    }
    /** Recupera sólo atribución persistida o inicios posteriores a la asignación actual. */
    public function sincronizar(int $uid): void {
        $this->autorizar($uid);$this->requerir();
        $rows=$this->rows("SELECT e.* FROM telefonia_zadarma_eventos e
            JOIN telefonia_extensiones t ON t.extension = BINARY e.internal AND t.usuario_id=? AND t.activo=1 AND t.proveedor='ZADARMA'
            WHERE e.evento IN ('NOTIFY_INTERNAL','NOTIFY_OUT_START') AND e.received_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)
              AND e.received_at>=GREATEST(t.created_at,t.updated_at)
              AND (e.call_start IS NULL OR e.call_start>=GREATEST(t.created_at,t.updated_at))
              AND NOT EXISTS (SELECT 1 FROM telefonia_ventas_llamadas c WHERE c.pbx_call_id = BINARY e.pbx_call_id AND c.extension = BINARY e.internal)
            ORDER BY e.id LIMIT 300",[$uid]);
        foreach($rows as $e)$this->capturarEvento($e);
        // Transferencias comerciales explícitas preservan el destinatario histórico,
        // incluso si la PBX sólo notificó la respuesta de su extensión.
        $transfers=$this->rows("SELECT o.referencia,o.destino_extension,o.numero,d.ruta_json,MIN(e.received_at) fecha
            FROM telefonia_transferencia_operaciones o JOIN telefonia_disponibilidad d
              ON d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario
             AND d.destino_extension = BINARY o.destino_extension AND d.referencia = BINARY o.referencia
            JOIN telefonia_zadarma_eventos e ON e.pbx_call_id = BINARY o.referencia AND e.internal = BINARY o.destino_extension
             AND e.evento IN ('NOTIFY_INTERNAL','NOTIFY_ANSWER')
            WHERE o.destino_usuario=? AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
              AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas c WHERE c.pbx_call_id = BINARY o.referencia AND c.extension = BINARY o.destino_extension)
            GROUP BY o.id,d.id ORDER BY o.id DESC LIMIT 100",[$uid]);
        foreach($transfers as $t){$route=json_decode($t['ruta_json'],true)?:[];
            if(($route['tipo']??'')==='PROSPECTO'&&(int)($route['responsable_id']??0)===$uid)
                $this->insertar($uid,$t['destino_extension'],$t['referencia'],'Entrante',$t['numero'],$t['fecha']);}
        // Las marcaciones previas conservan propietario aun si cambia la extensión.
        $rows=$this->rows("SELECT m.*,MIN(e.received_at) fecha FROM telefonia_ventas_marcaciones m
            JOIN telefonia_zadarma_eventos e ON e.pbx_call_id = BINARY m.pbx_call_id AND e.internal = BINARY m.extension AND e.evento='NOTIFY_OUT_START'
            WHERE m.usuario_id=? AND m.pbx_call_id IS NOT NULL AND m.created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)
            AND NOT EXISTS (SELECT 1 FROM telefonia_ventas_llamadas c WHERE c.pbx_call_id = BINARY m.pbx_call_id AND c.extension = BINARY m.extension)
            GROUP BY m.id ORDER BY m.id DESC LIMIT 300",[$uid]);
        foreach($rows as $r)$this->insertar($uid,$r['extension'],$r['pbx_call_id'],'Saliente',$r['destino'],$r['fecha']);
    }
    public static function hechos(array $events,string $ext): array {
        $answer=false;$end=false;$duration=null;$disposition='';$ids=[];
        foreach($events as $e) {
            $p=json_decode((string)($e['payload_json']??''),true)?:[];
            $own=trim((string)($e['internal']??''))===$ext;
            $explicitEnd=($e['internal']??'')===''&&($p['last_internal']??'')===$ext;
            $type=$e['evento']??'';
            if($own&&$type==='NOTIFY_ANSWER')$answer=true;
            if(($own||$explicitEnd)&&in_array($type,['NOTIFY_END','NOTIFY_OUT_END'],true)) {
                $end=true;$disposition=strtolower((string)($e['disposition']??''));
                if($own){$duration=max($duration??0,max(0,(int)($e['duration']??0)));
                    if(in_array($disposition,['answered','completed'],true))$answer=true;}
            }
            // Una referencia global de audio no identifica el tramo comercial.
            if($own&&$type==='NOTIFY_RECORD') {
                $id=(string)($e['call_id_with_rec']??'');
                if(ZadarmaPbxIdentifierHelper::callIdValido($id))$ids[$id]=true;
            }
        }
        return ['finalizada'=>$end,'conectada'=>$answer,'segundos'=>$end?$duration:null,
            'estado_pbx'=>!$end?($answer?'En conversación':'Sin cierre confirmado'):
                ($answer?'Conectada':(['busy'=>'Ocupada','no answer'=>'Sin respuesta','no_answer'=>'Sin respuesta','cancel'=>'Cancelada','failed'=>'Fallida'][$disposition]??'Sin respuesta')),
            'segmentos'=>count($ids)<=8?array_keys($ids):[]];
    }
    public function obtener(int $uid,string $ref,string $ext=''): array {
        $this->autorizar($uid);$this->requerir();
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref))throw new DomainException('Referencia comercial inválida.');
        $rows=$this->rows('SELECT * FROM telefonia_ventas_llamadas WHERE usuario_id=? AND pbx_call_id=?'.($ext!==''?' AND extension=?':'').' LIMIT 2',$ext!==''?[$uid,$ref,$ext]:[$uid,$ref]);
        if(count($rows)!==1)throw new DomainException('La llamada no está confirmada a tu nombre o su tramo es ambiguo.');
        $row=$rows[0];$events=$this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id',[$ref]);
        $row+=self::hechos($events,$row['extension']);
        $result=$this->rows('SELECT * FROM telefonia_ventas_resultados WHERE usuario_id=? AND extension=? AND pbx_call_id=?',[$uid,$row['extension'],$ref]);
        $row['registro']=$result[0]??null;return $row;
    }
    public function listar(int $uid,array $filters=[]): array {
        $this->sincronizar($uid);
        $rows=$this->rows("SELECT c.*,r.id registro_id,r.resultado,r.observacion,r.prospecto_nombre,r.contacto_id,r.proxima_accion_at
            FROM telefonia_ventas_llamadas c LEFT JOIN telefonia_ventas_resultados r
              ON r.usuario_id=c.usuario_id AND r.extension = BINARY c.extension AND r.pbx_call_id = BINARY c.pbx_call_id
            WHERE c.usuario_id=? AND c.fecha>=DATE_SUB(NOW(),INTERVAL 90 DAY) ORDER BY c.fecha DESC,c.id DESC",[$uid]);
        $events=$this->rows("SELECT e.* FROM telefonia_zadarma_eventos e JOIN telefonia_ventas_llamadas c
            ON c.pbx_call_id = BINARY e.pbx_call_id AND c.usuario_id=? AND c.fecha>=DATE_SUB(NOW(),INTERVAL 90 DAY)
            ORDER BY e.id",[$uid]);
        $grouped=[];foreach($events as $event)$grouped[$event['pbx_call_id']][]=$event;
        foreach($rows as &$row){
            $row+=self::hechos($grouped[$row['pbx_call_id']]??[],$row['extension']);
            $row['registro']=$row['registro_id']===null?null:array_intersect_key($row,array_flip(['resultado','observacion','prospecto_nombre','contacto_id','proxima_accion_at']));
            unset($row['registro_id'],$row['resultado'],$row['observacion'],$row['prospecto_nombre'],$row['contacto_id'],$row['proxima_accion_at']);
        }unset($row);return $rows;
    }
    public function bandeja(int $uid,array $filters=[]): array {
        require_once __DIR__.'/TelefoniaResultadoVentasService.php';
        $calls=$this->listar($uid);
        $unlinked=$this->rows("SELECT m.* FROM telefonia_ventas_marcaciones m WHERE m.usuario_id=? AND m.pbx_call_id IS NULL
            AND m.created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY) ORDER BY m.created_at DESC LIMIT 100",[$uid]);
        foreach($unlinked as $m)$calls[]=['id'=>'intento-'.$m['id'],'usuario_id'=>$uid,'extension'=>$m['extension'],
            'pbx_call_id'=>'','direccion'=>'Saliente','numero'=>$m['destino'],'nombre'=>'','origen_extension'=>'',
            'fecha'=>$m['created_at'],'finalizada'=>false,'conectada'=>false,'segundos'=>null,'estado_pbx'=>'Sin conexión confirmada','segmentos'=>[],'registro'=>null];
        $operations=$this->rows("SELECT o.*,d.ruta_json FROM telefonia_transferencia_operaciones o
            JOIN telefonia_disponibilidad d ON d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario
              AND d.referencia = BINARY o.referencia AND d.destino_extension = BINARY o.destino_extension
            WHERE o.destino_usuario=? AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
              AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas c WHERE c.usuario_id=? AND c.pbx_call_id = BINARY o.referencia AND c.extension = BINARY o.destino_extension)
            ORDER BY o.id DESC LIMIT 100",[$uid,$uid]);
        foreach($operations as $op){$route=json_decode($op['ruta_json'],true)?:[];if(($route['tipo']??'')!=='PROSPECTO')continue;
            $when=(new DateTimeImmutable($op['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $calls[]=['id'=>'transferencia-'.$op['id'],'usuario_id'=>$uid,'extension'=>$op['destino_extension'],'pbx_call_id'=>'',
                'direccion'=>'Entrante','numero'=>$op['numero'],'nombre'=>(string)($route['institucion_nombre']??''),'origen_extension'=>$op['origen_extension'],
                'fecha'=>$when,'finalizada'=>false,'conectada'=>false,'segundos'=>null,'registro'=>null,'segmentos'=>[],
                'estado_pbx'=>$op['estado']==='SIN_RESPUESTA'?'Transferencia no contestada':'Transferencia sin confirmación'];}
        usort($calls,static fn($a,$b)=>strcmp($b['fecha'],$a['fecha']));
        $from=(string)($filters['desde']??date('Y-m-d',strtotime('-29 days')));$until=(string)($filters['hasta']??date('Y-m-d'));
        foreach([$from,$until] as $day){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$day);if(!$parsed||$parsed->format('Y-m-d')!==$day)throw new InvalidArgumentException('Selecciona fechas válidas.');}
        if($from>$until||$until>date('Y-m-d')||$from<date('Y-m-d',strtotime('-89 days')))throw new InvalidArgumentException('Consulta un intervalo de los últimos 90 días.');
        $search=trim((string)($filters['q']??''));if(mb_strlen($search)>100)throw new InvalidArgumentException('La búsqueda es demasiado larga.');
        $calls=array_values(array_filter($calls,static fn($c)=>substr($c['fecha'],0,10)>=$from&&substr($c['fecha'],0,10)<=$until&&
            ($search===''||mb_stripos($c['numero'].' '.$c['nombre'].' '.($c['registro']['prospecto_nombre']??''),$search)!==false)));
        $summary=['total'=>count($calls),'pendientes'=>0,'perdidas'=>0,'conversaciones'=>0];
        foreach($calls as $c){$summary['pendientes']+=(int)($c['finalizada']&&!$c['registro']);
            $summary['perdidas']+=(int)($c['finalizada']&&!$c['conectada']&&$c['direccion']==='Entrante');
            $summary['conversaciones']+=(int)TelefoniaResultadoVentasService::esConversacion($c['registro']['resultado']??'');}
        $search=trim((string)($filters['q']??''));$state=(string)($filters['estado']??'');
        if(mb_strlen($search)>100)throw new InvalidArgumentException('La búsqueda es demasiado larga.');
        $calls=array_values(array_filter($calls,static function($c)use($search,$state){
            if($search!==''&&mb_stripos($c['numero'].' '.$c['nombre'].' '.($c['registro']['prospecto_nombre']??''),$search)===false)return false;
            return match($state){'pendientes'=>$c['finalizada']&&!$c['registro'],'perdidas'=>$c['finalizada']&&!$c['conectada']&&$c['direccion']==='Entrante',
                'sin_conexion'=>!$c['conectada'],'transferidas'=>$c['origen_extension']!=='','Entrante','Saliente'=>$c['direccion']===$state,default=>true};
        }));
        $total=count($calls);$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($filters['pagina']??1)));
        return ['filas'=>array_slice($calls,($page-1)*20,20),'total'=>$total,'pagina'=>$page,'paginas'=>$pages,'resumen'=>$summary];
    }

}
