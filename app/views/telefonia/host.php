<?php

$extensionHost = trim(
    (string)($asignacionTelefonica['extension'] ?? '')
);
$usuarioHost = trim(
    (string)($_SESSION['nombre'] ?? '') . ' ' .
    (string)($_SESSION['apellidos'] ?? '')
);

if ($usuarioHost === '') {
    $usuarioHost = (string)($_SESSION['usuario'] ?? 'Usuario');
}
$mostrarPestanaTelefonica = (string)($_GET['display'] ?? '') === 'tab';
$motorTelefonicoEmbebido = (string)($_GET['display'] ?? '') === 'embed';
$analistaHostSdk = $motorTelefonicoEmbebido &&
    strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Analista de Datos') === 0 &&
    !empty($asignacionTelefonica['permite_entrantes']) &&
    tienePermiso('telefonia.recibir') &&
    tienePermiso('seguimientos_vinculacion.operar_propios');

?>
<!DOCTYPE html>
<html
    lang="es"
    data-impe-telephony-host="1"
    data-impe-telephony-host-version="8">
<head>
    <?php require dirname(__DIR__) . '/layout/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">
    <title>Telefonía</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap"
        rel="stylesheet">
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/telefonia_host.css?v=<?= filemtime(ROOT_PATH . '/public/css/telefonia_host.css') ?>">
</head>
<body
    class="<?= $motorTelefonicoEmbebido ? 'telephony-host-embed' : ($mostrarPestanaTelefonica ? 'telephony-host-tab' : '') ?>"
    data-telephony-host
    data-user-id="<?= (int)($_SESSION['usuario_id'] ?? 0) ?>"
    data-extension="<?= htmlspecialchars($extensionHost, ENT_QUOTES, 'UTF-8') ?>">

    <div
        class="telephony-host-sentinel"
        aria-hidden="true">
    </div>
    <?php if ($mostrarPestanaTelefonica): ?>
        <main class="telephony-host-tab-info">
            <span class="telephony-host-tab-icon" aria-hidden="true">
                <i class="bi bi-headset"></i>
            </span>
            <h1>Teléfono institucional</h1>
            <p>Extensión <?= htmlspecialchars($extensionHost, ENT_QUOTES, 'UTF-8') ?></p>
            <div class="telephony-host-tab-status" role="status">
                <i class="bi bi-circle-fill" aria-hidden="true"></i>
                La recepción se está conectando.
            </div>
            <p class="telephony-host-tab-note">
                Esta pestaña mantiene la conexión con Zadarma.
                <strong>No la cierres ni la recargues</strong> mientras estés disponible.
                Puedes trabajar normalmente en la pestaña del CRM.
                Si recibes una llamada, vuelve aquí si tu navegador no
                muestra automáticamente el teléfono.
            </p>
        </main>
    <?php endif; ?>

    <script>
        window.IMPE_TELEPHONY_HOST = <?= json_encode([
            'userId' => (int)($_SESSION['usuario_id'] ?? 0),
            'extension' => $extensionHost,
            'webrtcUrl' => BASE_URL . 'prueba_telefonia/api/zadarma_webrtc.php',
            'validarTransferenciaUrl' => BASE_URL . 'index.php?controller=telefonia&action=validarOrdenTransferencia',
            'transferenciaCsrf' => (string)$_SESSION['csrf_token'],
            'estadoUrl' => BASE_URL . 'prueba_telefonia/api/zadarma_estado_llamada.php',
            'entradaUrl' => BASE_URL . 'prueba_telefonia/api/zadarma_entrada_activa.php',
            'operacionUrl' => BASE_URL . 'prueba_telefonia/api/transferencia_operacion.php',
            'permiteSalientes' => !empty($asignacionTelefonica['permite_salientes']),
            'permiteEntrantes' => !empty($asignacionTelefonica['permite_entrantes']),
            // Sólo para el Analista con sesión autorizada y motor embebido.
            // Marketing continúa usando exclusivamente eventos PBX.
            'analystSdkIncoming' => $analistaHostSdk,
            'permiteTransferir' =>
                !empty($asignacionTelefonica['permite_transferir']) &&
                tienePermiso('telefonia.transferir')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script
        src="<?= BASE_URL ?>public/javascript/telefonia_entrantes_bridge.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_entrantes_bridge.js') ?>">
    </script>
    <?php if ($analistaHostSdk): ?>
    <script
        src="<?= BASE_URL ?>public/javascript/telefonia_sdk_entrantes_observador.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_sdk_entrantes_observador.js') ?>">
    </script>
    <?php endif; ?>
    <script
        src="<?= BASE_URL ?>public/javascript/telefonia_webrtc_dtmf.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_webrtc_dtmf.js') ?>">
    </script>
    <script src="<?= BASE_URL ?>public/javascript/telefonia_audio.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_audio.js') ?>"></script>
    <script src="<?= BASE_URL ?>public/javascript/telefonia_transferencia_runtime.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_transferencia_runtime.js') ?>"></script>
    <script src="<?= BASE_URL ?>public/javascript/telefonia_numeros.js?v=<?= filemtime(ROOT_PATH.'/public/javascript/telefonia_numeros.js') ?>"></script>
    <script
        src="<?= BASE_URL ?>public/javascript/telefonia_host.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_host.js') ?>">
    </script>
</body>
</html>
