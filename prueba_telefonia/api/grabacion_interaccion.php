<?php
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/models/SeguimientoVinculacionModel.php';
require_once $root . '/app/services/ZadarmaRecordingService.php';
require_once $root . '/config/db_connection.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
    http_response_code(405);header('Allow: GET');exit;
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Sesión no activa.');
}

if (!tienePermiso('seguimientos_vinculacion.ver')) {
    http_response_code(403);
    exit('No tienes permiso para consultar esta grabación.');
}

$interaccionId = (int)($_GET['interaccion_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if ($interaccionId <= 0 || $usuarioId <= 0) {
    http_response_code(422);
    exit('Interacción no válida.');
}

try {
    $connection = Database::compartida();
} catch (Throwable $error) {
    if (Database::responderFalloTemporal($error, false)) exit;
    throw $error;
}
$sql = "SELECT
            id,
            seguimiento_id,
            usuario_id,
            canal,
            resultado,
            notas,
            proveedor_externo,
            id_externo,
            duracion_segundos
        FROM interacciones_vinculacion
        WHERE id = ?
        LIMIT 1";
$stmt = $connection->prepare($sql);
$stmt->bind_param('i', $interaccionId);
$stmt->execute();
$interaccion = $stmt->get_result()->fetch_assoc() ?: null;

if (!$interaccion || strtoupper((string)$interaccion['canal']) !== 'LLAMADA_IP') {
    http_response_code(404);
    exit('La llamada solicitada no existe.');
}

$seguimientoId = (int)$interaccion['seguimiento_id'];
$modelo = new SeguimientoVinculacionModel($connection);
$rolId = (int)($_SESSION['rol_id'] ?? 0);
$rolNombre = trim((string)($_SESSION['rol'] ?? ''));
$esConsultaGlobal =
    $rolId === 1 ||
    strcasecmp($rolNombre, 'Marketing') === 0;

if ($esConsultaGlobal) {
    $seguimiento = $modelo->obtenerSeguimientoAdministrador($seguimientoId);
} elseif (tienePermiso('seguimientos_vinculacion.supervisar')) {
    $seguimiento = $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
} else {
    $seguimiento = $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
}

if (!$seguimiento) {
    http_response_code(403);
    exit('No tienes acceso a esta grabación.');
}

$proveedor = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));
$idExterno = trim((string)($interaccion['id_externo'] ?? ''));
$duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));
$resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
$notas = (string)($interaccion['notas'] ?? '');
$excluirGrabacion =
    strpos($notas, '[BUZON_VOZ]') !== false ||
    strpos($notas, '[FUERA_SERVICIO]') !== false ||
    in_array(
        $resultado,
        ['NO_CONTESTO', 'SIN_RESPUESTA', 'OCUPADO', 'BUZON_VOZ', 'FUERA_SERVICIO', 'NUMERO_INCORRECTO'],
        true
    );

if ($excluirGrabacion) {
    http_response_code(404);
    exit('Esta llamada se conserva únicamente en el historial telefónico.');
}

if ($duracion <= 0 && $proveedor !== 'ZADARMA') {
    http_response_code(404);
    exit('Esta llamada no tiene una grabación asociada.');
}

$rangeHeader = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($rangeHeader !== '' && !preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
    http_response_code(416);
    header('Accept-Ranges: bytes');
    exit;
}

$forzarDescarga = (int)($_GET['download'] ?? 0) === 1;

try {
    $audio = null;

    if ($proveedor === 'ZADARMA') {
        require_once $root.'/app/services/TelefoniaGrabacionInteraccionService.php';
        require_once $root.'/app/services/TelefoniaGrabacionSelector.php';
        $catalog=(new TelefoniaGrabacionInteraccionService($connection))->resolver($interaccion);
        $ids=$catalog['ids'];
        if(isset($_GET['verificar'])){
            $info=[];$temporary=false;
            $servicio=new ZadarmaRecordingService(false);
            foreach($ids as $index=>$id){
                try{$record=$servicio->obtenerGrabacionPorCallId($id);
                    if(!empty($record['link']))$info[]=['indice'=>$index,'referencia'=>$id,'etiqueta'=>'Parte '.($index+1).' de la llamada'];
                }catch(Throwable $error){$temporary=true;}
            }
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
            echo json_encode(['ok'=>true,'disponible'=>(bool)$info,'segmentos'=>count($info),'segmentos_info'=>$info,
                'catalogo'=>TelefoniaGrabacionSelector::catalogo($ids),
                'estado'=>$info?(count($info)<count($ids)?'DISPONIBLE_PARCIAL':'DISPONIBLE'):($temporary?'ERROR_TEMPORAL':($ids?'GRABACION_EN_PREPARACION':'TRAMO_PENDIENTE_VINCULACION'))]);exit;
        }
        $index=TelefoniaGrabacionSelector::indice($ids,$_GET);
        $servicio=new ZadarmaRecordingService(false);
        $grabacion=$servicio->obtenerGrabacionPorCallId($ids[$index]);
        if(!$grabacion||empty($grabacion['link'])){http_response_code(404);exit('La grabación todavía no está disponible.');}
        $audio=$servicio->descargarGrabacion((string)$grabacion['link'],$rangeHeader);
    } else {
        http_response_code(404);
        exit('Esta llamada no tiene una grabación asociada.');
    }

    require_once $root.'/app/services/TelefoniaAudioRespuesta.php';
    $response=TelefoniaAudioRespuesta::respuesta($audio,$rangeHeader);
    http_response_code($response['status']);
    foreach($response['headers'] as $name=>$value)header($name.': '.$value);
    $extension=TelefoniaAudioRespuesta::extension($response['headers']['Content-Type']??'');
    header('Content-Disposition: '.($forzarDescarga?'attachment':'inline').'; filename="llamada-'.$interaccionId.'.'.$extension.'"');
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    echo $response['body'];
} catch (DomainException $error) {
    http_response_code(in_array($error->getCode(),[404,409],true)?$error->getCode():403);
    exit('Falta acreditar una grabación propia inequívoca para esta interacción.');
} catch (Throwable $error) {
    http_response_code(502);
    exit('No fue posible recuperar la grabación en este momento.');
}
