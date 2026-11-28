<?php
require_once dirname(__DIR__,2).'/app/services/TelefoniaVerificacionEvidenciaService.php';
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();

require_once dirname(__DIR__, 2) . '/app/services/ZadarmaCallLookupService.php';
require_once dirname(__DIR__, 2) .
    '/app/services/TelefoniaExtensionService.php';
require_once dirname(__DIR__, 2) .
    '/app/services/ZadarmaWebhookEventStoreService.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaPbxSegmentosService.php';
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaAsignacionFechaHelper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$transactionActive=false;

function responderJson(array $data, int $status = 200): void
{
    global $connection,$transactionActive;
    if($transactionActive){
        try{$connection->rollback();}catch(Throwable $ignored){}
        $transactionActive=false;
        if($status<400){$status=500;$data=['ok'=>false,'mensaje'=>'No se confirmó la conciliación. El resultado permanece guardado.'];}
    }
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function fechaMysqlDesdeIso(?string $valor): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    try {
        $fecha = new DateTime($valor,new DateTimeZone('America/Mexico_City'));
        $errors=DateTime::getLastErrors();
        if($errors!==false&&($errors['warning_count']||$errors['error_count']))return null;
        $fecha->setTimezone(new DateTimeZone('America/Mexico_City'));
        return $fecha->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function fechaMysqlLocal(?string $valor): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    $fecha = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $valor,
        new DateTimeZone('America/Mexico_City')
    );

    if (!$fecha||$fecha->format('Y-m-d H:i:s')!==$valor) {
        return null;
    }

    return $fecha->format('Y-m-d H:i:s');
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($usuarioId <= 0) {
    responderJson(['ok' => false, 'mensaje' => 'Sesión no activa.'], 401);
}

if ($rolId !== 4) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Solo el Analista puede vincular una llamada del seguimiento.'
    ], 403);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    responderJson(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
}

$seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
$interaccionId = (int)($_POST['interaccion_id'] ?? 0);
$pbxCallId = trim((string)($_POST['pbx_call_id'] ?? ''));
$sessionId=trim((string)($_POST['session_id']??''));
$lookupAttempt = max(0, (int)($_POST['lookup_attempt'] ?? 0));
$permitirLookupEstadisticas =
    $lookupAttempt === 0 ||
    $lookupAttempt === 12 ||
    $lookupAttempt >= 24;

if (
    $seguimientoId <= 0 ||
    $interaccionId <= 0 ||
    ($pbxCallId !== '' && !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId))
) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Los datos de la llamada o de la interacción no son válidos.'
    ], 422);
}

$rootPath = dirname(__DIR__, 2);
$logPath = $rootPath . '/storage/zadarma_webhooks.log';

try {
    $connection=Database::compartida();
    $asignacionTelefonica =
        (new TelefoniaExtensionService($connection))
            ->resolverParaUsuario($usuarioId);
} catch (Throwable $e) {
    responderJson([
        'ok' => false,
        'mensaje' => 'No fue posible consultar la extensión telefónica del usuario.'
    ], 500);
}

if (!$asignacionTelefonica||empty($asignacionTelefonica['permite_salientes'])||!tienePermiso('telefonia.salientes')) {
    responderJson([
        'ok' => false,
        'mensaje' => 'Tu usuario no tiene una extensión Zadarma activa asignada.'
    ], 422);
}

$extension = trim(
    (string)($asignacionTelefonica['extension'] ?? '')
);

if (!preg_match('/^\d{3,6}$/', $extension)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La extensión Zadarma asignada no es válida.'
    ], 422);
}
$asignacionDesde=TelefoniaAsignacionFechaHelper::aMexico((string)$asignacionTelefonica['asignacion_desde']);
if($sessionId!==''){
    $csrf=(string)($_SESSION['csrf_token']??'');
    if($csrf===''||!is_string($_POST['csrf_token']??null)||!hash_equals($csrf,$_POST['csrf_token']))
        responderJson(['ok'=>false,'mensaje'=>'Actualiza la sesión para conciliar esta llamada.'],403);
    try{
        require_once dirname(__DIR__,2).'/app/services/TelefoniaSesionLocalService.php';
        $resolved=(new TelefoniaSesionLocalService($connection))->resolverSalientePreparada($usuarioId,$sessionId);
        $reference=(string)($resolved['inicio']['pbx_call_id']??'');
        if($resolved['identificacion']!=='CONFIRMADA'||($pbxCallId!==''&&$pbxCallId!==$reference))
            responderJson(['ok'=>false,'mensaje'=>'La intención todavía no acredita esta referencia PBX.'],409);
        $pbxCallId=$reference;
    }catch(DomainException|InvalidArgumentException $e){responderJson(['ok'=>false,'mensaje'=>$e->getMessage()],409);}
    catch(Throwable $e){
        if(Database::responderFalloTemporal($e))exit;
        responderJson(['ok'=>false,'mensaje'=>'No se pudo comprobar la intención telefónica. El resultado permanece guardado.'],503);
    }
}
if($pbxCallId==='')responderJson(['ok'=>false,'mensaje'=>'Falta la referencia PBX acreditada. El resultado permanece registrado.'],409);

