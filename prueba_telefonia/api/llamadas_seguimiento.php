<?php
session_start();
require_once dirname(__DIR__,2).'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
TelefoniaPeticionContexto::liberarSesion();
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
require_once $root . '/app/services/TelefoniaHistorialInteraccionPbxService.php';
require_once $root . '/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
require_once $root . '/app/helpers/TelefoniaFechaHoraHelper.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
}

$etapaHistorial = 'recuperar_permisos';
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
$conexionHistorial = Database::compartida();
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

$resultadosConContacto = [
    'CONTACTADO',
    'CONTACTO_INCORRECTO',
    'SOLICITO_INFORMACION',
    'SOLICITO_LLAMAR_DESPUES',
    'MENSAJE_ENVIADO',
    'NO_INTERESADO',
];


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
$interaccionesHistorial=$modelo->obtenerInteraccionesSeguimiento($seguimientoId);
try{$estadoZadarma=(new TelefoniaHistorialInteraccionPbxService($conexionHistorial))->lote($interaccionesHistorial,$seguimientoId);}
catch(Throwable $errorPbx){$estadoZadarma=[];error_log('[historial_pbx] '.get_class($errorPbx));}
foreach ($interaccionesHistorial as $interaccion) {
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

    $metaPbx=$estadoZadarma[$interaccionId]??null;
    // Conservar duración nativa positiva. Completar sólo con el tramo propio,
    // nunca END general, diferencia de relojes ni duración de otro participante.
    if($proveedor==='ZADARMA'&&$duracion<=0)$duracion=$metaPbx['segundos']??null;

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

    $zadarmaValida = $proveedor==='ZADARMA' && $metaPbx!==null &&
        ZadarmaPbxIdentifierHelper::pbxValido($idExterno);
    $llamadaTecnicaValida = $duracion > 0 && $idExterno!=='' && $proveedor!=='';
    $verificacionEfectiva = $verificacionMarcada && $llamadaTecnicaValida;
    $zadarmaGrabada = $zadarmaValida && !empty($metaPbx['grabada']);
    $grabacionWebhookLista = $zadarmaValida && !empty($metaPbx['ids']);

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
    if (Database::responderFalloTemporal($error)) exit;
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
