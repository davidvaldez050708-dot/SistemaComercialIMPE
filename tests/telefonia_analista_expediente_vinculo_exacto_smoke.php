<?php
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
function assertAudio(bool $v,string $why): void {if(!$v)throw new RuntimeException('FALLO: '.$why);}
$real='sdk-123456789012abcd-external';
$session=TelefoniaGrabacionExpedienteTransferidaService::sessionIdExterno('WEBRTC-'.$real);
assertAudio($session===$real,'WebRTC external exacta');
assertAudio(TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(
 'sdk-123456789012abcd',$session),'Consultada real seguida de -external');
assertAudio(TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(
 $real,$real),'Sesion directa con ID identico');
foreach(['sdk-123456789012abcd-other','sdk-123456789012abc','',
         'sdk-123456789012abcd-external-extra'] as $foreign)
    assertAudio(!TelefoniaGrabacionExpedienteTransferidaService::sdkDeTransferenciaCoincide(
        $foreign,$real),'No asociar otra RTCSession: '.$foreign);
foreach(['','123456789012abcd','PBX-out_123','WEBRTC-out_123456789012abcd',
         'WEBRTC-sdk-abc','WEBRTC-sdk-123456789012ABCD',
         'WEBRTC-sdk-123456789012abcd?'] as $invalid)
    assertAudio(TelefoniaGrabacionExpedienteTransferidaService::sessionIdExterno($invalid)===null,
        'No aceptar ID no coincidente: '.$invalid);
$code=file_get_contents(__DIR__.'/../app/services/TelefoniaGrabacionExpedienteTransferidaService.php');
assertAudio(strpos($code,"WHERE usuario_id=? AND seguimiento_id=? AND interaccion_id=?")!==false,
    'Solo interacción registrada en seguimiento del analista');
assertAudio(strpos($code,"AND session_id=BINARY ?")!==false,
    'Exigir misma identidad SDK, sin aproximación');
assertAudio(strpos($code,"sdk_consulta=BINARY ? OR CONCAT(sdk_consulta,'-external')=BINARY ?")!==false,
    'Buscar transferencia por token exacto');
assertAudio(strpos($code,'$audios->verificar(')!==false,
    'El permiso se valida por operación, transferencia institucional y respuesta del 101');
echo "OK: expediente vincula interacción -> sesión SDK -> transferencia PBX exacta, sin cruce de llamadas.\n";
