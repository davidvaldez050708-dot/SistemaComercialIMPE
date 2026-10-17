<?php
/**
 * Verificación sin conexiones externas: límites de seguridad y flujo entrante.
 * php tests/telefonia_analista_entrantes_smoke.php
 */
$raiz = dirname(__DIR__);
$src = static function (string $ruta) use ($raiz): string {
    $contenido = file_get_contents($raiz . '/' . $ruta);
    if ($contenido === false) throw new RuntimeException("Falta $ruta");
    return $contenido;
};
$servicio = $src('app/services/TelefoniaEntrantesAnalistaService.php');
$reglas = $src('app/services/VerificacionInstitucionalService.php');
$modelo = $src('app/models/SeguimientoVinculacionModel.php');
$controller = $src('app/controllers/SeguimientoVinculacionController.php');
$shell = $src('app/views/telefonia/shell.php');
$jsShell = $src('public/javascript/telefonia_shell.js');
$vista = $src('app/views/seguimiento_vinculacion/llamadas_entrantes.php');
$js = $src('public/javascript/seguimiento_entrantes_analista.js');
$head = $src('app/views/layout/dashboard_head.php');
$footer = $src('app/views/layout/dashboard_footer.php');
$sql = $src('database/migrations/2026_10_13_telefonia_entrantes_analistas.sql');

$checks = [
    'Acceso exclusivo a Analista de Datos' =>
        str_contains($controller, "'Analista de Datos'") &&
        str_contains($controller, "telefonia.recibir") &&
        str_contains($controller, "seguimientos_vinculacion.operar_propios"),
    'Validación CSRF de guardado' =>
        str_contains($controller, 'hash_equals($esperado, $recibido)') &&
        str_contains($vista, 'name="csrf_token"'),
    'Telefonía verificada mediante evento entrante firmado' =>
        str_contains($servicio, "evento = 'NOTIFY_INTERNAL'") &&
        str_contains($servicio, "obtenerEstadoEntrantePorPbxCallId") &&
        str_contains($servicio, "received_at >= ?"),
    'Respeta propiedad de extensión y vigencia de asignación' =>
        str_contains($servicio, "te.usuario_id = ?") &&
        str_contains($servicio, "a.tipo_asignacion = 'ANALISTA_DATOS'") &&
        str_contains($servicio, 'a.fecha_fin'),
    'Llamada no contestada no crea interacción' =>
        str_contains($servicio, 'empty($call[\'answer_at\']) && $decision !== \'sin_identificar\''),
    'Un único resultado por analista y llamada' =>
        str_contains($sql, 'UNIQUE KEY uk_analista_llamada (usuario_id, pbx_call_id)') &&
        str_contains($servicio, 'FOR UPDATE'),
    'Expediente, interacción y clasificación comparten transacción' =>
        str_contains($servicio, '$this->db->begin_transaction();') &&
        str_contains($servicio, 'crearSeguimientoManual([') &&
        str_contains($servicio, 'registrarInteraccionManual(') &&
        str_contains($servicio, '], true)') &&
        str_contains($servicio, '$this->db->commit();') &&
        str_contains($servicio, '$this->db->rollback();'),
    'Interacción enlazada directamente a PBX' =>
        str_contains($modelo, 'proveedor_externo,') &&
        str_contains($modelo, 'id_externo,') &&
        str_contains($modelo, 'duracion_segundos,') &&
        str_contains($servicio, "'id_externo' => " . '$sdk ?') &&
        str_contains($servicio, "'WEBRTC-' . " . '$id') &&
        str_contains($servicio, "'proveedor_externo' => " . '$sdk ?'),
    'No registrar una verificación efectiva falsa' =>
        str_contains($servicio, "'datos_verificados' => 0") &&
        str_contains($servicio, 'empty($call[\'answer_at\'])') &&
        str_contains($servicio, "\$personaAtendio==='' || \$segundosConversacion<=0"),
    'Verificaciones: evidencia técnica y de contacto' =>
        str_contains($reglas, 'verificacion_telefono_confirmado') &&
        str_contains($reglas, 'verificacion_correo_confirmado') &&
        str_contains($reglas, 'verificacion_contacto_confirmado') &&
        str_contains($servicio, '[VERIFICACION_PENDIENTE_PBX]') &&
        str_contains($servicio, 'pbx_conversacion_confirmada') &&
        str_contains($servicio, "'preservar_estado'=>true"),
    'Descarte motivado sujeto a resultado de no interés' =>
        str_contains($servicio, "Para descartar indica No interesado") &&
        str_contains($servicio, "'descartar' => " . '$descartar ? 1 : 0') &&
        str_contains($vista, 'data-incoming-discard-toggle'),
    'Sin identificar no crea un expediente falso' =>
        str_contains($servicio, '$decision === \'sin_identificar\'') &&
        str_contains($servicio, 'SIN_IDENTIFICAR'),
    'Formulario de resultado y búsqueda en Seguimiento' =>
        str_contains($vista, 'Registrar resultado de llamada') &&
        str_contains($vista, 'data-incoming-lookup') &&
        str_contains($js, 'searchFollowups'),
    'Recursos aislados a la ruta de la bandeja' =>
        str_contains($head, 'seguimiento_entrantes_analista.css') &&
        str_contains($footer, 'seguimiento_entrantes_analista.js'),
    'Aviso sólo para analistas en shell' =>
        str_contains($shell, '<?php if ($analistaEntrantesShell): ?>') &&
        str_contains($jsShell, 'cfg.analystIncomingResults') &&
        str_contains($shell, "data-analyst-register-call"),
    'WebRTC observado guardado separado y sujeto a confirmación humana' =>
        str_contains($servicio, 'TelefoniaAnalistaWebrtcService') &&
        str_contains($servicio, "confirmacion_manual_sdk") &&
        str_contains($servicio, "[VERIFICACION_PENDIENTE_PBX]") &&
        str_contains($vista, 'data-incoming-sdk-id') &&
        str_contains($vista, 'data-incoming-sdk-confirm') &&
        str_contains($jsShell, 'finishedIncoming'),
    'La recepción de Marketing continúa independiente' =>
        str_contains($shell, "'autoReception' => " . '$puedeRecibirShell') &&
        str_contains($shell, '$marketingShell'),
];
foreach ($checks as $nombre => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $nombre\n");
        exit(1);
    }
}
echo "Entrantes de Analistas: OK (" . count($checks) . " verificaciones estáticas)\n";
