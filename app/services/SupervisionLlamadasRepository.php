<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/db_connection.php';
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';
require_once __DIR__.'/TelefoniaVentasEvidenciaService.php';
require_once __DIR__.'/TelefoniaResultadoVentasCatalogo.php';

/** Sólo lectura. La identidad histórica procede del autor/propietario persistido,
 * nunca del teléfono, de la hora o del usuario actual de una extensión. */
final class SupervisionLlamadasRepository
{
    private array $tables=[],$events=[],$relations=[],$salesFacts=[];
    public function __construct(private mysqli $db) {}
    public function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);
        if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$out=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $out;
    }
    public function tabla(string $name): bool
    {
        return $this->tables[$name]??=$this->rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$name])!==[];
    }
    public function acceso(int $uid,bool $audio=false): string
    {
        $u=$this->rows('SELECT u.rol_id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.estado=1',[$uid]);
        $role=(int)($u[0]['rol_id']??0);$scope=[1=>'global',2=>'global',6=>'equipo'][$role]??'';
        if($scope==='')throw new DomainException('No tienes acceso a la supervisión de llamadas.');
        foreach($audio?['llamadas.supervision.ver','llamadas.supervision.audio']:['llamadas.supervision.ver'] as $code){
            if(!$this->rows('SELECT p.id FROM permisos p JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE rp.rol_id=? AND p.codigo=? AND p.estado=1',[$role,$code]))
                throw new DomainException('No tienes permiso para esta consulta.');
        }
        return $scope;
    }
    public function personas(int $uid,string $area,bool $audio=false): array
    {
        $scope=$this->acceso($uid,$audio);$role=$area==='asesores'?3:4;$type=$role===3?'ASESOR':'ANALISTA_DATOS';
        $where='u.rol_id=?';$args=[$role];
        if($scope==='equipo'){
            $where.=" AND EXISTS(SELECT 1 FROM asignaciones_territorio a
                JOIN asignaciones_territorio c ON c.id=a.cuenta_clave_asignacion_id AND c.estado_id=a.estado_id
                JOIN estados e ON e.id=a.estado_id AND e.estado=1
                WHERE a.usuario_id=u.id AND a.tipo_asignacion=? AND c.tipo_asignacion='CUENTA_CLAVE' AND c.usuario_id=?
                AND a.activo=1 AND c.activo=1
                AND (a.fecha_inicio IS NULL OR a.fecha_inicio<=CURDATE()) AND (a.fecha_fin IS NULL OR a.fecha_fin>=CURDATE())
                AND (c.fecha_inicio IS NULL OR c.fecha_inicio<=CURDATE()) AND (c.fecha_fin IS NULL OR c.fecha_fin>=CURDATE()))";
            array_push($args,$type,$uid);
        }
        return $this->rows("SELECT u.id,CONCAT_WS(' ',u.nombre,u.apellidos) nombre,u.foto_perfil,u.estado activo FROM usuarios u WHERE $where ORDER BY u.nombre,u.apellidos,u.id",$args);
    }
    public function eventos(string $ref): array
    {
        if($ref===''||!$this->tabla('telefonia_zadarma_eventos'))return [];
        return $this->events[$ref]??=$this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id=BINARY ? ORDER BY id',[$ref]);
    }
    private function relacionados(string $table,array $identity): array
    {
        if(isset($this->relations[$table]))return array_values(array_filter($this->relations[$table],static function($row)use($identity){foreach($identity as $key=>$value)if((string)($row[$key]??'')!==(string)$value)return false;return true;}));
        $conditions=[];foreach($identity as $key=>$value)$conditions[]="$key=BINARY ?";
        return $this->rows("SELECT * FROM $table WHERE ".implode(' AND ',$conditions).' LIMIT 2',array_values($identity));
    }
    /** Cargar las relaciones y eventos por lotes, sin una consulta por tarjeta. */
    private function precargar(array $raw,array $ids): void
    {
        $this->relations=[];$this->events=[];$this->salesFacts=[];
        $refs=[];$iv=[];$sdk=[];$sales=[];
        foreach($raw as [$source,$r]){
            $ref=(string)($r['pbx_call_id']??'');if($ref!=='')$refs[$ref]=true;
            if($source==='interaccion'){$iv[(int)$r['id']]=true;$external=(string)($r['id_externo']??'');if(str_starts_with($external,'WEBRTC-'))$sdk[substr($external,7)]=true;elseif($external!=='')$refs[$external]=true;}
            if(!empty($r['interaccion_id']))$iv[(int)$r['interaccion_id']]=true;
            if(isset($r['session_id']))$sdk[$r['session_id']]=true;
            if(str_starts_with($ref,'sdk-'))$sdk[$ref]=true;
            if($source==='venta')$sales[(int)$r['usuario_id']][]=$r;
        }
        $marks=implode(',',array_fill(0,count($ids),'?'));
        foreach(['interacciones_vinculacion','telefonia_analista_entrantes','telefonia_analista_webrtc_sesiones'] as $table){
            if(!$this->tabla($table))continue;
            $this->relations[$table]=[];
            foreach($raw as [$source,$r])if((self::SOURCES[$source][0]??'')===$table)$this->relations[$table][(string)$r['id']]=$r;
            if($iv)foreach(array_chunk(array_keys($iv),500) as $chunk){
                $column=$table==='interacciones_vinculacion'?'id':'interaccion_id';
                foreach($this->rows("SELECT * FROM $table WHERE usuario_id IN ($marks) AND $column IN (".implode(',',array_fill(0,count($chunk),'?')).')',array_merge($ids,$chunk)) as $r)$this->relations[$table][(string)$r['id']]=$r;
            }
        }
        foreach($this->relations['telefonia_analista_entrantes']??[] as $r)if($r['pbx_call_id']!=='')$refs[$r['pbx_call_id']]=true;
        foreach($this->relations['telefonia_analista_webrtc_sesiones']??[] as $r)$sdk[$r['session_id']]=true;
        if($sdk&&$this->tabla('telefonia_transferencia_operaciones')){
            $this->relations['telefonia_transferencia_operaciones']=[];$base=[];
            foreach(array_keys($sdk) as $s){$base[$s]=true;if(str_ends_with($s,'-external'))$base[substr($s,0,-9)]=true;}
            foreach(array_chunk(array_keys($base),500) as $chunk)foreach($this->rows("SELECT * FROM telefonia_transferencia_operaciones WHERE destino_usuario IN ($marks) AND estado='ENTREGADA' AND sdk_consulta IN (".implode(',',array_fill(0,count($chunk),'?')).')',array_merge($ids,$chunk)) as $r){$this->relations['telefonia_transferencia_operaciones'][]=$r;$refs[$r['referencia']]=true;}
        }
        if($sales){
            $this->relations['telefonia_ventas_resultados']=[];
            foreach(array_chunk(array_keys($refs),500) as $chunk)$this->relations['telefonia_ventas_resultados']=array_merge($this->relations['telefonia_ventas_resultados'],$this->rows("SELECT * FROM telefonia_ventas_resultados WHERE usuario_id IN ($marks) AND pbx_call_id IN (".implode(',',array_fill(0,count($chunk),'?')).')',array_merge($ids,$chunk)));
            $this->relations['telefonia_ventas_resultados']=TelefoniaResultadoVentasCatalogo::completarRegistros($this->db,$this->relations['telefonia_ventas_resultados']);
        }
        foreach(array_keys($refs) as $ref)$this->events[$ref]=[];
        if($refs&&$this->tabla('telefonia_zadarma_eventos'))foreach(array_chunk(array_keys($refs),500) as $chunk)foreach($this->rows('SELECT * FROM telefonia_zadarma_eventos WHERE pbx_call_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY id',$chunk) as $e)$this->events[$e['pbx_call_id']][]=$e;
        foreach($sales as $uid=>$calls)foreach(array_chunk($calls,500) as $chunk){
            foreach($chunk as &$r)$r=array_merge($r,TelefoniaVentasLlamadasService::hechos($this->eventos($r['pbx_call_id']),$r['extension'],(string)($r['origen_extension']??'')));unset($r);
            foreach((new TelefoniaVentasEvidenciaService($this->db))->enriquecer($chunk,(int)$uid) as $r)$this->salesFacts[$uid.'|'.$r['extension'].'|'.$r['pbx_call_id']]=$r;
        }
    }
    private const SOURCES=[
        'venta'=>['telefonia_ventas_llamadas','fecha'],
        'interaccion'=>['interacciones_vinculacion','fecha_inicio'],
        'entrante'=>['telefonia_analista_entrantes','created_at'],
        'sesion'=>['telefonia_analista_webrtc_sesiones','fecha_inicio'],
    ];
    public function obtener(int $supervisor,string $key,bool $audio=false): array
    {
        $this->acceso($supervisor,$audio);
        if(!preg_match('/^(venta|interaccion|entrante|sesion):([1-9][0-9]{0,18})$/D',$key,$m))throw new InvalidArgumentException('Llamada inválida.');
        $area=$m[1]==='venta'?'asesores':'analistas';$people=$this->personas($supervisor,$area,$audio);
        $ids=array_map('intval',array_column($people,'id'));$table=self::SOURCES[$m[1]][0];
        if(!$ids||!$this->tabla($table))throw new DomainException('Llamada fuera de tu alcance.');
        $rows=$this->rows("SELECT * FROM $table WHERE id=? AND usuario_id IN (".implode(',',array_fill(0,count($ids),'?')).')',array_merge([$m[2]],$ids));
        if(count($rows)!==1||($m[1]==='interaccion'&&$rows[0]['canal']!=='LLAMADA_IP'))throw new DomainException('Llamada fuera de tu alcance.');
        return $this->normalizar($m[1],$rows[0],$people);
    }
    public function listado(int $uid,array $f): array
    {
        $people=$this->personas($uid,$f['area']);$ids=array_map('intval',array_column($people,'id'));
        if($f['persona_id']&&!in_array($f['persona_id'],$ids,true))throw new DomainException('La persona seleccionada está fuera de tu equipo.');
        if($f['persona_id'])$ids=[$f['persona_id']];
        $all=[];$raw=[];
        if($ids)foreach(self::SOURCES as $source=>[$table,$date]){
            if(($f['area']==='asesores')!==($source==='venta')||!$this->tabla($table))continue;
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $rows=$this->rows("SELECT * FROM $table WHERE usuario_id IN ($marks) AND $date BETWEEN ? AND ?".
                ($source==='interaccion'?" AND canal='LLAMADA_IP'":'')." ORDER BY $date DESC,id DESC LIMIT 3001",array_merge($ids,[$f['desde'].' 00:00:00',$f['hasta'].' 23:59:59']));
            if(count($rows)>3000)throw new InvalidArgumentException('Hay demasiadas llamadas. Reduce el periodo o selecciona una persona.');
            foreach($rows as $row)$raw[]=[$source,$row];
        }
        if(count($raw)>3000)throw new InvalidArgumentException('Hay demasiadas llamadas. Reduce el periodo o selecciona una persona.');
        if($raw){$this->precargar($raw,$ids);foreach($raw as [$source,$row])$all[]=$this->normalizar($source,$row,$people);}
        // Mismo vínculo exacto: priorizar la interacción y después la clasificación.
        // Las sesiones sin vínculo inequívoco conservan su identidad independiente.
        $canonical=[];
        foreach($all as $call){
            $identity=$call['usuario_id'].'|'.($call['pbx_call_id']!==''?'ref:'.$call['extension'].'|'.$call['pbx_call_id']:($call['interaccion_id']?'iv:'.$call['interaccion_id']:($call['session_id']!==''?'sesion:'.$call['session_id']:'key:'.$call['key'])));
            if(!isset($canonical[$identity]))$canonical[$identity]=$call;
        }
        $calls=array_values(array_filter($canonical,static fn($c)=>
            ($f['direccion']===''||$c['direccion']===$f['direccion'])&&
            ($f['resultado']===''||in_array($f['resultado'],$c['resultados_filtro'],true))&&
            ($f['buscar']===''||mb_stripos($c['persona'].' '.$c['numero'].' '.$c['nombre'].' '.$c['resultado_texto'],$f['buscar'])!==false)));
        usort($calls,static fn($a,$b)=>strcmp($b['fecha'],$a['fecha'])?:strcmp($b['key'],$a['key']));
        $results=[];foreach($canonical as $c)foreach($c['resultados_codigos'] as $code)$results[$code]=self::textoResultado($code);asort($results);
        $total=count($calls);$pages=max(1,(int)ceil($total/30));$page=min($pages,$f['pagina']);
        return ['personas'=>$people,'llamadas'=>array_slice($calls,($page-1)*30,30),'total'=>$total,'paginas'=>$pages,'pagina'=>$page,'resultados'=>$results];
    }
    /** Resultado comercial: nunca inventar uno para llamadas sin conexión. */
    public static function resultadoAuditable(
        string $source, string $direction, bool $finalizada, bool $conectada, string $result
    ): array {
        $guardado=$result!=='';
        $noRequiere=$source==='venta'&&$direction==='Saliente'&&$finalizada&&!$conectada;
        $estado=$guardado?'Resultado registrado':
            ($noRequiere?'No requiere resultado':
            ($finalizada&&$conectada?'Sin resultado registrado':'Sin resultado confirmado'));
        return ['guardado'=>$guardado,'estado'=>$estado];
    }

    private function normalizar(string $source,array $r,array $people): array
    {
        $uid=(int)$r['usuario_id'];$person=array_values(array_filter($people,static fn($p)=>(int)$p['id']===$uid))[0];
        $ref=(string)($r['pbx_call_id']??'');$ext=(string)($r['extension']??'');$iv=$source==='interaccion'?(int)$r['id']:(int)($r['interaccion_id']??0);
        $session=$source==='sesion'?(string)$r['session_id']:'';$classification=(string)($r['clasificacion']??$r['estado']??'');$linked=[];
        if($iv&&$source!=='interaccion'){
            $valid=$this->relacionados('interacciones_vinculacion',['id'=>$iv,'usuario_id'=>$uid,'canal'=>'LLAMADA_IP']);
            if(!$valid)$iv=0;
        }
        if($source==='interaccion'){
            $external=(string)($r['id_externo']??'');
            if(str_starts_with($external,'WEBRTC-'))$session=substr($external,7);else $ref=$external;
            if($this->tabla('telefonia_analista_entrantes'))$linked=$this->relacionados('telefonia_analista_entrantes',['usuario_id'=>$uid,'interaccion_id'=>$iv]);
            if(count($linked)===1){$ext=(string)$linked[0]['extension'];$ref=(string)$linked[0]['pbx_call_id'];}
            if($this->tabla('telefonia_analista_webrtc_sesiones')){
                $s=$this->relacionados('telefonia_analista_webrtc_sesiones',['usuario_id'=>$uid,'interaccion_id'=>$iv]);
                if(count($s)===1){$session=(string)$s[0]['session_id'];$ext=(string)$s[0]['extension'];$classification=(string)$s[0]['clasificacion'];}
            }
        }
        if(str_starts_with($ref,'sdk-')){$session=$ref;$ref='';}
        if($session!==''&&$this->tabla('telefonia_transferencia_operaciones')){
            if(isset($this->relations['telefonia_transferencia_operaciones']))$ops=array_values(array_filter($this->relations['telefonia_transferencia_operaciones'],static fn($o)=>(int)$o['destino_usuario']===$uid&&(string)$o['destino_extension']===$ext&&($o['sdk_consulta']===$session||$o['sdk_consulta'].'-external'===$session)));
            else $ops=$this->rows('SELECT referencia FROM telefonia_transferencia_operaciones WHERE destino_usuario=? AND destino_extension=BINARY ? AND estado=\'ENTREGADA\' AND (sdk_consulta=BINARY ? OR CONCAT(sdk_consulta,\'-external\')=BINARY ?) LIMIT 2',[$uid,$ext,$session,$session]);
            if(count($ops)===1)$ref=(string)$ops[0]['referencia'];
        }
        $events=$this->eventos($ref);
        if($ext===''&&$source==='interaccion'&&str_starts_with($ref,'out_')){
            $starts=[];foreach($events as $e)if($e['evento']==='NOTIFY_OUT_START'&&preg_match('/^[0-9]{3,6}$/D',(string)$e['internal']))$starts[(string)$e['internal']]=true;
            if(count($starts)===1)$ext=(string)array_key_first($starts);
        }
        $facts=TelefoniaVentasLlamadasService::hechos($events,$ext,(string)($r['origen_extension']??''));
        // La evidencia PBX es la fuente de estado; el enriquecimiento
        // posterior de Ventas no debe borrarla accidentalmente.
        $pbxFacts=$facts;
        $result=(string)($r['resultado']??($source==='sesion'?$classification:($source==='entrante'?$r['estado']:'')));
        $note=(string)($r['notas']??$r['observacion']??'');$name=(string)($r['nombre']??'');
        $registroVenta=null;
        if($source==='venta'){
            $precargado=isset($this->relations['telefonia_ventas_resultados']);
            $saved=$this->relacionados('telefonia_ventas_resultados',['usuario_id'=>$uid,'extension'=>$ext,'pbx_call_id'=>$ref]);
            if(!$precargado)$saved=TelefoniaResultadoVentasCatalogo::completarRegistros($this->db,$saved);
            $registroVenta=$saved[0]??null;
            $result=(string)($saved[0]['resultado']??'');$note=(string)($saved[0]['observacion']??'');$name=(string)($saved[0]['prospecto_nombre']??$name);
            if(isset($this->salesFacts[$uid.'|'.$ext.'|'.$ref]))$facts=$this->salesFacts[$uid.'|'.$ext.'|'.$ref];
            else{$enriched=(new TelefoniaVentasEvidenciaService($this->db))->enriquecer([array_merge($r,$facts)],$uid);$facts=$enriched[0];}
        }
        $direction=(string)($r['direccion']??'Entrante');
        if($source==='interaccion')$direction=$linked||$session!==''||str_starts_with($ref,'in_')?'Entrante':'Saliente';
        $duration=$facts['segundos']??null;$observed=false;
        if($duration===null&&$source==='interaccion'&&$r['duracion_segundos']!==null){$duration=max(0,(int)$r['duracion_segundos']);$observed=true;}
        if($duration===null&&$source==='sesion'&&$r['duracion_observada_segundos']!==null){$duration=max(0,(int)$r['duracion_observada_segundos']);$observed=true;}
        $conectada=!empty($facts['conectada']);
        $finalizada=!empty($facts['finalizada']);
        $estadoPbx=trim((string)($facts['estado_pbx']??$pbxFacts['estado_pbx']??''));
        if($estadoPbx==='')$estadoPbx=$conectada?'Conectada':
            ($finalizada?'Sin conexión confirmada':'Sin cierre confirmado');
        // No se crea ni inventa un resultado. Un registro requiere una
        // clasificación persistida de ese usuario/extensión/tramo.
        $audit=self::resultadoAuditable($source,$direction,$finalizada,$conectada,$result);
        $resultadoGuardado=$audit['guardado'];
        $resultadoEstado=$audit['estado'];
        $resultadoTexto=$resultadoGuardado?($source==='venta'?TelefoniaResultadoVentasCatalogo::textoRegistro($registroVenta):self::textoResultado($result)):$resultadoEstado;
        $number=(string)($r['numero']??$r['telefono_destino']??$r['numero_origen']??'');
        if($number==='')foreach($events as $event){$payload=json_decode((string)($event['payload_json']??''),true)?:[];$number=(string)($event['caller_id']??$payload['caller_id']??'');if($number!=='')break;}
        return ['key'=>$source.':'.$r['id'],'source'=>$source,'usuario_id'=>$uid,'persona'=>$person['nombre'],'foto_perfil'=>$person['foto_perfil'],
            'activo'=>(bool)$person['activo'],'area'=>$source==='venta'?'asesores':'analistas','extension'=>$ext,'pbx_call_id'=>$ref,'session_id'=>$session,
            'interaccion_id'=>$iv,'seguimiento_id'=>(int)($r['seguimiento_id']??0),'clasificacion'=>$classification,
            'fecha'=>(string)($r['fecha']??$r['fecha_inicio']??$r['created_at']), 'direccion'=>$direction,
            'numero'=>$number,'nombre'=>$name,
            'resultado'=>$result,'resultado_texto'=>$resultadoTexto,
            'resultados_codigos'=>$source==='venta'?TelefoniaResultadoVentasCatalogo::seleccionesRegistro($registroVenta):($result!==''?[$result]:[]),
            'resultados_filtro'=>$source==='venta'?TelefoniaResultadoVentasCatalogo::codigosFiltroRegistro($registroVenta):($result!==''?[$result]:[]),
            'detalle_resultado'=>$source==='venta'?TelefoniaResultadoVentasCatalogo::aclaracionesRegistro($registroVenta):'',
            'resultado_guardado'=>$resultadoGuardado,'resultado_estado'=>$resultadoEstado,
            'estado_pbx'=>$estadoPbx,'observacion'=>$note,
            'segundos'=>$duration,'observada'=>$observed,'conectada'=>(bool)$facts['conectada'],'finalizada'=>(bool)$facts['finalizada'],
            'origen_extension'=>(string)($r['origen_extension']??''),'solicitud_id'=>$r['solicitud_id']??null,'hechos'=>$facts,
            'audio_consultable'=>$source==='venta'?($facts['conectada']&&TelefoniaResultadoVentasService::esConversacion($result)&&($facts['finalizada']||(!empty($r['origen_extension'])&&!empty($r['solicitud_id'])))):($facts['conectada']||($session!==''&&$classification==='INTERNA'))];
    }
    public static function textoResultado(string $value): string
    {
        if($value==='')return 'Sin resultado registrado';
        $labels=TelefoniaResultadoVentasCatalogo::opcionesConsulta()+['CONTACTADO'=>'Contacto establecido','NO_CONTESTO'=>'No contestó','INTERNA'=>'Llamada interna','SIN_IDENTIFICAR'=>'Sin identificar','INSTITUCION'=>'Institución','PENDIENTE'=>'Pendiente de clasificación'];
        if(isset($labels[$value]))return $labels[$value];
        return mb_convert_case(mb_strtolower(str_replace('_',' ',$value),'UTF-8'),MB_CASE_TITLE,'UTF-8');
    }
}
