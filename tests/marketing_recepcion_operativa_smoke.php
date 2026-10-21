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
foreach (['transferred','answered-transfer','busy','unallocated','voicemail','race-answered','foreign-transfer'] as $id) {
    $events[]=eventoOperacion($id,'NOTIFY_OUT_START','100');
}
$events[]=eventoOperacion('transferred','NOTIFY_INTERNAL','101',['payload_json'=>'{"transfer_from":"100"}']);
$events[]=eventoOperacion('transferred','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>8,'is_recorded'=>1]);
$events[]=eventoOperacion('answered-transfer','NOTIFY_ANSWER','101',['payload_json'=>'{"transfer_from":"100"}']);
$events[]=eventoOperacion('answered-transfer','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>11]);
$events[]=eventoOperacion('busy','NOTIFY_ANSWER','100');
$events[]=eventoOperacion('busy','NOTIFY_OUT_END','100',['disposition'=>'busy']);
$events[]=eventoOperacion('unallocated','NOTIFY_OUT_END','100',['disposition'=>'unallocated number']);
$events[]=eventoOperacion('voicemail','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>20,'is_recorded'=>1]);
$events[]=eventoOperacion('race-answered','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>12]);
$events[]=eventoOperacion('foreign-transfer','NOTIFY_INTERNAL','101',['payload_json'=>'{"transfer_from":"102"}']);
$events[]=eventoOperacion('foreign-transfer','NOTIFY_OUT_END','100',['disposition'=>'answered']);
$events[]=eventoOperacion('foreign-call','NOTIFY_OUT_START','102');
$events[]=eventoOperacion('bad-extension','NOTIFY_OUT_START','100');
$events[]=eventoOperacion('bad-extension','NOTIFY_INTERNAL','<script>',['payload_json'=>'{"transfer_from":"100"}']);
$calls=array_column(TelefoniaRecepcionMarketingService::agruparEventos($events,'100',true),null,'pbx_call_id');
foreach (['101','150'] as $destino) {
    $c=TelefoniaRecepcionMarketingService::agruparEventos([
        eventoOperacion('out-last','NOTIFY_OUT_START','100'),
        eventoOperacion('out-last','NOTIFY_OUT_END',$destino,['disposition'=>'answered','duration'=>40,'is_recorded'=>1,'payload_json'=>json_encode(['last_internal'=>$destino])])
    ],'100',true)[0];
    operarCheck($c['tipo']==='Saliente' && $c['estado']==='Transferida' && $c['destino_transferencia']===$destino,'OUT_END firmado conserva dirección y destino sin INTERNAL');
    operarCheck($c['segundos']===40 && $c['tiene_grabacion'],'conservar duración y audio reportados');
}
$c=TelefoniaRecepcionMarketingService::agruparEventos([
    eventoOperacion('last-unanswered','NOTIFY_OUT_START','100'),
    eventoOperacion('last-unanswered','NOTIFY_OUT_END','100',['disposition'=>'no answer','payload_json'=>'{"last_internal":"150"}'])
],'100',true)[0];
operarCheck($c['estado']==='Sin respuesta','last_internal sin respuesta no acredita entrega');
operarCheck($calls['transferred']['estado']==='Transferida' && $calls['transferred']['destino_transferencia']==='101',
    'saliente transferida muestra 101');
operarCheck($calls['transferred']['tiene_grabacion'] && $calls['transferred']['grabacion_transferida'],
    'audio de transferencia por ruta de segmentos');
operarCheck($calls['answered-transfer']['destino_transferencia']==='101','transfer_from en NOTIFY_ANSWER');
operarCheck($calls['busy']['estado']==='Ocupada','el cierre PBX prevalece sobre una respuesta intermedia');
operarCheck($calls['unallocated']['estado']==='Número inexistente','resultado explícito del proveedor');
operarCheck($calls['voicemail']['estado']==='Completada','no inferir buzón ni contacto humano desde answered');
operarCheck($calls['voicemail']['tiene_grabacion'],'respuesta sin clasificar conserva audio reportado');
$falloTransferido=TelefoniaRecepcionMarketingService::agruparEventos([
    eventoOperacion('failed_transfer','NOTIFY_OUT_START','100'),
    eventoOperacion('failed_transfer','NOTIFY_ANSWER','100'),
    eventoOperacion('failed_transfer','NOTIFY_INTERNAL','101',['payload_json'=>'{"transfer_from":"100"}']),
    eventoOperacion('failed_transfer','NOTIFY_OUT_END','100',['disposition'=>'busy','is_recorded'=>1])
],'100',true)[0];
operarCheck($falloTransferido['estado']==='Transferida' && $falloTransferido['excluir_grabacion'] &&
    !$falloTransferido['tiene_grabacion'],'agrupador excluye cierre fallido con grabación reportada y transferencia');
foreach (['BUZON','NO_DISPONIBLE','NUMERO_INCORRECTO'] as $code) {
    foreach (['Entrante','Saliente'] as $type) {
        $excluded=TelefoniaRecepcionMarketingService::aplicarPoliticaGrabacion(
            array_merge($calls['transferred'],['tipo'=>$type,'resultado'=>$code]));
        operarCheck($excluded['excluir_grabacion'] && !$excluded['tiene_grabacion'] &&
            !$excluded['grabacion_transferida'] && !$excluded['grabacion_salida_pendiente'],
            'ocultar audio reportado de resultado negativo, también en transferencias');
        operarCheck($excluded['estado']==='Transferida' && $excluded['destino_transferencia']==='101',
            'conservar evidencia de transferencia');
    }
}
foreach (['Perdida','Sin respuesta','Ocupada','Cancelada','Fallida','Número inexistente',
    'No disponible','Sin saldo','Límite excedido'] as $state) {
    $excluded=TelefoniaRecepcionMarketingService::aplicarPoliticaGrabacion(
        array_merge($calls['transferred'],['estado_cierre'=>$state,'resultado'=>'CONTACTO']));
    operarCheck($excluded['excluir_grabacion'] && !$excluded['tiene_grabacion'],
        'cierre negativo prevalece incluso con estado Transferida o resultado contradictorio');
}
foreach (['CONTACTO','OTRO','SIN_CLASIFICAR',''] as $code) {
    $allowed=TelefoniaRecepcionMarketingService::aplicarPoliticaGrabacion(
        array_merge($calls['transferred'],['resultado'=>$code]));
    operarCheck(!$allowed['excluir_grabacion'] && $allowed['tiene_grabacion'],
        'conservar audio de conversación sin un resultado negativo conocido');
}
$sinAudio=TelefoniaRecepcionMarketingService::aplicarPoliticaGrabacion(
    ['estado'=>'Atendida','estado_cierre'=>'Atendida','resultado'=>'CONTACTO','tiene_grabacion'=>false]);
operarCheck(!$sinAudio['tiene_grabacion'],'un resultado positivo no inventa una grabación');
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
            foreach ($this->db->saved as $existing) {
                if ($existing['usuario_id']===$user && $existing['extension']===$ext && $existing['pbx_call_id']===$id) {
                    throw new mysqli_sql_exception('duplicate key',1062);
                }
            }
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
    public bool $race=false;
    public function __construct(public array $events) {}
    public function prepare($sql) {
        if (str_starts_with($sql,'INSERT INTO') && $this->race) {
            $this->saved[]=['usuario_id'=>7,'extension'=>'100','pbx_call_id'=>'race-answered','resultado'=>'CONTACTO','nota'=>'Primer envío'];
            $this->race=false;
        }
        if (str_contains($sql,'SELECT pbx_call_id, resultado, nota') && !$this->table)
            throw new mysqli_sql_exception('table missing',1146);
        return new OperacionStmt($this,$sql);
    }
    public function query($sql) {$this->creates++;$this->table=true;return true;}
}
class OperacionServicio extends TelefoniaRecepcionMarketingService {
    // Esta prueba aísla agrupación/resultados. Las operaciones se prueban con MySQL real.
    protected function operacionesDelUsuario(int $usuarioId): array {return [];}
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
operarCheck($saved['excluir_grabacion'] && !$saved['tiene_grabacion'],
    'respuesta de guardado oculta audio inmediatamente');
$summary=$service->resumen(7);
$savedCalls=array_column($summary['llamadas'],null,'pbx_call_id');
operarCheck($savedCalls['voicemail']['resultado']==='BUZON' && $savedCalls['voicemail']['estado']==='Completada',
    'resultado separado de evidencia PBX');
operarCheck($savedCalls['voicemail']['excluir_grabacion'] && !$savedCalls['voicemail']['tiene_grabacion'] &&
    !$savedCalls['voicemail']['grabacion_salida_pendiente'],'buzón queda sólo como historial después de recargar');
operarCheck($summary['sin_contacto']===3,'sin contacto incluye buzón manual y fallos PBX');
try {
    $service->guardarResultado(7,'voicemail','CONTACTO','Intento de edición');
    operarCheck(false,'resultado definitivo debe rechazar cambios');
} catch (DomainException $error) {
    operarCheck($error->getCode()===409,'resultado registrado responde conflicto');
}
operarCheck(count($db->saved)===1 && $db->saved[0]['resultado']==='BUZON' &&
    $db->saved[0]['nota']==='Volver a llamar por la tarde','conservar resultado y nota originales');
foreach ([[8,'voicemail','BUZON',''],[7,'foreign-call','BUZON',''],[7,'bad-extension','BUZON',''],
    [7,'voicemail','INVENTADO',''],[7,'voicemail','BUZON',str_repeat('x',501)]] as $args) {
    try {$service->guardarResultado(...$args);operarCheck(false,'rechazar resultado ajeno, activo o inválido');}
    catch (DomainException|InvalidArgumentException $error) {}
}
operarCheck(count($db->saved)===1,'intentos rechazados no escriben resultados');
$db->race=true;
try {
    $service->guardarResultado(7,'race-answered','BUZON','Segundo envío');
    operarCheck(false,'rechazar doble envío concurrente');
} catch (DomainException $error) {
    operarCheck($error->getCode()===409,'la clave única evita sobrescrituras concurrentes');
}
operarCheck(count($db->saved)===2 && $db->saved[1]['resultado']==='CONTACTO' &&
    $db->saved[1]['nota']==='Primer envío','el segundo envío no altera el primero');
echo "Recepción operativa: OK (transferencias, estados, persistencia, permisos y límites).\n";