$inicio = null;
$fin = null;
$eventosLlamada = [];

if ($pbxCallId !== '') {
    try {
        $eventosLlamada =
            (new ZadarmaWebhookEventStoreService($connection))
                ->obtenerPorPbxCallId($pbxCallId);
    } catch (Throwable $errorStore) {
        error_log('[zadarma_vincular_store] ' . $errorStore->getMessage());
    }

    /*
     * Respaldo para llamadas antiguas o contingencia de almacenamiento.
     */
    if ($sessionId===''&&empty($eventosLlamada) && is_file($logPath)) {
        $lineas = file(
            $logPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [];

        foreach ($lineas as $linea) {
            $fila = json_decode($linea, true);
            if (
                is_array($fila) &&
                hash_equals(
                    $pbxCallId,
                    trim((string)($fila['pbx_call_id'] ?? ''))
                )
            ) {
                $eventosLlamada[] = $fila;
            }
        }
    }

    $iniciosPropios=[];
    foreach ($eventosLlamada as $fila) {
        if((string)($fila['pbx_call_id']??'')!==$pbxCallId)continue;
        $propia=(string)($fila['internal']??'')===$extension;
        $evento = (string)($fila['event'] ?? '');
        if ($evento === 'NOTIFY_OUT_START'&&$propia&&
            (string)fechaMysqlDesdeIso($fila['received_at']??null)>=$asignacionDesde&&
            (empty($fila['call_start'])||(string)fechaMysqlDesdeIso($fila['call_start'])>=$asignacionDesde)) {
            $inicio = $fila;
            $iniciosPropios[]=$fila;
        } elseif ($evento === 'NOTIFY_OUT_END'&&$propia) {
            $fin = $fila;

        }
    }
    if(count($iniciosPropios)>1)responderJson(['ok'=>false,'mensaje'=>'La referencia contiene inicios incompatibles. Conserva la captura para revisión.'],409);
    if($inicio && !$fin){
        $scoped=array_map(static function(array $e):array{$e['evento']=$e['event']??'';return $e;},$eventosLlamada);
        $closed=TelefoniaPbxSegmentosService::hechos($scoped,$extension,'',max(0,(int)($inicio['id']??0)-1));
        if($closed['finalizada'])$fin=['event'=>'SCOPED_PBX_END','received_at'=>$closed['end_at'],
            'disposition'=>$closed['disposition'],'has_duration'=>false];
    }
}

$estadistica = null;
if (
    (!$inicio || !$fin || $pbxCallId === '' ||
     ($fin && empty($fin['has_duration']))) &&
    $permitirLookupEstadisticas
) {
    try {
        $lookup = new ZadarmaCallLookupService();

        if ($pbxCallId !== '') {
            $estadistica = $lookup->buscarPorPbxCallId($pbxCallId,$extension);
        }

        if ($estadistica) {
            // La búsqueda no puede sustituir silenciosamente el PBX ID.
            $idEstadistica = trim((string)($estadistica['pbx_call_id'] ?? ''));
            $extEstadistica = trim((string)($estadistica['sip'] ?? ''));
            if ($idEstadistica === '' ||
                ($pbxCallId !== '' && !hash_equals($pbxCallId, $idEstadistica)) ||
                $extEstadistica!==$extension||
                (string)($estadistica['callstart']??'')<$asignacionDesde) {
                $estadistica = null;
            }
        }
        if ($estadistica) {
            $pbxCallId = trim((string)($estadistica['pbx_call_id'] ?? $pbxCallId));
            $callStart = trim((string)($estadistica['callstart'] ?? ''));
            $segundos = max(0, (int)($estadistica['seconds'] ?? 0));
            $inicioTimestamp = $callStart !== '' ? strtotime($callStart) : false;

            if (!$inicio) {
                $inicio = [
                    'event' => 'STATISTICS_OUT_START',
                    'internal' => $extension,
                    'destination' => (string)($estadistica['destination'] ?? ''),
                    'call_start' => $callStart,
                    'pbx_call_id' => $pbxCallId,
                ];
            }

            if ($fin) {
                // Sólo mejorar el dato con estadística de la MISMA llamada.
                $segundosConfirmados = max(0, (int)($estadistica['seconds'] ?? 0));
                if ($segundosConfirmados > (int)($fin['duration'] ?? 0)) {
                    $fin['duration'] = (string)$segundosConfirmados;
                }
                if (!empty($estadistica['is_recorded'])) {
                    $fin['is_recorded'] = '1';
                }
                if (empty($fin['call_id_with_rec']) &&
                    !empty($estadistica['call_id'])) {
                    $fin['call_id_with_rec'] = (string)$estadistica['call_id'];
                }
            }
            if (!$fin) {
                $fin = [
                    'event' => 'STATISTICS_OUT_END',
                    'internal' => $extension,
                    'destination' => (string)($estadistica['destination'] ?? ''),
                    'call_start' => $callStart,
                    'pbx_call_id' => $pbxCallId,
                    'duration' => (string)$segundos,
                    'disposition' => (string)($estadistica['disposition'] ?? ''),
                    'is_recorded' => !empty($estadistica['is_recorded']) ? '1' : '0',
                    'call_id_with_rec' => (string)($estadistica['call_id'] ?? ''),
                    'received_at' => '', // CDR sin fecha de fin: no fabricar la hora actual.
                ];
            }
        }
    } catch (Throwable $error) {
        error_log('[zadarma_vincular_estadisticas] ' . $error->getMessage());
    }
}

if ($pbxCallId === '' || !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)) {
    responderJson([
        'ok' => false,
        'mensaje' => 'El proveedor de telefonía todavía está publicando el identificador de la llamada.'
    ], 409);
}

