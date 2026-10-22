<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Nunca entregar una respuesta vacía por excepciones operativas de Hostinger.
// Las causas técnicas se registran sólo en el log del servidor.
ob_start();
$etapaHistorial = 'inicializar';
try {

$root = dirname(__DIR__, 2);
require_once $root . '/app/helpers/PermissionHelper.php';
require_once $root . '/app/models/RolModel.php';
require_once $root . '/app/models/SeguimientoVinculacionModel.php';
require_once $root . '/app/services/ZadarmaCallLookupService.php';
require_once $root . '/app/services/ZadarmaWebhookEventStoreService.php';
require_once $root . '/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
require_once $root . '/app/helpers/TelefoniaFechaHoraHelper.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
}

$etapaHistorial = 'recuperar_permisos';
if (!isset($_SESSION['permisos'])) {
    $modeloRol = new RolModel();
    $modeloRol->inicializarPermisosSistema();
    $_SESSION['permisos'] = $modeloRol->obtenerCodigosPermisosPorRol(
        (int)($_SESSION['rol_id'] ?? 0)
    );
}

if (!tienePermiso('seguimientos_vinculacion.ver')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'No tienes permiso para consultar este expediente.']);
    exit;
}

$seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

if ($seguimientoId <= 0 || $usuarioId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Seguimiento no válido.']);
    exit;
}

$etapaHistorial = 'conectar_mysql';
// La consulta telefónica es de sólo lectura: reutilizar conexiones PHP-FPM
// evita crear una nueva sesión MySQL en cada apertura del expediente.
// En algunos procesos PHP-FPM de Hostinger el pool persistente MySQL
// devuelve HY000/2002 (Operation not permitted). Reintentar una sola vez
// con conexión no persistente sólo en este endpoint; no alterar el acceso
// a MySQL del resto del CRM ni encadenar reintentos ilimitados.
try {
    $conexionHistorial = (new Database())->connect(true);
} catch (Throwable $errorConexionPersistente) {
    $causaConexion = $errorConexionPersistente;
    for ($nivel = 0; $nivel < 5 && $causaConexion->getPrevious(); $nivel++) {
        $causaConexion = $causaConexion->getPrevious();
    }
    $error2002 = (int)$causaConexion->getCode() === 2002
        && stripos($causaConexion->getMessage(), 'operation not permitted') !== false;
    if (!$error2002) {
        throw $errorConexionPersistente;
    }
    $etapaHistorial = 'conectar_mysql_sin_persistencia';
    error_log('[llamadas_seguimiento_mysql] Pool persistente rechazado; se intenta conexion normal una sola vez.');
    $conexionHistorial = (new Database())->connect(false);
}
$modelo = new SeguimientoVinculacionModel($conexionHistorial);
$rolId = (int)($_SESSION['rol_id'] ?? 0);
$rolNombre = trim((string)($_SESSION['rol'] ?? ''));
$esConsultaGlobal =
    $rolId === 1 ||
    strcasecmp($rolNombre, 'Marketing') === 0;

$etapaHistorial = 'consultar_expediente';
if ($esConsultaGlobal) {
    $seguimiento = $modelo->obtenerSeguimientoAdministrador($seguimientoId);
} elseif (tienePermiso('seguimientos_vinculacion.supervisar')) {
    $seguimiento = $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
} else {
    $seguimiento = $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
}

if (!$seguimiento) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'No tienes acceso al seguimiento solicitado.']);
    exit;
}

$llamadas = [];
$marcadorBuzon = '[BUZON_VOZ]';
$marcadorFueraServicio = '[FUERA_SERVICIO]';
$marcadorContacto = '[CONTACTO_EFECTIVO]';
$marcadorSinContacto = '[SIN_CONTACTO_EFECTIVO]';
$marcadorVerificacion = '[VERIFICACION_EFECTIVA]';
$marcadorVerificacionPendiente = '[VERIFICACION_PENDIENTE_TELEFONIA]';
$marcadorPrueba = '[REGISTRO_LLAMADA_PRUEBA]';
$marcadorManual = '[REGISTRO_LLAMADA_MANUAL]';
$marcadorWebrtcSinPbx = '[WEBRTC OBSERVADO; SIN EVENTO PBX FIRMADO; RESULTADO DECLARADO POR ANALISTA]';

// Zadarma entrega NOTIFY_RECORD cuando el audio ya está listo.
// Los eventos persistidos son la fuente primaria. El log se conserva únicamente
// como respaldo para llamadas antiguas o contingencia de base de datos.
$estadoZadarma = [];
$zadarmaEventStore = null;
$logPath = $root . '/storage/zadarma_webhooks.log';

