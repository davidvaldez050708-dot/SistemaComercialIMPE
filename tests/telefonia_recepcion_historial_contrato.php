<?php
declare(strict_types=1);
// El mismo contrato que cruza Recepción -> Mis llamadas; sin DB ni proveedor.
require_once __DIR__.'/../app/services/TelefoniaRecepcionMarketingService.php';
require_once __DIR__.'/../app/services/TelefoniaMisLlamadasService.php';
$checks=0;
$check=static function(bool $ok,string $message) use (&$checks): void {
    if(!$ok)throw new RuntimeException($message);
    $checks++;
};
$event=static function(string $ref,string $type,string $ext='100',array $extra=[]): array {
    return array_replace(['pbx_call_id'=>$ref,'evento'=>$type,'internal'=>$ext,
        'destination'=>'5550000001','caller_id'=>'5550000002',
        'received_at'=>'2026-10-24 10:00:00','duration'=>0,'payload_json'=>'{}'],$extra);
};
$cases=[
    'entrante atendida'=>[true,[$event('case-in','NOTIFY_INTERNAL'),$event('case-in','NOTIFY_ANSWER'),$event('case-in','NOTIFY_END')]],
    'saliente atendida'=>[true,[$event('case-out','NOTIFY_OUT_START'),$event('case-out','NOTIFY_OUT_END','100',['disposition'=>'answered','duration'=>12])]],
    'transferida con respuesta propia'=>[true,[$event('case-transfer','NOTIFY_INTERNAL'),$event('case-transfer','NOTIFY_ANSWER'),$event('case-transfer','NOTIFY_INTERNAL','150',['payload_json'=>'{"transfer_from":"100"}']),$event('case-transfer','NOTIFY_END')]],
    'perdida con duración'=>[false,[$event('case-lost','NOTIFY_INTERNAL'),$event('case-lost','NOTIFY_END','100',['duration'=>27])]],
    'ocupada'=>[false,[$event('case-busy','NOTIFY_OUT_START'),$event('case-busy','NOTIFY_OUT_END','100',['disposition'=>'busy','duration'=>15])]],
    'sin respuesta'=>[false,[$event('case-no-answer','NOTIFY_OUT_START'),$event('case-no-answer','NOTIFY_OUT_END','100',['disposition'=>'no answer'])]],
    'sólo grabación'=>[false,[$event('case-record','NOTIFY_INTERNAL'),$event('case-record','NOTIFY_RECORD','100',['is_recorded'=>1,'call_id_with_rec'=>'synthetic-record'])]],
    'respondió otra extensión'=>[false,[$event('case-foreign','NOTIFY_INTERNAL'),$event('case-foreign','NOTIFY_ANSWER','150'),$event('case-foreign','NOTIFY_END')]],
    'eventos desordenados'=>[true,[$event('case-order','NOTIFY_ANSWER'),$event('case-order','NOTIFY_INTERNAL'),$event('case-order','NOTIFY_END')]],
];
foreach($cases as $name=>[$expected,$events]){
    $calls=TelefoniaRecepcionMarketingService::agruparEventos($events,'100',true);
    $check(count($calls)===1,$name.': una sola comunicación');
    $check(!array_key_exists('contestada',$calls[0]),$name.': usar salida pública real');
    // resumen() elimina este auxiliar: nunca depender de él.
    unset($calls[0]['fue_contestada']);
    $rows=TelefoniaMisLlamadasService::normalizarMarketing($calls,7,'100');
    $check($rows[0]['conectada']===$expected,$name.': conexión correcta en historial');
    $check($rows[0]['usuario_id']===7 && $rows[0]['extension']==='100',$name.': propietario conservado');
}
$rows=TelefoniaMisLlamadasService::normalizarMarketing([
    ['pbx_call_id'=>'ambiguous','tipo'=>'Entrante','contestada'=>true,'segundos'=>90],
    ['pbx_call_id'=>'explicit-false','tipo'=>'Entrante','atendida'=>false,'contestada'=>true]
],7,'100');
$check(!$rows[0]['conectada']&&!$rows[1]['conectada'],'Ni duración ni campo interno confirman una llamada');
$check(TelefoniaRecepcionMarketingService::agruparEventos([
    $event('foreign-start','NOTIFY_OUT_START','150'),$event('foreign-start','NOTIFY_OUT_END','150',['disposition'=>'answered'])
],'100',true)===[],'No heredar llamadas ajenas');
echo "OK: $checks comprobaciones Recepción -> historial, sin llamadas reales.\n";