if (!$inicio) {
    responderJson([
        'ok' => false,
        'mensaje' => 'El proveedor de telefonía todavía está publicando el inicio de la llamada.'
    ], 409);
}

if (trim((string)($inicio['internal'] ?? '')) !== $extension) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La llamada no corresponde a la extensión Zadarma asignada a tu usuario.'
    ], 403);
}

if (!$fin) {
    responderJson([
        'ok' => false,
        'mensaje' => 'La llamada todavía no termina de procesarse en el proveedor de telefonía.'
    ], 409);
}

try {
    require_once $rootPath . '/config/db_connection.php';

    $sqlSeguimiento = "SELECT id,telefono_fuente,telefono_verificado
        FROM seguimientos_vinculacion
        WHERE id = ?
          AND analista_id = ?
          AND activo = 1
        LIMIT 1";
    $stmtSeguimiento = $connection->prepare($sqlSeguimiento);
    $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
    $stmtSeguimiento->execute();

    $expediente=$stmtSeguimiento->get_result()->fetch_assoc();$stmtSeguimiento->close();
    if (!$expediente) {
        responderJson(['ok' => false, 'mensaje' => 'No tienes acceso a este seguimiento.'], 403);
    }

    $connection->begin_transaction();
    $transactionActive=true;
    $sqlInteraccion = "SELECT
            id,
            proveedor_externo,
            id_externo,
            notas,telefono_destino,duracion_segundos,fecha_inicio,fecha_fin
        FROM interacciones_vinculacion
        WHERE id = ?
          AND seguimiento_id = ?
          AND usuario_id = ?
          AND canal = 'LLAMADA_IP'
        LIMIT 1 FOR UPDATE";
    $stmtInteraccion = $connection->prepare($sqlInteraccion);
    $stmtInteraccion->bind_param('iii', $interaccionId, $seguimientoId, $usuarioId);
    $stmtInteraccion->execute();
    $interaccion = $stmtInteraccion->get_result()->fetch_assoc() ?: null;

    if (!$interaccion) {
        responderJson([
            'ok' => false,
            'mensaje' => 'La interacción telefónica indicada no pertenece a este seguimiento.'
        ], 404);
    }

    $idExternoActual = trim((string)($interaccion['id_externo'] ?? ''));
    $proveedorActual = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));

    if ($idExternoActual !== '' && !hash_equals($idExternoActual, $pbxCallId)) {
        responderJson([
            'ok' => false,
            'mensaje' => 'Esta interacción ya está vinculada con otra llamada.'
        ], 409);
    }

    if ($idExternoActual !== '' && $proveedorActual !== '' && $proveedorActual !== 'ZADARMA') {
        responderJson([
            'ok' => false,
            'mensaje' => 'Esta interacción ya está vinculada con otro proveedor de telefonía.'
        ], 409);
    }
    if($idExternoActual===''){
        require_once $rootPath.'/app/services/ProspectoNoContactarV3.php';
        $numbers=ProspectoNoContactarV3::formatos((string)($inicio['destination']??''));$matches=false;
        $destinations=!empty($interaccion['telefono_destino'])?[$interaccion['telefono_destino']]:[$expediente['telefono_verificado'],$expediente['telefono_fuente']];
        foreach($destinations as $number)
            if(array_intersect($numbers,ProspectoNoContactarV3::formatos((string)$number)))$matches=true;
        if(!$matches)responderJson(['ok'=>false,'mensaje'=>'La llamada no corresponde al contacto de esta interacción.'],409);
    }

    $fechaInicio = fechaMysqlLocal($inicio['call_start'] ?? null) ?? fechaMysqlDesdeIso($inicio['received_at']??null);
    if($fechaInicio===null)responderJson(['ok'=>false,'mensaje'=>'Falta la fecha acreditada del inicio telefónico.'],409);
    $fechaFin = fechaMysqlDesdeIso($fin['received_at'] ?? null);
    $disposicion = strtoupper(trim((string)($fin['disposition'] ?? '')));
    $raw=array_map(static function(array $e):array{$e['evento']=$e['event']??'';return $e;},$eventosLlamada);
    $hechos=TelefoniaPbxSegmentosService::hechos($raw,$extension,'',max(0,(int)($inicio['id']??0)-1));
    $huboRespuesta=$hechos['conectada']||($estadistica!==null&&in_array($disposicion,['ANSWERED','ANSWER','CONNECTED','SUCCESS'],true));

    /*
     * Sólo duración del tramo propio PBX/CDR. El cronómetro SDK no acredita
     * conversación del proveedor; una duración ausente permanece desconocida.
     */
    $duracionProveedor=$hechos['segundos'];
    if($estadistica!==null)$duracionProveedor=max((int)($duracionProveedor??0),(int)$estadistica['seconds']);
    $duracion=$huboRespuesta?$duracionProveedor:0;
    $confirmarEvidencia=$huboRespuesta&&$duracion!==null&&$duracion>0;
    $evidenciaFinal=$duracionProveedor!==null||!$huboRespuesta;
    $grabaciones=$hechos['segmentos'];
    if($estadistica!==null){
        $propios=TelefoniaPbxSegmentosService::propios([$estadistica],$pbxCallId,$extension);
        foreach($propios['tramos'] as $tramo)
            if($tramo['grabada']&&!in_array($tramo['id'],$hechos['audio_bloqueadas'],true))$grabaciones[]=$tramo['id'];
        $grabaciones=array_values(array_unique($grabaciones));
    }
    // No borrar un dato técnico histórico ya asociado si esta consulta carece
    // de duración. Tampoco usar ese dato previo para acreditar evidencia nueva.
    if($duracion===null&&$idExternoActual===$pbxCallId)$duracion=$interaccion['duracion_segundos'];
    if($fechaFin===null&&$idExternoActual===$pbxCallId)$fechaFin=$interaccion['fecha_fin'];

    $proveedor = 'ZADARMA';

    /*
     * Una verificación declarada por el Analista solo conserva su marca de
     * efectividad cuando Zadarma confirma que la llamada fue contestada.
     * Con esto un resultado manual favorable no puede convertir una llamada
     * sin respuesta en verificación efectiva.
     */
    $notasInteraccion = (string)($interaccion['notas'] ?? '');
    $verificacionSolicitada=strpos($notasInteraccion,'[VERIFICACION_PENDIENTE_TELEFONIA]')!==false;

    if($evidenciaFinal)$notasInteraccion = str_replace(
        '[VERIFICACION_PENDIENTE_TELEFONIA]',
        '',
        $notasInteraccion
    );

    if (
        strpos($notasInteraccion, '[VERIFICACION_EFECTIVA]') !== false &&
        (!$huboRespuesta||($verificacionSolicitada&&$evidenciaFinal&&!$confirmarEvidencia))
    ) {
        $notasInteraccion = str_replace(
            '[VERIFICACION_EFECTIVA]',
            '',
            $notasInteraccion
        );
        $notasInteraccion = preg_replace(
            '/^Verificación obtenida:\s*.*$/miu',
            'Verificación no contabilizada: la evidencia telefónica no acredita conversación con duración positiva.',
            $notasInteraccion
        );
        $notasInteraccion = trim((string)$notasInteraccion);
    }

    $sqlActualizar = "UPDATE interacciones_vinculacion
        SET fecha_inicio = ?,
            fecha_fin = ?,
            duracion_segundos = ?,
            proveedor_externo = ?,
            id_externo = ?,
            notas = ?
        WHERE id = ?
          AND seguimiento_id = ?
          AND usuario_id = ?
          AND canal = 'LLAMADA_IP'";
    $stmtActualizar = $connection->prepare($sqlActualizar);
    $stmtActualizar->bind_param(
        'ssisssiii',
        $fechaInicio,
        $fechaFin,
        $duracion,
        $proveedor,
        $pbxCallId,
        $notasInteraccion,
        $interaccionId,
        $seguimientoId,
        $usuarioId
    );
    $stmtActualizar->execute();
    if($evidenciaFinal)
        (new TelefoniaVerificacionEvidenciaService($connection))->confirmarSalida($usuarioId,$interaccionId,$pbxCallId,$confirmarEvidencia);
    $connection->commit();
    $transactionActive=false;

    $verificacionEfectiva =
        $confirmarEvidencia &&
        strpos($notasInteraccion, '[VERIFICACION_EFECTIVA]') !== false;
    $yaContabilizadaHoy = false;

    if ($verificacionEfectiva) {
        $fechaConteo = substr((string)$fechaInicio, 0, 10);
        $sqlDuplicada = "SELECT COUNT(*) AS total
            FROM interacciones_vinculacion
            WHERE seguimiento_id = ?
              AND usuario_id = ?
              AND id <> ?
              AND canal = 'LLAMADA_IP'
              AND DATE(fecha_inicio) = ?
              AND notas LIKE '%[VERIFICACION_EFECTIVA]%'
              AND TRIM(COALESCE(proveedor_externo, '')) <> ''
              AND TRIM(COALESCE(id_externo, '')) <> ''
              AND COALESCE(duracion_segundos, 0) > 0";
        $stmtDuplicada = $connection->prepare($sqlDuplicada);
        $stmtDuplicada->bind_param(
            'iiis',
            $seguimientoId,
            $usuarioId,
            $interaccionId,
            $fechaConteo
        );
        $stmtDuplicada->execute();
        $filaDuplicada = $stmtDuplicada->get_result()->fetch_assoc() ?: [];
        $yaContabilizadaHoy = (int)($filaDuplicada['total'] ?? 0) > 0;
    }

    responderJson([
        'ok' => true,
        'mensaje' => 'La llamada quedó vinculada con la interacción exacta.',
        'interaccion_id' => $interaccionId,
        'pbx_call_id' => $pbxCallId,
        'duracion_segundos' => $duracion,
        'estado_zadarma' => (string)($fin['disposition'] ?? ''),
        'hubo_respuesta' => $huboRespuesta,
        'verificacion_efectiva' => $verificacionEfectiva,
        'institucion_ya_contabilizada_hoy' => $yaContabilizadaHoy,
        'verificacion_pendiente'=>$verificacionSolicitada&&!$evidenciaFinal,
        'verificacion_rechazada'=>$verificacionSolicitada&&$evidenciaFinal&&!$confirmarEvidencia,
        'grabacion_disponible'=>!empty($grabaciones),
        'call_id_with_rec'=>count($grabaciones)===1?(string)$grabaciones[0]:'',
        'segmentos_grabacion'=>$grabaciones,
    ]);
} catch (Throwable $e) {
    if($transactionActive){
        try{$connection->rollback();}catch(Throwable $ignored){}
        $transactionActive=false;
    }
    if (Database::responderFalloTemporal($e)) exit;
    responderJson([
        'ok' => false,
        'mensaje' => 'No se pudo vincular la llamada con la interacción.',
    ], 500);
}
