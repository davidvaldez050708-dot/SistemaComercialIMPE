<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/services/TelefoniaRecepcionMarketingService.php';
function operarCheck(bool $ok,string $message): void {
    if (!$ok) {fwrite(STDERR,"FALLO recepción: $message\n");exit(1);}
}
function eventoOperacion(string $id,string $event,string $ext,array $extra=[]): array {
    return array_merge(['pbx_call_id'=>$id,'evento'=>$event,'internal'=>$ext,
        'caller_id'=>'7777885173','destination'=>'7777885173','received_at'=>'2026-10-16 12:00:00',
        'duration'=>0,'disposition'=>'','payload_json'=>'{}'],$extra);
}
$events=[];
foreach (['transferred','answered-transfer','busy','unallocated','voicemail','foreign-transfer'] as $id) {
    $events[]=eventoOperacion($id,'NOTIFY_OUT_START','100');
}
$events[]=eventoOperacion('transferred','NOTIFY_INTERNAL','101',['payload_json'=>'{"transfer_from":"100"}']);
$events[]=eventoOperacion('transferred','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>8,'is_recorded'=>1]);
$events[]=eventoOperacion('answered-transfer','NOTIFY_ANSWER','101',['payload_json'=>'{"transfer_from":"100"}']);
$events[]=eventoOperacion('answered-transfer','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>11]);
$events[]=eventoOperacion('busy','NOTIFY_ANSWER','100');
$events[]=eventoOperacion('busy','NOTIFY_OUT_END','100',['disposition'=>'busy']);
$events[]=eventoOperacion('unallocated','NOTIFY_OUT_END','100',['disposition'=>'unallocated number']);
$events[]=eventoOperacion('voicemail','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>20]);
$events[]=eventoOperacion('foreign-transfer','NOTIFY_INTERNAL','101',['payload_json'=>'{"transfer_from":"102"}']);
$events[]=eventoOperacion('foreign-transfer','NOTIFY_OUT_END','100',['disposition'=>'answered']);
$events[]=eventoOperacion('foreign-call','NOTIFY_OUT_START','102');
$events[]=eventoOperacion('bad-extension','NOTIFY_OUT_START','100');
$events[]=eventoOperacion('bad-extension','NOTIFY_INTERNAL','<script>',['payload_json'=>'{"transfer_from":"100"}']);
$calls=array_column(TelefoniaRecepcionMarketingService::agruparEventos($events,'100',true),null,'pbx_call_id');
operarCheck($calls['transferred']['estado']==='Transferida' && $calls['transferred']['destino_transferencia']==='101',
    'saliente transferida muestra 101');
operarCheck($calls['transferred']['tiene_grabacion'] && $calls['transferred']['grabacion_transferida'],
    'audio de transferencia por ruta de segmentos');
operarCheck($calls['answered-transfer']['destino_transferencia']==='101','transfer_from en NOTIFY_ANSWER');
operarCheck($calls['busy']['estado']==='Ocupada','el cierre PBX prevalece sobre una respuesta intermedia');
operarCheck($calls['unallocated']['estado']==='Número inexistente','resultado explícito del proveedor');
operarCheck($calls['voicemail']['estado']==='Completada','no inferir buzón ni contacto humano desde answered');
operarCheck($calls['foreign-transfer']['estado']==='Completada' && $calls['foreign-transfer']['destino_transferencia']==='',
    'no adjudicar transferencia de otra extensión');
operarCheck($calls['bad-extension']['destino_transferencia']==='','validar extensión transferida');
operarCheck(!isset($calls['foreign-call']),'aislamiento de llamadas de otra extensión');
operarCheck(TelefoniaRecepcionMarketingService::agruparEventos($events,'100',false)===[],'permiso saliente');

// Doble de base de datos: comprueba persistencia y las guardas de servicio
// sin abrir conexiones ni modificar la base de datos de producción.
class OperacionResult {
    private int $index=0;
    public function __construct(private array $rows) {}
    public function fetch_assoc() {return $this->rows[$this->index++] ?? null;}
    public function fetch_all($mode) {return $this->rows;}
}
class OperacionStmt {
    private array $params=[];
    public function __construct(private OperacionDB $db,private string $sql) {}
    public function bind_param($types,&...$params) {$this->params=&$params;}
    public function execute() {
        if (str_starts_with($this->sql,'INSERT INTO')) {
            [$user,$ext,$id,$code,$note]=$this->params;
            $this->db->saved[]=['usuario_id'=>$user,'extension'=>$ext,'pbx_call_id'=>$id,'resultado'=>$code,'nota'=>$note];
        }
        return true;
    }
    public function get_result() {
        if (str_contains($this->sql,'SELECT e.evento')) return new OperacionResult($this->db->events);
        return new OperacionResult(array_values(array_filter($this->db->saved,
            fn($r)=>$r['usuario_id']===$this->params[0] && $r['extension']===$this->params[1])));
    }
    public function close() {}
}
class OperacionDB {
    public array $saved=[];
    public int $creates=0;
    public bool $table=false;
    public function __construct(public array $events) {}
    public function prepare($sql) {
        if (str_contains($sql,'SELECT pbx_call_id, resultado, nota') && !$this->table)
            throw new mysqli_sql_exception('table missing',1146);
        return new OperacionStmt($this,$sql);
    }
    public function query($sql) {$this->creates++;$this->table=true;return true;}
}
class OperacionServicio extends TelefoniaRecepcionMarketingService {
    public function __construct($db) {
        $property=new ReflectionProperty(TelefoniaRecepcionMarketingService::class,'db');
        $property->setValue($this,$db);
    }
    public function asignacion(int $usuarioId): ?array {
        return $usuarioId===7 ? ['extension'=>'100','asignada_desde'=>'2026-10-01 00:00:00','permite_salientes'=>true] : null;
    }
}
$db=new OperacionDB($events);
$service=new OperacionServicio($db);
$summary=$service->resumen(7);
operarCheck($db->creates===0,'leer historial no crea tabla de resultados');
operarCheck($summary['transferidas']===2,'contabilizar transferencias salientes');
$saved=$service->guardarResultado(7,'voicemail','BUZON','Volver a llamar por la tarde');
operarCheck($saved['resultado_etiqueta']==='Buzón de voz' && count($db->saved)===1,'persistir resultado observado');
$summary=$service->resumen(7);
$savedCalls=array_column($summary['llamadas'],null,'pbx_call_id');
operarCheck($savedCalls['voicemail']['resultado']==='BUZON' && $savedCalls['voicemail']['estado']==='Completada',
    'resultado separado de evidencia PBX');
operarCheck($summary['sin_contacto']===3,'sin contacto incluye buzón manual y fallos PBX');
foreach ([[8,'voicemail','BUZON',''],[7,'foreign-call','BUZON',''],[7,'bad-extension','BUZON',''],
    [7,'voicemail','INVENTADO',''],[7,'voicemail','BUZON',str_repeat('x',501)]] as $args) {
    try {$service->guardarResultado(...$args);operarCheck(false,'rechazar resultado ajeno, activo o inválido');}
    catch (DomainException|InvalidArgumentException $error) {}
}
operarCheck(count($db->saved)===1,'intentos rechazados no escriben resultados');
echo "Recepción operativa: OK (transferencias, estados, persistencia, permisos y límites).\n";