function acumularEventoZadarma(array &$estadoZadarma, array $fila): void
{
    $pbxCallId = trim((string)($fila['pbx_call_id'] ?? ''));
    if ($pbxCallId === '') {
        return;
    }

    if (!isset($estadoZadarma[$pbxCallId])) {
        $estadoZadarma[$pbxCallId] = [
            'respuesta' => null,
            'fin' => null,
            'grabada' => false,
            'grabacion' => false,
        ];
    }

    $evento = (string)($fila['event'] ?? '');

    if ($evento === 'NOTIFY_ANSWER') {
        $estadoZadarma[$pbxCallId]['respuesta'] = $fila;
    } elseif ($evento === 'NOTIFY_OUT_END') {
        $estadoZadarma[$pbxCallId]['fin'] = $fila;
        $estadoZadarma[$pbxCallId]['grabada'] =
            (string)($fila['is_recorded'] ?? '') === '1' ||
            trim((string)($fila['call_id_with_rec'] ?? '')) !== '';
    } elseif ($evento === 'NOTIFY_RECORD') {
        $estadoZadarma[$pbxCallId]['grabacion'] = true;
    }
}

try {
    $zadarmaEventStore = new ZadarmaWebhookEventStoreService($conexionHistorial);
} catch (Throwable $errorStore) {
    $zadarmaEventStore = null;
    error_log('[llamadas_seguimiento_zadarma_store] ' . $errorStore->getMessage());

    if (is_file($logPath)) {
        $lineas = file(
            $logPath,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [];

        foreach ($lineas as $linea) {
            $fila = json_decode($linea, true);
            if (is_array($fila)) {
                acumularEventoZadarma($estadoZadarma, $fila);
            }
        }
    }
}

$resultadosConContacto = [
    'CONTACTADO',
    'CONTACTO_INCORRECTO',
    'SOLICITO_INFORMACION',
    'SOLICITO_LLAMAR_DESPUES',
    'MENSAJE_ENVIADO',
    'NO_INTERESADO',
];

$zadarmaLookup = null;
$zadarmaLookupDisponible = true;

function descomponerNotasLlamada(string $notas, string $contactoReferencia = ''): array
{
    $notas = trim(str_replace(["\r\n", "\r"], "\n", $notas));
    if ($notas === '') {
        return ['contacto' => '', 'detalle' => ''];
    }

    $lineas = array_values(array_filter(
        array_map('trim', explode("\n", $notas)),
        static function ($linea) {
            return $linea !== '';
        }
    ));

    $contacto = '';
    $detalle = [];
    $prefijo = '/^(?:Persona\s+atendi[oó]|Atendi[oó]|Contacto)\s*:\s*(.*)$/iu';

    // Las llamadas recibidas comienzan con "Llamada entrante recibida...".
    // Buscar el nombre en CUALQUIER línea y no duplicarlo en el detalle.
    $contactoReferencia = trim($contactoReferencia);
    foreach ($lineas as $linea) {
        if ($contacto === '' && preg_match($prefijo, $linea, $coincidencia)) {
            $valor = trim((string)($coincidencia[1] ?? ''));
            if ($contactoReferencia !== '' &&
                stripos($valor, $contactoReferencia) === 0) {
                $contacto = $contactoReferencia;
                $resto = trim(substr($valor, strlen($contactoReferencia)));
                if ($resto !== '') {
                    $detalle[] = $resto;
                }
            } else {
                $contacto = $valor;
            }
            continue;
        }
        $detalle[] = $linea;
    }

    return [
        'contacto' => $contacto,
        'detalle' => trim(implode("\n", $detalle)),
    ];
}

$etapaHistorial = 'consultar_interacciones';
$asociadorTransferencia = new TelefoniaGrabacionExpedienteTransferidaService($conexionHistorial);
foreach ($modelo->obtenerInteraccionesSeguimiento($seguimientoId) as $interaccion) {
    if (strtoupper((string)($interaccion['canal'] ?? '')) !== 'LLAMADA_IP') {
        continue;
    }
    // El historial WebRTC guarda fecha UTC. Convertir SOLO en la respuesta,
    // preservando BD y dejando intactas llamadas Zadarma y manuales.
    $interaccion = TelefoniaFechaHoraHelper::normalizarInteraccion($interaccion);

    $interaccionId = (int)($interaccion['id'] ?? 0);
    $proveedor = strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')));
    $idExterno = trim((string)($interaccion['id_externo'] ?? ''));
    $duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));

    if (
        $proveedor === 'ZADARMA' &&
        $idExterno !== '' &&
        !isset($estadoZadarma[$idExterno]) &&
        $zadarmaEventStore !== null
    ) {
        try {
            foreach ($zadarmaEventStore->obtenerPorPbxCallId($idExterno) as $eventoZadarma) {
                acumularEventoZadarma($estadoZadarma, $eventoZadarma);
            }
        } catch (Throwable $errorStore) {
            error_log('[llamadas_seguimiento_zadarma_eventos] ' . $errorStore->getMessage());
        }
    }

    if ($proveedor === 'ZADARMA' && $duracion <= 0 && isset($estadoZadarma[$idExterno])) {
        $metaZadarma = $estadoZadarma[$idExterno];
        $finZadarma = is_array($metaZadarma['fin'] ?? null) ? $metaZadarma['fin'] : null;
        $respuestaZadarma = is_array($metaZadarma['respuesta'] ?? null)
            ? $metaZadarma['respuesta']
            : null;

        $duracion = max(0, (int)($finZadarma['duration'] ?? 0));

        if ($duracion <= 0 && $respuestaZadarma && $finZadarma) {
            $inicioConversacion = strtotime((string)($respuestaZadarma['received_at'] ?? ''));
            $finConversacion = strtotime((string)($finZadarma['received_at'] ?? ''));

            if (
                $inicioConversacion !== false &&
                $finConversacion !== false &&
                $finConversacion > $inicioConversacion
            ) {
                $duracion = max(1, $finConversacion - $inicioConversacion);
            }
        }
    }

    $resultado = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
    $notas = trim((string)($interaccion['notas'] ?? ''));
    $webrtcSinPbx = $proveedor === 'ZADARMA_WEBRTC' ||
        strpos($notas, $marcadorWebrtcSinPbx) !== false;
    $verificacionMarcada = strpos($notas, $marcadorVerificacion) !== false;
    $verificacionPendienteMarcada =
        strpos($notas, $marcadorVerificacionPendiente) !== false;
    $registroPrueba = strpos($notas, $marcadorPrueba) !== false;
    $registroManual = strpos($notas, $marcadorManual) !== false;
    $evidenciasVerificacion = [];

    if (preg_match('/^Verificación obtenida:\s*(.+)$/miu', $notas, $coincidenciaVerificacion)) {
        $evidenciasVerificacion = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*·\s*/u', (string)($coincidenciaVerificacion[1] ?? '')) ?: []
        )));
    }

    $resultadoTelefonico = $resultado;
    $excluirGrabacion = false;

    if (strpos($notas, $marcadorBuzon) !== false) {
        $resultadoTelefonico = 'BUZON_VOZ';
        $excluirGrabacion = true;
    } elseif (strpos($notas, $marcadorFueraServicio) !== false) {
        $resultadoTelefonico = 'FUERA_SERVICIO';
        $excluirGrabacion = true;
    }

    if (in_array(
        $resultado,
        ['NO_CONTESTO', 'SIN_RESPUESTA', 'OCUPADO', 'BUZON_VOZ', 'FUERA_SERVICIO', 'NUMERO_INCORRECTO'],
        true
    )) {
        $excluirGrabacion = true;
    }

    $contactoEfectivo = in_array($resultado, $resultadosConContacto, true);
    if (strpos($notas, $marcadorSinContacto) !== false) {
        $contactoEfectivo = false;
    } elseif (strpos($notas, $marcadorContacto) !== false) {
        $contactoEfectivo = true;
    }

    $notasLimpias = trim(str_replace(
        [
            $marcadorBuzon,
            $marcadorFueraServicio,
            $marcadorContacto,
            $marcadorSinContacto,
            $marcadorVerificacion,
            $marcadorVerificacionPendiente,
            $marcadorPrueba,
            $marcadorManual,
            $marcadorWebrtcSinPbx,
        ],
        '',
        $notas
    ));

    $notasEstructuradas = descomponerNotasLlamada(
        $notasLimpias,
        trim((string)($seguimiento['contacto_nombre'] ?? ''))
    );

    $zadarmaValida =
        $proveedor === 'ZADARMA' &&
        $duracion > 0 &&
        preg_match('/^out_[a-fA-F0-9]{32,64}$/', $idExterno);

    $llamadaTecnicaValida =
        $duracion > 0 &&
        $idExterno !== '' &&
        $proveedor !== '';
    $verificacionEfectiva =
        $verificacionMarcada &&
        $llamadaTecnicaValida;

    $zadarmaGrabada = $zadarmaValida &&
        (
            !empty($estadoZadarma[$idExterno]['grabada']) ||
            !empty($estadoZadarma[$idExterno]['grabacion'])
        );

    if ($zadarmaValida && !$zadarmaGrabada && $zadarmaLookupDisponible) {
        try {
            if (!$zadarmaLookup) {
                $zadarmaLookup = new ZadarmaCallLookupService();
            }

            $estadisticaZadarma = $zadarmaLookup->buscarPorPbxCallId($idExterno);
            $zadarmaGrabada = !empty($estadisticaZadarma['is_recorded']);
        } catch (Throwable $error) {
            $zadarmaLookupDisponible = false;
            error_log('[llamadas_seguimiento_zadarma_stats] ' . $error->getMessage());
        }
    }

    $grabacionWebhookLista =
        $zadarmaValida &&
        !empty($estadoZadarma[$idExterno]['grabacion']);

    $fechaFinZadarma = strtotime((string)($interaccion['fecha_fin'] ?? ''));
    $edadGrabacion = $fechaFinZadarma !== false
        ? max(0, time() - $fechaFinZadarma)
        : 0;

    $grabacionZadarma =
        $zadarmaValida &&
        $zadarmaGrabada &&
        ($grabacionWebhookLista || $edadGrabacion >= 45);

    $puedeTenerGrabacion =
        !$excluirGrabacion &&
        $interaccionId > 0 &&
        $grabacionZadarma;

    $grabacionProcesando =
        !$excluirGrabacion &&
        $zadarmaValida &&
        $zadarmaGrabada &&
        !$grabacionZadarma;

    // La sesión observada WebRTC puede tener audio en Zadarma, pero sin
    // pbx_call_id validado NO existe una asociación segura con ese audio.
    // No confundir "archivo no vinculado" con "no se grabó".
    // Solo ofrecer audio al propietario de la extensión si una transferencia
    // firmada y la sesión WebRTC coinciden sin ambigüedad. No reescribir el
    // proveedor ni el identificador original de la interacción.
    $grabacionPbxId = null;
    $evidenciaAudio=['estado'=>'NO_APLICA','mensaje'=>''];
    if (!$excluirGrabacion && in_array($proveedor,['ZADARMA','ZADARMA_WEBRTC'],true) && $interaccionId > 0) {
        try {
            $evidenciaAudio = $asociadorTransferencia->inspeccionar(
                $usuarioId, $seguimientoId, $interaccionId
            );
            if(($evidenciaAudio['estado']??'')==='REPORTADA'){
                $grabacionPbxId=$evidenciaAudio['pbx_call_id'];
                $puedeTenerGrabacion=true;$grabacionProcesando=false;
            }
        } catch (Throwable $errorAsociacion) {
            // Una tabla ausente o MySQL temporalmente limitado no debe hacer
            // fallar el historial general ni exponer audio sin verificación.
            error_log('[llamadas_seguimiento_asociacion_pbx] '
                . get_class($errorAsociacion));
              if(!$zadarmaValida)$evidenciaAudio=['estado'=>'EVIDENCIA_PENDIENTE','mensaje'=>'No fue posible comprobar los eventos de esta llamada. Actualiza el historial para volver a verificar.'];
        }
    }
    $grabacionSinVincular = !$excluirGrabacion && $webrtcSinPbx &&
        !$puedeTenerGrabacion && $grabacionPbxId === null;

    $nombreUsuario = trim(
        (string)($interaccion['nombre'] ?? '') . ' ' .
        (string)($interaccion['apellidos'] ?? '')
    );

    $llamadas[] = [
        'id' => $interaccionId,
        'fecha_inicio' => $interaccion['fecha_inicio'] ?? null,
        'fecha_fin' => $interaccion['fecha_fin'] ?? null,
        'duracion_segundos' => $duracion,
        'resultado' => $resultado,
        'resultado_telefonico' => $resultadoTelefonico,
        'contacto_efectivo' => (bool)$contactoEfectivo,
        'verificacion_efectiva' => (bool)$verificacionEfectiva,
        'verificacion_pendiente_vinculo' =>
            (bool)($verificacionPendienteMarcada && !$llamadaTecnicaValida),
        'registro_prueba' => (bool)$registroPrueba,
        'registro_manual' => (bool)$registroManual,
        'verificacion_evidencias' => $evidenciasVerificacion,
        'contacto' => (string)($notasEstructuradas['contacto'] ?? ''),
        'detalle' => (string)($notasEstructuradas['detalle'] ?? ''),
        'notas' => $notasLimpias,
        'proveedor' => $proveedor,
        'usuario' => $nombreUsuario,
        'rol' => (string)($interaccion['rol'] ?? ''),
        'excluir_grabacion' => $excluirGrabacion,
        'tiene_grabacion' => (bool)$puedeTenerGrabacion,
        'grabacion_procesando' => (bool)$grabacionProcesando,
        'grabacion_sin_vincular' => (bool)$grabacionSinVincular,
        'grabacion_transferida_pbx' => $grabacionPbxId,
        'grabacion_transferida_institucion' => ($evidenciaAudio['tipo_audio']??'')==='INSTITUCION_SALIENTE',
        'seguimiento_id' => $seguimientoId,
        'estado_grabacion' => $evidenciaAudio['estado'],
        'evidencia_grabacion_pendiente' => $evidenciaAudio['mensaje'],
        'grabacion_url' => $puedeTenerGrabacion
            ? 'prueba_telefonia/api/grabacion_interaccion.php?interaccion_id=' . $interaccionId
            : null
    ];
}

