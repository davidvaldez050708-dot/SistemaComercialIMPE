<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/../helpers/ZadarmaPbxIdentifierHelper.php';
require_once __DIR__.'/TelefoniaLimpiezaGuard.php';

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
    /**
     * Las asignaciones de telefonia_extensiones están en UTC; los webhooks
     * normalizados en telefonia_zadarma_eventos emplean America/Mexico_City.
     * Comparar sus fechas sin convertirlas descarta llamadas recién asignadas.
     */
    public static function asignacionLocal(string $utc): string {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$utc,new DateTimeZone('UTC'));
        if(!$date || $date->format('Y-m-d H:i:s')!==$utc)
            throw new DomainException('Fecha de asignación telefónica inválida.');
        return $date->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
    }
    /** Llamado DESPUÉS de guardar el webhook; no crea otro evento ni toca SIP. */
    public function capturarEvento(array $event): void {
        if(!$this->disponible())return;
        $type=$event['event']??$event['evento']??'';
        if(!in_array($type,['NOTIFY_INTERNAL','NOTIFY_OUT_START','NOTIFY_ANSWER'],true))return;
        $ref=(string)($event['pbx_call_id']??'');$ext=(string)($event['internal']??'');
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref)||!preg_match('/^[0-9]{3,6}$/D',$ext))return;
        if (TelefoniaLimpiezaGuard::bloqueada($this->db,$ref,$ext)) return;
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
        $desdeLocal=self::asignacionLocal((string)$owners[0]['desde']);
        if($date<$desdeLocal||($started!==''&&$started<$desdeLocal))return;
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
        require_once __DIR__.'/TelefoniaMarcacionesVentasService.php';
        $assigned=$this->rows("SELECT extension FROM telefonia_extensiones WHERE usuario_id=? AND activo=1 AND proveedor='ZADARMA' AND permite_salientes=1",[$uid]);
        if(count($assigned)===1)(new TelefoniaMarcacionesVentasService($this->db,false))->reconciliarPendientes($uid,$assigned[0]['extension']);
        $limpiezaEvento=TelefoniaLimpiezaGuard::condicion($this->db,'e.pbx_call_id','e.internal');
        $limpiezaOperacion=TelefoniaLimpiezaGuard::condicion($this->db,'o.referencia','o.destino_extension');
        $limpiezaMarcacion=TelefoniaLimpiezaGuard::condicion($this->db,'m.pbx_call_id','m.extension');
        // Backfill por conjuntos: no resolver propietario, permisos y metadata por cada evento.
        $sql="INSERT IGNORE INTO telefonia_ventas_llamadas (usuario_id,extension,pbx_call_id,direccion,numero,fecha)
            SELECT t.usuario_id,e.internal,e.pbx_call_id,IF(e.evento='NOTIFY_OUT_START','Saliente','Entrante'),
              IF(e.evento='NOTIFY_OUT_START',e.destination,e.caller_id),MIN(e.received_at)
            FROM telefonia_zadarma_eventos e JOIN telefonia_extensiones t ON t.extension=BINARY e.internal
            WHERE $limpiezaEvento AND t.usuario_id=? AND t.activo=1 AND t.proveedor='ZADARMA'
              AND NOT EXISTS(SELECT 1 FROM telefonia_extensiones other WHERE other.extension=BINARY t.extension AND other.activo=1 AND other.usuario_id<>t.usuario_id)
              AND ((e.evento='NOTIFY_OUT_START' AND t.permite_salientes=1 AND EXISTS(SELECT 1 FROM usuarios u JOIN rol_permisos rp ON rp.rol_id=u.rol_id JOIN permisos p ON p.id=rp.permiso_id WHERE u.id=t.usuario_id AND p.codigo='telefonia.salientes' AND p.estado=1))
                OR(e.evento='NOTIFY_INTERNAL' AND t.permite_entrantes=1 AND EXISTS(SELECT 1 FROM usuarios u JOIN rol_permisos rp ON rp.rol_id=u.rol_id JOIN permisos p ON p.id=rp.permiso_id WHERE u.id=t.usuario_id AND p.codigo='telefonia.recibir' AND p.estado=1)))
              AND e.received_at>=DATE_SUB(NOW(),INTERVAL 90 DAY) AND e.received_at>=CONVERT_TZ(GREATEST(t.created_at,t.updated_at),'+00:00','-06:00')
              AND(e.call_start IS NULL OR e.call_start>=CONVERT_TZ(GREATEST(t.created_at,t.updated_at),'+00:00','-06:00'))
              AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas old WHERE old.pbx_call_id=BINARY e.pbx_call_id AND old.extension=BINARY e.internal)
              AND e.pbx_call_id REGEXP '^[A-Za-z0-9_-]{6,100}$' AND e.internal REGEXP '^[0-9]{3,6}$'
            GROUP BY t.usuario_id,e.internal,e.pbx_call_id,e.evento,e.destination,e.caller_id";
        $st=$this->db->prepare($sql);$st->bind_param('i',$uid);$st->execute();$st->close();
        // Una operación consumida conserva el destinatario histórico incluso sin INTERNAL.
        $sql="INSERT IGNORE INTO telefonia_ventas_llamadas(usuario_id,extension,pbx_call_id,direccion,numero,fecha)
            SELECT o.destino_usuario,o.destino_extension,o.referencia,'Entrante',o.numero,MIN(e.received_at)
            FROM telefonia_transferencia_operaciones o JOIN telefonia_disponibilidad d ON d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario
             AND d.destino_extension=BINARY o.destino_extension AND d.referencia=BINARY o.referencia
            JOIN telefonia_zadarma_eventos e ON e.pbx_call_id=BINARY o.referencia AND e.internal=BINARY o.destino_extension AND e.evento IN('NOTIFY_INTERNAL','NOTIFY_ANSWER')
            WHERE $limpiezaOperacion AND o.destino_usuario=? AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
              AND d.estado IN('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA')
              AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas old WHERE old.pbx_call_id=BINARY o.referencia AND old.extension=BINARY o.destino_extension)
              AND JSON_VALID(d.ruta_json) AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.tipo'))='PROSPECTO'
              AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.responsable_id'))=CAST(o.destino_usuario AS CHAR)
              AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.extension'))=BINARY o.destino_extension
              AND JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.referencia_llamada'))=BINARY o.referencia
            GROUP BY o.destino_usuario,o.destino_extension,o.referencia,o.numero";
        $st=$this->db->prepare($sql);$st->bind_param('i',$uid);$st->execute();$st->close();
        $sql="INSERT IGNORE INTO telefonia_ventas_llamadas(usuario_id,extension,pbx_call_id,direccion,numero,fecha)
            SELECT m.usuario_id,m.extension,m.pbx_call_id,'Saliente',m.destino,MIN(e.received_at)
            FROM telefonia_ventas_marcaciones m JOIN telefonia_zadarma_eventos e ON e.pbx_call_id=BINARY m.pbx_call_id AND e.internal=BINARY m.extension AND e.evento='NOTIFY_OUT_START'
            WHERE $limpiezaMarcacion AND m.usuario_id=? AND m.created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)
              AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas old WHERE old.pbx_call_id=BINARY m.pbx_call_id AND old.extension=BINARY m.extension)
            GROUP BY m.usuario_id,m.extension,m.pbx_call_id,m.destino";
        $st=$this->db->prepare($sql);$st->bind_param('i',$uid);$st->execute();$st->close();
        // Enriquecer sólo la referencia exacta, con una única solicitud consumida.
        $metadata=$this->rows("SELECT c.id,MIN(d.id) solicitud_id FROM telefonia_ventas_llamadas c JOIN telefonia_disponibilidad d
             ON d.destino_usuario=c.usuario_id AND d.destino_extension=BINARY c.extension AND d.referencia=BINARY c.pbx_call_id
            WHERE c.usuario_id=? AND c.direccion='Entrante' AND c.solicitud_id IS NULL
             AND d.estado IN('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA') GROUP BY c.id HAVING COUNT(*)=1",[$uid]);
        foreach($metadata as $m){$d=$this->rows('SELECT * FROM telefonia_disponibilidad WHERE id=?',[$m['solicitud_id']])[0];$route=json_decode($d['ruta_json'],true)?:[];
            if(($route['tipo']??'')!=='PROSPECTO'||(int)($route['responsable_id']??0)!==$uid||(string)($route['extension']??'')!==$d['destino_extension']||(string)($route['referencia_llamada']??'')!==$d['referencia'])continue;
            $name=mb_substr(trim((string)($route['institucion_nombre']??'')),0,150);$state=(int)($route['estado_id']??0);
            $st=$this->db->prepare('UPDATE telefonia_ventas_llamadas SET solicitud_id=?,nombre=?,origen_extension=?,estado_id=?,numero=? WHERE id=? AND usuario_id=? AND solicitud_id IS NULL');
            $st->bind_param('issisii',$d['id'],$name,$d['origen_extension'],$state,$d['numero'],$m['id'],$uid);$st->execute();$st->close();
        }
    }
    /** Referencias firmadas del mismo PBX. RECORD global confirma disponibilidad, no extensión. */
    public static function evidenciaAudio(array $events,string $ext): array {
        require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
        return TelefoniaPbxSegmentosService::deEventos($events,$ext);
    }
    /** La conexión y el resultado humano son hechos distintos; no inferir buzón por duración. */
    public static function presentacionAudio(array $call): array {
        require_once __DIR__.'/TelefoniaResultadoVentasService.php';
        $out=['disponible'=>false,'estado'=>'sin_referencia','texto'=>'Grabación sin confirmar','detalle'=>'La centralita todavía no ha enviado una referencia de grabación para este tramo.'];
        if(empty($call['pbx_call_id']))return array_replace($out,['estado'=>'sin_llamada','texto'=>'Sin llamada confirmada','detalle'=>'Es un intento sin referencia confirmada de la centralita; no hay audio verificable.']);
        if(empty($call['finalizada']))return array_replace($out,!empty($call['registro'])
            ?['estado'=>'por_conciliar','texto'=>'Grabación por verificar','detalle'=>'El resultado humano ya está guardado. Falta evidencia del cierre y de los segmentos de Zadarma.']
            :['estado'=>'en_curso','texto'=>'Grabación al finalizar','detalle'=>'Espera a que la centralita confirme el cierre de la llamada.']);
        $code=(string)($call['registro']['resultado']??'');
        if(empty($call['conectada'])||($code!==''&&!TelefoniaResultadoVentasService::esConversacion($code)))return array_replace($out,['estado'=>'no_aplica','texto'=>'Sin grabación de conversación','detalle'=>'Las llamadas sin conversación, buzones y locuciones no habilitan reproducción.']);
        if($code==='')return array_replace($out,['estado'=>'pendiente_resultado','texto'=>'Pendiente de resultado','detalle'=>'Registra lo ocurrido para distinguir una conversación de un buzón o una locución.']);
        if(!empty($call['segmentos']))return array_replace($out,['disponible'=>true,'estado'=>'referencia_propia','texto'=>'Consultar grabación','detalle'=>'Referencia confirmada del tramo de tu extensión. El proveedor verifica el archivo al reproducir.']);
        return match($call['audio_motivo']??'sin_referencia'){
            'no_reportada'=>array_replace($out,['estado'=>'no_reportada','texto'=>'Sin grabación reportada','detalle'=>'La estadística del tramo propio no reporta una grabación. Puedes comprobarla de nuevo si el proveedor la prepara después.']),
            'pendiente_referencia'=>array_replace($out,['estado'=>'pendiente_referencia','texto'=>'Preparando grabación','detalle'=>'La centralita indicó grabación, pero falta su referencia de audio.']),
            'sin_tramo'=>array_replace($out,['estado'=>'sin_tramo','texto'=>'Pendiente de vinculación','detalle'=>'Hay un aviso de grabación de la llamada, pero no identifica un segmento propio de tu extensión.']),
            'compartida','limite'=>array_replace($out,['estado'=>'por_verificar','texto'=>'Audio por verificar','detalle'=>'Las referencias recibidas no permiten identificar de forma inequívoca los segmentos de tu extensión.']),
            default=>$out
        };
    }
    /** La captura humana se habilita antes del cierre PBX en transferencias propias atendidas;
     * la validación estricta de ruta, titular y ANSWER sigue en el servicio de guardado. */
    public static function resultadoCapturable(array $call): bool {
        if (empty($call['pbx_call_id']) || !empty($call['registro'])) return false;
        // Todo cierre propio con referencia permite describir lo ocurrido:
        // una locución de buzón puede terminar técnicamente como cancelada.
        // Entrantes transferidas conservan sus reglas de captura anticipada.
        if (($call['direccion'] ?? '') === 'Saliente') {
            return !empty($call['finalizada']);
        }
        return !empty($call['finalizada']) || (
            !empty($call['conectada']) && !empty($call['solicitud_id'])
            && ($call['direccion'] ?? '') === 'Entrante'
            && trim((string)($call['origen_extension'] ?? '')) !== ''
            && (string)$call['origen_extension'] !== (string)($call['extension'] ?? '')
        );
    }

    public static function hechos(array $events,string $ext,string $source=''): array {
        $answer=false;$end=false;$duration=null;$disposition='';
        // La consulta se ordena por id ASC. Un cierre OUT_END del origen
        // sólo puede cerrar al destinatario después de START de Recepción
        // y ANSWER de Ventas del mismo PBX (con origen persistido).
        $sourceStarted=false;$answeredAfterSource=false;
        foreach($events as $e) {
            $type=(string)($e['evento']??'');
            $internal=trim((string)($e['internal']??''));
            if($source!==''&&$source!==$ext&&$type==='NOTIFY_OUT_START'&&$internal===$source)$sourceStarted=true;
            if($type==='NOTIFY_ANSWER'&&$internal===$ext){
                $answer=true;
                if($sourceStarted)$answeredAfterSource=true;
            }
            $p=json_decode((string)($e['payload_json']??''),true)?:[];
            $own=$internal===$ext;
            // Zadarma conserva internal del iniciador; last_internal puede
            // ser el destinatario 150, quedar vacío o mantener al origen 100.
            // Nunca aceptar aquí last_internal de una tercera extensión.
            $last=trim((string)($e['last_internal']??''));if($last==='')$last=trim((string)($p['last_internal']??''));
            $explicitEnd=$last===$ext;
            $sourceEnd=$sourceStarted&&$answeredAfterSource&&($last===''||$last===$source)&&
                $type==='NOTIFY_OUT_END'&&$internal===$source;
            $type=$e['evento']??'';
            if($own&&$type==='NOTIFY_ANSWER')$answer=true;
            if(($own||$explicitEnd||$sourceEnd)&&in_array($type,['NOTIFY_END','NOTIFY_OUT_END'],true)) {
                $end=true;$disposition=strtolower((string)($e['disposition']??''));
                if($own){$known=array_key_exists('has_duration',$e)?(bool)$e['has_duration']:($p?array_key_exists('duration',$p):array_key_exists('duration',$e));if($known)$duration=max($duration??0,max(0,(int)($e['duration']??0)));
                    if(in_array($disposition,['answered','completed'],true))$answer=true;}
            }
        }
        require_once __DIR__.'/TelefoniaPbxSegmentosService.php';
        $transfer=TelefoniaPbxSegmentosService::transferencia($events,$ext,$source);
        $allowed=$transfer['atendida']&&$transfer['finalizada']&&$transfer['origen']!==''?[$transfer['origen']]:[];
        $audio=TelefoniaPbxSegmentosService::deEventos($events,$ext,$allowed);
        return ['finalizada'=>$end,'conectada'=>$answer,'segundos'=>$end?$duration:null,
            'estado_pbx'=>!$end?($answer?'En conversación':'Sin cierre confirmado'):
                ($answer?'Conectada':(['busy'=>'Ocupada','no answer'=>'Sin respuesta','no_answer'=>'Sin respuesta','cancel'=>'Cancelada','failed'=>'Fallida'][$disposition]??'Sin respuesta')),
            'audio_extensiones'=>$allowed]+$audio;
    }
    /** Alias reservados en la operación exacta. No deduce identidad por teléfono/fecha. */
    public function conVinculosSdk(int $uid,array $calls): array {
        if(!$calls)return [];
        $refs=array_values(array_unique(array_column($calls,'pbx_call_id')));
        $links=$this->rows('SELECT o.referencia,o.destino_extension,o.sdk_consulta FROM telefonia_transferencia_operaciones o
            JOIN telefonia_ventas_llamadas c ON c.usuario_id=o.destino_usuario AND c.extension=BINARY o.destino_extension AND c.pbx_call_id=BINARY o.referencia
            WHERE c.usuario_id=? AND o.sdk_consulta IS NOT NULL AND o.referencia IN ('.implode(',',array_fill(0,count($refs),'?')).')',array_merge([$uid],$refs));
        foreach($calls as &$call){
            $matched=array_values(array_filter($links,static fn($o)=>$o['referencia']===$call['pbx_call_id']&&$o['destino_extension']===$call['extension']));
            $sdk=count($matched)===1?(string)$matched[0]['sdk_consulta']:'';
            $call['sdk_tokens']=preg_match('/^sdk-[a-z0-9-]{12,94}$/D',$sdk)?[$sdk,$sdk.'-external']:[];
        }unset($call);
        return $calls;
    }
    public function obtenerPorSdk(int $uid,string $sdk): ?array {
        $this->autorizar($uid);$this->requerir();
        if(!preg_match('/^sdk-[a-z0-9-]{12,110}$/D',$sdk))throw new InvalidArgumentException('Sesión telefónica inválida.');
        $rows=$this->rows("SELECT c.pbx_call_id,c.extension FROM telefonia_transferencia_operaciones o
            JOIN telefonia_ventas_llamadas c ON c.usuario_id=o.destino_usuario AND c.extension=BINARY o.destino_extension AND c.pbx_call_id=BINARY o.referencia
            WHERE c.usuario_id=? AND (o.sdk_consulta=BINARY ? OR CONCAT(o.sdk_consulta,'-external')=BINARY ?) LIMIT 2",[$uid,$sdk,$sdk]);
        if(count($rows)!==1)return null;
        return $this->conVinculosSdk($uid,[$this->obtener($uid,$rows[0]['pbx_call_id'],$rows[0]['extension'])])[0];
    }
    public function obtener(int $uid,string $ref,string $ext=''): array {
        $this->autorizar($uid);$this->requerir();
        if(!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref))throw new DomainException('Referencia comercial inválida.');
        $rows=$this->rows('SELECT * FROM telefonia_ventas_llamadas WHERE usuario_id=? AND pbx_call_id=?'.($ext!==''?' AND extension=?':'').' LIMIT 2',$ext!==''?[$uid,$ref,$ext]:[$uid,$ref]);
        if(count($rows)!==1)throw new DomainException('La llamada no está confirmada a tu nombre o su tramo es ambiguo.');
        $row=$rows[0];$events=$this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id',[$ref]);
        $row+=self::hechos($events,$row['extension'],(string)($row['origen_extension']??''));
        $result=$this->rows('SELECT * FROM telefonia_ventas_resultados WHERE usuario_id=? AND extension=? AND pbx_call_id=?',[$uid,$row['extension'],$ref]);
        require_once __DIR__.'/TelefoniaResultadoVentasCatalogo.php';
        $row['registro']=TelefoniaResultadoVentasCatalogo::completarRegistro($this->db,$result[0]??null);
        if($this->db->query("SHOW TABLES LIKE 'telefonia_ventas_prospecto_llamada'")->num_rows){
            $capture=$this->rows('SELECT datos_json FROM telefonia_ventas_prospecto_llamada WHERE usuario_id=? AND extension=? AND pbx_call_id=?',[$uid,$row['extension'],$ref]);
            $row['captura_prospecto']=isset($capture[0])?(json_decode($capture[0]['datos_json'],true)?:null):null;
        }
        require_once __DIR__.'/TelefoniaVentasEvidenciaService.php';
        return (new TelefoniaVentasEvidenciaService($this->db))->enriquecer([$row],$uid)[0];
    }
    public function listar(int $uid,array $filters=[],bool $conciliar=true): array {
        if($conciliar)$this->sincronizar($uid);
        else{$this->autorizar($uid);$this->requerir();}
        [$from,$until]=self::intervalo($filters);$from.=' 00:00:00';$until.=' 23:59:59';
        $rows=$this->rows("SELECT c.*,r.id registro_id,r.resultado,r.observacion,r.prospecto_nombre,r.contacto_id,r.proxima_accion_at
            FROM telefonia_ventas_llamadas c LEFT JOIN telefonia_ventas_resultados r
              ON r.usuario_id=c.usuario_id AND r.extension = BINARY c.extension AND r.pbx_call_id = BINARY c.pbx_call_id
            WHERE c.usuario_id=? AND c.fecha BETWEEN ? AND ? ORDER BY c.fecha DESC,c.id DESC",[$uid,$from,$until]);
        $events=$this->rows("SELECT e.pbx_call_id,e.evento,e.internal,e.disposition,e.duration,e.call_id_with_rec,e.is_recorded,e.received_at,CASE WHEN JSON_VALID(e.payload_json) THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.transfer_from')) ELSE NULL END transfer_from,CASE WHEN JSON_VALID(e.payload_json) THEN JSON_CONTAINS_PATH(e.payload_json,'one','$.duration') ELSE 0 END has_duration,CASE WHEN JSON_VALID(e.payload_json) THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.last_internal')) ELSE NULL END last_internal FROM telefonia_zadarma_eventos e JOIN telefonia_ventas_llamadas c
            ON c.pbx_call_id = BINARY e.pbx_call_id AND c.usuario_id=? AND c.fecha BETWEEN ? AND ?
            ORDER BY e.id",[$uid,$from,$until]);
        $grouped=[];foreach($events as $event)$grouped[$event['pbx_call_id']][]=$event;
        foreach($rows as &$row){
            $row+=self::hechos($grouped[$row['pbx_call_id']]??[],$row['extension'],(string)($row['origen_extension']??''));
            $row['registro']=$row['registro_id']===null?null:array_intersect_key($row,array_flip(['resultado','observacion','prospecto_nombre','contacto_id','proxima_accion_at']));
            unset($row['registro_id'],$row['resultado'],$row['observacion'],$row['prospecto_nombre'],$row['contacto_id'],$row['proxima_accion_at']);
        }unset($row);
        require_once __DIR__.'/TelefoniaVentasEvidenciaService.php';
        return (new TelefoniaVentasEvidenciaService($this->db))->enriquecer($rows,$uid);
    }
    private static function intervalo(array $filters): array {
        $from=(string)($filters['desde']??date('Y-m-d',strtotime('-29 days')));$until=(string)($filters['hasta']??date('Y-m-d'));
        foreach([$from,$until] as $day){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$day);if(!$parsed||$parsed->format('Y-m-d')!==$day)throw new InvalidArgumentException('Selecciona fechas válidas.');}
        if($from>$until||$until>date('Y-m-d')||$from<date('Y-m-d',strtotime('-89 days')))throw new InvalidArgumentException('Consulta un intervalo de los últimos 90 días.');
        return [$from,$until];
    }
    public function bandeja(int $uid,array $filters=[]): array {
        require_once __DIR__.'/TelefoniaResultadoVentasService.php';
        [$from,$until]=self::intervalo($filters);
        $calls=$this->listar($uid,$filters);
        $marcador=new TelefoniaMarcacionesVentasService($this->db,false);
        $sqlFrom=$marcador->fechaMarcacionMySQL($from.' 00:00:00');
        $sqlUntil=$marcador->fechaMarcacionMySQL($until.' 23:59:59');
        $unlinked=$this->rows("SELECT m.* FROM telefonia_ventas_marcaciones m WHERE m.usuario_id=? AND m.pbx_call_id IS NULL
            AND m.created_at BETWEEN ? AND ? ORDER BY m.created_at DESC",[$uid,$sqlFrom,$sqlUntil]);
        foreach($unlinked as $m)$calls[]=['id'=>'intento-'.$m['id'],'usuario_id'=>$uid,'extension'=>$m['extension'],
            'pbx_call_id'=>'','direccion'=>'Saliente','numero'=>$m['destino'],'nombre'=>'','origen_extension'=>'',
            'fecha'=>$marcador->fechaMarcacionLocal((string)$m['created_at']),'finalizada'=>false,'conectada'=>false,'segundos'=>null,'estado_pbx'=>'Sin conexión confirmada','segmentos'=>[],'registro'=>null];
        $operations=$this->rows("SELECT o.*,d.ruta_json FROM telefonia_transferencia_operaciones o
            JOIN telefonia_disponibilidad d ON d.origen_usuario=o.origen_usuario AND d.destino_usuario=o.destino_usuario
              AND d.referencia = BINARY o.referencia AND d.destino_extension = BINARY o.destino_extension
            WHERE o.destino_usuario=? AND o.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
              AND NOT EXISTS(SELECT 1 FROM telefonia_ventas_llamadas c WHERE c.usuario_id=? AND c.pbx_call_id = BINARY o.referencia AND c.extension = BINARY o.destino_extension)
            ORDER BY o.id DESC",[$uid,$uid]);
        $groupedOps=[];foreach($operations as $op){$route=json_decode($op['ruta_json'],true)?:[];if(($route['tipo']??'')!=='PROSPECTO'||(int)($route['responsable_id']??0)!==$uid||(string)($route['extension']??'')!==$op['destino_extension']||(string)($route['referencia_llamada']??'')!==$op['referencia'])continue;
            $groupedOps[$op['id']][]=$op;
        }
        foreach($groupedOps as $group){$op=$group[0];$names=array_unique(array_map(static fn($x)=>(string)((json_decode($x['ruta_json'],true)?:[])['institucion_nombre']??''),$group));$name=count($names)===1?(string)reset($names):'';
            $when=(new DateTimeImmutable($op['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            $calls[]=['id'=>'transferencia-'.$op['id'],'usuario_id'=>$uid,'extension'=>$op['destino_extension'],'pbx_call_id'=>'',
                'direccion'=>'Entrante','numero'=>$op['numero'],'nombre'=>$name,'origen_extension'=>$op['origen_extension'],
                'fecha'=>$when,'finalizada'=>false,'conectada'=>false,'segundos'=>null,'registro'=>null,'segmentos'=>[],
                'estado_pbx'=>$op['estado']==='SIN_RESPUESTA'?'Transferencia no contestada':'Transferencia sin confirmación'];}
        usort($calls,static fn($a,$b)=>strcmp($b['fecha'],$a['fecha']));
        $search=trim((string)($filters['q']??''));if(mb_strlen($search)>100)throw new InvalidArgumentException('La búsqueda es demasiado larga.');
        $calls=array_values(array_filter($calls,static fn($c)=>substr($c['fecha'],0,10)>=$from&&substr($c['fecha'],0,10)<=$until&&
            ($search===''||mb_stripos($c['numero'].' '.$c['nombre'].' '.($c['registro']['prospecto_nombre']??''),$search)!==false)));
        $summary=['total'=>count($calls),'pbx'=>0,'intentos'=>0,'transferencias_sin_tramo'=>0,'pendientes'=>0,'perdidas'=>0,'conversaciones'=>0];
        foreach($calls as $c){
            $confirmedRef=trim((string)($c['pbx_call_id']??''))!=='';
            $summary['pbx']+=(int)$confirmedRef;
            $summary['intentos']+=(int)(!$confirmedRef&&str_starts_with((string)$c['id'],'intento-'));
            $summary['transferencias_sin_tramo']+=(int)(!$confirmedRef&&str_starts_with((string)$c['id'],'transferencia-'));
            $summary['pendientes']+=(int)(self::resultadoCapturable($c)&&!$c['registro']);
            $summary['perdidas']+=(int)($c['finalizada']&&!$c['conectada']&&$c['direccion']==='Entrante');
            $summary['conversaciones']+=(int)TelefoniaResultadoVentasService::esConversacion($c['registro']['resultado']??'');}
        $search=trim((string)($filters['q']??''));$state=(string)($filters['estado']??'');
        if(mb_strlen($search)>100)throw new InvalidArgumentException('La búsqueda es demasiado larga.');
        $calls=array_values(array_filter($calls,static function($c)use($search,$state){
            if($search!==''&&mb_stripos($c['numero'].' '.$c['nombre'].' '.($c['registro']['prospecto_nombre']??''),$search)===false)return false;
            return match($state){'pendientes'=>self::resultadoCapturable($c)&&!$c['registro'],'perdidas'=>$c['finalizada']&&!$c['conectada']&&$c['direccion']==='Entrante',
                'sin_conexion'=>!$c['conectada'],'transferidas'=>$c['origen_extension']!=='','Entrante','Saliente'=>$c['direccion']===$state,default=>true};
        }));
        $total=count($calls);$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)($filters['pagina']??1)));
        return ['filas'=>array_slice($calls,($page-1)*20,20),'total'=>$total,'pagina'=>$page,'paginas'=>$pages,'resumen'=>$summary];
    }

}
