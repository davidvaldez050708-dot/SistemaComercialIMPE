<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaGrabacionesTransferenciaInstitucionService.php';
function tcheck(bool $v,string $text):void{if(!$v)throw new RuntimeException('FALLO: '.$text);}
function forbidden(callable $fn,string $text):void{
    try{$fn();}catch(DomainException $e){return;}
    throw new RuntimeException('Se autorizó incorrectamente: '.$text);
}
$ev=static fn(string $evento,string $internal='',string $id=''):array=>
 ['evento'=>$evento,'internal'=>$internal,'call_id_with_rec'=>$id];
$events=[
 $ev('NOTIFY_OUT_START','100'),
 $ev('NOTIFY_ANSWER','100'),
 $ev('NOTIFY_OUT_END','100'), // Antes de que el 101 conteste.
 $ev('NOTIFY_RECORD','','1439981389.2702773'),
 $ev('NOTIFY_ANSWER','101'),
 $ev('NOTIFY_RECORD','','1439981389.2702884')
 // NO existe END del 101.
];
$ids=TelefoniaGrabacionesTransferenciaInstitucionService::referencias($events,'100','101');
tcheck($ids===['1439981389.2702773','1439981389.2702884'],
 'Dos tramos en la misma referencia PBX sin END del Analista');
$copy=$events;$copy[]=$ev('NOTIFY_RECORD','','1439981389.2702773');
tcheck(count(TelefoniaGrabacionesTransferenciaInstitucionService::referencias($copy,'100','101'))===2,
 'Dos eventos RECORD idénticos no duplican audio');
$noAnswer=array_filter($events,static fn($x)=>!($x['evento']==='NOTIFY_ANSWER'&&$x['internal']==='101'));
forbidden(static fn()=>TelefoniaGrabacionesTransferenciaInstitucionService::referencias($noAnswer,'100','101'),
 'No contestó 101');
$noStart=array_filter($events,static fn($x)=>!($x['evento']==='NOTIFY_OUT_START'));
forbidden(static fn()=>TelefoniaGrabacionesTransferenciaInstitucionService::referencias($noStart,'100','101'),
 'Sin saliente inicial de Recepción');
$third=$events;$third[]=$ev('NOTIFY_RECORD','150','1439981389.2702995');
forbidden(static fn()=>TelefoniaGrabacionesTransferenciaInstitucionService::referencias($third,'100','101'),
 'Audio atribuido a una tercera extensión');
$last=$events;$last[]=$ev('NOTIFY_END','100')+['last_internal'=>'150'];
forbidden(static fn()=>TelefoniaGrabacionesTransferenciaInstitucionService::referencias($last,'100','101'),
 'last_internal de una tercera extensión');
$foreign=$events;$foreign[]=$ev('NOTIFY_RECORD','','https://externo.invalido/audio');
tcheck(count(TelefoniaGrabacionesTransferenciaInstitucionService::referencias($foreign,'100','101'))===2,
 'No aceptar URLs o IDs arbitrarios desde eventos');
$tooMany=$events;
for($i=0;$i<7;$i++)$tooMany[]=$ev('NOTIFY_RECORD','','1439981389.2703'.str_pad((string)$i,3,'0',STR_PAD_LEFT));
forbidden(static fn()=>TelefoniaGrabacionesTransferenciaInstitucionService::referencias($tooMany,'100','101'),
 'Límite de 8 segmentos');
forbidden(static fn()=>TelefoniaGrabacionesTransferenciaInstitucionService::referencias($events,'101','101'),
 'Origen y destino idénticos');
echo "OK: analista recibe dos RECORD 100->101 sin END, con permisos y pruebas adversas\n";