// Ordenar DESPUÉS de normalizar las fechas de WebRTC a hora local.
// Desempatar por ID sin alterar datos, relaciones ni enlaces de grabación.
usort($llamadas, static function (array $a, array $b): int {
    $fechaA = (string)($a['fecha_inicio'] ?? '');
    $fechaB = (string)($b['fecha_inicio'] ?? '');
    $comparacion = strcmp($fechaB, $fechaA);
    return $comparacion !== 0
        ? $comparacion
        : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
});

$etapaHistorial = 'generar_respuesta';
echo json_encode([
    'ok' => true,
    'seguimiento_id' => $seguimientoId,
    'total' => count($llamadas),
    'llamadas' => $llamadas
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $error) {
    $referencia = bin2hex(random_bytes(4));
    // Conservar el código y SQLSTATE de la causa interna, no credenciales.
    $causa = $error;
    for ($profundidad = 0; $profundidad < 5 && $causa->getPrevious(); $profundidad++) {
        $causa = $causa->getPrevious();
    }
    $codigoSql = (int)$causa->getCode();
    $estadoSql = $causa instanceof mysqli_sql_exception
        ? $causa->getSqlState() : '-';
    // Evitar escribir credenciales o mensajes completos del driver en logs.
    $mensajeDriver = strtolower($causa->getMessage());
    if (str_contains($mensajeDriver, 'operation not permitted')) {
        $tipoConexion = 'operacion_no_permitida';
    } elseif (str_contains($mensajeDriver, 'no such file or directory')) {
        $tipoConexion = 'socket_no_disponible';
    } elseif (str_contains($mensajeDriver, 'connection refused')) {
        $tipoConexion = 'conexion_rechazada';
    } elseif (str_contains($mensajeDriver, 'too many connections')) {
        $tipoConexion = 'demasiadas_conexiones';
    } elseif (str_contains($mensajeDriver, 'timed out')) {
        $tipoConexion = 'tiempo_agotado';
    } else {
        $tipoConexion = 'otro';
    }
    // El error_log de PHP-FPM puede estar fuera del acceso SSH en Hostinger.
    // Conservar una bitácora mínima PRIVADA, fuera de public_html, para
    // correlacionar la referencia mostrada al analista sin exponer el error.
    $diagnostico = sprintf(
        "[%s] [llamadas_seguimiento:%s] etapa=%s sql_code=%d sqlstate=%s conexion=%s %s en %s:%d - %s\n",
        date('Y-m-d H:i:s'),
        $referencia,
        $etapaHistorial,
        $codigoSql,
        $estadoSql,
        $tipoConexion,
        get_class($error),
        basename($error->getFile()),
        $error->getLine(),
        substr(str_replace(["\r", "\n"], ' ', $error->getMessage()), 0, 700)
    );
    $archivoPrivado = dirname($root) . '/crm-diagnosticos/llamadas_seguimiento.log';
    if (is_dir(dirname($archivoPrivado)) && !is_link(dirname($archivoPrivado))) {
        if (@file_put_contents($archivoPrivado, $diagnostico, FILE_APPEND | LOCK_EX) !== false) {
            @chmod($archivoPrivado, 0600);
        }
    }
    error_log('[llamadas_seguimiento:' . $referencia . '] ' . $error->getMessage());
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No fue posible consultar el historial de llamadas. Referencia: ' . $referencia,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
