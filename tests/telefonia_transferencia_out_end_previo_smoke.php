<?php
declare(strict_types=1);
/* Regresión sin BD: cierre OUT_END del origen 100 antes de ANSWER 150.
 * No crea datos, no accede a Zadarma ni necesita sesión web.
 */
require_once dirname(__DIR__).'/app/services/ZadarmaWebhookEventStoreService.php';
$instance=(new ReflectionClass(ZadarmaWebhookEventStoreService::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(ZadarmaWebhookEventStoreService::class,'construirEstadoEntrante');
$event=static function(string $name,string $internal,string $time,array $extra=[]):array {
    return array_merge(['event'=>$name,'internal'=>$internal,'pbx_call_id'=>'pbx-transfer-test',
        'received_at'=>'2026-10-21 '.$time,'caller_id'=>'test','destination'=>'test',
        'call_start'=>'2026-10-21 20:10:23','duration'=>0,'disposition'=>'',
        'last_internal'=>'','transfer_from'=>''], $extra);
};
$start=$event('NOTIFY_OUT_START','100','20:10:23',['destination'=>'5555555555']);
$sourceAnswer=$event('NOTIFY_ANSWER','100','20:10:37');
$earlyEnd=$event('NOTIFY_OUT_END','100','20:10:56',['duration'=>19,'disposition'=>'answered']);
$ownAnswer=$event('NOTIFY_ANSWER','150','20:11:11',['transfer_from'=>'100']);
$ready=$event('NOTIFY_RECORD','','20:11:30');
$events=[$start,$sourceAnswer,$earlyEnd,$ownAnswer,$ready];
$check=static function(array $events,string $expected,int $duration=0) use ($instance,$method,$ownAnswer):void {
    $state=$method->invoke($instance,'150',$ownAnswer,$events);
    if($state['estado']!==$expected || (int)$state['duration']!==$duration)
        throw new RuntimeException('Estado inesperado: '.json_encode($state));
};
$check($events,'answered',0); // CALL sigue abierta según webhooks.
$check(array_merge($events,[$event('NOTIFY_END','100','20:11:38',
    ['last_internal'=>'150','duration'=>27,'disposition'=>'answered'])]),'ended',27);
$check(array_merge($events,[$event('NOTIFY_OUT_END','100','20:11:42',
    ['duration'=>75,'disposition'=>'answered'])]),'ended',31);
$check(array_merge($events,[$event('NOTIFY_END','101','20:11:38',
    ['duration'=>999,'disposition'=>'answered'])]),'answered',0);
echo "OK: OUT_END 100 previo no finaliza 150; END posterior propio, saliente posterior y terceros aislados\n";
