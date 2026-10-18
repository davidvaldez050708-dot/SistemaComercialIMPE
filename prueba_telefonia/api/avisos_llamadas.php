<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaRecepcionMarketingService.php';
require_once $root . '/app/services/TelefoniaEntrantesAnalistaService.php';
function responderAvisos(array $data, int $status=200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') responderAvisos(['ok'=>false],405);
$id=(int)($_SESSION['usuario_id'] ?? 0);
if (!$id) responderAvisos(['ok'=>false],401);
$roles=new RolModel();$roles->inicializarPermisosSistema();
$_SESSION['permisos']=$roles->obtenerCodigosPermisosPorRol((int)($_SESSION['rol_id'] ?? 0));
$recepcion=TelefoniaRecepcionMarketingService::tienePermisos();
$analista=strcasecmp(trim((string)($_SESSION['rol'] ?? '')),'Analista de Datos')===0 &&
    tienePermiso('telefonia.usar') && tienePermiso('telefonia.recibir') &&
    tienePermiso('seguimientos_vinculacion.operar_propios');
if (!$recepcion && !$analista) responderAvisos(['ok'=>false],403);
session_write_close();
try {
    $data=$recepcion?(new TelefoniaRecepcionMarketingService())->resumen($id):
        (new TelefoniaEntrantesAnalistaService())->listar($id);
    $calls=[];
    foreach ($data['llamadas'] as $call) {
        if (!$recepcion && ($call['tipo_llamada'] ?? '')==='INTERNA') continue;
        $final=$recepcion?!empty($call['finalizada']):in_array($call['estado_pbx'] ?? '',['ended','transferred'],true);
        $answered=$recepcion?!empty($call['atendida']):!empty($call['contestada']);
        $calls[]=[
            'id'=>(string)$call['pbx_call_id'],
            'aliases'=>array_values(array_filter([(string)($call['sdk_session_id'] ?? '')])),
            'numero'=>(string)($call['numero'] ?? ''),
            'fecha_ts'=>(strtotime((string)($call['fecha'] ?? '')) ?: 0)*1000,
            'fin_ts'=>(strtotime((string)($call['fecha_fin'] ?? '')) ?: 0)*1000,
            'finalizada'=>$final,'atendida'=>$answered,
            'perdida'=>$final && !$answered && (!$recepcion || ($call['tipo'] ?? '')==='Entrante'),
            'requiere_resultado'=>$recepcion && !empty($call['requiere_resultado']),
            'transferida'=>$recepcion && ($call['estado'] ?? '')==='Transferida',
            'destino'=>(string)($call['destino_transferencia'] ?? ''),
            'tipo'=>(string)($call['tipo'] ?? 'Entrante')
        ];
    }
    responderAvisos(['ok'=>true,'extension'=>$data['extension'],'llamadas'=>$calls]);
}catch(Throwable $e){
    error_log('[avisos_llamadas] '.$e->getMessage());
    responderAvisos(['ok'=>false,'mensaje'=>'No se pudo actualizar el cierre de llamadas.'],500);
}
