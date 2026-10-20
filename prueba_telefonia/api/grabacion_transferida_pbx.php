<?php
/**
 * Segmentos PBX de una entrante transferida, para participantes verificados.
 * Nunca acepta call_id_with_rec ni URLs de audio aportados por el navegador.
 */
declare(strict_types=1);
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/db_connection.php';
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/services/TelefoniaRecepcionMarketingService.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';
require_once $root . '/app/helpers/ZadarmaPbxIdentifierHelper.php';
require_once $root . '/app/services/TelefoniaPbxSegmentosService.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit; }
$userId = (int)($_SESSION['usuario_id'] ?? 0);
$pbx = trim((string)($_GET['pbx_call_id'] ?? ''));
if ($userId < 1) { http_response_code(401); exit; }
if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $pbx)) { http_response_code(422); exit; }
$rol = new RolModel();
$rol->inicializarPermisosSistema();
$_SESSION['permisos'] = $rol->obtenerCodigosPermisosPorRol((int)($_SESSION['rol_id'] ?? 0));
$analista = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Analista de Datos') === 0
    && tienePermiso('telefonia.usar') && tienePermiso('telefonia.recibir')
    && tienePermiso('seguimientos_vinculacion.operar_propios');
$recepcion = TelefoniaRecepcionMarketingService::tienePermisos();
if (!$analista && !$recepcion) { http_response_code(403); exit; }
try {
    $db = (new Database())->connect();
    $q = $db->prepare("SELECT extension, GREATEST(created_at,updated_at) AS desde
        FROM telefonia_extensiones WHERE usuario_id=? AND proveedor='ZADARMA'
          AND activo=1 AND permite_entrantes=1 LIMIT 1");
    $q->bind_param('i', $userId);
    $q->execute();
    $assignment = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$assignment) { http_response_code(404); exit; }
    $ext = (string)$assignment['extension'];
    // Requiere evidencia de atención propia, fin PBX y una transferencia
    // realmente notificada; evita acceso por conocer identificadores.
    $q = $db->prepare("SELECT
        EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
           WHERE e.pbx_call_id=? AND e.internal=?
             AND e.evento='NOTIFY_INTERNAL' AND e.received_at>=?
             AND e.received_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)) AS participa,
        EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
           WHERE e.pbx_call_id=? AND e.internal=?
             AND e.evento='NOTIFY_ANSWER') AS atendio,
        EXISTS(SELECT 1 FROM telefonia_zadarma_eventos e
           WHERE e.pbx_call_id=? AND e.evento='NOTIFY_END') AS termino");
    $desde = (string)$assignment['desde'];
    $q->bind_param('ssssss', $pbx, $ext, $desde, $pbx, $ext, $pbx);
    $q->execute();
    $evidence = $q->get_result()->fetch_assoc();
    $q->close();
    $estadoPropio = (new ZadarmaWebhookEventStoreService($db))->obtenerEstadoEntrantePorPbxCallId($ext, $pbx);
    // Incluye la transferencia de una saliente: respuesta propia con
    // iniciador confirmado y fin de esa misma llamada original.
    $autorizada = $estadoPropio && !empty($estadoPropio['answer_at']) &&
        in_array($estadoPropio['estado'], ['ended','transferred'], true) &&
        !empty($estadoPropio['end_at']) &&
        strcmp((string)$estadoPropio['answer_at'], $desde) >= 0 &&
        strtotime((string)$estadoPropio['answer_at']) >= strtotime('-30 days');
    $autorizadas=[];
    if ($recepcion) {
        // El historial verifica también las salientes propias, su permiso,
        // asignación, fin y transferencia notificada antes de ofrecer segmentos.
        $autorizada = false;
        $historial = (new TelefoniaRecepcionMarketingService())->resumen($userId);
        foreach ($historial['llamadas'] as $call) {
            if ($call['pbx_call_id'] === $pbx && $call['estado'] === 'Transferida' &&
                !empty($call['finalizada']) && !empty($call['tiene_grabacion']) &&
                empty($call['excluir_grabacion']) &&
                !empty($call['destino_transferencia'])) {
                    $autorizada = true;
                    // Recepción ya podía consultar las partes de SU
                    // transferencia. El destino procede del historial privado.
                    $autorizadas[]=(string)$call['destino_transferencia'];break;
                }
        }
    }
    if (!$autorizada) { http_response_code(404); exit; }
    $q=$db->prepare('SELECT evento,internal,call_id_with_rec,is_recorded,payload_json FROM telefonia_zadarma_eventos WHERE pbx_call_id=? ORDER BY id');
    $q->bind_param('s',$pbx);$q->execute();$events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
    $ids=TelefoniaPbxSegmentosService::deEventos($events,$ext,$autorizadas)['segmentos'];
    if (!$ids) { http_response_code(404); exit; }
    $service = new ZadarmaRecordingService();
    $records = [];
    foreach ($ids as $id) {
        $record = $service->obtenerGrabacionPorCallId($id);
        if (!$record || empty($record['link'])) {
            if (isset($_GET['verificar'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>true,'disponible'=>false,'segmentos'=>0]); exit;
            }
            http_response_code(404); exit;
        }
        $records[]=$record;
    }
    if (isset($_GET['verificar'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'disponible'=>true,'segmentos'=>count($records)]);
        exit;
    }
    $index = filter_var($_GET['segmento'] ?? null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>0,'max_range'=>7]]);
    if ($index===false || $index===null || !isset($records[$index])) {
        http_response_code(404); exit;
    }
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range!=='' && !preg_match('/^bytes=\\d*-\\d*$/D',$range)) {
        http_response_code(416); exit;
    }
    $audio = $service->descargarGrabacion((string)$records[$index]['link'],$range);
    $body = (string)($audio['body'] ?? '');
    $type = strtolower(trim((string)($audio['content_type'] ?? '')));
    if ($body==='' || ($type!=='' && !str_starts_with($type,'audio/') &&
        $type!=='application/octet-stream')) { http_response_code(502); exit; }
    $mime = str_contains($type,'wav')?'audio/wav':
        (str_contains($type,'ogg')?'audio/ogg':
        (str_contains($type,'mp4')?'audio/mp4':'audio/mpeg'));
    header('Content-Type: '.$mime);
    if ((string)($_GET['download'] ?? '') === '1') {
        $extension = $mime === 'audio/wav' ? 'wav'
            : ($mime === 'audio/ogg' ? 'ogg'
            : ($mime === 'audio/mp4' ? 'm4a' : 'mp3'));
        header('Content-Disposition: attachment; filename="llamada-transferida-parte-'
            . ($index + 1) . '.' . $extension . '"');
    } else {
        header('Content-Disposition: inline');
    }
    header('Accept-Ranges: bytes');
    if ($range!=='' && (int)($audio['status']??200)===206 &&
        preg_match('~^bytes \\d+-\\d+/\\d+$~',
            (string)($audio['headers']['content-range']??''))) {
        header('Content-Range: '.$audio['headers']['content-range']);
        http_response_code(206);
    } else http_response_code(200);
    header('Content-Length: '.strlen($body));
    echo $body;
} catch (Throwable $error) {
    error_log('[audio_transferido_pbx] '.get_class($error));
    if (!headers_sent()) http_response_code(502);
    exit('Grabación temporalmente no disponible.');
}
